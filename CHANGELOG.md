# CHANGELOG

Формат: [Keep a Changelog](https://keepachangelog.com/uk/1.0.0/).
Версіонування: [SemVer](https://semver.org/lang/uk/).

## [1.3.3] — 2026-11-04

### Додано

- **Випадаючі списки прив'язки властивостей** у формі налаштувань модуля:
  поля SOC (`SOC_PROPERTY`), сигналу живлення (`POWER_PROPERTY`) і всі чотири
  поля прогнозу (`FORECAST_TODAY_PROP`, `FORECAST_TOMORROW_PROP`,
  `FORECAST_UPDATED_PROP`, `FORECAST_PROVIDER_PROP`) тепер обираються зі
  `<select>`, наповненого УСІМА властивостями об'єктів MajorDoMo у форматі
  «Об'єкт.Властивість» (сортовано за абеткою; перший порожній пункт = «не
  прив'язано»). Якщо список порожній (немає БД/об'єктів) — повернення до
  текстового поля. Нова публічна метода `allProperties()`.
- **Часовий пояс** тепер випадаючий список: за замовчуванням пропонується
  системний часовий пояс MajorDoMo (або `Europe/Kyiv` як запасний варіант),
  наповнення зі списку ідентифікаторів PHP; збережене значення не губиться.
- **Вікно пільгового тарифу з хвилинами**: нові ключі `CHEAP_START_MIN` /
  `CHEAP_END_MIN`, початок і кінець задаються як година + хвилина. Нові
  типові значення — **23:05** і **06:55**. Повна зворотна сумісність: без
  хвилинних ключів поведінка ідентична погодинній (хвилини = 0). Перетин
  опівночі визначається за секундами доби.

### Виправлено

- **ESP32 розривав HTTP-з'єднання (`Connection reset by peer`)** на кожен запит
  до web_server. Причина: `web_server: version: 3` з `local: true` вбудовує
  важкий JS, який на ESP32 + Arduino (ESPAsyncWebServer) валить з'єднання/краш
  ще до обробки auth. Для машинного транспорту (PHP-модуль читає REST) важка
  сторінка не потрібна — перейшли на **`web_server: version: 2`** (легкий,
  стабільний REST/SSE). Flash зменшився (~45.8%). Образ перезібрано (SUCCESS).

## [1.3.1] — 2026-11-01

### Додано

- **Світлодіод статусу** (`firmware/packages/status_led.yaml`), економний до
  акума: LED не світиться постійно, лише короткі рідкісні блимання
  (~60 мс, <2% часу). Патерни: PIN/пейрінг — щосекунди; немає Wi-Fi/BLE —
  раз на ~4 с; усе добре — раз на ~16 с. Пін налаштовуваний
  (`status_led_pin`/`status_led_inverted`, дефолт GPIO2). Перевірено компіляцією.
- **Скрипт накату змін на ESP** `tools/deploy.py` (+ `deploy-ota.bat`,
  `deploy-usb.bat`): компіляція і заливка одним кроком — по мережі (OTA) або по
  кабелю (USB). OTA-пароль береться з secrets.yaml самим ESPHome.
- Діагностика «плата не світиться / не сканується» у docs/flashing-windows11
  (UA/RU): як перевірити, що плата жива (PnP USB-UART + esptool chip_id).

### Виправлено

- CSRF: токен тепер зберігається в сесії MajorDoMo (`$session->data` + `save()`),
  а не через неіснуючі `get()/set()` — усуває «Помилка безпеки форми (CSRF)».

## [1.3.0] — 2026-11-01

### Додано

- **Єдиний об'єкт бойлер+ESP** (`lib/BoilerObject.php`, клас/об'єкт `EspBoiler`):
  усе, що ESP витягує з бойлера, + діагностика ESP, + сесії/події присутності
  зберігаються як властивості ОДНОГО об'єкта. Властивості створюються з
  **описами** (DESCRIPTION), а керувальні (`requestedTarget`, `requestedMode`,
  `boost`, `automationEnabled`, `allowBatteryHeat`) **позначені описом**
  «⚙ КЕРУВАННЯ: зміна застосовується до бойлера» і мають **прив'язаний метод**:
  зміна властивості будь-де в MajorDoMo → guarded REST у бойлер
  (`applyObjectControl`).
