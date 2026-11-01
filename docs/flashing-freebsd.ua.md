# Прошивка ESP32 на FreeBSD

Надійний план: **збирати** прошивку на Linux/Windows 11, перевірити SHA256, а на
FreeBSD лише **записати** готовий артефакт через Python esptool. Native-збірку
ESPHome/PlatformIO на FreeBSD не обіцяємо до перевірки toolchain під вашу версію.

## Підготовка

```sh
# назву пакета Python оберіть за реальним `pkg search python`
pkg install python3
python3 -m venv .venv-flash
.venv-flash/bin/python -m pip install -r requirements-flash.lock
```

## Порт

```sh
usbconfig
dmesg
ls -l /dev/cuaU*        # типово /dev/cuaU0, але не гарантовано
```

Якщо порту немає — визначте VID/PID і підтримуваний USB serial driver; не
завантажуйте навмання `ucom`/`uchcom`/`uslcom`. Драйвери — за документацією ОС.
Права — через відповідну групу / `devfs.rules` (зміна правил — за погодженням),
не `chmod 666` назавжди.

## Прошивка

```sh
sha256 firmware.factory.bin
PORT=/dev/cuaU0
.venv-flash/bin/python tools/flash/flash.py --port "$PORT" info
.venv-flash/bin/python tools/flash/flash.py --port "$PORT" backup backup-private.bin
.venv-flash/bin/python tools/flash/flash.py --port "$PORT" write firmware.factory.bin --address 0x0 --i-understand-this-writes-flash
cu -l "$PORT" -s 115200   # лог; вихід ~. на початку рядка (man cu)
```

Не відкривайте `cu` одночасно з esptool. Якщо hardware-тесту не було — у релізі
прямо зазначте «команди підготовлено, hardware test pending».

> Статус: цей маршрут підготовлено, але на реальному FreeBSD-залізі **не
> перевірено** (`NOT RUN` у `test-report.md`).
