# Прошивка ESP32 на Linux

Виконуйте на окремому build/flash-хості (не обов'язково на production Pi).

## Підготовка

```sh
sudo apt install python3 python3-venv
python3 -m venv .venv-build
.venv-build/bin/python -m pip install -r requirements-build.lock
cp firmware/secrets.example.yaml firmware/secrets.yaml   # заповнити
```

## Збірка

```sh
.venv-build/bin/python -m esphome config  firmware/boiler-weact.yaml   # INFO Configuration is valid!
.venv-build/bin/python -m esphome compile firmware/boiler-weact.yaml
.venv-build/bin/python tools/release/build_firmware.py                 # merged image
```

## Порт і права

```sh
ls -l /dev/serial/by-id/   # стабільний шлях; або /dev/ttyUSB0
lsusb                      # VID/PID для драйвера
```

Зазвичай потрібна група `dialout`: `sudo usermod -aG dialout $USER`, потім
перезайти в сесію. Не запускайте весь toolchain від root і не робіть
`chmod 666` як постійне рішення. Якщо порт зайнятий — знайдіть власника
(`fuser`/`lsof`), не вимикайте служби глобально.

## Прошивка (esptool 4.x)

```sh
python3 -m venv .venv-flash
.venv-flash/bin/python -m pip install -r requirements-flash.lock
PORT=/dev/ttyUSB0  # ваш порт

.venv-flash/bin/python tools/flash/flash.py --port "$PORT" info
.venv-flash/bin/python tools/flash/flash.py --port "$PORT" backup backup-private.bin
.venv-flash/bin/python tools/flash/flash.py --port "$PORT" write firmware.factory.bin --address 0x0 --i-understand-this-writes-flash
```

Підтвердьте 8 MiB через `info` (flash_id). Адреса merged-образу — з
`flash-manifest.json`. Втручання ModemManager — за потреби вузький per-device
виняток, не ламаючи мережу.
