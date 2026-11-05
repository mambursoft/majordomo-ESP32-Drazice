# Компонент smartboiler — походження та атрибуція

Цей каталог містить код BLE-компонента для бойлерів Drazice SMART, похідний від
проєкту:

- **Джерело:** https://github.com/LubosD/esphome-smartboiler
- **Автор:** LubosD (та контриб'ютори)
- **Зафіксований commit походження:** `c1b4e18ded8d3de58a19022e5155134982ddeb9a`
- **Файли:** `SBProtocol.h`, `SBProtocol.cpp`, `smartboiler.h`, `smartboiler.cpp`,
  `__init__.py`

## Дозвіл на використання

Автор upstream (LubosD) **надав дозвіл** на використання свого коду в цьому
проєкті. Код вендоровано (включено безпосередньо) замість підключення через
`external_components: git`, щоб збірка була відтворюваною і не залежала від
доступності зовнішнього репозиторію під час компіляції.

Будь-які виправлення цього коду бажано надсилати також в upstream.

## Локальні модифікації

- **2025-01 (ux-critique §5-E):** знижено мінімум числа Pairing PIN у нашому
  codegen (`__init__.py`) з `1111` до `0`, щоб приймати реальні 4-значні
  коди спарювання з провідними нулями (наприклад `0692`). `send_pin` уже
  передає будь-яке значення через `write_le()`, тому `smartboiler.cpp` НЕ
  змінювався — це зміна в нашому codegen, а не в upstream BLE-протоколі.

- **Діагностика парингу (pairing-trace):** додано спостережуваність причини
  циклу «Require PIN → Disconnected». BLE-протокол НЕ змінювався.
  - `smartboiler.h/.cpp`: фіксоване кільце з 10 подій (`char[10][26]`, без
    heap-алокацій; `snprintf`), публікація лише з `loop()` коли були нові події.
    Події: `CONN#n`, `AUTH_Q`, `AUTH_TX`, `REQ_PIN`, `PIN_RX(Nd)` /
    `PIN_IGNORED(стан)`, `PIN_Q`, `PIN_TX`, `RES=<raw≤6>`, `DISC(r=<gatt reason>,<стан>)`.
    Цифри PIN не записуються (лише кількість цифр числового значення).
    `ESP_GATTC_DISCONNECT_EVT` тепер читає `param->disconnect.reason`.
  - Нові entity через `__init__.py`: `pair_trace`, `last_disconnect`
    (text_sensor), `pair_attempts`, `connect_count` (sensor).
  - `send_to_boiler()`: сирий пакет `GLOBAL_PAIRPIN` більше не потрапляє в
    DEBUG-лог (містить PIN) — друкується `<pin redacted>`.
  - Поведінку `SmartBoilerPinInput::control()` збережено (не в `NEED_PIN` —
    значення ігнорується); додано лише запис `PIN_IGNORED` у трасу.
- **rest_api (наш компонент, не upstream):** буфери стеку для text_sensor
  збільшено (`MAX_TEXT=304`, `MAX_JSON=704`), щоб 280-байтова траса не
  обрізалась (JSON дублює текст у `value` і `state`). Контракт REST не змінено.
- **2026-11 (HDO-керування):** у `smartboiler.h` додано публічний wrapper
  `hdo_enable_public(const std::string&)`, що делегує в protected
  `on_set_hdo_enabled()`. Потрібен, щоб template-button `Boiler HDO On/Off`
  (packages/policy_guard.yaml) могли вмикати/вимикати HDO-декодер бойлера.
  Самого upstream-методу не змінено.
- **2026-11 (годинник бойлера):** upstream уже парсив `SBC_PACKET_HOME_TIME`
  (формат `D.HH:MM:SS`, байт дня + рядок часу), але лише ЛОГУВАВ результат.
  Додано спостережуваність без зміни BLE-протоколу:
  - `smartboiler.h/.cpp`: новий text_sensor `boiler_time_txt_` + сетер
    `set_boiler_time()`; у гілці `HOME_TIME` результат публікується у стабільній
    формі `"Day HH:MM:SS"` (напр. `Mon 14:03:27`). `day_to_string()` переведено
    на 3-літерні абревіатури (Mon..Sun) — простіше парсити на боці модуля.
  - `getInitData()` тепер запитує `HOME_TIME` разом з рештою init-даних, тож
    час оновлюється на кожному (ре)конекті — зокрема після відновлення зв'язку.
  - Нове entity через `__init__.py`: `b_time` (text_sensor "Boiler Time").
  PHP-модуль звіряє цей час з годинником сервера й сигналізує про дрейф.

Решта проєкту (`boiler_guard`, модуль MajorDoMo, документація) — під MIT, див.
кореневий `LICENSE`.
