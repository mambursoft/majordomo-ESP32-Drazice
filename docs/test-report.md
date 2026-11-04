# Звіт про тестування

Чесний статус: PASS / FAIL / NOT RUN з методом, версією, датою. Test skeleton за
пройдений тест не видаємо.

Середовище: Windows, Python 3.13.13, PHP 8.2.29 CLI (цільовий runtime — PHP 7.3,
синтаксис 7.3-сумісний), ESPHome 2025.9.3. Дата: 2026-11-01.

## Модуль 1.8.0: кеш стану, AJAX-бекенд та AJAX-вкладки (виконано локально, PHP 8.2.29, 2026-11-04)

| Пункт | Статус | Метод |
|---|---|---|
| Юніт-тести бібліотек (Transport `/state` + URL запису `/set`, StateCache, AjaxApi, сумісність з PHP 7.3, `LangKeysTest`: ключі мов, ідентичність двох наборів, токени шаблонів, статичний JS) | **PASS** | `php majordomo/tests/run.php` → PASSED: 602, FAILED: 0 (було 222 до 1.8.0) |
| Модульні тести (0 викликів ESP при рендері 7 вкладок і `usual()`, 1 `getAll` у `cycleStep`, CSRF/метод до будь-якої роботи, коалесценція, DIRTY, ESP недоступний, відсутність `ESP_PASS` у JSON, `JS_I18N`/`JS_CONFIG`/`DATA_*` у кожній вкладці) | **PASS** | `php majordomo/tests/run_module.php` → PASSED: 323, FAILED: 0 (заглушки MajorDoMo, `CountingTransport`) |
| Синтаксис `drazice_smart.js` | **PASS** | `node --check majordomo/modules/drazice_smart/js/drazice_smart.js` |
| `tools/diag_install.php` (admin 7 вкладок, usual, ajax без fatal) | **PASS** | вивід містить `OK: no fatal` |
| Мовні файли: два набори байт-у-байт | **PASS** | `fc /b` для default, ru, ua + `LangKeysTest` |
| `esphome config` (`GET /state`, FEAT-001) | **PASS** | `Configuration is valid!`, ESPHome 2025.9.3 |
| `esphome compile` (RAM/Flash, граф залежностей) | **PASS** | RAM 19.1%, Flash 44.6%, без ESPAsyncWebServer/AsyncTCP/ArduinoJson (запуск FEAT-001, прошивка після нього не змінювалась) |
| Хост-тест `json_stream.h` (g++) | **PASS** | `tools/test/test_json_stream.cpp`, 17 перевірок |
| Збірка пакета (`tools/build/build_package.py`) | **PASS** | `drazice_smart.tgz` та `app_drazice_smart.tgz` містять `modules/drazice_smart/js/drazice_smart.js` і нові lib-файли |
| Поведінка JS у браузері (авто-оновлення Статусу ≈10 с, пейрінг ≈2 с, відправка форм без перезавантаження) | **NOT RUN** | перевірено лише читанням коду та `node --check`; браузерного прогону не було |
| `GET /state` на реальному залізі (автентифікація, LED, heap) | **NOT RUN** | плату не прошивали |
| Підрахунок викликів до ESP до/після | **PASS** (лише кількість викликів зі заглушок, не час на залізі) | `php tools/measure_calls.php`; admin() було 28 GET → стало 0 |
| `php -l` усіх PHP-файлів `majordomo/` і `tools/` | **PASS** | 0 помилок (на PHP 8.2; сумісність з 7.3 перевіряє токен-скан у `Php73CompatTest`) |
| Реальний MajorDoMo (jTemplate, сесія, MariaDB 10.3, PHP 7.3), реальна плата | **NOT RUN** | потрібне середовище користувача |

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
