# Установка модуля в MajorDoMo

## Перед изменениями (read-only)

Проверьте: версии PHP CLI **и** FPM (могут отличаться), расширения `curl`/`json`,
права на каталог модулей, доступ к БД через `~/.my.cnf`. Пароль БД не печатайте
в логах.

## Установка пакета

```bash
sudo tar -xzf drazice_smart.tgz -C /var/www/html/
```

Кладёт `modules/drazice_smart/`, `languages/drazice_smart_*.php`,
`scripts/cycle_drazice_smart.php`. При первом запуске создаются таблицы
`drazice_*` (миграции re-safe, историю не удаляют) и безопасные значения по
умолчанию (мониторинг, автоматика выключена, dry-run).

## Проверка

```bash
find /var/www/html/modules/drazice_smart -name '*.php' -exec php -l {} \;
```

## Cycle-worker

Зарегистрируйте `scripts/cycle_drazice_smart.php` в штатном планировщике MajorDoMo.
Скрипт делает один ограниченный шаг за вызов, с файловым локом и self-throttle;
без бесконечного цикла и длинных sleep. Не создавайте дубль-воркер.

## Порядок ввода

Backup → разворачивание → `php -l`/тесты → «Устройства» (найти ESP32) →
сопряжение → сутки мониторинга → согласовать политику/тариф, привязать SOC и
прогноз → ручное управление с readback → только потом включить автоматику.

## Nginx

Не трогать без необходимости. Если нужно: backup → `sudo nginx -t` → при успехе
`reload`. Сохраните `$without_megad`, LAN-исключения, маршруты MegaD/TV. ESP32 не
открывать через публичный 80/443.

Откат — `rollback.ru.md`.
