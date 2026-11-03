# Third-Party Notices / Повідомлення про сторонні компоненти

Цей проєкт **посилається** на сторонній код, але не включає його копій у
власний репозиторій, окрім випадків, явно зазначених нижче.

## 1. LubosD/esphome-smartboiler (BLE-компонент, вендоровано)

- Репозиторій: https://github.com/LubosD/esphome-smartboiler
- Автор: LubosD та контриб'ютори
- Зафіксований commit походження: `c1b4e18ded8d3de58a19022e5155134982ddeb9a`
- Розташування в цьому проєкті: `firmware/components/smartboiler/`
- Роль: реалізація BLE-протоколу Drazice SMART.

**Дозвіл.** Автор upstream (LubosD) **надав дозвіл** на використання свого коду
в цьому проєкті. Тому код **вендоровано** (включено безпосередньо) замість
підключення через `external_components: git`. Це робить збірку відтворюваною і
незалежною від доступності зовнішнього репозиторію під час компіляції.

Атрибуція поряд з кодом — `firmware/components/smartboiler/UPSTREAM.md`.
Виправлення цього коду бажано надсилати також в upstream. Якщо upstream додасть
окремий файл ліцензії — додайте його копію поряд і оновіть це повідомлення.

## 2. ESPHome

- https://esphome.io — ліцензії MIT (ядро) та GPLv3 (окремі частини).
- Використовується як інструмент збірки прошивки; не розповсюджується в складі
  цього репозиторію.

### 2a. ESPHome `web_server` (вендоровано, мінімально пропатчено)

- Джерело: ядро ESPHome, компонент `esphome/components/web_server`.
- Зафіксована версія: **2025.9.3** (див. `requirements-build.lock`).
- Ліцензія: MIT (ядро ESPHome).
- Розташування в цьому проєкті: `firmware/components/web_server/`.
- Роль і причина вендорингу: побайтова копія pinned-компонента з ВИМКНЕНИМ SSE
  event source (`DeferredUpdateEventSource`). Його безперервна JSON-серіалізація
  у `loop()` під Bluedroid BLE вичерпувала heap (ArduinoJson `operator new` →
  `abort()` → boot-loop → SAFE MODE). У 2025.9.3 немає YAML-опції вимкнути event
  source, тож його вимкнено у C++. Per-entity REST та auth не змінені.
- Детальна атрибуція і список правок — `firmware/components/web_server/UPSTREAM.md`.

## 3. MajorDoMo

- https://github.com/sergejey/majordomo
- Цільова платформа. Модуль `drazice_smart` — незалежний код під MIT, який
  працює поверх MajorDoMo через його публічні механізми модулів.

## 4. Залежності PHP-модуля

Модуль `drazice_smart` написаний без зовнішніх Composer-залежностей, сумісний з
PHP 7.3. Жодних vendored-бібліотек не включено.
