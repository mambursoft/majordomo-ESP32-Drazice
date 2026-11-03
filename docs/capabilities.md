# Capability-профіль: Drazice OKHE 125 SMART

Підтверджені можливості (з коду upstream, pinned commit). Для інших SMART-моделей
створюйте окремий перевірений профіль — не припускайте підтримку всіх Drazice.

| Можливість | Статус | Джерело |
|---|---|---|
| Читання Temp1/Temp2 | ✔ підтверджено | smartboiler.cpp |
| Читання Heat (HSRCSTATE) | ✔ підтверджено | smartboiler.cpp |
| Читання HDO | ✔ підтверджено | smartboiler.cpp |
| Читання Energy (kWh) | ✔ (перевірити масштаб/скидання) | consumption/1000 |
| Встановлення target 40–80 °C | ✔ підтверджено діапазон | MIN_TEMP/MAX_TEMP |
| Встановлення режиму | ✔ ANTIFREEZE/SMART/PROG/MANUAL | __init__.py |
| STOP (повне вимкнення) | �’ **недоступний як команда** | on_set_mode |
| Pause: ANTIFREEZE + target=40 + верифікація Heat=false | ✔ реалізовано в boiler_guard | enter_cooldown_ |
| Hard pause / фізичне heating inhibit | ⚠️ best-effort через BLE (не safety-rated) | STOP недоступний |

## Критичний gate перед автоматикою

До production-автоматики підтвердити **на вашому бойлері**:
1. Чи змінюється target у NORMAL/HDO, межі й крок.
2. Що означають NIGHT/ANTIFROST у цьому firmware.
3. Чи є реальна команда heating inhibit (у README немає).
4. Чи зниження target реально зупиняє Heat, з якою затримкою/hysteresis.
5. Поведінка після reboot, BLE loss, ручної зміни, антибактеріального циклу.

Механізм паузи реалізовано: при вході в cooldown guard перемикає бойлер у
**ANTIFREEZE** і знижує target до 40 °C (найсильніша доступна дія через
BLE-протокол, бо STOP недоступний), після чого **верифікує** фактичний
`Heat=false` протягом debounce. Якщо Heat не впав у межах `command_timeout` →
стан `FAULT` (а не «пауза виконана»), нові дозволи heating блокуються.

Це best-effort через BLE, **не** safety-rated фізичне відключення. Якщо вам
потрібне гарантоване знеструмлення ТЕНу (напр. при роботі від батарей) — це
окремий апаратний контактор, не ця прошивка. На вашому конкретному firmware
бойлера затримку/гістерезис реакції Heat варто виміряти на стенді (значення
`heat_false_debounce_s`, `command_timeout_s` конфігуровані).
