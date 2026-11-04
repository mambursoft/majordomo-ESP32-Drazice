# REST-контракт ESP32 ↔ модуль

Транспорт: PHP cURL → ESPHome `web_server` v3 REST. Без MQTT, без нативного API.
HTTP 200 **не доводить** виконання BLE-команди — завжди readback.

## Кодування шляху

Ім'я entity кодується **за сегментами** (пробіли → `%20`). Значення беремо з
JSON (`value`), **не** парсимо рядок `state` типу `'55 °C'`.

```sh
# Читання (не лишайте реальний пароль в історії shell).
curl --user "$ESP_USER:$ESP_PASS" --connect-timeout 2 --max-time 5 \
  "http://$ESP_HOST/sensor/Boiler%20Temp%20Upper"

# Guarded-запит (лише на стенді з дозволом керування).
curl --user "$ESP_USER:$ESP_PASS" -X POST \
  "http://$ESP_HOST/number/Boiler%20Requested%20Target/set?value=55"
```

## Пакетний стан: `GET /state`

Один запит замість ~28 окремих `GET /{domain}/{name}` (швидші сторінки модуля).
Відповідь — один JSON: `{"sensor":{...},"binary_sensor":{...},"text_sensor":{...},
"number":{...},"select":{...}}`; всередині домену ключ — display-name entity,
значення — `{"value":..,"state":..}` з тією ж семантикою, що й у per-entity GET
(без поля `id`). Відсутній стан/NaN → `value:null` (ніколи 0).

- Той самий Basic auth; без нього — `401`.
- HTTP/1.0 з `Connection: close`, без `Content-Length`: читати до закриття.
- internal entity не віддаються; є write-only `Boiler Pairing PIN` —
  споживачі ігнорують його `value`.
- Per-entity endpoints лишаються без змін.

```sh
curl --user "$ESP_USER:$ESP_PASS" --connect-timeout 2 --max-time 5 "http://$ESP_HOST/state"
```

## Entity (див. `firmware/firmware-api-manifest.json`)

Читання: `Boiler Temp Lower/Upper`, `Boiler Heating`, `Boiler HDO`,
`Boiler Energy`, `Boiler State/Version/Name`, `Boiler Guard State/Reason`,
`Boiler Continuous Heat Seconds`, `Boiler Cooldown Remaining`,
`Boiler Lease Remaining`, діагностика `ESP *`.

Запис (guarded, через `boiler_guard`): `number/Boiler Requested Target` (40–80),
`select/Boiler Requested Mode` (ANTIFREEZE/SMART/PROG/MANUAL),
`button/Boiler Lease Renew|Release BLE|Reconnect BLE`,
`number/Boiler Pairing PIN` (1111–9999, чутливе — не логувати).

Прямі entity бойлера (`Boiler Mode (direct)`, `Boiler Thermostat (direct)`) —
`internal: true`, назовні недоступні → обійти guard не можна.

## Безпека транспорту (реалізовано у `lib/Transport.php`)

- Валідація хоста: лише приватні LAN-адреси; loopback, link-local
  (169.254.169.254), публічні IP — відхиляються (SSRF-захист).
- `value` з JSON; NaN/null/нескінченність → `unavailable` (ніколи 0).
- Таймаути connect/max; без follow-redirect.
- 401/403 → окрема помилка auth.

## Стабільність контракту

Модуль звіряє `firmware-api-manifest.json` (`schema_version`). При невідомій
major-схемі — read-only + попередження. Остаточні імена entity фіксуються після
`esphome compile` і REST-тесту.
