#pragma once

// json_stream: потоковий JSON-writer без heap. Header-only, без esphome-include.
// Пише через фіксований буфер 384 B на стеку; коли він заповнюється — скидає
// його в Sink (WiFiClient-сумісний: size_t write(const uint8_t *, size_t)).
// Довгі рядки екрануються потоково (без копії), тож не обрізаються.

#include <cmath>
#include <cstdint>
#include <cstdio>
#include <cstring>

namespace esphome {
namespace rest_api {

template<typename Sink> class JsonStream {
 public:
  explicit JsonStream(Sink &sink) : sink_(sink), n_(0), ok_(true) {}

  // Сирий фрагмент (структурні символи, літерали). Не екранується.
  void raw(const char *s) {
    while (*s != '\0')
      this->put_(*s++);
  }

  // "name": (екранований ключ + двокрапка).
  void key(const char *name) {
    this->str(name);
    this->put_(':');
  }

  // Рядок у лапках з екрануванням за RFC 8259.
  void str(const char *s) { this->str(s, std::strlen(s)); }

  void str(const char *s, size_t len) {
    this->put_('"');
    for (size_t i = 0; i < len; i++) {
      unsigned char c = (unsigned char) s[i];
      switch (c) {
        case '"':
          this->put_('\\');
          this->put_('"');
          break;
        case '\\':
          this->put_('\\');
          this->put_('\\');
          break;
        case '\b':
          this->put_('\\');
          this->put_('b');
          break;
        case '\f':
          this->put_('\\');
          this->put_('f');
          break;
        case '\n':
          this->put_('\\');
          this->put_('n');
          break;
        case '\r':
          this->put_('\\');
          this->put_('r');
          break;
        case '\t':
          this->put_('\\');
          this->put_('t');
          break;
        default:
          if (c < 0x20) {
            static const char HEXDIGITS[] = "0123456789abcdef";
            this->put_('\\');
            this->put_('u');
            this->put_('0');
            this->put_('0');
            this->put_(HEXDIGITS[(c >> 4) & 0xF]);
            this->put_(HEXDIGITS[c & 0xF]);
          } else {
            this->put_((char) c);
          }
      }
    }
    this->put_('"');
  }

  void str_null() { this->raw("null"); }

  // Те саме %g, що в serve_sensor_; NaN/inf -> null (ніколи не 0).
  void number(float v) {
    if (!std::isfinite(v)) {
      this->raw("null");
      return;
    }
    char vb[32];
    std::snprintf(vb, sizeof(vb), "%g", (double) v);
    this->raw(vb);
  }

  // sensor / number entry of GET /state. No state or NaN/+-inf -> {"value":null,"state":"NA"}
  // (never a bare nan/inf token, never 0): a single bad value must not break the whole batch.
  void numeric_entry(bool has_state, float v) {
    if (!has_state || !std::isfinite(v)) {
      this->raw("{\"value\":null,\"state\":\"NA\"}");
      return;
    }
    char vb[32];
    std::snprintf(vb, sizeof(vb), "%g", (double) v);
    this->raw("{\"value\":");
    this->raw(vb);
    this->raw(",\"state\":\"");
    this->raw(vb);
    this->raw("\"}");
  }

  void boolean(bool b) { this->raw(b ? "true" : "false"); }

  // Скинути буфер у sink. false, якщо клієнт зник (write повернув 0).
  bool flush() {
    size_t off = 0;
    while (this->ok_ && off < this->n_) {
      size_t w = this->sink_.write((const uint8_t *) this->buf_ + off, this->n_ - off);
      if (w == 0) {
        this->ok_ = false;
        break;
      }
      off += w;
    }
    this->n_ = 0;
    return this->ok_;
  }

  bool ok() const { return this->ok_; }

 protected:
  void put_(char c) {
    if (!this->ok_)
      return;
    if (this->n_ >= sizeof(this->buf_))
      this->flush();
    if (!this->ok_)
      return;
    this->buf_[this->n_++] = c;
  }

  Sink &sink_;
  char buf_[384];
  size_t n_;
  bool ok_;
};

}  // namespace rest_api
}  // namespace esphome
