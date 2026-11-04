<?php
/**
 * BoilerObject: the single MajorDoMo object that represents the boiler AND its
 * ESP32 as one entity (default title "EspBoiler", class "EspBoiler").
 *
 * Everything the ESP can read from the boiler, plus ESP diagnostics, plus the
 * toilet-presence sessions and the events to process, is stored as properties
 * of THIS one object.
 *
 * Property groups:
 *   - READ-ONLY telemetry/diagnostics (written by the module from the ESP);
 *   - CONTROL properties whose change is applied TO THE BOILER. These are
 *     clearly marked in their DESCRIPTION ("⚙ ЗМІНА ЦІЄЇ ВЛАСТИВОСТІ
 *     ЗАСТОСОВУЄТЬСЯ ДО БОЙЛЕРА ...") and get a linked method so a change made
 *     anywhere in MajorDoMo (UI, scene, rule, app) is picked up and routed
 *     through the guarded REST path (cooldown / lease preserved);
 *   - PRESENCE session/event properties.
 *
 * Object/property creation is idempotent and uses the MajorDoMo DB schema
 * directly (objects / classes / properties / methods / pvalues), because core
 * exposes addClassObject() but no high-level "add property with description +
 * linked method" helper. All writes guard function_exists() so unit tests
 * (which do not load this class) are unaffected.
 *
 * PHP 7.3 compatible.
 *
 * @package drazice_smart
 * @license MIT
 */

namespace DraziceSmart;

class BoilerObject
{
    const CLASS_NAME = 'EspBoiler';

