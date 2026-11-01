# Прошивка (firmware)

ESPHome-прошивка для WeAct Studio ESP32-D0WD-V3, яка читає стан бойлера Drazice
SMART по BLE та віддає його по HTTP у локальну мережу.

## Склад

```text
boiler-weact.yaml              головний файл збірки
packages/
  network.yaml                 Wi-Fi + резервна AP + BLE-клієнт
  diagnostics.yaml             uptime, RSSI, heap, reset reason
  smartboiler.yaml             інтеграція upstream smartboiler (entity бойлера)
  policy_guard.yaml            guarded entities + реєстрація boiler_guard
components/boiler_guard/       власний компонент: arbiter, cooldown, lease, readback
secrets.example.yaml           шаблон секретів (скопіювати у secrets.yaml)
firmware-api-manifest.json     стабільний контракт для модуля MajorDoMo
```

## Збірка (коротко)

```powershell
# з кореня репозиторію
py -3 -m venv .venv-build
.\.venv-build\Scripts\python.exe -m pip install -r requirements-build.lock
copy firmware\secrets.example.yaml firmware\secrets.yaml   # і заповнити
.\.venv-build\Scripts\python.exe -m esphome config  firmware\boiler-weact.yaml
.\.venv-build\Scripts\python.exe -m esphome compile firmware\boiler-weact.yaml
```

Повні інструкції прошивання — `docs/flashing-windows11.ua.md`,
`docs/flashing-linux.ua.md`, `docs/flashing-freebsd.ua.md`.
Пейрінг із бойлером — `docs/pairing.ua.md`.

## Важливо

- `external_components` завантажує код upstream з GitHub під час збірки. Ми його
  **не копіюємо** в репозиторій. Див. `THIRD_PARTY_NOTICES.md`.
- `board`, `flash_size`, pinout підтвердити на реальній платі (`flash_id`,
  USB VID/PID). 8 MB з етикетки не доводить PSRAM — не вмикати PSRAM без доказу.
- Зібраний `*.bin` може містити секрети (Wi-Fi/OTA) — **не** публікувати
  персоналізовані бінарники.
