// Host test for firmware/components/boiler_guard/heat_tracker.h (no ESPHome).
//   g++ -std=c++17 -I firmware/components/boiler_guard tools/test/test_heat_tracker.cpp -o test_heat_tracker
#include <cstdio>
#include "heat_tracker.h"

using esphome::boiler_guard::HeatTracker;

static int g_pass = 0, g_fail = 0;
#define CHECK(cond, msg)                                   \
  do {                                                     \
    if (cond) { g_pass++; std::printf("  [PASS] %s\n", msg); } \
    else { g_fail++; std::printf("  [FAIL] %s\n", msg); }  \
  } while (0)

// Drive tick() once per second from t0 to t1 inclusive.
static void run(HeatTracker &h, uint32_t t0, uint32_t t1, bool accumulate = true) {
  for (uint32_t t = t0; t <= t1; t++) h.tick(t, accumulate);
}

int main() {
  std::printf("HeatTracker\n");

  {  // Normal heating accumulates, stop is confirmed by TIME (the regression).
    HeatTracker h;
    h.set_debounce_s(30);
    h.tick(1000, true);
    h.report(true, 1000);
    run(h, 1001, 1100);
    CHECK(h.confirmed(), "heating confirmed immediately");
    CHECK(h.continuous_s() == 100, "100 s of heating accumulated");

    h.report(false, 1100);          // ONE event, then silence (sensor publishes on change only)
    run(h, 1101, 1120);
    CHECK(h.confirmed(), "still confirmed inside the debounce window");
    run(h, 1121, 1135);
    CHECK(!h.confirmed(), "REGRESSION: stop is confirmed after debounce without another event");
    CHECK(h.continuous_s() == 0, "continuous counter reset when heating really stopped");
  }

  {  // The exact production failure: long idle after a heating episode.
    HeatTracker h;
    h.set_debounce_s(30);
    h.tick(1, true);
    h.report(true, 1);
    run(h, 2, 60);
    h.report(false, 60);
    run(h, 61, 61 + 2 * 3600);      // 2 hours of NO heating, no further events
    CHECK(!h.confirmed(), "2 h idle: not confirmed");
    CHECK(h.continuous_s() == 0, "2 h idle: counter stays 0 (old code climbed to the 3600 s limit)");
  }

  {  // Flapping shorter than the debounce does not break continuity.
    HeatTracker h;
    h.set_debounce_s(30);
    h.tick(1, true);
    h.report(true, 1);
    run(h, 2, 100);
    h.report(false, 100);
    run(h, 101, 110);               // 10 s off (< 30 s)
    h.report(true, 110);
    run(h, 111, 200);
    CHECK(h.confirmed(), "short dip: still confirmed");
    CHECK(h.continuous_s() >= 199, "short dip: continuity preserved (counter kept counting)");
  }

  {  // Accumulation can be paused (cooldown).
    HeatTracker h;
    h.tick(1, true);
    h.report(true, 1);
    run(h, 2, 50, true);
    uint32_t before = h.continuous_s();
    run(h, 51, 150, false);         // cooldown: do not accumulate
    CHECK(h.continuous_s() == before, "accumulate=false freezes the counter");
    run(h, 151, 160, true);
    CHECK(h.continuous_s() == before + 10, "accumulation resumes afterwards");
  }

  {  // Not heating from boot: nothing accumulates.
    HeatTracker h;
    h.tick(5, true);
    h.report(false, 5);
    run(h, 6, 5000);
    CHECK(!h.confirmed() && h.continuous_s() == 0, "idle from boot: stays idle, counter 0");
  }

  {  // Restored durable counter is cleared once the boiler is seen idle.
    HeatTracker h;
    h.set_debounce_s(30);
    h.set_continuous_s(3000);       // value restored from NVS after reboot
    h.tick(10, true);
    h.report(false, 10);
    run(h, 11, 60);
    CHECK(h.continuous_s() == 0, "restored counter reset after idle is confirmed");
  }

  {  // Time source starting at 0 does not wedge the debounce.
    HeatTracker h;
    h.set_debounce_s(30);
    h.report(true, 0);
    run(h, 0, 10);
    h.report(false, 0);             // now==0 edge: must still start a timer
    run(h, 1, 60);
    CHECK(!h.confirmed(), "report(false) at t=0 still debounces");
  }

  std::printf("\nPASSED: %d   FAILED: %d\n", g_pass, g_fail);
  return g_fail ? 1 : 0;
}
