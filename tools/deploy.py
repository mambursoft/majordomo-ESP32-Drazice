#!/usr/bin/env python3
"""
Накат змін на ESP32 (компіляція + заливка прошивки) — одним кроком.

Два способи:
  OTA  (по мережі, без кабелю — рекомендовано, коли плата вже стоїть біля
        бойлера): компілює і заливає через Wi-Fi за IP/hostname.
  USB  (по кабелю): компілює, збирає merged factory image і пише через esptool.

ЗАПУСКАТИ з кореня репозиторію у BUILD venv (requirements-build.lock), бо
потрібен esphome. USB-режим додатково кличе esptool з того ж venv (5.x) АБО
tools/flash/flash.py (.venv-flash, 4.x) — див. --use-flash-venv.

Приклади:
  # OTA на плату за адресою (host або IP):
  python tools/deploy.py ota --host drazice-boiler.local
  python tools/deploy.py ota --host 172.16.0.150

  # USB по кабелю:
  python tools/deploy.py usb --port COM7

  # лише зібрати, без заливки:
  python tools/deploy.py build

Безпека: OTA — лише в довіреній LAN/VPN, під наглядом, поза активним критичним
нагрівом (так само, як у docs). Пароль OTA береться з firmware/secrets.yaml
самим ESPHome — його не треба передавати в командному рядку.
"""

import os
import sys
import argparse
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
YAML = ROOT / "firmware" / "boiler-weact.yaml"
SECRETS = ROOT / "firmware" / "secrets.yaml"


def run(cmd):
    print("+ " + " ".join(str(c) for c in cmd))
    return subprocess.run([str(c) for c in cmd]).returncode


def need_secrets():
    if not SECRETS.exists():
        print("firmware/secrets.yaml відсутній. Скопіюйте secrets.example.yaml і заповніть.")
        sys.exit(2)


def do_compile():
    py = sys.executable
    rc = run([py, "-m", "esphome", "compile", str(YAML)])
    if rc != 0:
        print("Компіляція НЕ вдалася.")
        sys.exit(rc)
    print("Компіляція OK.")


def do_ota(host):
    """Накат по мережі. ESPHome сам компілює (за потреби) і заливає OTA."""
    need_secrets()
    py = sys.executable
    # --device задає, куди заливати; ESPHome обирає OTA для мережевої адреси.
    rc = run([py, "-m", "esphome", "upload", str(YAML), "--device", host])
    if rc != 0:
        print("OTA-заливка НЕ вдалася. Перевірте, що плата в мережі, адреса/hostname "
              "правильні, і OTA-пароль у secrets.yaml збігається з тим, що на платі.")
        sys.exit(rc)
    print("OTA-заливка успішна. Плата перезавантажиться з новою прошивкою.")


def do_usb(port, use_flash_venv):
    """Накат по кабелю: compile -> merged factory -> write_flash."""
    need_secrets()
    do_compile()
    # Збираємо merged factory image у корінь (tools/release/build_firmware.py
    # копіює firmware.factory.bin у корінь і пише flash-manifest.json).
    py = sys.executable
    rc = run([py, str(ROOT / "tools" / "release" / "build_firmware.py")])
    if rc != 0:
        sys.exit(rc)

    image = ROOT / "firmware.factory.bin"
    if use_flash_venv:
        # Писати через .venv-flash (esptool 4.x) — узгоджено з docs і AGENTS.
        flash_py = ROOT / ".venv-flash" / "Scripts" / "python.exe"
        if not flash_py.exists():
            flash_py = ROOT / ".venv-flash" / "bin" / "python"  # Linux/macOS
        if not Path(flash_py).exists():
            print("Не знайдено .venv-flash. Створіть його з requirements-flash.lock "
                  "(див. docs/flashing-*), або запустіть flash.py вручну.")
            sys.exit(2)
        rc = run([str(flash_py), str(ROOT / "tools" / "flash" / "flash.py"),
                  "--port", port, "write", str(image), "--address", "0x0",
                  "--i-understand-this-writes-flash"])
    else:
        # Писати через ESPHome (він сам викличе esptool).
        rc = run([py, "-m", "esphome", "upload", str(YAML), "--device", port])
    if rc != 0:
        print("USB-заливка НЕ вдалася.")
        sys.exit(rc)
    print("USB-заливка успішна. Плата перезавантажиться з новою прошивкою.")


def main():
    ap = argparse.ArgumentParser(description="Накат змін прошивки на ESP32 (OTA або USB).")
    sub = ap.add_subparsers(dest="mode", required=True)

    sub.add_parser("build", help="лише зібрати прошивку")

    o = sub.add_parser("ota", help="накат по мережі (Wi-Fi)")
    o.add_argument("--host", required=True, help="IP або hostname плати, напр. 172.16.0.150 або drazice-boiler.local")

    u = sub.add_parser("usb", help="накат по кабелю")
    u.add_argument("--port", required=True, help="послідовний порт, напр. COM7 або /dev/ttyUSB0")
    u.add_argument("--use-flash-venv", action="store_true",
                   help="писати через .venv-flash (esptool 4.x) замість ESPHome")

    args = ap.parse_args()

    if args.mode == "build":
        need_secrets()
        do_compile()
    elif args.mode == "ota":
        do_ota(args.host)
    elif args.mode == "usb":
        do_usb(args.port, args.use_flash_venv)


if __name__ == "__main__":
    main()
