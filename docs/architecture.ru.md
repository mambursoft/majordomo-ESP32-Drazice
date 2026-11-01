# Архитектура решения

```text
Drazice OKHE 125 SMART
        ↕ BLE (один клиент одновременно)
WeAct ESP32  ·  ESPHome: smartboiler (upstream) + boiler_guard (наш) + web_server
        ↕ локальная Wi-Fi (HTTP auth, только доверенные узлы)
MajorDoMo PHP 7.3  ·  модуль drazice_smart
        ├─ Transport      (cURL → REST, SSRF-защита, readback)
        ├─ Planner        (чистая функция: snapshot+config → решение)
        ├─ PolicyEngine   (сеть/батарея/неизвестно, SOC, freshness)
        ├─ Tariff         (ночной тариф по часам счётчика, DST)
        ├─ ForecastAdapter(привязка прогноза; отсутствует = unavailable)
        ├─ Presence       (пассивное Wi-Fi/BLE по MAC, опционально)
        └─ Repository     (БД: телеметрия/решения/команды; getGlobal/setGlobal)
```

## Распределение ответственности

**ESP32 (локальный guard `boiler_guard`):** BLE-телеметрия через upstream;
единый арбитр команд (наружу — только guarded entities, прямые — internal);
учёт длительного нагрева по подтверждённому Heat=true; cooldown по монотонным
часам (reboot не обнуляет паузу, durable state в NVS); lease/heartbeat (без
lease новые пуски запрещены); readback; машина состояний BOOT_SAFE … FAULT.

**MajorDoMo (модуль):** детерминированный планировщик с объяснимыми причинами;
интеграция SOC/прогноза/тарифа; dirty-checking в setGlobal (без рекурсии);
cycle-worker с файловым локом и ограниченным временем; UI, журнал, политики;
без записей по умолчанию.

## Приоритеты планировщика (от высшего)

1. Аппаратные защиты. 2. Непригодные данные/unknown power/unknown heat → hold.
3. Cooldown/лимит нагрева. 4. Запрет нагрева от батарей. 5. Гигиена/комфорт.
6. Ручной boost с TTL (не обходит 1–4). 7. Комфортный минимум.
8. Солнечный избыток по SOC / ночной тариф.
