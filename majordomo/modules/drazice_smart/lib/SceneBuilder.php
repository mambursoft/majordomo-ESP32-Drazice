<?php
/**
 * SceneBuilder: creates (idempotently) a MajorDoMo scene that shows the boiler
 * data as a dashboard and lets the user edit it afterwards in the native scene
 * editor.
 *
 * MajorDoMo scene model (verified against the live DB schema):
 *   scenes(ID, TITLE, ...)
 *   elements(ID, SCENE_ID, TITLE, TYPE, TOP, LEFT, WIDTH, HEIGHT,
 *            LINKED_OBJECT, LINKED_PROPERTY, LINKED_METHOD, CSS_STYLE, ...)
 *   elm_states(ID, ELEMENT_ID, ...)
 *
 * TYPE is a style folder under cms/scenes/styles (informer / switch / button /
 * mode / html / value...). We use "informer" for read-only values and "switch"
 * for the control properties. The scene is a normal, fully editable MajorDoMo
 * scene afterwards (drag, restyle, add widgets).
 *
 * Idempotent: a scene with the same TITLE is reused and its auto elements are
 * rebuilt, so pressing the button twice does not duplicate anything.
 *
 * PHP 7.3 compatible.
 *
 * @package drazice_smart
 * @license MIT
 */

namespace DraziceSmart;

class SceneBuilder
{
    const SCENE_TITLE = 'Drazice Boiler';

    /** Runtime available? Needs the objects + DB layer. */
    public static function available()
    {
        return function_exists('SQLSelectOne') && function_exists('SQLInsert')
            && function_exists('SQLUpdate');
    }

    /**
     * Build the scene. Ensures the boiler object exists first (so the widgets
     * have something to bind to), then creates/refreshes the scene elements.
     *
     * @param string $object the boiler object title (BOILER_OBJECT)
     * @return array ok(bool), detail(string), scene_id(int)
     */
    public static function create($object)
    {
        if (!self::available()) {
            return array('ok' => false, 'detail' => 'objects/DB runtime unavailable');
        }
        $object = ($object !== '') ? $object : 'EspBoiler';

        // Make sure the object + properties exist (the scene binds to them).
        if (BoilerObject::available()) {
            BoilerObject::ensureObject($object, 'smart');
        }

        // 1. Scene row (reuse by title).
        $scene = SQLSelectOne("SELECT ID FROM scenes WHERE TITLE='" . self::safe(self::SCENE_TITLE) . "'");
        if (is_array($scene) && !empty($scene['ID'])) {
            $sceneId = (int) $scene['ID'];
            // Remove our previously auto-created elements so we can rebuild
            // cleanly (user widgets added by hand have SYSTEM='' and a different
            // TITLE prefix, so we only drop ours, tagged SYSTEM='drazice').
            self::deleteAutoElements($sceneId);
        } else {
            $sceneId = (int) SQLInsert('scenes', array(
                'TITLE'      => self::SCENE_TITLE,
                'BACKGROUND' => '',
                'PRIORITY'   => 0,
                'HIDDEN'     => 0,
                'AUTO_SCALE' => 1,
                'SYSTEM'     => 'drazice',
            ));
            if (!$sceneId) {
                return array('ok' => false, 'detail' => 'scene insert failed');
            }
        }

        // 2. Elements. Grid layout: label+value tiles.
        $defs = self::layout($object);
        $created = 0;
        foreach ($defs as $d) {
            if (self::addElement($sceneId, $d)) {
                $created++;
            }
        }
        return array('ok' => true, 'detail' => 'scene #' . $sceneId . ', ' . $created . ' widgets', 'scene_id' => $sceneId);
    }

    /** Element layout: read-only informers + control switches, tiled. */
    private static function layout($o)
    {
        // columns at x=20 and x=320; rows every 70 px.
        $col1 = 20; $col2 = 320; $col3 = 620;
        $rowH = 70; $y0 = 20;
        $r = function ($i) use ($rowH, $y0) { return $y0 + $i * $rowH; };

        $info = function ($title, $prop, $x, $y, $style) use ($o) {
            return array('type' => 'informer', 'title' => $title, 'object' => $o,
                'property' => $prop, 'method' => '', 'left' => $x, 'top' => $y, 'style' => $style);
        };
        $sw = function ($title, $prop, $method, $x, $y) use ($o) {
            return array('type' => 'switch', 'title' => $title, 'object' => $o,
                'property' => $prop, 'method' => $method, 'left' => $x, 'top' => $y, 'style' => 'default');
        };

        return array(
            // read-only telemetry (informer tiles)
            $info('Температура (верх)', 'tempUpper',   $col1, $r(0), 'i_temp'),
            $info('Температура (низ)',  'tempLower',   $col2, $r(0), 'i_temp'),
            $info('Нагрів',             'heating',     $col3, $r(0), 'i_warm'),
            $info('SOC батареї, %',     'batterySoc',  $col1, $r(1), 'i_power'),
            $info('Джерело живлення',   'powerSource', $col2, $r(1), 'i_power'),
            $info('Тариф активний',     'tariffActive',$col3, $r(1), 'i_check_electro'),
            $info('Енергія, кВт·год',   'energyTotalKwh', $col1, $r(2), 'i_power'),
            $info('HDO',                'hdo',         $col2, $r(2), 'i_check_electro'),
            $info('Рішення',            'controlState',$col3, $r(2), 'default'),
            $info('Чому гріє / чому ні','decisionHuman', $col1, $r(3), 'default'),
            $info('Ціль планувальника', 'plannedTarget', $col3, $r(3), 'i_temp'),
            // control widgets (switch tiles -> guarded writes)
            $sw('Автоматика',        'automationEnabled', 'applyAutomationEnabled', $col1, $r(4)),
            $sw('Нагрів від акб',    'allowBatteryHeat',  'applyAllowBatteryHeat',  $col2, $r(4)),
            $sw('Boost',             'boost',             'applyBoost',             $col3, $r(4)),
        );
    }

    private static function addElement($sceneId, $d)
    {
        $rec = array(
            'SCENE_ID'        => (int) $sceneId,
            'TITLE'           => $d['title'],
            'TYPE'            => $d['type'],
            'TOP'             => (int) $d['top'],
            'LEFT'            => (int) $d['left'],
            'WIDTH'           => 0,
            'HEIGHT'          => 0,
            'CSS_STYLE'       => isset($d['style']) ? $d['style'] : 'default',
            'LINKED_OBJECT'   => $d['object'],
            'LINKED_PROPERTY' => $d['property'],
            'LINKED_METHOD'   => isset($d['method']) ? $d['method'] : '',
            'EASY_CONFIG'     => 1,
            'SYSTEM'          => 'drazice',
            'PRIORITY'        => 50,
        );
        return (int) SQLInsert('elements', $rec);
    }

    /** Drop the elements we previously created (tagged SYSTEM='drazice'). */
    private static function deleteAutoElements($sceneId)
    {
        if (!function_exists('SQLExec')) {
            return;
        }
        $rows = function_exists('SQLSelect')
            ? SQLSelect("SELECT ID FROM elements WHERE SCENE_ID=" . (int) $sceneId . " AND SYSTEM='drazice'")
            : array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!empty($row['ID'])) {
                    SQLExec("DELETE FROM elm_states WHERE ELEMENT_ID=" . (int) $row['ID']);
                }
            }
        }
        SQLExec("DELETE FROM elements WHERE SCENE_ID=" . (int) $sceneId . " AND SYSTEM='drazice'");
    }

    private static function safe($s)
    {
        return function_exists('DBSafe') ? DBSafe($s) : addslashes($s);
    }
}
