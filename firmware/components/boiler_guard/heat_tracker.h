#pragma once

// HeatTracker: облік ПІДТВЕРДЖЕНОГО нагріву для boiler_guard.
//
// Header-only, без залежностей від ESPHome, щоб логіку можна було перевірити
// хост-тестом (tools/test/test_heat_tracker.cpp, g++).
//
// Чому окремий клас. Бінарний сенсор Heat публікує стан ЛИШЕ при зміні. Старий
// код перевіряв debounce для Heat=false тільки всередині обробника цієї зміни,
// тобто рівно один раз і в момент, коли debounce ще не минув. Тому
// "heat_confirmed" ніколи не скидався, лічильник безперервного нагріву ріс
// далі навіть коли бойлер не гріє, сягав ліміту, guard входив у cooldown, не
// бачив зупинки й переходив у FAULT назавжди (FAULT відкидає всі команди).
// Тут debounce перевіряється в tick() за часом, незалежно від подій.

#include <stdint.h>

namespace esphome {
namespace boiler_guard {

class HeatTracker {
 public:
  void set_debounce_s(uint32_t v) { debounce_s_ = v; }

  // Виклик на КОЖНУ подію зміни Heat (з on_state бінарного сенсора).
  void report(bool heating, uint32_t now) {
    if (heating) {
      confirmed_ = true;      // нагрів підтверджуємо одразу
      false_since_ = 0;       // скасовуємо відлік "не гріє"
    } else if (false_since_ == 0) {
      // Початок відліку debounce. 0 означає "відліку нема", тому now==0
      // зсуваємо на 1 с (uptime 0 с на практиці не трапляється).
      false_since_ = now ? now : 1;
    }
  }

  // Виклик щоциклу loop() з монотонним годинником у секундах.
  // accumulate=false (наприклад, під час cooldown) не нарощує лічильник.
  void tick(uint32_t now, bool accumulate) {
    if (last_tick_ == 0) last_tick_ = now;
    uint32_t dt = (now >= last_tick_) ? (now - last_tick_) : 0;
    last_tick_ = now;

    // Debounce Heat=false за часом: якщо "не гріє" триває достатньо довго,
    // нагрів більше не підтверджений, а безперервність перервана -> скидаємо.
    if (false_since_ != 0 && (now - false_since_) >= debounce_s_) {
      confirmed_ = false;
      continuous_s_ = 0;
    }

    if (confirmed_ && accumulate) continuous_s_ += dt;
  }

  bool confirmed() const { return confirmed_; }
  uint32_t continuous_s() const { return continuous_s_; }
  void set_continuous_s(uint32_t v) { continuous_s_ = v; }
  void reset_continuous() { continuous_s_ = 0; }

 protected:
  uint32_t debounce_s_{30};
  bool confirmed_{false};
  uint32_t false_since_{0};
  uint32_t continuous_s_{0};
  uint32_t last_tick_{0};
};

}  // namespace boiler_guard
}  // namespace esphome
