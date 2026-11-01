import esphome.codegen as cg
import esphome.config_validation as cv
from esphome.const import CONF_ID
from esphome.components import select, climate, binary_sensor

# boiler_guard: локальний arbiter команд бойлера. Веде облік тривалого нагріву,
# cooldown, lease та readback. Залежить від upstream smartboiler (external).

CONF_SMARTBOILER_ID = "smartboiler_id"
CONF_MODE_SELECT_ID = "mode_select_id"
CONF_THERMOSTAT_ID = "thermostat_id"
CONF_HEATING_SENSOR_ID = "heating_sensor_id"
CONF_MAX_CONTINUOUS_HEAT_S = "max_continuous_heat_s"
CONF_MIN_PAUSE_S = "min_pause_s"
CONF_HEAT_FALSE_DEBOUNCE_S = "heat_false_debounce_s"
CONF_LEASE_TTL_S = "lease_ttl_s"
CONF_COMMAND_TIMEOUT_S = "command_timeout_s"

boiler_guard_ns = cg.esphome_ns.namespace("boiler_guard")
BoilerGuard = boiler_guard_ns.class_("BoilerGuard", cg.Component)

# ID-посилання на upstream-типи ми не резолвимо суворо (щоб не прив'язуватись до
# приватних символів зовнішнього компонента). Приймаємо use_id як generic.
CONFIG_SCHEMA = cv.Schema(
    {
        cv.GenerateID(): cv.declare_id(BoilerGuard),
        cv.Required(CONF_SMARTBOILER_ID): cv.use_id(cg.Component),
        cv.Required(CONF_MODE_SELECT_ID): cv.use_id(select.Select),
        cv.Required(CONF_THERMOSTAT_ID): cv.use_id(climate.Climate),
        cv.Required(CONF_HEATING_SENSOR_ID): cv.use_id(binary_sensor.BinarySensor),
        cv.Optional(CONF_MAX_CONTINUOUS_HEAT_S, default=3600): cv.positive_int,
        cv.Optional(CONF_MIN_PAUSE_S, default=600): cv.positive_int,
        cv.Optional(CONF_HEAT_FALSE_DEBOUNCE_S, default=30): cv.positive_int,
        cv.Optional(CONF_LEASE_TTL_S, default=180): cv.positive_int,
        cv.Optional(CONF_COMMAND_TIMEOUT_S, default=20): cv.positive_int,
    }
).extend(cv.COMPONENT_SCHEMA)


async def to_code(config):
    var = cg.new_Pvariable(config[CONF_ID])
    await cg.register_component(var, config)

    sb = await cg.get_variable(config[CONF_SMARTBOILER_ID])
    cg.add(var.set_smartboiler(sb))
    mode = await cg.get_variable(config[CONF_MODE_SELECT_ID])
    cg.add(var.set_mode_select(mode))
    therm = await cg.get_variable(config[CONF_THERMOSTAT_ID])
    cg.add(var.set_thermostat(therm))
    heat = await cg.get_variable(config[CONF_HEATING_SENSOR_ID])
    cg.add(var.set_heating_sensor(heat))

    cg.add(var.set_max_continuous_heat_s(config[CONF_MAX_CONTINUOUS_HEAT_S]))
    cg.add(var.set_min_pause_s(config[CONF_MIN_PAUSE_S]))
    cg.add(var.set_heat_false_debounce_s(config[CONF_HEAT_FALSE_DEBOUNCE_S]))
    cg.add(var.set_lease_ttl_s(config[CONF_LEASE_TTL_S]))
    cg.add(var.set_command_timeout_s(config[CONF_COMMAND_TIMEOUT_S]))
