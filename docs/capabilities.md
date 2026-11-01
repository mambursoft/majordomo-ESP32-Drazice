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
| Hard pause / heating inhibit | ⛔ **BLOCKED** (не доведено на обладнанні) | — |
| Pause через зниження target | ❓ не гарантує Heat=false | потребує стенду |

## Критичний gate перед автоматикою

До production-автоматики підтвердити **на вашому бойлері**:
1. Чи змінюється target у NORMAL/HDO, межі й крок.
2. Що означають NIGHT/ANTIFROST у цьому firmware.
3. Чи є реальна команда heating inhibit (у README немає).
4. Чи зниження target реально зупиняє Heat, з якою затримкою/hysteresis.
5. Поведінка після reboot, BLE loss, ручної зміни, антибактеріального циклу.

Поки hard-pause не доведено — автоматика обмежена, вимога «батарея → без
навантаження» позначена **BLOCKED**. Guard робить best-effort (зниження target),
але підтверджує зупинку **лише** за фактичним Heat=false; інакше → `FAULT`, а не
«пауза виконана».
