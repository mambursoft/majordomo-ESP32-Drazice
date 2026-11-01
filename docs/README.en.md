# MajorDoMo ↔ ESP32 ↔ Drazice SMART (English summary)

Monitor and safely control **Drazice SMART** water heaters (primarily OKHE 125
SMART) over **Wi-Fi → ESP32 → BLE** for [MajorDoMo](https://github.com/sergejey/majordomo).
No MQTT, no mandatory Home Assistant.

> **Safe by default.** The module starts in **monitor-only** (`dry-run`).
> Automation is off until you explicitly enable it. BLE is not an emergency
> cut-off: losing the link does not stop heating.

UI languages: Ukrainian (primary), English, Russian. Docs: Ukrainian & Russian
in `docs/` (`*.ua.md` / `*.ru.md`).

## Components

1. **ESPHome firmware** for the WeAct Studio ESP32-D0WD-V3: reads boiler state
   over BLE (via the upstream `LubosD/esphome-smartboiler` component, pinned,
   referenced not copied) and exposes it over local HTTP REST. A custom
   `boiler_guard` component is the single command arbiter: continuous-heat
   accounting by confirmed Heat, cooldown on a monotonic clock, lease/heartbeat,
   readback, state machine BOOT_SAFE…FAULT.
2. **MajorDoMo module `drazice_smart`** (PHP 7.3): cURL→REST transport with SSRF
   protection, a pure deterministic planner with reason codes, a policy engine
   (grid/battery/unknown, solar surplus by SOC, cooldown, lease, boost), a
   forecast adapter (missing = unavailable, never 0), meter-clock tariff with
   DST, optional passive Wi-Fi/BLE presence, and a Ukrainian admin UI with
   wizards and plain-language explanations.

## Key facts verified locally

- `esphome config` → **Configuration is valid!** on ESPHome **2025.9.3** (the
  upstream component only works on ESPHome 2025.5–2025.10 — see `discovery.md`).
- 103 PHP unit tests pass (`majordomo/tests/run.php`).

## Safety

The firmware never switches the heating element. Lowering the target or changing
mode does not guarantee the element physically stops (STOP is unavailable;
ANTIFROST is frost protection, not OFF). See `docs/electrical-safety.*.md`.

## Upstream licence

The upstream BLE component has **no declared licence**. We do not copy its code;
we reference it via `external_components`. See `THIRD_PARTY_NOTICES.md`.
