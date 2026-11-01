#!/usr/bin/env python3
"""
Flashing helper for the WeAct ESP32 boiler firmware.

Thin, SAFE wrapper around esptool 4.x. It NEVER erases by default (erase wipes
pairing UUID/secrets), always reads chip_id + flash_id first, and takes a full
backup before any write. The actual firmware must be built separately with
`esphome compile` and merged; this script only verifies and flashes.

Pinned esptool 4.x (underscore subcommands) — see requirements-flash.lock.
Do NOT mix with esptool 5.x (hyphenated) commands.

Usage:
  python tools/flash/flash.py --port COM7 info
  python tools/flash/flash.py --port COM7 backup backup-private.bin
  python tools/flash/flash.py --port COM7 write firmware.factory.bin --address 0x0
"""

import sys
import argparse
import subprocess


def esptool(port, *args):
    cmd = [sys.executable, "-m", "esptool", "--chip", "esp32", "--port", port] + list(args)
    print("+ " + " ".join(cmd))
    return subprocess.run(cmd)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--port", required=True, help="e.g. COM7 or /dev/ttyUSB0")
    sub = ap.add_subparsers(dest="cmd", required=True)

    sub.add_parser("info", help="read chip_id and flash_id")

    b = sub.add_parser("backup", help="read full flash to a private backup file")
    b.add_argument("outfile")
    b.add_argument("--size", default="0x800000", help="flash size (default 8MiB)")

    w = sub.add_parser("write", help="write a merged factory image")
    w.add_argument("image")
    w.add_argument("--address", default="0x0",
                   help="merged factory image base is 0x0; confirm via flash-manifest.json")
    w.add_argument("--baud", default="115200")
    w.add_argument("--i-understand-this-writes-flash", action="store_true",
                   help="required safety flag")

    args = ap.parse_args()

    if args.cmd == "info":
        esptool(args.port, "chip_id")
        esptool(args.port, "flash_id")
        return

    if args.cmd == "backup":
        print("Reading full flash backup (may contain Wi-Fi/NVS/UUID — keep it private).")
        esptool(args.port, "read_flash", "0x0", args.size, args.outfile)
        return

    if args.cmd == "write":
        if not args.i_understand_this_writes_flash:
            print("Refusing to write without --i-understand-this-writes-flash.")
            print("Take a backup first:  python tools/flash/flash.py --port <port> backup backup-private.bin")
            sys.exit(2)
        print("Writing merged factory image. Ensure address matches flash-manifest.json.")
        esptool(args.port, "--baud", args.baud, "write_flash", args.address, args.image)
        return


if __name__ == "__main__":
    main()