    /**
     * Full property catalogue of the single boiler+ESP object.
     * key => array(desc, control(bool), method(string|null))
     * "control" properties are applied TO the boiler when changed.
     */
    public static function catalogue()
    {
        $c = function ($desc) { return array('desc' => $desc, 'control' => false, 'method' => null, 'history' => 0); };
        $h = function ($desc) { return array('desc' => $desc, 'control' => false, 'method' => null, 'history' => 1); };
        $w = function ($desc, $method) { return array('desc' => $desc, 'control' => true, 'method' => $method, 'history' => 0); };

        $applied = '⚙ КЕРУВАННЯ: зміна цієї властивості ЗАСТОСОВУЄТЬСЯ ДО БОЙЛЕРА через ESP32 (з дотриманням cooldown/lease). ';

        return array(
            // --- read-only telemetry from the boiler ---
            'tempUpper'      => $h('Температура верхнього датчика бойлера, °C (лише читання, з ESP). Історія увімкнена.'),
            'tempLower'      => $h('Температура нижнього датчика бойлера, °C (лише читання, з ESP). Історія увімкнена.'),
            'heating'        => $c('Чи гріє бойлер зараз (Heat), 1/0 (лише читання, з ESP).'),
            'hdo'            => $c('Стан тарифного входу HDO, 1/0 (лише читання, з ESP).'),
            'reportedMode'   => $c('Поточний режим бойлера, повідомлений бойлером (лише читання).'),
            'energyTotalKwh' => $h('Накопичена енергія бойлера, кВт·год (лише читання, з ESP). Історія увімкнена — для графіків споживання по годинах/днях/місяцях.'),
            'boilerVersion'  => $c('Версія прошивки/серійний номер бойлера (лише читання).'),
            'boilerName'     => $c('Назва бойлера (лише читання).'),
            'bleState'       => $c('Стан BLE-з\'єднання ESP↔бойлер (лише читання).'),
            // --- ESP diagnostics ---
            'espUptime'      => $c('Час роботи ESP32, с (діагностика, лише читання).'),
            'espRssi'        => $c('Рівень Wi-Fi сигналу ESP32, dBm (діагностика).'),
            'espFreeHeap'    => $c('Вільна пам\'ять ESP32, байт (діагностика).'),
            'espResetReason' => $c('Причина останнього перезапуску ESP32 (діагностика).'),
            'guardState'     => $c('Стан локального захисту (guard) на ESP32 (діагностика).'),
            'guardReason'    => $c('Причина поточного стану guard (діагностика).'),
            'continuousHeatSeconds' => $c('Тривалість безперервного фактичного нагріву, с (діагностика).'),
            'cooldownRemaining'     => $c('Залишок обов\'язкової паузи (cooldown), с (діагностика).'),
            'leaseRemaining'        => $c('Залишок дозволу (lease), с (діагностика).'),
            // --- planner / policy (read-only reflections) ---
            'powerSource'    => $c('Джерело живлення: grid/battery/unknown (лише читання, трактування).'),
            'batterySoc'     => $c('Заряд батареї SOC, % (з прив\'язаної властивості, лише читання).'),
            'tariffActive'   => $c('Чи активний пільговий тариф зараз, 1/0 (лише читання).'),
            'reportedTarget' => $c('Фактична цільова температура бойлера, °C (лише читання).'),
            'plannedTarget'  => $c('Ціль, яку порахував планувальник, °C (лише читання).'),
            'controlState'   => $c('Поточна дія планувальника: heat/hold (лише читання).'),
            'decisionReason' => $c('Коротка причина рішення планувальника (лише читання).'),
            'decisionHuman'  => $c('Пояснення «чому гріє / чому ні» людською мовою (лише читання).'),
            'decisionAllowed'=> $c('Чи дозволено нагрів поточним рішенням, 1/0 (лише читання).'),
            'decisionLevel'  => $c('Рівень пріоритету спрацьованого правила (лише читання).'),
            'decisionLog'    => $h('Компактний рядок рішення. Історія увімкнена — видно рішення за останні дні.'),
            'batterySocHist' => $h('SOC батареї з історією (окрема копія для графіка).'),
            'hdoDesired'     => $c('Бажаний стан HDO, порахований модулем, 1/0 (лише читання).'),
            'hdoControlEnabled' => $c('Чи ввімкнено керування HDO (сонячний оверрайд), 1/0 (лише читання).'),
            'vacationActive' => $c('Чи активний режим відпустки зараз, 1/0 (лише читання).'),
            'connected'      => $c('Чи доступний ESP32 по HTTP, 1/0 (лише читання).'),
            'lastSampleUtc'  => $c('UTC час останнього зчитування телеметрії (лише читання).'),

            // --- CONTROL properties (change -> applied to the boiler) ---
            'requestedTarget'   => $w($applied . 'Запит цільової температури, °C (40..80).', 'applyRequestedTarget'),
            'requestedMode'     => $w($applied . 'Запит режиму: ANTIFREEZE/SMART/PROG/MANUAL.', 'applyRequestedMode'),
            'boost'             => $w($applied . 'Разовий boost: встав 1, щоб запустити нагрів на BOOST_TTL; 0 — скасувати.', 'applyBoost'),
            'automationEnabled' => $w($applied . 'Увімкнути (1) / вимкнути (0) автоматичне керування бойлером.', 'applyAutomationEnabled'),
            'allowBatteryHeat'  => $w($applied . 'Дозволити (1) нагрів навіть від акумуляторів (безпека бойлера зберігається).', 'applyAllowBatteryHeat'),

            // --- toilet presence sessions & events (written by the module) ---
            'presentPeople'      => $c('Хто зараз присутній у туалеті і як довго (людський опис, лише читання).'),
            'presenceSessionJson'=> $c('Активні сесії присутності (JSON): person, since_utc, duration_s, source (ble/wifi).'),
            'presenceEvent'      => $c('Остання подія присутності для обробки: entered/long/too_long/left + person.'),
            'presenceEventJson'  => $c('Повна остання подія присутності (JSON) для обробника/сцен.'),
        );
    }

    public static function available()
    {
        return function_exists('SQLSelectOne') && function_exists('addClassObject')
            && function_exists('setGlobal');
    }

    /**
     * Create the object + all properties (with DESCRIPTION) and attach linked
     * methods to control properties. Idempotent. $objectTitle lets the admin
     * rename the object; defaults to the class name.
     */
    public static function ensureObject($objectTitle, $moduleName)
    {
        if (!self::available()) {
            return array('ok' => false, 'reason' => 'objects_runtime_unavailable');
        }
        $objectTitle = ($objectTitle !== '') ? $objectTitle : self::CLASS_NAME;

        // 1. Object (idempotent by title; addClassObject also creates the class).
        $objectId = addClassObject(self::CLASS_NAME, $objectTitle, 'drazice_' . $moduleName);
        if (!$objectId) {
            return array('ok' => false, 'reason' => 'object_create_failed');
        }

        // 2. Properties with descriptions; methods for control properties.
        $created = 0;
        foreach (self::catalogue() as $name => $def) {
            self::ensureProperty($objectId, $name, $def['desc'], !empty($def['history']));
            if (!empty($def['control']) && $def['method']) {
                self::ensureControlMethod($objectId, $objectTitle, $name, $def['method'], $moduleName);
            }
            $created++;
        }
        return array('ok' => true, 'object' => $objectTitle, 'properties' => $created);
    }

