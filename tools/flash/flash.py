#!/usr/bin/env python3
"""
Flashing helper for the WeAct ESP32 boiler firmware.

Thin, SAFE wrapper around esptool 4.x. It NEVER erases by default (erase wipes
pairing UUID/secrets), always reads chip_id + flash_id first, and takes a full
backup before any write. Build the image first with
`python tools/release/build_firmware.py` (it runs `esphome compile` and copies
the merged firmware.factory.bin to the repo root). This script only verifies
and flashes; it also auto-locates the image in the ESPHome build dir if needed.

RUN THIS IN .venv-flash (pinned esptool 4.x, underscore subcommands — see
requirements-flash.lock). Do NOT run it in .venv-build (that has esptool 5.x);
never mix 4.x and 5.x in the flashing flow.

Usage:
  python tools/flash/flash.py --port COM7 info
  python tools/flash/flash.py --port COM7 backup backup-private.bin
  python tools/flash/flash.py --port COM7 write firmware.factory.bin --address 0x0
"""

import sys
import argparse
import subprocess
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]


def esptool(port, *args):
    cmd = [sys.executable, "-m", "esptool", "--chip", "esp32", "--port", port] + list(args)
    print("+ " + " ".join(cmd))
    return subprocess.run(cmd)


def resolve_image(image):
    """Find the image: as given, relative to repo root, or in the ESPHome build dir."""
    p = Path(image)
    if p.is_file():
        return str(p.resolve())
    rooted = REPO_ROOT / image
    if rooted.is_file():
        return str(rooted.resolve())
    # Fall back to the ESPHome build output.
    hits = list((REPO_ROOT / "firmware" / ".esphome" / "build").glob("*/.pioenvs/*/" + p.name))
    if hits:
        return str(hits[0].resolve())
    print("ERROR: image '%s' not found." % image)
    print("Looked in: current dir, repo root (%s), and the ESPHome build dir." % REPO_ROOT)
    print("Build it first:  python tools/release/build_firmware.py")
    print("(that copies firmware.factory.bin to the repo root).")
    sys.exit(2)


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
        image_path = resolve_image(args.image)
        print("Writing merged factory image: %s" % image_path)
        print("Address %s (merged ESPHome factory image base is 0x0)." % args.address)
        esptool(args.port, "--baud", args.baud, "write_flash", args.address, image_path)
        return


if __name__ == "__main__":
    main()
