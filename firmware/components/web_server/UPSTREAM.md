# Vendored `web_server` component — upstream source and local modifications

## Source

- Upstream: ESPHome core component `esphome/components/web_server`.
- Pinned ESPHome version: **2025.9.3** (see `requirements-build.lock` /
  `firmware/firmware-api-manifest.json`).
- License: MIT (ESPHome core).
- This directory is a byte-for-byte copy of the pinned component, copied into
  `firmware/components/web_server/` so ESPHome resolves the local copy (declared
  in the `external_components` block of `firmware/boiler-weact.yaml`) instead of
  the packaged one. Keeping it in-repo keeps the build reproducible per
  `AGENTS.md`.

## Why it is vendored (the only reason)

Under the Bluedroid BLE stack (`esp32_ble_tracker` + `ble_client`), the stock
`web_server` SSE mechanism — `DeferredUpdateEventSource` — serializes all entity
state to JSON every `loop()` whenever a `/events` client is connected. On low
free heap `ArduinoJson`'s `operator new` fails, a C++ exception is thrown,
`abort()` runs, the board reboots, and ESPHome's boot-loop detector drops the
device into SAFE MODE (port 80 → connection refused). There is **no YAML option**
in 2025.9.3 to disable the event source, so it is disabled here in C++.

NimBLE is not available in ESPHome 2025.9.3 (the only BLE stack is Bluedroid),
so the memory fix is to remove the SSE heap consumer rather than swap BLE stacks.

## Exact local modifications (all tagged `MAJORDOMO-DRAZICE OVERRIDE`)

In `web_server.cpp`:

1. `WebServer::loop()` body changed from `{ this->events_.loop(); }` to `{}` so
   the per-loop deferred-queue JSON serialization never runs.
2. `WebServer::handleRequest()` — the `#ifdef USE_ARDUINO` branch for `/events`
   now does `request->send(404); return;` instead of
   `this->events_.add_new_client(this, request);` (no event source is ever
   created, so no client can connect).
3. `WebServer::canHandle()` — still claims `/events` (comment only) so the 404
   above is served deterministically.
4. Added a file-local `static void (*web_activity_led_hook)() = nullptr;` plus
   the setter `void set_web_activity_led_hook(void (*)())`
   (declared in `web_server.h`). `handleRequest()` calls the hook on its first
   line so every matched request can pulse the status LED. The hook is
   registered from `firmware/packages/status_led.yaml` and is non-blocking and
   allocation-free.

Everything else — per-entity REST handlers (`GET /sensor/..`,
`POST /number/../set`, `POST /select/../set`, button press, etc.), HTTP basic/
digest auth (`USE_WEBSERVER_AUTH`), the index route, and `__init__.py`
schema/defines — is unchanged. The entity contract and auth behavior are
unaffected.
