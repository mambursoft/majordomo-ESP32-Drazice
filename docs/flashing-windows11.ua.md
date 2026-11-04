# Прошивка ESP32 на Windows 11 — покроково

«Прошити» означає записати нашу програму в пам'ять плати. Робиться один раз
(потім оновлення можна робити по мережі — OTA).

## Що знадобиться

- Плата WeAct ESP32 і **USB-кабель для даних** (не тільки для заряджання!).
- Python 3 з [python.org](https://www.python.org/) (під час інсталяції
  поставте галочку «Add python.exe to PATH»).
- 10 хвилин.

## Крок 1. Драйвер USB-UART

Під'єднайте плату USB-кабелем. Відкрийте «Диспетчер пристроїв» (Device Manager) →
розділ «Порти (COM і LPT)». Запам'ятайте номер порту (напр. `COM7`). Якщо плата
не з'явилась або з жовтим знаком оклику — встановіть драйвер USB-UART з
офіційного джерела виробника чипа (CH340 / CP210x / CH343 — залежно від плати;
визначте за VID/PID у властивостях пристрою). Не вгадуйте драйвер наперед.

## Крок 2. Підготуйте секрети

У теці `firmware/` скопіюйте `secrets.example.yaml` у `secrets.yaml` і заповніть:
Wi-Fi, паролі, BLE MAC бойлера. Цей файл **не** потрапляє в Git.

## Крок 3. Середовище збірки

У PowerShell з кореня репозиторію:

```powershell
py -3 -m venv .venv-build
.\.venv-build\Scripts\python.exe -m pip install --upgrade pip
.\.venv-build\Scripts\python.exe -m pip install -r requirements-build.lock
```

## Крок 4. Перевірка та збірка

```powershell
.\.venv-build\Scripts\python.exe -m esphome config  firmware\boiler-weact.yaml
.\.venv-build\Scripts\python.exe -m esphome compile firmware\boiler-weact.yaml
```

`config` має завершитись рядком **`INFO Configuration is valid!`**.
`compile` збере прошивку (перший раз довго — тягне фреймворк).

## Крок 5. Спочатку прочитати плату і зробити backup

Окреме середовище для прошивки (esptool 4.x):

```powershell
py -3 -m venv .venv-flash
.\.venv-flash\Scripts\python.exe -m pip install -r requirements-flash.lock

# Замініть COM7 на ваш порт.
.\.venv-flash\Scripts\python.exe tools\flash\flash.py --port COM7 info
.\.venv-flash\Scripts\python.exe tools\flash\flash.py --port COM7 backup backup-private.bin
```

`info` покаже chip_id і flash_id — **підтвердьте 8 MiB** (0x800000). Backup може
містити Wi-Fi/UID — зберігайте приватно, не в Git.

## Крок 6. Зібрати merged-образ і записати

```powershell
.\.venv-build\Scripts\python.exe tools\release\build_firmware.py
# з'являться firmware.factory.bin і flash-manifest.json

.\.venv-flash\Scripts\python.exe tools\flash\flash.py --port COM7 write firmware.factory.bin --address 0x0 --i-understand-this-writes-flash
```

Адресу `0x0` беремо лише якщо так каже `flash-manifest.json` (merged image з base
0x0). Скрипт сам відмовиться писати без прапорця підтвердження.

## Накат змін одним кроком: скрипти deploy

Кроки 4–6 вручну потрібні лише для першої прошивки та діагностики. Для
звичайного оновлення є скрипт `tools/deploy.py`. Він збирає прошивку і
заливає її одним викликом. Запускайте з кореня репозиторію, через
`.venv-build` (там ESPHome).

| Режим | Команда | Що робить |
|---|---|---|
| OTA (по Wi-Fi) | `tools\deploy-ota.bat 172.16.0.70` | `esphome upload --device <IP>`: за потреби компілює і заливає по мережі (порт 3232) |
| USB (по кабелю) | `tools\deploy-usb.bat COM7` | компіляція → `build_firmware.py` (merged `firmware.factory.bin`) → запис з `0x0` через `.venv-flash` (esptool 4.x) |
| Лише збірка | `.\.venv-build\Scripts\python.exe tools\deploy.py build` | компілює без заливки |

`.bat`-файли — тонкі обгортки над `deploy.py`. Їх можна викликати й напряму:

```powershell
.\.venv-build\Scripts\python.exe tools\deploy.py ota --host 172.16.0.70
.\.venv-build\Scripts\python.exe tools\deploy.py usb --port COM7 --use-flash-venv
```

IP або hostname і номер порту замініть на свої. Пароль OTA береться з
`firmware/secrets.yaml` (`ota_password`), у командному рядку його не передаємо.

### Коли USB, а коли OTA

- **OTA** — звичайний шлях, коли плата вже стоїть біля бойлера. Займає ~30 с.
  Кабель не потрібен.
- **USB обов'язковий**, якщо:
  - це перша прошивка;
  - змінилась таблиця розділів (`partitions.csv`). OTA пише лише в розділ
    програми і не може оновити таблицю;
  - OTA падає з `ERROR Error binary size: Unknown error from ESP`. Образ не
    вміщується в OTA-розділ старої таблиці. Один раз прошийте по USB, далі OTA
    працюватиме;
  - плата не в мережі.
