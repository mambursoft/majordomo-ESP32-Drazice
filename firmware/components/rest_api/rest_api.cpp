#include "rest_api.h"

#include "esphome/core/application.h"
#include "esphome/core/entity_base.h"
#include "esphome/core/helpers.h"
#include "esphome/core/log.h"

#include "esphome/components/sensor/sensor.h"
#include "esphome/components/binary_sensor/binary_sensor.h"
#include "esphome/components/text_sensor/text_sensor.h"
#include "esphome/components/number/number.h"
#include "esphome/components/select/select.h"
#include "esphome/components/button/button.h"

#include <cmath>
#include <cstdio>
#include <cstring>
#include <cstdlib>
#include <iterator>
#include <type_traits>

namespace esphome {
namespace rest_api {

static const char *const TAG = "rest_api";

// Per-request LED activity hook (та сама семантика, що в старому web_server).
static void (*web_activity_led_hook)() = nullptr;
void set_web_activity_led_hook(void (*hook)()) { web_activity_led_hook = hook; }

// Ліміти фіксованих стекових буферів. Запити машинні й короткі (один поллер).
static const size_t MAX_LINE = 512;       // request line (метод + шлях + query)
static const size_t MAX_HEADER = 256;      // один заголовок
static const size_t MAX_NAME = 160;        // декодоване display-name entity
static const size_t MAX_TEXT = 304;        // екранований text_sensor (напр. Boiler Pair Trace ~280 B)
static const size_t MAX_JSON = 704;        // тіло JSON-відповіді: value і state дублюють текст (2*MAX_TEXT + префікс)
static const uint32_t CLIENT_TIMEOUT_MS = 1500;

// ---- Малі хелпери без heap --------------------------------------------------

// URL-декод сегмента (%XX). '+' НЕ перетворюємо (модуль шле %20, не '+').
// Пише у dst (із термінатором), безпечно обрізаючи до dst_size-1.
static void url_decode(const char *src, size_t src_len, char *dst, size_t dst_size) {
  size_t o = 0;
  for (size_t i = 0; i < src_len && o + 1 < dst_size; i++) {
    char c = src[i];
    if (c == '%' && i + 2 < src_len) {
      char h1 = src[i + 1], h2 = src[i + 2];
      auto hexv = [](char h) -> int {
        if (h >= '0' && h <= '9')
          return h - '0';
        if (h >= 'a' && h <= 'f')
          return h - 'a' + 10;
        if (h >= 'A' && h <= 'F')
          return h - 'A' + 10;
        return -1;
      };
      int v1 = hexv(h1), v2 = hexv(h2);
      if (v1 >= 0 && v2 >= 0) {
        dst[o++] = (char) ((v1 << 4) | v2);
        i += 2;
        continue;
      }
    }
    dst[o++] = c;
  }
  dst[o] = '\0';
}

// Екранування рядка для JSON у фіксований буфер (лапки, бекслеш, керівні).
// Повертає кількість записаних байтів (без термінатора); безпечно обрізає.
static size_t json_escape(const char *src, char *dst, size_t dst_size) {
  size_t o = 0;
  for (size_t i = 0; src[i] != '\0' && o + 2 < dst_size; i++) {
    unsigned char c = (unsigned char) src[i];
    if (c == '"' || c == '\\') {
      dst[o++] = '\\';
      dst[o++] = c;
    } else if (c == '\n') {
      if (o + 2 >= dst_size)
        break;
      dst[o++] = '\\';
      dst[o++] = 'n';
    } else if (c == '\r') {
      if (o + 2 >= dst_size)
        break;
      dst[o++] = '\\';
      dst[o++] = 'r';
    } else if (c == '\t') {
      if (o + 2 >= dst_size)
        break;
      dst[o++] = '\\';
      dst[o++] = 't';
    } else if (c < 0x20) {
      // інші керівні — пропускаємо
    } else {
      dst[o++] = c;
    }
  }
  dst[o] = '\0';
  return o;
}

// Збіг display-name entity з декодованим іменем із запиту. Контейнер може бути
// std::vector<T*> або esphome::StaticVector<T*, N> — шаблонізуємо за типом
// контейнера, щоб приймати будь-який ітерабельний реєстр App.get_*().
template<typename Container>
static auto find_by_name(const Container &list, const char *name) ->
    typename std::decay<decltype(*std::begin(list))>::type {
  using Ptr = typename std::decay<decltype(*std::begin(list))>::type;
  size_t name_len = std::strlen(name);
  for (Ptr e : list) {
    if (e->is_internal())
      continue;
    const StringRef &n = e->get_name();
    if (n.size() == name_len && std::memcmp(n.c_str(), name, n.size()) == 0)
      return e;
  }
  return nullptr;
}

// Витягнути значення параметра value= з query-рядка у dst. true, якщо знайдено.
static bool query_get_value(const char *query, char *dst, size_t dst_size) {
  if (query == nullptr)
    return false;
  const char *p = query;
  while (*p) {
    // шукаємо "value="
    if ((p == query || p[-1] == '&' || p[-1] == '?') && std::strncmp(p, "value=", 6) == 0) {
      const char *v = p + 6;
      const char *end = std::strchr(v, '&');
      size_t vlen = end ? (size_t) (end - v) : std::strlen(v);
      url_decode(v, vlen, dst, dst_size);
      return true;
    }
    p++;
  }
  return false;
}

// ---- Component --------------------------------------------------------------

float RestApiComponent::get_setup_priority() const { return setup_priority::AFTER_WIFI; }

void RestApiComponent::setup() {
  // Попередньо обчислюємо base64("user:pass") один раз.
  if (!this->auth_username_.empty() || !this->auth_password_.empty()) {
    std::string cred = this->auth_username_ + ":" + this->auth_password_;
    this->expected_auth_b64_ = base64_encode((const uint8_t *) cred.c_str(), cred.size());
  }
  this->server_ = WiFiServer(this->port_);
  this->server_.begin();
  this->server_.setNoDelay(true);
  this->server_started_ = true;
  ESP_LOGCONFIG(TAG, "rest_api server started on port %u", this->port_);
}

void RestApiComponent::loop() {
  if (!this->server_started_)
    return;
  WiFiClient client = this->server_.available();
  if (!client)
    return;

  // Перша дія обробки — неблокуючий LED-пульс (alloc-free).
  if (web_activity_led_hook != nullptr)
    web_activity_led_hook();

  this->handle_client_(client);
}

void RestApiComponent::dump_config() {
  ESP_LOGCONFIG(TAG, "REST API:");
  ESP_LOGCONFIG(TAG, "  Port: %u", this->port_);
  ESP_LOGCONFIG(TAG, "  Auth: %s", this->expected_auth_b64_.empty() ? "disabled" : "basic");
  ESP_LOGCONFIG(TAG, "  Transport: synchronous WiFiServer (LAN-only, no mDNS)");
}

void RestApiComponent::handle_client_(WiFiClient &client) {
  char line[MAX_LINE];
  size_t line_len = 0;

  // --- Читаємо request line (до CRLF/LF) ---
  uint32_t start = millis();
  bool got_line = false;
  while (millis() - start < CLIENT_TIMEOUT_MS) {
    if (!client.connected())
      break;
    while (client.available()) {
      char c = (char) client.read();
      if (c == '\n') {
        got_line = true;
        break;
      }
      if (c == '\r')
        continue;
      if (line_len + 1 < MAX_LINE)
        line[line_len++] = c;
      // якщо request line довша за буфер — надлишок просто відкидаємо
    }
    if (got_line)
      break;
    delay(1);
  }
  line[line_len] = '\0';

  if (!got_line) {
    this->send_status_(client, 400, "Bad Request");
    client.stop();
    return;
  }

  // --- Парсимо "METHOD SP PATH SP HTTP/x.y" ---
  char *sp1 = std::strchr(line, ' ');
  if (sp1 == nullptr) {
    this->send_status_(client, 400, "Bad Request");
    client.stop();
    return;
  }
  *sp1 = '\0';
  const char *method = line;
  char *path = sp1 + 1;
  char *sp2 = std::strchr(path, ' ');
  if (sp2 != nullptr)
    *sp2 = '\0';

  bool is_get = std::strcmp(method, "GET") == 0;
  bool is_post = std::strcmp(method, "POST") == 0;

  // Відокремлюємо query.
  char *query = std::strchr(path, '?');
  if (query != nullptr) {
    *query = '\0';
    query++;
  }

  // --- Читаємо заголовки, шукаємо Authorization ---
  char auth_b64[MAX_HEADER];
  auth_b64[0] = '\0';
  bool has_auth = false;
  start = millis();
  while (millis() - start < CLIENT_TIMEOUT_MS) {
    if (!client.connected())
      break;
    char hdr[MAX_HEADER];
    size_t hlen = 0;
    bool got_hdr = false;
    while (millis() - start < CLIENT_TIMEOUT_MS) {
      if (!client.connected())
        break;
      while (client.available()) {
        char c = (char) client.read();
        if (c == '\n') {
          got_hdr = true;
          break;
        }
        if (c == '\r')
          continue;
        if (hlen + 1 < MAX_HEADER)
          hdr[hlen++] = c;
      }
      if (got_hdr)
        break;
      delay(1);
    }
    hdr[hlen] = '\0';
    if (!got_hdr || hlen == 0)
      break;  // порожній рядок -> кінець заголовків (або таймаут)

    // case-insensitive "authorization:"
    if (hlen >= 15 && strncasecmp(hdr, "authorization:", 14) == 0) {
      const char *v = hdr + 14;
      while (*v == ' ')
        v++;
      if (strncasecmp(v, "basic ", 6) == 0) {
        v += 6;
        while (*v == ' ')
          v++;
        std::strncpy(auth_b64, v, MAX_HEADER - 1);
        auth_b64[MAX_HEADER - 1] = '\0';
        has_auth = true;
      }
    }
  }

  // --- Auth ---
  if (!this->expected_auth_b64_.empty()) {
    if (!has_auth || !this->check_auth_(auth_b64, std::strlen(auth_b64))) {
      this->send_401_(client);
      client.stop();
      return;
    }
  }

  // --- Парсимо шлях /{domain}/{name}[/{method}] ---
  if (path[0] != '/') {
    this->send_status_(client, 404, "Not Found");
    client.stop();
    return;
  }
  char *domain = path + 1;
  char *name_seg = std::strchr(domain, '/');
  if (name_seg == nullptr) {
    // лише /{domain} або "/" — нічого корисного для машинного API
    this->send_status_(client, 404, "Not Found");
    client.stop();
    return;
  }
  *name_seg = '\0';
  name_seg++;
  char *method_seg = std::strchr(name_seg, '/');
  if (method_seg != nullptr) {
    *method_seg = '\0';
    method_seg++;
  }

  char decoded_name[MAX_NAME];
  url_decode(name_seg, std::strlen(name_seg), decoded_name, MAX_NAME);

  if (this->log_) {
    ESP_LOGD(TAG, "%s /%s/%s%s%s", method, domain, decoded_name, method_seg ? "/" : "",
             method_seg ? method_seg : "");
  }

  if (!is_get && !is_post) {
    this->send_status_(client, 405, "Method Not Allowed");
    client.stop();
    return;
  }

  this->route_(client, is_get, domain, decoded_name, method_seg, query);
  client.stop();
}

bool RestApiComponent::check_auth_(const char *auth_b64, size_t len) const {
  // Порівнюємо отриманий base64-токен із попередньо обчисленим. Без алокацій.
  if (this->expected_auth_b64_.size() != len)
    return false;
  return std::memcmp(this->expected_auth_b64_.c_str(), auth_b64, len) == 0;
}

void RestApiComponent::route_(WiFiClient &client, bool is_get, const char *domain,
                              const char *name, const char *method, const char *query) {
  if (is_get) {
    if (std::strcmp(domain, "sensor") == 0)
      return this->serve_sensor_(client, name);
    if (std::strcmp(domain, "binary_sensor") == 0)
      return this->serve_binary_sensor_(client, name);
    if (std::strcmp(domain, "text_sensor") == 0)
      return this->serve_text_sensor_(client, name);
    if (std::strcmp(domain, "number") == 0)
      return this->serve_number_(client, name);
    if (std::strcmp(domain, "select") == 0)
      return this->serve_select_(client, name);
    return this->send_status_(client, 404, "Not Found");
  }

  // POST
  if (std::strcmp(domain, "number") == 0 && method != nullptr && std::strcmp(method, "set") == 0)
    return this->apply_number_(client, name, query);
  if (std::strcmp(domain, "select") == 0 && method != nullptr && std::strcmp(method, "set") == 0)
    return this->apply_select_(client, name, query);
  if (std::strcmp(domain, "button") == 0 && method != nullptr && std::strcmp(method, "press") == 0)
    return this->press_button_(client, name);
  this->send_status_(client, 404, "Not Found");
}

// ---- GET серіалізатори ------------------------------------------------------

void RestApiComponent::serve_sensor_(WiFiClient &client, const char *name) {
  auto *e = find_by_name(App.get_sensors(), name);
  if (e == nullptr)
    return this->send_status_(client, 404, "Not Found");
  std::string oid = e->get_object_id();
  char json[MAX_JSON];
  // NaN/без стану -> value ПРОПУСКАЄМО (не 0), state:"NA".
  if (!e->has_state() || std::isnan(e->state)) {
    snprintf(json, MAX_JSON, "{\"id\":\"sensor-%s\",\"value\":null,\"state\":\"NA\"}", oid.c_str());
  } else {
    char vb[32];
    snprintf(vb, sizeof(vb), "%g", e->state);
    snprintf(json, MAX_JSON, "{\"id\":\"sensor-%s\",\"value\":%s,\"state\":\"%s\"}", oid.c_str(), vb, vb);
  }
  this->send_json_(client, json);
}

void RestApiComponent::serve_binary_sensor_(WiFiClient &client, const char *name) {
  auto *e = find_by_name(App.get_binary_sensors(), name);
  if (e == nullptr)
    return this->send_status_(client, 404, "Not Found");
  std::string oid = e->get_object_id();
  char json[MAX_JSON];
  if (!e->has_state()) {
    snprintf(json, MAX_JSON, "{\"id\":\"binary_sensor-%s\",\"value\":null,\"state\":\"NA\"}", oid.c_str());
  } else {
    bool v = e->state;
    snprintf(json, MAX_JSON, "{\"id\":\"binary_sensor-%s\",\"value\":%s,\"state\":\"%s\"}", oid.c_str(),
             v ? "true" : "false", v ? "ON" : "OFF");
  }
  this->send_json_(client, json);
}

void RestApiComponent::serve_text_sensor_(WiFiClient &client, const char *name) {
  auto *e = find_by_name(App.get_text_sensors(), name);
  if (e == nullptr)
    return this->send_status_(client, 404, "Not Found");
  std::string oid = e->get_object_id();
  char json[MAX_JSON];
  if (!e->has_state()) {
    snprintf(json, MAX_JSON, "{\"id\":\"text_sensor-%s\",\"value\":null,\"state\":null}", oid.c_str());
  } else {
    char esc[MAX_TEXT];
    json_escape(e->state.c_str(), esc, MAX_TEXT);
    snprintf(json, MAX_JSON, "{\"id\":\"text_sensor-%s\",\"value\":\"%s\",\"state\":\"%s\"}", oid.c_str(), esc,
             esc);
  }
  this->send_json_(client, json);
}

void RestApiComponent::serve_number_(WiFiClient &client, const char *name) {
  auto *e = find_by_name(App.get_numbers(), name);
  if (e == nullptr)
    return this->send_status_(client, 404, "Not Found");
  std::string oid = e->get_object_id();
  char json[MAX_JSON];
  if (!e->has_state() || std::isnan(e->state)) {
    snprintf(json, MAX_JSON, "{\"id\":\"number-%s\",\"value\":null,\"state\":\"NA\"}", oid.c_str());
  } else {
    char vb[32];
    snprintf(vb, sizeof(vb), "%g", e->state);
    snprintf(json, MAX_JSON, "{\"id\":\"number-%s\",\"value\":%s,\"state\":\"%s\"}", oid.c_str(), vb, vb);
  }
  this->send_json_(client, json);
}

void RestApiComponent::serve_select_(WiFiClient &client, const char *name) {
  auto *e = find_by_name(App.get_selects(), name);
  if (e == nullptr)
    return this->send_status_(client, 404, "Not Found");
  std::string oid = e->get_object_id();
  char json[MAX_JSON];
  if (!e->has_state()) {
    snprintf(json, MAX_JSON, "{\"id\":\"select-%s\",\"value\":null,\"state\":null}", oid.c_str());
  } else {
    char esc[MAX_NAME];
    json_escape(e->state.c_str(), esc, MAX_NAME);
    snprintf(json, MAX_JSON, "{\"id\":\"select-%s\",\"value\":\"%s\",\"state\":\"%s\"}", oid.c_str(), esc, esc);
  }
  this->send_json_(client, json);
}

// ---- POST обробники ---------------------------------------------------------

void RestApiComponent::apply_number_(WiFiClient &client, const char *name, const char *query) {
  auto *e = find_by_name(App.get_numbers(), name);
  if (e == nullptr)
    return this->send_status_(client, 404, "Not Found");
  char vbuf[48];
  if (!query_get_value(query, vbuf, sizeof(vbuf)))
    return this->send_status_(client, 400, "Bad Request");
  char *endp = nullptr;
  float v = std::strtof(vbuf, &endp);
  if (endp == vbuf)
    return this->send_status_(client, 400, "Bad Request");
  // Той самий guarded шлях, що й у старому web_server: template number ->
  // set_action у policy_guard.yaml делегує в boiler_guard. Ми НЕ обходимо guard.
  e->make_call().set_value(v).perform();
  this->send_status_(client, 200, "OK");
}

void RestApiComponent::apply_select_(WiFiClient &client, const char *name, const char *query) {
  auto *e = find_by_name(App.get_selects(), name);
  if (e == nullptr)
    return this->send_status_(client, 404, "Not Found");
  char vbuf[48];
  if (!query_get_value(query, vbuf, sizeof(vbuf)))
    return this->send_status_(client, 400, "Bad Request");
  e->make_call().set_option(std::string(vbuf)).perform();
  this->send_status_(client, 200, "OK");
}

void RestApiComponent::press_button_(WiFiClient &client, const char *name) {
  auto *e = find_by_name(App.get_buttons(), name);
  if (e == nullptr)
    return this->send_status_(client, 404, "Not Found");
  e->press();
  this->send_status_(client, 200, "OK");
}

// ---- Відповіді --------------------------------------------------------------

void RestApiComponent::send_status_(WiFiClient &client, int code, const char *reason) {
  char hdr[96];
  int n = snprintf(hdr, sizeof(hdr),
                   "HTTP/1.1 %d %s\r\nContent-Length: 0\r\nConnection: close\r\n\r\n", code, reason);
  client.write((const uint8_t *) hdr, (size_t) n);
}

void RestApiComponent::send_json_(WiFiClient &client, const char *json) {
  size_t body_len = std::strlen(json);
  char hdr[128];
  int n = snprintf(hdr, sizeof(hdr),
                   "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: %u\r\n"
                   "Connection: close\r\n\r\n",
                   (unsigned) body_len);
  client.write((const uint8_t *) hdr, (size_t) n);
  client.write((const uint8_t *) json, body_len);
}

void RestApiComponent::send_401_(WiFiClient &client) {
  static const char *const RESP =
      "HTTP/1.1 401 Unauthorized\r\n"
      "WWW-Authenticate: Basic realm=\"esp\"\r\n"
      "Content-Length: 0\r\n"
      "Connection: close\r\n\r\n";
  client.write((const uint8_t *) RESP, std::strlen(RESP));
}

}  // namespace rest_api
}  // namespace esphome
