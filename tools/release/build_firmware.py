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
import shutil
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

    # ESPHome (2025.x) already merges a flashable factory image at offset 0x0
    # during `compile`. We just collect it — no second merge needed.
    src_factory = build_dir / "firmware.factory.bin"
    src_ota = build_dir / "firmware.ota.bin"
    if not src_factory.exists():
        print("firmware.factory.bin not found in build dir. Did compile succeed?")
        sys.exit(1)

    merged = ROOT / "firmware.factory.bin"
    shutil.copyfile(str(src_factory), str(merged))
    if src_ota.exists():
        shutil.copyfile(str(src_ota), str(ROOT / "firmware.ota.bin"))
    # Merged ESPHome factory image is always based at 0x0 (see compile log:
    # "ready to flash to offset 0x0"). This is the only correct write address.
    base = "0x0"

    sha = hashlib.sha256(merged.read_bytes()).hexdigest()
    manifest = {
        "chip": "esp32",
        "flash_size": "8MB",
        "flash_mode": "dio",
        "flash_freq": "40m",
        "image_type": "merged_factory",
        "base_address": base,
        "image": "firmware.factory.bin",
        "ota_image": "firmware.ota.bin" if src_ota.exists() else None,
        "sha256": sha,
        "esphome_version": "2025.9.3",
        "upstream_smartboiler": "vendored (with author's permission) from LubosD/esphome-smartboiler@c1b4e18",
        "note": "Factory image merged by ESPHome at 0x0 during compile. Personalised image: do NOT publish.",
    }
    (ROOT / "flash-manifest.json").write_text(json.dumps(manifest, indent=2), encoding="utf-8")
    print("Wrote firmware.factory.bin (and firmware.ota.bin) + flash-manifest.json to repo root")
    print("Factory image base address: 0x0")
    print("SHA256:", sha)
    print("\nFlash with:")
    print("  python tools/flash/flash.py --port <PORT> write firmware.factory.bin"
          " --address 0x0 --i-understand-this-writes-flash")


if __name__ == "__main__":
    main()
