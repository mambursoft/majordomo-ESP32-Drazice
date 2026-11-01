# Прошивка ESP32 на Windows 11 — пошагово

«Прошить» = записать программу в память платы. Делается один раз (потом — OTA).

## Нужно

Плата WeAct ESP32, **USB-кабель для данных**, Python 3 с python.org (галочка
«Add python.exe to PATH»).

## Шаг 1. Драйвер USB-UART

Подключите плату. «Диспетчер устройств» → «Порты (COM и LPT)» → запомните порт
(напр. `COM7`). Если платы нет / жёлтый знак — поставьте драйвер USB-UART с
официального сайта производителя чипа (CH340/CP210x/CH343, определите по VID/PID).

## Шаг 2. Секреты

`firmware/`: скопируйте `secrets.example.yaml` в `secrets.yaml`, заполните Wi-Fi,
пароли, BLE MAC. В Git не попадает.

## Шаг 3. Среда и сборка

```powershell
py -3 -m venv .venv-build
.\.venv-build\Scripts\python.exe -m pip install -r requirements-build.lock
.\.venv-build\Scripts\python.exe -m esphome config  firmware\boiler-weact.yaml
.\.venv-build\Scripts\python.exe -m esphome compile firmware\boiler-weact.yaml
```

`config` должен дать `INFO Configuration is valid!`.

## Шаг 4. Прочитать плату, backup, записать

```powershell
py -3 -m venv .venv-flash
.\.venv-flash\Scripts\python.exe -m pip install -r requirements-flash.lock
.\.venv-flash\Scripts\python.exe tools\flash\flash.py --port COM7 info
.\.venv-flash\Scripts\python.exe tools\flash\flash.py --port COM7 backup backup-private.bin
.\.venv-build\Scripts\python.exe tools\release\build_firmware.py
.\.venv-flash\Scripts\python.exe tools\flash\flash.py --port COM7 write firmware.factory.bin --address 0x0 --i-understand-this-writes-flash
```

`info` → подтвердите 8 MiB. Адрес — из `flash-manifest.json`. Backup храните
приватно.

## Если «Connecting...» зависает

Закройте мониторы порта. Зажмите **BOOT**, подключите USB / нажмите RESET,
отпустите BOOT. Не подавайте 5 В на GPIO. Далее — `pairing.ru.md`.
