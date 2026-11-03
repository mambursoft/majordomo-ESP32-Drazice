#pragma once

// rest_api: allocation-light HTTP REST-сервер для PHP-модуля MajorDoMo.
// Синхронний WiFiServer/WiFiClient (lwIP) у loop(). Жодного ESPAsyncWebServer,
// ArduinoJson чи per-request heap-документів: запит читається у фіксовані
// стекові буфери, JSON будується вручну у char[]. LAN-only, без mDNS.
//
// Контракт (джерело істини — majordomo/.../lib/Transport.php):
//   GET  /{domain}/{name}                 domain in sensor|binary_sensor|
//                                         text_sensor|number|select
//   POST /{domain}/{name}/set?value={v}   number|select (обидва через value=)
//   POST /button/{name}/press             натиснути button
//   Basic auth (web_username/web_password); невірно/немає -> 401.

#include "esphome/core/component.h"

#include <WiFi.h>
#include <WiFiServer.h>
#include <WiFiClient.h>

namespace esphome {
namespace rest_api {

// Неблокуючий LED-хук на кожен прийнятий HTTP-запит (та сама точка, що її
// раніше надавав vendored web_server). status_led.yaml/on_boot реєструє
// callback, який коротко пульсує status LED через scheduler. Якщо хук не
// зареєстровано — no-op. Alloc-free, не блокує loop().
void set_web_activity_led_hook(void (*hook)());

class RestApiComponent : public Component {
 public:
  void setup() override;
  void loop() override;
  void dump_config() override;
  float get_setup_priority() const override;

  void set_port(uint16_t port) { this->port_ = port; }
  void set_log(bool log) { this->log_ = log; }
  void set_auth_username(const std::string &u) { this->auth_username_ = u; }
  void set_auth_password(const std::string &p) { this->auth_password_ = p; }

 protected:
  // Обробка одного прийнятого клієнта: читання request line + заголовків,
  // auth, маршрутизація, відповідь. Усе у стекових буферах.
  void handle_client_(WiFiClient &client);

  // Перевірка HTTP Basic з заголовка Authorization (рядок після "Basic ").
  // Повертає true, якщо auth не налаштовано (сервер відкритий) або збіг.
  bool check_auth_(const char *auth_b64, size_t len) const;

  // Маршрутизація за методом/доменом/іменем/method-сегментом. Пише відповідь
  // напряму в client. decoded_name — вже URL-декодоване ім'я entity.
  void route_(WiFiClient &client, bool is_get, const char *domain,
              const char *decoded_name, const char *method, const char *query);

  // GET-серіалізатори: знаходять entity за display-name і пишуть 200+JSON або 404.
  void serve_sensor_(WiFiClient &client, const char *name);
  void serve_binary_sensor_(WiFiClient &client, const char *name);
  void serve_text_sensor_(WiFiClient &client, const char *name);
  void serve_number_(WiFiClient &client, const char *name);
  void serve_select_(WiFiClient &client, const char *name);

  // POST-обробники.
  void apply_number_(WiFiClient &client, const char *name, const char *query);
  void apply_select_(WiFiClient &client, const char *name, const char *query);
  void press_button_(WiFiClient &client, const char *name);

  // Відповіді.
  void send_status_(WiFiClient &client, int code, const char *reason);
  void send_json_(WiFiClient &client, const char *json);
  void send_401_(WiFiClient &client);

  uint16_t port_{80};
  bool log_{true};
  std::string auth_username_;
  std::string auth_password_;
  // Попередньо обчислений base64("user:pass") (один раз у setup()), щоб не
  // алокувати/декодувати на кожен запит. Порожній -> auth вимкнено.
  std::string expected_auth_b64_;
  WiFiServer server_{80};
  bool server_started_{false};
};

}  // namespace rest_api
}  // namespace esphome
