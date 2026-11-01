# Discovery — підтверджені факти та прогалини

Дата: 2026-11-01. Джерела прочитано під час реалізації.

## Upstream smartboiler (LubosD/esphome-smartboiler)

Зафіксований commit: `c1b4e18ded8d3de58a19022e5155134982ddeb9a`.

Прочитано фактичний код (`smartboiler.cpp`, `smartboiler.h`, `__init__.py`):

| Факт | Значення (з коду) |
|---|---|
| BLE сервіси | main 0x1899/0x2B99 (запис), logging 0x1898/0x2B98 (нотифікації) |
| Setpoint діапазон | `MIN_TEMP = 40`, `MAX_TEMP = 80` °C |
| Режими (запис) | ANTIFREEZE, SMART, PROG, MANUAL |
| STOP | присутній у enum, але `on_set_mode` його не виставляє → **недоступний як команда** |
| Heat | `SBC_PACKET_HOME_HSRCSTATE == 1` → binary_sensor `heat_on` |
| Temp1 / Temp2 | нижній / верхній датчики (°C) |
| Energy | `STATISTICS_GETALL`, публікується `consumption/1000` (kWh, total_increasing) |
| Автентифікація | випадковий 6-символьний UID (NVS), PIN-пейрінг у стані NEED_PIN |
| Entity-ключі YAML | temp1, temp2, mode, hdo_low_tariff, heat_on, thermostat, consumption, b_name, pin, state, version |
| MULTI_CONF | 3 (до 3 бойлерів, але автор радить окрему ESP на кожен) |

**Ліцензія upstream: відсутня** (немає файлу LICENSE, у README не згадана). Наслідок:
ми **не копіюємо** код upstream, а підключаємо через `external_components`
(`type: git`, pinned commit). Деталі — `THIRD_PARTY_NOTICES.md`.

## Сумісність версій ESPHome (перевірено локально)

Критична знахідка: upstream викликає `climate.climate_schema()`,
`number.number_schema()`, `select.select_schema()`. Ці хелпери існують лише у
вікні **ESPHome 2025.5 … 2025.10**:

- у 2024.12 їх ще немає → `AttributeError: module 'esphome.components.climate'
  has no attribute 'climate_schema'` (перевірено `esphome config`);
- у 2025.11 їх видалено (позначено «Remove before 2025.11.0» у коді ESPHome).

Тому зафіксовано **ESPHome 2025.9.3**. `esphome config firmware/boiler-weact.yaml`
→ **`INFO Configuration is valid!`** (перевірено локально, Windows, Python 3.13).

## Апаратна платформа

WeAct ESP32-D0WD-V3, етикетка 8 MB Flash. `board: esp32dev`, framework Arduino.
8 MB з етикетки **не доводить** PSRAM — PSRAM не вмикаємо. Реальні board / flash
mode / freq / pinout / USB VID-PID підтвердити на платі (`flash_id`, `chip_id`).

## HTTP без MQTT

ESPHome `web_server` v3 з auth, `local: true` (JS/CSS у прошивці, без CDN).
Нативний API вимкнено. Транспорт модуля — PHP cURL до REST. HTTP 200 ≠ успіх
команди: модуль завжди робить readback.

## Прогалини, які закриває користувач

- Реальний BLE MAC бойлера (видно лише без іншого клієнта).
- Семантика `BatteryMode.active` (полярність 0/1) — підтверджує адмін у UI.
- Назва властивості прогнозу СЕС — пошук у UI, прив'язка вручну.
- Реальна потужність ТЕНу, перевірка лінії електриком.
- Доведений механізм hard-pause — поки `BLOCKED`.
