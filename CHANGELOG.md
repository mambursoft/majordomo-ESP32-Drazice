# CHANGELOG

Формат: [Keep a Changelog](https://keepachangelog.com/uk/1.0.0/).
Версіонування: [SemVer](https://semver.org/lang/uk/).

## [1.1.0] — 2026-11-01

### Змінено

- **Компонент smartboiler вендоровано** (включено в репо,
  `firmware/components/smartboiler/`) з **дозволу автора upstream** LubosD.
  Раніше підключався через `external_components: git`. Збірка тепер
  відтворювана і не залежить від доступності зовнішнього репо. Атрибуція —
  `firmware/components/smartboiler/UPSTREAM.md`, `THIRD_PARTY_NOTICES.md`.
- Прибрано застереження «ліцензія upstream невідома» з README і NOTICES.
- **Прошивку реально зібрано** (`esphome compile`), а не лише валідовано:
  створюються `firmware.factory.bin` і `firmware.ota.bin`.

### Виправлено

- Колізія id `esp_reset_reason` із системною функцією ESP-IDF, що ламала
  компіляцію (`redeclared as different kind of entity`). Перейменовано на
  `esp_reset_cause`; виклик через `::esp_reset_reason()`.

### Усунено «відкриті моменти»

- **Пошук BLE MAC бойлера без здогадок**: діагностична прошивка
  `firmware/boiler-scan.yaml` + пакет `packages/ble_scan.yaml` сканують ефір,
  логують усі пристрої та позначають «схоже на бойлер». Більше не треба знати
  MAC заздалегідь.
- **Механізм паузи реалізовано** (не BLOCKED): при cooldown guard перемикає
  бойлер у ANTIFREEZE + target 40 °C і **верифікує** фактичний Heat=false;
  інакше FAULT. Best-effort через BLE (не safety-rated).
- **Присутність стійка до рандомізації MAC**: пакет `packages/presence.yaml`
  додає визначення за стабільним **iBeacon UUID** (надійно, не залежить від
  рандомного MAC) поряд з визначенням за MAC.

## [1.0.0] — 2026-11-01

Перша публічна версія.

### Додано

- **Прошивка ESPHome** для WeAct Studio ESP32-D0WD-V3 (classic ESP32, Arduino
  framework): `firmware/boiler-weact.yaml` з пакетами network / diagnostics /
  smartboiler / policy_guard.
- **Власний компонент `boiler_guard`** (C++/ESPHome): локальний arbiter команд,
  облік тривалого нагріву за підтвердженим Heat=true, cooldown за монотонним
  годинником, lease/heartbeat, readback desired/reported, стан-машина
  (BOOT_SAFE … FAULT).
- **Підключення upstream** `LubosD/esphome-smartboiler` через
  `external_components` (pinned commit) — без копіювання коду.
- **Модуль MajorDoMo `drazice_smart`** (PHP 7.3):
  - Транспорт cURL → HTTP REST ESPHome з SSRF-захистом і валідацією адреси.
  - Детермінований планувальник (чиста функція snapshot+config → рішення) з
    reason-кодами.
  - Policy engine: grid/battery/unknown, сонячний надлишок за SOC, cooldown,
    lease, boost з TTL.
  - Адаптер прогнозу СЕС з прив'язкою до модуля solarPlanner або будь-якого
    namespace; відсутні дані = `unavailable`, не нуль.
  - Тариф 23:00–07:00 за годинником лічильника з offset, guards, DST-коректним
    обчисленням (Europe/Kyiv).
  - Опційний профіль присутності (пасивне Wi-Fi/BLE за MAC) — вимкнено за
    замовчуванням.
  - Адмін-UI українською з вкладками, поясненнями, майстрами прив'язки, пошуком
    ESP у мережі, дефолтами для недосвідченого користувача.
- **Локалізація** інтерфейсу: UA (основна), RU, EN.
- **Документація** UA та RU: discovery, architecture, api-contract,
  capabilities, electrical-safety, flashing (Windows 11 / Linux / FreeBSD),
  pairing, majordomo-install, energy-policy, forecast-bindings, presence,
  test-report, rollback.
- **Скрипти**: `tools/build`, `tools/flash`, `tools/release`,
  `build_package.py` (tgz-пакет модуля).
- **Тести**: policy, tariff, planner, transport, presence, forecast adapter.

### Відомі обмеження

- Реальна збірка прошивки та запис на плату виконуються користувачем; у
  `docs/test-report.md` відповідні пункти позначені `NOT RUN`.
- Hard pause нагріву не доведено на обладнанні — позначено `BLOCKED` до
  перевірки на конкретному firmware бойлера.
- Ліцензія upstream не оголошена — див. `THIRD_PARTY_NOTICES.md`.
