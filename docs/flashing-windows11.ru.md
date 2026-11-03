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

## Диагностика: плата не светится / «не сканируется»

После прошивки плата перезагружается и **светодиод гаснет — это нормально**:
прошивка не держит LED включённым постоянно (экономия акума), а лишь изредка
коротко мигает. ESP32 при этом работает.

Проверить, что плата жива:

```powershell
Get-PnpDevice -Class Ports | Where-Object FriendlyName -match 'CH34|CP210|FTDI|UART'
#   должно показать напр. "USB-SERIAL CH340K (COM7)" со статусом OK
.\.venv-flash\Scripts\python.exe -m esptool --chip esp32 --port COM7 chip_id
#   должно вывести "Chip is ESP32-D0WD-V3 ..." и MAC — плата жива
```

Если `chip_id` вывел чип и MAC — плата исправна, просто нет постоянной
индикации. Светодиод добавляет пакет `packages/status_led.yaml`: короткая
вспышка раз в ~16 с, когда всё хорошо; чаще — когда нет Wi-Fi/BLE; каждую
секунду — когда бойлер просит PIN (сопряжение).

**«Не сканируется»:** основная прошивка `boiler-weact.yaml` не сканирует эфир —
она подключается напрямую к MAC из `secrets.yaml`. Чтобы сканировать список
BLE-устройств, прошейте `firmware/boiler-scan.yaml` (см. `pairing.ru.md`, шаг 2).

**Если `chip_id` НЕ подключается:** закройте мониторы порта; зажмите **BOOT**,
нажмите/отпустите RESET, отпустите BOOT; повторите. Проверьте, что кабель — для
данных.

### Светодиод и аккумулятор

LED включается лишь на ~60 мс во время редких вспышек (<2% времени) — вклад в
разряд мизерный. Чтобы отключить индикацию — уберите строку
`status_led: !include packages/status_led.yaml`. Если LED не реагирует — ваш
пин другой: измените `status_led_pin` / `status_led_inverted` и перепрошейте.