    /** Create one property (with DESCRIPTION) on the object if missing. */
    private static function ensureProperty($objectId, $name, $description, $keepHistory = false)
    {
        // KEEP_HISTORY in MajorDoMo is a number of days (0 = off). Use 30 days
        // for history-enabled properties so charts have data without filling
        // the DB forever. Never DOWNGRADE a user's larger retention.
        $histDays = $keepHistory ? 30 : 0;
        $rec = SQLSelectOne("SELECT ID FROM properties WHERE OBJECT_ID=" . (int) $objectId
            . " AND TITLE='" . self::safe($name) . "'");
        if (is_array($rec) && !empty($rec['ID'])) {
            $upd = SQLSelectOne("SELECT ID, DESCRIPTION, KEEP_HISTORY FROM properties WHERE ID=" . (int) $rec['ID']);
            $changed = false;
            if (is_array($upd)) {
                if (isset($upd['DESCRIPTION']) && $upd['DESCRIPTION'] !== $description) {
                    $upd['DESCRIPTION'] = $description;
                    $changed = true;
                }
                if ($keepHistory && (int) (isset($upd['KEEP_HISTORY']) ? $upd['KEEP_HISTORY'] : 0) < $histDays) {
                    $upd['KEEP_HISTORY'] = $histDays;
                    $changed = true;
                }
                if ($changed) {
                    SQLUpdate('properties', $upd);
                }
            }
            return (int) $rec['ID'];
        }
        $prop = array(
            'OBJECT_ID'   => (int) $objectId,
            'CLASS_ID'    => 0,
            'TITLE'       => $name,
            'DESCRIPTION' => $description,
            'KEEP_HISTORY'=> $histDays,
        );
        return (int) SQLInsert('properties', $prop);
    }

    /**
     * Attach a method to the object, linked to a control property, so the method
     * runs whenever that property changes. The method body calls back into the
     * module's cycle handler, which applies the change through guarded REST.
     */
    private static function ensureControlMethod($objectId, $objectTitle, $property, $methodName, $moduleName)
    {
        $exists = SQLSelectOne("SELECT ID FROM methods WHERE OBJECT_ID=" . (int) $objectId
            . " AND TITLE='" . self::safe($methodName) . "'");
        // The method code routes the change to the module. callMethodSafe keeps
        // it defensive if the module class is not loadable at call time.
        $code = "// AUTO: change of '" . $property . "' is applied to the boiler.\n"
            . "if (!class_exists('drazice_smart')) {\n"
            . "    @include_once(DIR_MODULES.'" . $moduleName . "/" . $moduleName . ".class.php');\n"
            . "}\n"
            . "if (class_exists('drazice_smart')) {\n"
            . "    \$m = new drazice_smart();\n"
            . "    \$m->applyObjectControl('" . $property . "', getGlobal('" . $objectTitle . "." . $property . "'));\n"
            . "}\n";

        if (is_array($exists) && !empty($exists['ID'])) {
            $rec = SQLSelectOne("SELECT * FROM methods WHERE ID=" . (int) $exists['ID']);
            $rec['CODE'] = $code;
            $rec['DESCRIPTION'] = 'AUTO: застосовує зміну властивості ' . $property . ' до бойлера (guarded).';
            SQLUpdate('methods', $rec);
            return (int) $exists['ID'];
        }
        $rec = array(
            'OBJECT_ID'   => (int) $objectId,
            'CLASS_ID'    => 0,
            'TITLE'       => $methodName,
            'DESCRIPTION' => 'AUTO: застосовує зміну властивості ' . $property . ' до бойлера (guarded).',
            'CODE'        => $code,
        );
        return (int) SQLInsert('methods', $rec);
    }

    /** Dirty-check write of a single property (avoids setGlobal feedback loops). */
    public static function set($objectTitle, $prop, $val)
    {
        if (!function_exists('setGlobal') || !function_exists('getGlobal')) {
            return;
        }
        if ($val === null) {
            return;
        }
        $full = $objectTitle . '.' . $prop;
        $cur = getGlobal($full);
        if ((string) $cur !== (string) $val) {
            setGlobal($full, $val);
        }
    }

    private static function safe($s)
    {
        return function_exists('DBSafe') ? DBSafe($s) : addslashes($s);
    }
}
