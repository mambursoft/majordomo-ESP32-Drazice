#include "boiler_guard.h"
#include "esphome/core/log.h"
#include "esphome/core/hal.h"
#include <cmath>

namespace esphome {
namespace boiler_guard {

static const char *const TAG = "boiler_guard";

uint32_t BoilerGuard::now_s_() { return millis() / 1000UL; }

void BoilerGuard::setup() {
  this->pref_ = global_preferences->make_preference<SavedGuardState>(fnv1_hash("boiler_guard_state"));
  this->restore_durable_();
  this->set_state_(GuardState::BOOT_SAFE, "boot_safe");
}

void BoilerGuard::on_safe_boot() {
  // Безпечний старт: не видаємо жодних дозволів heating до першого lease.
  // Якщо на момент reboot була пауза — відтворюємо ПОВНУ паузу від boot
  // (неможливо надійно відновити elapsed; консервативно починаємо спочатку).
  if (this->in_cooldown_) {
    this->cooldown_until_s_ = this->now_s_() + this->cooldown_total_s_;
    this->set_state_(GuardState::COOLDOWN, "cooldown_restored_full_after_reboot");
  } else {
    this->set_state_(GuardState::IDLE, "idle_after_boot");
  }
  this->lease_until_s_ = 0;  // немає lease при старті
}

void BoilerGuard::restore_durable_() {
  SavedGuardState s{};
  if (this->pref_.load(&s)) {
    this->cont_heat_s_ = s.cont_heat_s;
    this->in_cooldown_ = s.in_cooldown == 1;
    this->cooldown_total_s_ = s.cooldown_total_s ? s.cooldown_total_s : this->min_pause_s_;
  } else {
    this->in_cooldown_ = false;
    this->cooldown_total_s_ = this->min_pause_s_;
  }
}

void BoilerGuard::save_durable_() {
  SavedGuardState s{};
  s.cont_heat_s = this->cont_heat_s_;
  s.in_cooldown = this->in_cooldown_ ? 1 : 0;
  s.cooldown_total_s = this->cooldown_total_s_;
  s.cooldown_until_uptime_s = this->cooldown_until_s_;
  this->pref_.save(&s);
  this->last_save_s_ = this->now_s_();
}

void BoilerGuard::on_heat_report(bool heating) {
  // Облік тривалого нагріву за ПІДТВЕРДЖЕНИМ Heat, з debounce для дрібних провалів.
  uint32_t now = this->now_s_();
  if (heating) {
    if (!this->heat_confirmed_) {
      this->heat_confirmed_ = true;
      this->heat_true_since_s_ = now;
    }
    this->heat_false_since_s_ = 0;
  } else {
    // Heat=false: рахуємо паузу лише після стабільного debounce.
    if (this->heat_false_since_s_ == 0) this->heat_false_since_s_ = now;
    if (this->heat_confirmed_ &&
        (now - this->heat_false_since_s_) >= this->heat_false_debounce_s_) {
      this->heat_confirmed_ = false;
    }
  }
}

void BoilerGuard::loop() {
  uint32_t now = this->now_s_();

  // 1. Акумулюємо тривалий нагрів лише коли Heat ПІДТВЕРДЖЕНО true.
  static uint32_t last_tick = 0;
  if (last_tick == 0) last_tick = now;
  if (now != last_tick) {
    uint32_t dt = now - last_tick;
    last_tick = now;
    if (this->heat_confirmed_ && !this->in_cooldown_) {
      this->cont_heat_s_ += dt;
    }
  }

  // 2. Ліміт тривалого нагріву -> вимагаємо паузу.
  if (!this->in_cooldown_ && this->cont_heat_s_ >= this->max_continuous_heat_s_) {
    this->enter_cooldown_("max_continuous_heat_reached");
  }

  // 3. Під час cooldown чекаємо фактичного Heat=false. Якщо Heat лишається true
  //    понад command_timeout -> FAULT (а не завершена пауза).
  if (this->in_cooldown_) {
    if (this->heat_confirmed_ &&
        (now - this->pending_since_s_) > this->command_timeout_s_) {
      this->set_state_(GuardState::FAULT, "heat_not_stopped_in_cooldown");
    } else if (now >= this->cooldown_until_s_ && !this->heat_confirmed_) {
      this->in_cooldown_ = false;
      this->cont_heat_s_ = 0;
      this->save_durable_();
      this->set_state_(GuardState::IDLE, "cooldown_complete");
    }
  }

  // 4. Lease.
  if (this->state_ != GuardState::FAULT && this->state_ != GuardState::COOLDOWN) {
    if (!this->lease_valid_()) {
      if (this->state_ != GuardState::LEASE_EXPIRED)
        this->set_state_(GuardState::LEASE_EXPIRED, "lease_expired_no_new_heating");
    } else if (this->state_ == GuardState::LEASE_EXPIRED) {
      this->set_state_(GuardState::IDLE, "lease_renewed");
    }
  }

  // 5. Pending readback.
  if (this->pending_cmd_ && (now - this->pending_since_s_) > this->command_timeout_s_) {
    this->pending_cmd_ = false;
    ESP_LOGW(TAG, "command '%s' readback timeout", this->pending_kind_.c_str());
  }

  // 6. Періодичне збереження durable state (не частіше, ніж раз на 30 с).
  if ((now - this->last_save_s_) >= 30) this->save_durable_();
}

bool BoilerGuard::lease_valid_() { return this->now_s_() < this->lease_until_s_; }

void BoilerGuard::enter_cooldown_(const std::string &reason) {
  this->in_cooldown_ = true;
  this->cooldown_total_s_ = this->min_pause_s_;
  this->cooldown_until_s_ = this->now_s_() + this->min_pause_s_;
  this->pending_since_s_ = this->now_s_();
  this->save_durable_();
  // Найсильніша доступна стратегія паузи через BLE-протокол (STOP недоступний):
  //   1) перемикаємо режим у ANTIFREEZE — мінімальне енергоспоживання/захист;
  //   2) знижуємо target до мінімуму (40 °C).
  // УВАГА: це НЕ гарантує фізичного вимкнення ТЕНу. Реальну зупинку
  // ПІДТВЕРДЖУЄМО лише за фактичним Heat=false протягом debounce. Якщо Heat не
  // впав у межах command_timeout — переходимо у FAULT (див. loop()), а НЕ
  // вважаємо паузу виконаною.
  this->apply_mode_to_boiler_("ANTIFREEZE");
  this->apply_target_to_boiler_(40.0f);
  this->set_state_(GuardState::PAUSE_REQUESTED, reason);
  this->set_state_(GuardState::COOLDOWN, reason);
}

void BoilerGuard::request_target(float celsius) {
  if (std::isnan(celsius) || celsius < 40.0f || celsius > 80.0f) {
    ESP_LOGW(TAG, "requested target out of range: %.1f", celsius);
    return;
  }
  this->requested_target_ = celsius;
  // Під час cooldown/FAULT не піднімаємо target вище мінімуму.
  if (this->in_cooldown_ || this->state_ == GuardState::FAULT) {
    ESP_LOGW(TAG, "target request ignored during cooldown/fault");
    return;
  }
  if (!this->lease_valid_()) {
    ESP_LOGW(TAG, "target request without valid lease -> stored, not applied");
    return;
  }
  this->apply_target_to_boiler_(celsius);
  this->pending_cmd_ = true;
  this->pending_since_s_ = this->now_s_();
  this->pending_kind_ = "target";
}

void BoilerGuard::request_mode(const std::string &mode) {
  // Дозволені лише перевірені режими. STOP недоступний взагалі.
  if (mode != "ANTIFREEZE" && mode != "SMART" && mode != "PROG" && mode != "MANUAL") {
    ESP_LOGW(TAG, "rejected unknown mode: %s", mode.c_str());
    return;
  }
  this->requested_mode_ = mode;
  if (this->state_ == GuardState::FAULT) {
    ESP_LOGW(TAG, "mode request ignored during fault");
    return;
  }
  this->apply_mode_to_boiler_(mode);
  this->pending_cmd_ = true;
  this->pending_since_s_ = this->now_s_();
  this->pending_kind_ = "mode";
}

void BoilerGuard::apply_target_to_boiler_(float celsius) {
  if (this->thermostat_ == nullptr) return;
  auto call = this->thermostat_->make_call();
  call.set_target_temperature(celsius);
  call.perform();
}

void BoilerGuard::apply_mode_to_boiler_(const std::string &mode) {
  if (this->mode_select_ == nullptr) return;
  auto call = this->mode_select_->make_call();
  call.set_option(mode);
  call.perform();
}

void BoilerGuard::renew_lease() {
  this->lease_until_s_ = this->now_s_() + this->lease_ttl_s_;
  if (this->state_ == GuardState::LEASE_EXPIRED) this->set_state_(GuardState::IDLE, "lease_renewed");
}

void BoilerGuard::release_ble() {
  ESP_LOGI(TAG, "release BLE requested (штатний застосунок може під'єднатися)");
  // Фактичне відключення BLE робиться кнопкою reconnect на рівні ble_client у
  // діагностиці; тут лише фіксуємо намір і стан.
  this->set_state_(GuardState::DISCONNECTED, "ble_released_by_operator");
}

void BoilerGuard::reconnect_ble() {
  ESP_LOGI(TAG, "reconnect BLE requested");
  this->set_state_(GuardState::IDLE, "ble_reconnect_requested");
}

uint32_t BoilerGuard::get_cooldown_remaining_s() {
  if (!this->in_cooldown_) return 0;
  uint32_t now = this->now_s_();
  return now >= this->cooldown_until_s_ ? 0 : (this->cooldown_until_s_ - now);
}

uint32_t BoilerGuard::get_lease_remaining_s() {
  uint32_t now = this->now_s_();
  return now >= this->lease_until_s_ ? 0 : (this->lease_until_s_ - now);
}

void BoilerGuard::set_state_(GuardState s, const std::string &reason) {
  this->state_ = s;
  this->reason_ = reason;
  ESP_LOGI(TAG, "state=%s reason=%s", this->get_state_text().c_str(), reason.c_str());
}

std::string BoilerGuard::get_state_text() {
  switch (this->state_) {
    case GuardState::BOOT_SAFE:       return "BOOT_SAFE";
    case GuardState::DISCONNECTED:    return "DISCONNECTED";
    case GuardState::IDLE:            return "IDLE";
    case GuardState::HEATING_ALLOWED: return "HEATING_ALLOWED";
    case GuardState::PAUSE_REQUESTED: return "PAUSE_REQUESTED";
    case GuardState::COOLDOWN:        return "COOLDOWN";
    case GuardState::LEASE_EXPIRED:   return "LEASE_EXPIRED";
    case GuardState::FAULT:           return "FAULT";
    default:                          return "UNKNOWN";
  }
}

std::string BoilerGuard::get_reason_text() { return this->reason_; }

void BoilerGuard::dump_config() {
  ESP_LOGCONFIG(TAG, "BoilerGuard:");
  ESP_LOGCONFIG(TAG, "  max_continuous_heat_s: %u", this->max_continuous_heat_s_);
  ESP_LOGCONFIG(TAG, "  min_pause_s: %u", this->min_pause_s_);
  ESP_LOGCONFIG(TAG, "  lease_ttl_s: %u", this->lease_ttl_s_);
}

}  // namespace boiler_guard
}  // namespace esphome
