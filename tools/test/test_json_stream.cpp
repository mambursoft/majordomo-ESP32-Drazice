// Host-тест для firmware/components/rest_api/json_stream.h
// g++ -std=c++11 -Wall -Wextra -o _t.exe test_json_stream.cpp
#include "../../firmware/components/rest_api/json_stream.h"

#include <cmath>
#include <cstdio>
#include <limits>
#include <string>

using esphome::rest_api::JsonStream;

struct StrSink {
  std::string out;
  size_t write(const uint8_t *p, size_t n) {
    out.append((const char *) p, n);
    return n;
  }
};

struct FailSink {
  int calls = 0;
  size_t write(const uint8_t *, size_t) {
    calls++;
    return 0;
  }
};

static int failures = 0;
static void check(bool cond, const char *name) {
  std::printf("%s: %s\n", cond ? "PASS" : "FAIL", name);
  if (!cond)
    failures++;
}

static std::string run_str(const std::string &s) {
  StrSink sink;
  JsonStream<StrSink> js(sink);
  js.str(s.c_str());
  js.flush();
  return sink.out;
}

static std::string run_num(float v) {
  StrSink sink;
  JsonStream<StrSink> js(sink);
  js.number(v);
  js.flush();
  return sink.out;
}

static std::string run_entry(bool has, float v) {
  StrSink sink;
  JsonStream<StrSink> js(sink);
  js.numeric_entry(has, v);
  js.flush();
  return sink.out;
}

int main() {
  const float kInf = std::numeric_limits<float>::infinity();
  const std::string na = "{\"value\":null,\"state\":\"NA\"}";
  check(run_entry(true, 21.5f) == "{\"value\":21.5,\"state\":\"21.5\"}", "entry: finite -> value+state");
  check(run_entry(true, std::nanf("")) == na, "entry: NaN -> NA object");
  check(run_entry(true, kInf) == na, "entry: +inf -> NA object (no bare inf)");
  check(run_entry(true, -kInf) == na, "entry: -inf -> NA object (no bare -inf)");
  check(run_entry(false, 1.0f) == na, "entry: no state -> NA object");
  check(run_entry(true, 0.0f) == "{\"value\":0,\"state\":\"0\"}", "entry: real 0 stays 0");
  check(run_str("a\"b") == "\"a\\\"b\"", "quote escaped");
  check(run_str("a\\b") == "\"a\\\\b\"", "backslash escaped");
  check(run_str("a\nb") == "\"a\\nb\"", "newline escaped");
  check(run_str("a\tb") == "\"a\\tb\"", "tab escaped");
  check(run_str("a\x01" "b") == "\"a\\u0001b\"", "control char -> \\u0001");
  check(run_str("\b\f\r") == "\"\\b\\f\\r\"", "\\b \\f \\r escaped");

  const char *trace = "TAG@123 | CONN@5 | \"x\" \\ y";
  check(run_str(trace) == "\"TAG@123 | CONN@5 | \\\"x\\\" \\\\ y\"", "trace text round-trips");

  std::string big(3000, 'a');
  for (size_t i = 0; i < big.size(); i += 97)
    big[i] = 'Z';
  check(run_str(big) == "\"" + big + "\"", "3000-char string streams intact");

  // Багато екранованих символів поверх меж буфера.
  std::string esc(1000, '"');
  std::string expect = "\"";
  for (size_t i = 0; i < esc.size(); i++)
    expect += "\\\"";
  expect += "\"";
  check(run_str(esc) == expect, "escapes across flush boundaries");

  float nan = std::numeric_limits<float>::quiet_NaN();
  float inf = std::numeric_limits<float>::infinity();
  check(run_num(nan) == "null", "NaN -> null");
  check(run_num(inf) == "null", "+inf -> null");
  check(run_num(-inf) == "null", "-inf -> null");
  check(run_num(55.5f) == "55.5", "55.5 -> 55.5");
  {
    char expect_g[32];
    std::snprintf(expect_g, sizeof(expect_g), "%g", (double) 1e-7f);
    check(run_num(1e-7f) == expect_g, "1e-7 uses %g text");
  }

  {
    StrSink sink;
    JsonStream<StrSink> js(sink);
    js.raw("{");
    js.key("k\"");
    js.boolean(true);
    js.raw(",");
    js.key("n");
    js.str_null();
    js.raw("}");
    js.flush();
    check(sink.out == "{\"k\\\"\":true,\"n\":null}", "key/boolean/null/raw");
  }

  {
    FailSink sink;
    JsonStream<FailSink> js(sink);
    js.str(std::string(2000, 'x').c_str());
    bool r = js.flush();
    check(!r && !js.ok(), "failing sink: flush() false");
    int calls = sink.calls;
    js.str(std::string(2000, 'y').c_str());
    js.flush();
    check(sink.calls == calls, "failing sink: no further writes");
  }

  std::printf(failures == 0 ? "ALL PASS\n" : "FAILURES: %d\n", failures);
  return failures == 0 ? 0 : 1;
}
