#!/usr/bin/env python3
"""
Build the installable MajorDoMo package for the drazice_smart module.

Packages the majordomo/ subtree (modules, templates, languages, scripts,
modinfo.json, docs, img) into drazice_smart.tgz / app_drazice_smart.tgz at the
repository root. Validates modinfo.json and runs php -l when php is available.
Never ships secrets or local config.

Usage: python tools/build/build_package.py [--force]
"""

import os
import sys
import json
import hashlib
import tarfile
import argparse
import subprocess
from pathlib import Path

# repo root is three levels up from this file (tools/build/build_package.py)
ROOT_DIR = Path(__file__).resolve().parents[2]
MAJ_DIR = ROOT_DIR / "majordomo"

# Items inside majordomo/ that make up the installable module.
PACKAGE_FILES = [
    "modules",
    "languages",
    "scripts",
    "tests",
    "modinfo.json",
]
# Repo-level docs/licences shipped alongside.
EXTRA_FILES = [
    ("README.md", "README.md"),
    ("README.ru.md", "README.ru.md"),
    ("LICENSE", "LICENSE"),
    ("THIRD_PARTY_NOTICES.md", "THIRD_PARTY_NOTICES.md"),
    ("CHANGELOG.md", "CHANGELOG.md"),
]

EXCLUDE_NAMES = {"config.local.json", "property-map.local.json", "secrets.json",
                 "secrets.yaml", ".env"}
EXCLUDE_SUFFIX = (".pyc", ".tar.gz", ".tgz", ".zip", ".log", ".tmp")


def log(msg, level="INFO"):
    print(f"[{level}] {msg}")


def check_metadata():
    p = MAJ_DIR / "modinfo.json"
    if not p.exists():
        log("modinfo.json not found!", "ERROR")
        return False
    try:
        data = json.loads(p.read_text(encoding="utf-8"))
        for req in ("name", "version", "title"):
            if req not in data:
                log(f"modinfo.json missing key: {req}", "ERROR")
                return False
        log(f"modinfo.json OK: {data['name']} v{data['version']} ({data['title']})", "OK")
    except Exception as e:
        log(f"modinfo.json invalid: {e}", "ERROR")
        return False
    return True


def check_php_syntax():
    php_files = [p for p in MAJ_DIR.glob("**/*.php")]
    if not php_files:
        return True
    try:
        subprocess.run(["php", "-v"], stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True)
    except Exception:
        log("php CLI not found; skipping php -l.", "WARN")
        return True
    errors = 0
    for p in php_files:
        res = subprocess.run(["php", "-l", str(p)], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        if res.returncode != 0:
            log(f"Syntax error in {p}:\n{res.stdout.strip()}", "ERROR")
            errors += 1
    if errors:
        return False
    log(f"PHP syntax OK ({len(php_files)} files).", "OK")
    return True


def should_exclude(name):
    if name in EXCLUDE_NAMES:
        return True
    if name.endswith(EXCLUDE_SUFFIX):
        return True
    return False


def add_tree(tar, base, arc_prefix):
    count = 0
    if base.is_file():
        if not should_exclude(base.name):
            tar.add(base, arcname=arc_prefix)
            count += 1
        return count
    for rootdir, _, files in os.walk(base):
        for f in files:
            full = Path(rootdir) / f
            if should_exclude(f):
                continue
            rel = full.relative_to(base)
            arc = (arc_prefix + "/" + str(rel)).replace("\\", "/")
            tar.add(full, arcname=arc)
            count += 1
    return count


def build_tar(archive_name):
    target = ROOT_DIR / archive_name
    if target.exists():
        target.unlink()
    log(f"Creating {archive_name}...")
    count = 0
    with tarfile.open(target, "w:gz") as tar:
        for item in PACKAGE_FILES:
            ip = MAJ_DIR / item
            if ip.exists():
                count += add_tree(tar, ip, item)
        for src, arc in EXTRA_FILES:
            sp = ROOT_DIR / src
            if sp.exists():
                tar.add(sp, arcname=arc)
                count += 1
    size_kb = target.stat().st_size / 1024.0
    sha = hashlib.sha256(target.read_bytes()).hexdigest()
    log(f"{archive_name}: {size_kb:.1f} KB, {count} files, SHA256 {sha[:16]}...", "OK")
    return {"name": archive_name, "size_kb": size_kb, "files": count, "sha256": sha}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--force", action="store_true")
    ap.parse_args()

    print("=" * 52)
    print("  MajorDoMo drazice_smart - Package Builder")
    print("=" * 52)

    if not check_metadata():
        sys.exit(1)
    if not check_php_syntax():
        sys.exit(1)

    results = [build_tar(t) for t in ("drazice_smart.tgz", "app_drazice_smart.tgz")]

    print("\n" + "=" * 52)
    print("  BUILD SUMMARY")
    for r in results:
        print(f"  * {r['name']:<24} {r['size_kb']:>7.1f} KB  ({r['files']} files)  SHA {r['sha256'][:20]}...")
    print("=" * 52)
    # Write a release checksum file.
    (ROOT_DIR / "SHA256SUMS").write_text(
        "\n".join(f"{r['sha256']}  {r['name']}" for r in results) + "\n", encoding="utf-8")
    log("All packages built. SHA256SUMS written.", "OK")


if __name__ == "__main__":
    main()
