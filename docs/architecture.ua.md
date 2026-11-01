# Архітектура рішення

```text
Drazice OKHE 125 SMART
        ↕ BLE (один клієнт одночасно)
WeAct ESP32  ·  ESPHome: smartboiler (upstream) + boiler_guard (наш) + web_server
        ↕ локальна Wi-Fi (HTTP auth, тільки довірені вузли)
MajorDoMo PHP 7.3  ·  модуль drazice_smart
        ├─ Transport      (cURL → REST, SSRF-захист, readback)
        ├─ Planner        (чиста функція: snapshot+config → рішення)
        ├─ PolicyEngine   (мережа/батарея/невідомо, SOC, freshness)
        ├─ Tariff         (нічний тариф за годинником лічильника, DST)
        ├─ ForecastAdapter(прив'язка прогнозу СЕС; відсутнє = unavailable)
        ├─ Presence       (пасивне Wi-Fi/BLE за MAC, опційно)
        └─ Repository     (БД: телеметрія/рішення/команди; getGlobal/setGlobal)
        ↑ SOC (deye_battery.soc) · прогноз (solarPlanner) · годинник тарифу
```

## Розподіл відповідальності

**ESP32 (локальний guard `boiler_guard`):**
- BLE-телеметрія через upstream smartboiler;
- єдиний arbiter команд: зовні доступні лише guarded template entities
  (`Boiler Requested Target`, `Boiler Requested Mode`), прямі entity бойлера —
  `internal`, тож обійти guard не можна;
- облік тривалого нагріву за **підтвердженим** Heat=true (з debounce);
- cooldown за монотонним годинником; reboot не обнуляє паузу (durable state у NVS,
  консервативно — повна пауза від boot);
- lease/heartbeat: без чинного lease нові пуски заборонені;
- readback бажаного/підтвердженого стану; стан-машина BOOT_SAFE … FAULT.

**MajorDoMo (модуль):**
- детермінований планувальник (пояснювані reason-коди);
- інтеграція SOC/прогнозу/тарифу; dirty-checking у setGlobal (без рекурсії);
- cycle-worker з файловим локом, bounded runtime, без нескінченного SSE;
- UI, журнал, політики; без живих записів за замовчуванням.

## Стан-машина guard (ESP32)

`BOOT_SAFE → IDLE → HEATING_ALLOWED → (ліміт) → PAUSE_REQUESTED → COOLDOWN → IDLE`.
Гілки: `LEASE_EXPIRED` (немає lease), `FAULT` (Heat не впав у cooldown),
`DISCONNECTED` (BLE звільнено).

## Пріоритети планувальника (від найвищого)

1. Апаратні захисти (не замінюємо).
2. Непридатні дані / unknown power / unknown heat → hold.
3. Cooldown / ліміт тривалого нагріву.
4. Заборона нагріву від батарей.
5. (гігієна/комфорт — конфлікти показуємо людині).
6. Ручний boost з TTL (не обходить 1–4).
7. Комфортний мінімум.
8. Сонячний надлишок за SOC / нічний тариф.

Детальніше — `energy-policy.ua.md`.