- **Нагрів від акумуляторів за згодою користувача**: прапорець
  `ALLOW_BATTERY_HEAT` і boost з дозволом на батарею (`BOOST_ALLOW_BATTERY`).
  Безпека бойлера (cooldown / ліміт тривалого нагріву) **зберігається** — вона
  вища за дозвіл на батарею в пріоритетах планувальника.
- **Присутність за BLE + Wi-Fi** зі стадійними подіями: `entered` / `long` /
  `too_long` / `left` (поріг «дуже довго» — `PRESENCE_TOO_LONG_AFTER_S`). Збір
  спостережень із BLE-сканера ESP і з Wi-Fi (router/arp). Події пишуться в
  об'єкт бойлера (`presenceEvent`/`presenceEventJson`), у таблицю
  `drazice_presence` і викликають метод-обробник. Таблиця бірдінгу
  `drazice_people` (person → BLE MAC / Wi-Fi MAC / beacon UUID).

### Виправлено (HTTP 500 → це видання включає)

- `Table 'drazice_decisions' doesn't exist`: таблиці тепер гарантовано
  створюються при кожному завантаженні (`ensureTables()` ідемпотентно), а
  читання журналу захищене `tableExists()`. Це лікує встановлення, де попередній
  (зламаний) білд позначив модуль «встановленим» до створення таблиць.

## [1.2.1] — 2026-11-01

### Виправлено

- **HTTP 500 під час встановлення пакета.** Дві причини:
  1. Нескінченна рекурсія `checkInstalled() → parent::checkInstalled() →
     install() → checkInstalled()` (вичерпання пам'яті). Прибрано
     `parent::checkInstalled()` з перевизначеного методу — реєстрація
     виконується самим модулем, а `install()` викликає інсталятор напряму.
  2. `SQLInsert($table, &$record)` приймає запис **за посиланням**, а виклики
     передавали **літерал-масив** → fatal «cannot be passed by reference» на
     PHP 7+. Замінено на передачу через змінну (`project_modules`, `plugins`).
- Захищено доступ до `$rec['ID']/['CATEGORY']` і `$pRec['ID']` від undefined
  key. Оголошено властивості класу (module_category/id/title) — прибрано PHP 8.2
  deprecated dynamic property.
- Додано діагностичний харнес `tools/diag_install.php` (емулює MajorDoMo runtime
  і відтворює fatal локально; не входить у пакет модуля).

## [1.2.0] — 2026-11-01

### Додано

- **Інтеграція «Прості пристрої» (MajorDoMo devices)** — `lib/SimpleDevices.php`
  + вкладка «Прості пристрої». Бойлер створюється як штатний термостат
  (`SThermostats`, relay_readonly) + сенсори (нижній датчик, енергія, SOC, HDO)
  і синхронізується в циклі. Команда зміни цілі з UI «Пристроїв» перехоплюється
  і йде через guarded REST (cooldown/lease зберігаються). Створення
  ідемпотентне (`devices::addDevice` з дедуплікацією за об'єктом). Карта
  пристроїв редагується у UI (JSON); типи беруться з runtime, не вгадуються.
- **BLE-сканер бойлерів видно й обирається в MajorDoMo**: `packages/ble_scan.yaml`
  тепер веде СПИСОК знайдених пристроїв (MAC/назва/RSSI/«схоже на бойлер») у
  JSON через text_sensor «BLE Scan Results» + кнопка «BLE Scan Clear». Вкладка
  «Пристрої» показує таблицю і дозволяє обрати MAC одним кліком; обраний MAC
  зберігається в модулі.
- **Повна діагностика в UI**: вкладка «Діагностика» показує ВСІ entity прошивки
  (стан бойлера/guard, continuous heat, cooldown, lease, ESP uptime/RSSI/heap/
  reset reason/версія) + кнопки Lease Renew / Release / Reconnect BLE.
- Документ `docs/simple-devices.{ua,ru}.md` з інструкціями для MajorDoMo.

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
