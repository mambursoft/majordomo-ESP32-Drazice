# Присутність через Wi-Fi (ARP-таблиця сервера MajorDoMo)

Основна прошивка ESP32 ефір BLE **не сканує**: радіо зайняте з'єднанням з
бойлером, а пам'яті на платі обмаль. Тому модуль бере присутність з
властивості `EspBoiler.wifiObservationsJson`, яку заповнює ваш сценарій.

## Формат

```json
[{"mac":"aa:bb:cc:11:22:33","seen_utc":1762272000}]
```

- `mac` — MAC телефона **для цієї Wi-Fi мережі** (будь-який регістр).
- `seen_utc` — Unix-час **останнього реального виявлення**.

Модуль не пам'ятає історію сам: присутність рахується лише за `seen_utc` у
поточному JSON. Тому сценарій має зберігати час останнього виявлення, а не
прибирати телефон, щойно той пропустив одне сканування (сплячий телефон
іноді не відповідає 1–3 хвилини).

## 1. Підготовка (один раз)

1. На роутері закріпіть за кожним телефоном постійну IP (DHCP reservation).
2. Подивіться MAC саме для вашої мережі — сучасні телефони використовують
   окремий «приватний» MAC для кожної мережі:
   - iPhone: Налаштування → Wi-Fi → (i) → «Адреса Wi-Fi»;
   - Android: Wi-Fi → мережа → «Додатково» → «MAC-адреса».
3. Сервер MajorDoMo має бути в тій самій підмережі, що й Wi-Fi.

## 2. Сценарій `presenceWifiScan`

MajorDoMo → «Сценарії» → новий сценарій `presenceWifiScan`:

```php
// presenceWifiScan: Wi-Fi присутність за ARP-таблицею сервера.
// MAC (як показує телефон для ЦІЄЇ мережі) => закріплена IP-адреса.
$hosts = array(
    'aa:bb:cc:11:22:33' => '172.16.0.51', // телефон Назара
    'aa:bb:cc:44:55:66' => '172.16.0.52', // телефон Марії
);
$obj = 'EspBoiler';            // = «Об'єкт бойлера» в налаштуваннях модуля
$now = time();

// Попередні спостереження: зберігаємо час ОСТАННЬОГО реального виявлення.
$prev = json_decode((string) getGlobal($obj . '.wifiObservationsJson'), true);
$seen = array();
if (is_array($prev)) {
    foreach ($prev as $o) {
        if (isset($o['mac'], $o['seen_utc'])) {
            $seen[strtolower($o['mac'])] = (int) $o['seen_utc'];
        }
    }
}

// Будимо ARP: навіть якщо телефон не відповідає на ping, ARP-запис оновиться.
foreach ($hosts as $mac => $ip) {
    exec('ping -c 1 -W 1 ' . escapeshellarg($ip) . ' > /dev/null 2>&1');
}

$ipBin = is_executable('/sbin/ip') ? '/sbin/ip' : 'ip';
$neigh = (string) shell_exec($ipBin . ' -4 neigh show 2>/dev/null');
foreach (explode("\n", $neigh) as $line) {
    // "172.16.0.51 dev eth0 lladdr aa:bb:cc:11:22:33 REACHABLE"
    if (!preg_match('/lladdr\s+([0-9a-f:]{17})\s+(\w+)/i', $line, $m)) {
        continue;
    }
    $mac = strtolower($m[1]);
    $state = strtoupper($m[2]);
    if (isset($hosts[$mac]) && in_array($state, array('REACHABLE', 'DELAY', 'PROBE'), true)) {
        $seen[$mac] = $now;    // підтверджено щойно
    }
}

$out = array();
foreach ($hosts as $mac => $ip) {
    if (isset($seen[$mac])) {
        $out[] = array('mac' => $mac, 'seen_utc' => $seen[$mac]);
    }
}
setGlobal($obj . '.wifiObservationsJson', json_encode($out));
```

## 3. Запуск щохвилини

«Об'єкти» → `ClockChime` → метод `onNewMinute`, додайте рядок:

```php
runScript('presenceWifiScan');
```

## 4. Налаштування в модулі («Додатково» → «Присутність»)

- «Список людей (JSON)» — ті самі MAC, що в сценарії:
  `{"aa:bb:cc:11:22:33":"Назар","aa:bb:cc:44:55:66":"Марія"}`
- «Відсутній після, с» — **300** (сплячий телефон іноді пропускає 1–3 сканування).
- Увімкніть «Визначати присутність».
- «Метод-обробник» — оберіть зі списку метод об'єкта (`Об'єкт.метод`) або
  сценарій (`script:Назва`). Параметри: `person`, `duration_s`,
  `event` (`entered` / `long` / `too_long` / `left`).

## Перевірка

Через 1–2 хвилини на вкладці «Додатково» в блоці «Звідки модуль бере
присутність» з'явиться вміст `wifiObservationsJson`, а нижче — «Зараз
присутні: …».

## Обмеження

- PHP MajorDoMo має мати дозвіл на `exec` / `shell_exec` (зазвичай є).
- `ping` від користувача вебсервера має працювати (сучасні Ubuntu/Debian — без root).
- Це «телефон вдома», а не «телефон у туалеті». Точність до кімнати дав би
  лише BLE-сканер біля бойлера; його не додано свідомо — він конкурує за
  радіо з з'єднанням з бойлером і за обмежену пам'ять ESP32.
