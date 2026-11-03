import esphome.codegen as cg
import esphome.config_validation as cv
from esphome.const import CONF_ID, CONF_PORT

# rest_api: легкий локальний HTTP REST-сервер для PHP-модуля MajorDoMo.
# Замінює ESPHome web_server (ESPAsyncWebServer), який вичерпував heap під
# Bluedroid BLE (free heap ~208 B -> "Empty reply from server"). Реалізація —
# синхронний WiFiServer/WiFiClient у loop() з фіксованими стековими буферами,
# ручний JSON без ArduinoJson, без per-request heap-документів. LAN-only, без
# mDNS/discovery. Контракт (GET/POST set/press, Basic auth) — як у Transport.php.

CONF_AUTH = "auth"
CONF_USERNAME = "username"
CONF_PASSWORD = "password"
CONF_LOG = "log"

rest_api_ns = cg.esphome_ns.namespace("rest_api")
RestApiComponent = rest_api_ns.class_("RestApiComponent", cg.Component)

AUTH_SCHEMA = cv.Schema(
    {
        cv.Required(CONF_USERNAME): cv.string_strict,
        cv.Required(CONF_PASSWORD): cv.string_strict,
    }
)

CONFIG_SCHEMA = cv.Schema(
    {
        cv.GenerateID(): cv.declare_id(RestApiComponent),
        cv.Optional(CONF_PORT, default=80): cv.port,
        cv.Optional(CONF_AUTH): AUTH_SCHEMA,
        cv.Optional(CONF_LOG, default=True): cv.boolean,
    }
).extend(cv.COMPONENT_SCHEMA)


async def to_code(config):
    var = cg.new_Pvariable(config[CONF_ID])
    await cg.register_component(var, config)

    cg.add(var.set_port(config[CONF_PORT]))
    cg.add(var.set_log(config[CONF_LOG]))

    if CONF_AUTH in config:
        auth = config[CONF_AUTH]
        cg.add(var.set_auth_username(auth[CONF_USERNAME]))
        cg.add(var.set_auth_password(auth[CONF_PASSWORD]))
