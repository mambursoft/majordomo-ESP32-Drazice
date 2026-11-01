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

## Якщо «Connecting...» не завершується

Закрийте монітори порту. Затисніть **BOOT**, під'єднайте USB (або натисніть
RESET), відпустіть BOOT після входу в режим завантаження. Не подавайте 5 В на
GPIO. Після запису — RESET і подивіться лог на швидкості 115200.

## Далі

Коли плата під'єдналась до Wi-Fi — переходьте до `pairing.ua.md`.
Оновлення в майбутньому — по мережі (OTA), під наглядом, поза активним нагрівом.
