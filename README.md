# MajorDoMo ↔ ESP32 ↔ Drazice SMART

Моніторинг і безпечне керування бойлерами **Drazice SMART** (основна модель —
OKHE 125 SMART) через **Wi-Fi → ESP32 → BLE** для [MajorDoMo](https://github.com/sergejey/majordomo).
Без обов'язкового Home Assistant і без брокера MQTT.

> **Безпечно за замовчуванням.** Модуль стартує в режимі **лише моніторинг**
> (`dry-run`). Автоматичне керування вимкнено, доки ви явно його не ввімкнете і
> не підтвердите параметри. BLE **не є** каналом аварійного вимкнення: втрата
> зв'язку не гасить нагрів.

Мови інтерфейсу: **українська** (основна), English, русский.
Документація: українською та російською (`docs/`).

---

## Що це і для кого

Ви — власник будинку з бойлером Drazice OKHE 125 SMART і системою MajorDoMo на
Raspberry Pi. Ви **ніколи не працювали з ESP32**. Цей проєкт дає:

1. **Прошивку ESPHome** для плати WeAct Studio ESP32-D0WD-V3, яка під'єднується
   до бойлера по Bluetooth і віддає його стан у локальну мережу по HTTP.
2. **Модуль MajorDoMo `drazice_smart`** — панель керування українською з
   зрозумілими поясненнями, безпечними дефолтами та майстрами налаштування.
3. **Детермінований планувальник** нагріву: нічний тариф (23:00–07:00),
   сонячний надлишок (коли батарея заряджена на 100%), обмеження тривалого
   навантаження.
4. Опційне **визначення присутності** в туалеті за Wi-Fi/BLE смартфона з
   повідомленням на колонку (за замовчуванням вимкнено, чесно документовані
   обмеження).

Якщо ви не знаєте, з чого почати — відкрийте
[`docs/pairing.ua.md`](docs/pairing.ua.md) (покрокове під'єднання ESP32),
[`docs/majordomo-install.ua.md`](docs/majordomo-install.ua.md) та
[`docs/simple-devices.ua.md`](docs/simple-devices.ua.md) (сканування/вибір MAC у
MajorDoMo і бойлер як «Простий пристрій»).

## Безпека передусім

- Прошивка **не комутує** ТЕН бойлера. Жодних реле на силових клемах. Штатні
  захисти (термостат, термозахист, заземлення, ПЗВ) залишаються недоторканими.
- Зниження температури або зміна режиму **не гарантує** фізичного вимкнення
  нагріву. Докладно — [`docs/electrical-safety.ua.md`](docs/electrical-safety.ua.md).
- Секрети (Wi-Fi пароль, BLE MAC, PIN, паролі HTTP/OTA) ніколи не потрапляють у
  Git. Див. [`firmware/secrets.example.yaml`](firmware/secrets.example.yaml).

## Швидкий огляд архітектури

```text
Drazice OKHE 125 SMART
        ↕ BLE (один клієнт одночасно)
WeAct ESP32  ·  ESPHome smartboiler + policy_guard + HTTP REST
        ↕ локальна Wi-Fi (HTTP auth, тільки довірені вузли)
MajorDoMo PHP 7.3  ·  модуль drazice_smart: транспорт + поллер + планувальник + UI
        ↑ SOC батареї (deye_battery.soc) · прогноз СЕС (solarPlanner) · годинник тарифу
```

Детально — [`docs/architecture.ua.md`](docs/architecture.ua.md).

## Структура репозиторію

```text
firmware/      Прошивка ESPHome, власний компонент boiler_guard, скрипти збірки/прошивки
majordomo/     Модуль MajorDoMo drazice_smart (PHP 7.3), локалізація, шаблони, тести
docs/          Документація українською та російською
tools/         Скрипти build / flash / release
tests/         Фікстури й інтеграційні перевірки
```

## Статус

Це **перша публічна версія**. Код, тести, документація та скрипти готові.
Реальна збірка прошивки, запис на плату та керування обладнанням виконуються
користувачем за інструкціями; у [`docs/test-report.md`](docs/test-report.md)
чесно позначено, що пройдено автоматично, а що потребує стенду (`NOT RUN`).

## Ліцензія

Код цього репозиторію — [MIT](LICENSE). BLE-компонент
[`smartboiler`](firmware/components/smartboiler/) походить з
[`LubosD/esphome-smartboiler`](https://github.com/LubosD/esphome-smartboiler) і
**вендоровано з дозволу автора** (атрибуція —
[`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md) і
[`firmware/components/smartboiler/UPSTREAM.md`](firmware/components/smartboiler/UPSTREAM.md)).

[English summary](docs/README.en.md) · [Русская версия](README.ru.md)
