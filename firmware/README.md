# Прошивка (firmware)

ESPHome-прошивка для WeAct Studio ESP32-D0WD-V3, яка читає стан бойлера Drazice
SMART по BLE та віддає його по HTTP у локальну мережу.

## Склад

```text
boiler-weact.yaml              головний файл збірки
boiler-scan.yaml               діагностична прошивка: пошук BLE MAC бойлера
partitions.csv                 таблиця розділів на 8 MB (2×OTA по 3 MB)
packages/
  network.yaml                 Wi-Fi + резервна AP + BLE-клієнт
  diagnostics.yaml             uptime, RSSI, heap, reset reason
  smartboiler.yaml             інтеграція компонента smartboiler (entity бойлера)
  policy_guard.yaml            guarded entities + реєстрація boiler_guard
  ble_scan.yaml                сканер BLE (для boiler-scan.yaml)
  presence.yaml                опційна присутність (iBeacon UUID / MAC)
components/
  smartboiler/                 вендоровано з LubosD/esphome-smartboiler (з дозволу)
  boiler_guard/                власний компонент: arbiter, cooldown, lease, readback
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

- Компонент `smartboiler` **вендоровано** у `components/smartboiler/` з дозволу
  автора upstream (LubosD). Атрибуція — `components/smartboiler/UPSTREAM.md` і
  `THIRD_PARTY_NOTICES.md`.
- Плата має 8 MB flash: конфіг використовує `flash_size: 8MB` + `partitions.csv`
  (образ ~1.9 MB не вміщується в дефолтний 4 MB app-розділ). Підтвердьте
  `flash_id = 8 MiB` перед записом.
- Якщо не знаєте BLE MAC бойлера — зберіть `boiler-scan.yaml` для пошуку.
- `board`, `flash_size`, pinout підтвердити на реальній платі (`flash_id`,
  USB VID/PID). 8 MB з етикетки не доводить PSRAM — не вмикати PSRAM без доказу.
- Зібраний `*.bin` може містити секрети (Wi-Fi/OTA) — **не** публікувати
  персоналізовані бінарники.
