#!/usr/bin/env python3
"""
Build the firmware and produce a merged factory image + flash-manifest.json.

Steps:
  1. `esphome compile firmware/boiler-weact.yaml` (requires secrets.yaml).
  2. Locate the compiled .bin and the platformio flash_args.
  3. Merge into firmware.factory.bin using esptool merge_bin, reading the REAL
     offsets from flash_args (NOT guessed).
  4. Write flash-manifest.json with chip, flash size/mode/freq, offsets and the
     image SHA256.

Run from the repo root inside the BUILD venv (requirements-build.lock).

NOTE: This produces a PERSONALISED image (contains your Wi-Fi/OTA secrets).
Do NOT publish it. For public artifacts, build with non-personal settings.
"""

import os
import sys
import json
import glob
import hashlib
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
YAML = ROOT / "firmware" / "boiler-weact.yaml"


def run(*cmd):
    print("+ " + " ".join(str(c) for c in cmd))
    r = subprocess.run([str(c) for c in cmd])
    if r.returncode != 0:
        sys.exit(r.returncode)


def main():
    if not (ROOT / "firmware" / "secrets.yaml").exists():
        print("firmware/secrets.yaml missing. Copy secrets.example.yaml and fill it.")
        sys.exit(2)

    py = sys.executable
    run(py, "-m", "esphome", "compile", str(YAML))

    # ESPHome places build output under firmware/.esphome/build/<name>/.pioenvs/<name>/
    pioenvs = list((ROOT / "firmware" / ".esphome" / "build").glob("*/.pioenvs/*"))
    if not pioenvs:
        print("Could not locate .pioenvs build directory. Did compile succeed?")
        sys.exit(1)
    build_dir = pioenvs[0]
    print("Build dir:", build_dir)

    # Read flash_args for the real offsets (bootloader/partitions/app).
    flash_args = build_dir / "flash_args"
    merged = ROOT / "firmware.factory.bin"
    if flash_args.exists():
        args = flash_args.read_text().split()
        print("flash_args:", args)
        run(py, "-m", "esptool", "--chip", "esp32", "merge_bin", "-o", str(merged), *args)
        # Parse first offset as the base address (merged image base).
        base = args[0] if args and args[0].startswith("0x") else "0x0"
    else:
        print("flash_args not found; cannot safely merge. Aborting.")
        sys.exit(1)

    sha = hashlib.sha256(merged.read_bytes()).hexdigest()
    manifest = {
        "chip": "esp32",
        "flash_size": "8MB",
        "flash_mode": "dio",
        "flash_freq": "40m",
        "image_type": "merged_factory",
        "base_address": base,
        "image": "firmware.factory.bin",
        "sha256": sha,
        "esphome_version": "2025.9.3",
        "note": "Offsets taken from platformio flash_args, not guessed. Personalised image: do NOT publish.",
    }
    (ROOT / "flash-manifest.json").write_text(json.dumps(manifest, indent=2), encoding="utf-8")
    print("Wrote firmware.factory.bin and flash-manifest.json")
    print("SHA256:", sha)


if __name__ == "__main__":
    main()
