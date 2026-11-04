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

Решта проєкту (`boiler_guard`, модуль MajorDoMo, документація) — під MIT, див.
кореневий `LICENSE`.