- **USB стирає всю flash, включно з NVS.** Після цього плата генерує новий
  ідентифікатор BLE-клієнта, і бойлер попросить PIN для пейрингу ще раз (див.
  `pairing.ua.md`). OTA налаштувань і пейрингу не стирає.
- Запис по USB через CH340 повільний: ~2 хв на сам запис і кілька хвилин на
  компіляцію. Не перериваючи процес, дочекайтесь `Hash of data verified` і
  `USB-заливка успішна`.

### Перевірка після накату

Через ~15 с після перезавантаження плата має відповідати по REST:

```powershell
curl.exe -u "<web_username>:<web_password>" http://172.16.0.70/sensor/ESP%20Free%20Heap
```

Очікувано: JSON на зразок `{"id":"sensor-esp_free_heap","value":21328,...}`.
Без облікових даних запит має повертати `401`. Лог плати доступний лише по
USB (нативний API ESPHome вимкнено):

```powershell
.\.venv-build\Scripts\python.exe -m esphome logs firmware\boiler-weact.yaml --device COM7
```

### Типові помилки скриптів

| Симптом | Причина | Що робити |
|---|---|---|
| `'пускати' is not recognized...` під час запуску `.bat` | старі `.bat` з кирилицею, cmd читав їх в OEM-кодуванні | оновіть репозиторій: поточні `.bat` лише ASCII |
| `Git not found in PATH, please install Git` під час компіляції | PlatformIO не бачить git у PATH саме цієї сесії PowerShell | перезапустіть термінал або разово: `$env:PATH = "<шлях до Git>\cmd;" + $env:PATH` |
| `Could not connect to serial port COM7` / `Access is denied` | порт тримає інший процес (монітор порту, `esphome logs`, завислий python) | закрийте монітор/лог; за потреби завершіть процес python із `.venv-build` |
| `OTA-заливка НЕ вдалася` | плата не в мережі, неправильний IP або інший OTA-пароль на платі | перевірте `ping`, IP та `ota_password`; якщо не допомагає — один раз прошийте по USB |

## Якщо «Connecting...» не завершується

Закрийте монітори порту. Затисніть **BOOT**, під'єднайте USB (або натисніть
RESET), відпустіть BOOT після входу в режим завантаження. Не подавайте 5 В на
GPIO. Після запису — RESET і подивіться лог на швидкості 115200.

## Далі

Коли плата під'єдналась до Wi-Fi — переходьте до `pairing.ua.md`.
Оновлення в майбутньому — по мережі (OTA), під наглядом, поза активним нагрівом.

## Діагностика: плата не світиться / «не сканується»

Після прошивки плата перезавантажується і **світлодіод гасне — це нормально**:
прошивка не тримає LED увімкненим постійно (економія акума), а лише зрідка
коротко блимає (див. нижче). ESP32 при цьому працює.

Щоб переконатися, що плата жива (а не «мертва»):

```powershell
# 1. Чи видно USB-UART і порт?
Get-PnpDevice -Class Ports | Where-Object FriendlyName -match 'CH34|CP210|FTDI|UART'
#   має показати напр. "USB-SERIAL CH340K (COM7)" зі статусом OK

# 2. Чи відповідає чип? (через .venv-flash, esptool 4.x)
.\.venv-flash\Scripts\python.exe -m esptool --chip esp32 --port COM7 chip_id
#   має вивести "Chip is ESP32-D0WD-V3 ..." і MAC — отже плата жива
```

Якщо `chip_id` вивів чип і MAC — плата справна, просто немає постійної
індикації. Світлодіод додає пакет `packages/status_led.yaml` (вже підключений у
`boiler-weact.yaml`): коротка спалах раз на ~16 с, коли все добре; частіше —
коли немає Wi-Fi/BLE; щосекунди — коли бойлер просить PIN (пейрінг).

**Що означає «не сканується»:**
- Основна прошивка `boiler-weact.yaml` **не сканує** ефір — вона підключається
  напряму до MAC бойлера з `secrets.yaml`. Це за задумом.
- Щоб **сканувати** й бачити список BLE-пристроїв (для пошуку MAC) —
  прошийте `firmware/boiler-scan.yaml` (див. `pairing.ua.md`, крок 2).

**Якщо `chip_id` НЕ під'єднується** (`Connecting......` без результату):
закрийте всі монітори порту; затисніть **BOOT**, натисніть/відпустіть RESET,
відпустіть BOOT; спробуйте ще раз. Перевірте, що кабель — для даних, не лише
живлення.

### Світлодіод і акумулятор

LED керується економно: увімкнений лише ~60 мс під час рідкісних спалахів
(<2% часу), тож внесок у розряд акума мізерний. Якщо індикація взагалі не
потрібна — приберіть рядок `status_led: !include packages/status_led.yaml` з
`boiler-weact.yaml`. Якщо LED не реагує — ваш пін LED інший: змініть
`status_led_pin` / `status_led_inverted` у `substitutions` і перепрошийте.
