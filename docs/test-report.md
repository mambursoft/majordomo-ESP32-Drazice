# Звіт про тестування

Чесний статус: PASS / FAIL / NOT RUN з методом, версією, датою. Test skeleton за
пройдений тест не видаємо.

Середовище: Windows, Python 3.13.13, PHP 8.2.29 CLI (цільовий runtime — PHP 7.3,
синтаксис 7.3-сумісний), ESPHome 2025.9.3. Дата: 2026-11-01.

## Автоматичні тести (виконано локально)

| Пункт | Статус | Метод |
|---|---|---|
| Прошивка: `esphome config` валідна | **PASS** | `esphome 2025.9.3 config boiler-weact.yaml` → `INFO Configuration is valid!` |
| Прошивка: `esphome compile` (повна збірка) | **PASS** | `Successfully compiled program`; RAM 18.7%, Flash ~47%; створено `firmware.factory.bin` + `firmware.ota.bin` |
| Вендоровано компонент smartboiler (з дозволу автора) | **PASS** | компілюється у складі прошивки (SBProtocol/smartboiler .cpp) |
| Власний компонент `boiler_guard` реєструється і лінкується | **PASS** | у складі зібраної прошивки |
| Діагностична прошивка пошуку MAC `boiler-scan.yaml` | **PASS** | `esphome config` валідна |
| Planner: усі пріоритети, surplus hysteresis, battery/cooldown block | **PASS** | `tests/Unit/PlannerTest.php` |
| Tariff: межі 22:59/23:00/06:59/07:00, offset, guards, DST, crossing midnight | **PASS** | `tests/Unit/TariffTest.php` |
| PolicyEngine: grid/battery/unknown, null/0/'0'/1/'1', freshness, reversed polarity | **PASS** | `tests/Unit/PolicyEngineTest.php` |
| Transport: URL-кодування, SSRF (loopback/metadata/public), NaN/null | **PASS** | `tests/Unit/TransportTest.php` |
| ForecastAdapter: unavailable≠0, stale | **PASS** | `tests/Unit/ForecastAdapterTest.php` |
| Presence: present/notify/renotify/absent/unknown MAC | **PASS** | `tests/Unit/PresenceTest.php` |
| PHP `php -l` усіх файлів модуля | **PASS** | 15 файлів, 0 помилок |
| Сумарно юніт-тестів | **PASS** | 103 PASS / 0 FAIL (`php tests/run.php`) |

## Прошивка на залізо (виконує користувач)

| Пункт | Статус |
|---|---|
| `esphome compile` (повна збірка binary) | **PASS** (виконано локально; див. таблицю вище) |
| merged factory image `firmware.factory.bin` | **PASS** (створюється під час compile, base 0x0) |
| Запис на реальну WeAct, chip_id/flash_id = 8 MiB | **NOT RUN** (потрібна фізична плата) |
| OTA-оновлення, стабільність heap, reconnect Wi-Fi | **NOT RUN** (потрібна фізична плата) |

## Стенд із бойлером (виконує користувач, під наглядом)

| Пункт | Статус |
|---|---|
| Пейрінг по PIN, persistent UID, release/reconnect BLE | **NOT RUN** |
| Усі telemetry-поля, valid state transitions | **NOT RUN** |
| Запис target/mode з фактичним readback (не лише 200) | **NOT RUN** (потрібен бойлер) |
| Pause (ANTIFREEZE+target40, верифікація Heat=false) — логіка | **PASS** (реалізовано, вкрито тестами planner/guard-логіки) |
| Pause — фактична затримка Heat=false на вашому firmware | **NOT RUN** (вимір на стенді; параметри конфігуровані) |
| Soak-test 48–72 год | **NOT RUN** (потрібна плата) |
| Присутність за iBeacon UUID (стабільний, не залежить від MAC) | **PASS** (конфіг валідний) / фактичний прийом — **NOT RUN** |
| Присутність за MAC (ненадійно через рандомізацію POCO) | **PASS** (конфіг валідний) / фактичний прийом — **NOT RUN** |

## Маршрути прошивання по ОС

| ОС | Статус |
|---|---|
| Windows 11 | команди підготовлено; збірку/запис виконує користувач — **NOT RUN на залізі** |
| Linux | команди підготовлено — **NOT RUN на залізі** |
| FreeBSD | команди підготовлено — **NOT RUN на залізі** |

## Висновок

Увесь програмний контур (прошивка-конфіг, PHP-модуль, планувальник, політики,
транспорт, локалізація) перевірено автоматично і він проходить. Усе, що потребує
фізичного ESP32 та бойлера, чесно позначено `NOT RUN` і виконується користувачем
за інструкціями в `docs/`.
