#pragma once

#include "esphome/core/component.h"
#include "esphome/core/preferences.h"
#include "esphome/components/select/select.h"
#include "esphome/components/climate/climate.h"
#include "esphome/components/binary_sensor/binary_sensor.h"
#include <string>

namespace esphome {
namespace boiler_guard {

// Стан-машина локального arbiter. Відповідає docs/architecture.
enum class GuardState {
  BOOT_SAFE,
  DISCONNECTED,
  IDLE,
  HEATING_ALLOWED,
  PAUSE_REQUESTED,
  COOLDOWN,
  LEASE_EXPIRED,
  FAULT,
};

// Durable state, збережений у NVS. Пишемо рідко (не щосекунди).
struct SavedGuardState {
  uint32_t cooldown_until_uptime_s;  // відносно uptime НЕ зберігаємо між reboot
  uint32_t cont_heat_s;              // накопичений нагрів на момент збереження
  uint8_t  in_cooldown;              // 1 => на момент reboot була пауза
  uint32_t cooldown_total_s;         // повна тривалість паузи
} PACKED;

class BoilerGuard : public Component {
 public:
  void setup() override;
  void loop() override;
  void dump_config() override;
  float get_setup_priority() const override { return setup_priority::LATE; }

  // --- конфіг (сетери з __init__.py) ---
  void set_smartboiler(Component *c) { smartboiler_ = c; }
  void set_mode_select(select::Select *s) { mode_select_ = s; }
  void set_thermostat(climate::Climate *t) { thermostat_ = t; }
  void set_heating_sensor(binary_sensor::BinarySensor *s) { heating_sensor_ = s; }
  void set_max_continuous_heat_s(uint32_t v) { max_continuous_heat_s_ = v; }
  void set_min_pause_s(uint32_t v) { min_pause_s_ = v; }
  void set_heat_false_debounce_s(uint32_t v) { heat_false_debounce_s_ = v; }
  void set_lease_ttl_s(uint32_t v) { lease_ttl_s_ = v; }
  void set_command_timeout_s(uint32_t v) { command_timeout_s_ = v; }

  // --- хуки з YAML ---
  void on_safe_boot();
  void on_heat_report(bool heating);

  // --- guarded setters (викликаються з template entities) ---
  void request_target(float celsius);
  void request_mode(const std::string &mode);
  void renew_lease();
  void release_ble();
  void reconnect_ble();

  // --- геттери для readback / діагностики ---
  float get_requested_target() { return requested_target_; }
  std::string get_requested_mode() { return requested_mode_; }
  uint32_t get_continuous_heat_seconds() { return cont_heat_s_; }
  uint32_t get_cooldown_remaining_s();
  uint32_t get_lease_remaining_s();
  std::string get_state_text();
  std::string get_reason_text();

 protected:
  void set_state_(GuardState s, const std::string &reason);
  bool lease_valid_();
  void enter_cooldown_(const std::string &reason);
  void apply_mode_to_boiler_(const std::string &mode);
  void apply_target_to_boiler_(float celsius);
  void save_durable_();
  void restore_durable_();
  uint32_t now_s_();  // монотонний годинник у секундах

  Component *smartboiler_{nullptr};
  select::Select *mode_select_{nullptr};
  climate::Climate *thermostat_{nullptr};
  binary_sensor::BinarySensor *heating_sensor_{nullptr};

  uint32_t max_continuous_heat_s_{3600};
  uint32_t min_pause_s_{600};
  uint32_t heat_false_debounce_s_{30};
  uint32_t lease_ttl_s_{180};
  uint32_t command_timeout_s_{20};

  GuardState state_{GuardState::BOOT_SAFE};
  std::string reason_{"boot"};

  float requested_target_{NAN};
  std::string requested_mode_{};

  // облік фактичного нагріву
  bool heat_confirmed_{false};
  uint32_t heat_true_since_s_{0};
  uint32_t heat_false_since_s_{0};
  uint32_t cont_heat_s_{0};

  // cooldown
  bool in_cooldown_{false};
  uint32_t cooldown_until_s_{0};
  uint32_t cooldown_total_s_{0};

  // lease
  uint32_t lease_until_s_{0};

  // pending-команда для readback
  bool pending_cmd_{false};
  uint32_t pending_since_s_{0};
  std::string pending_kind_{};

  ESPPreferenceObject pref_;
  uint32_t last_save_s_{0};
};

}  // namespace boiler_guard
}  // namespace esphome
