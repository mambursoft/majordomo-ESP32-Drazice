#include "smartboiler.h"
#include "esphome/core/application.h"
#include "esphome/components/md5/md5.h"

#define UUID_LENGTH 6

static const char *const TAG = "smartboiler";

namespace esphome {
namespace sb {

// Requests are written to this characteristic
static const uint16_t SB_MAIN_SERVICE_UUID = 0x1899;
static const uint16_t SB_MAIN_CHARACTERISTIC_UUID = 0x2B99;

// Responses arrive through this characteristic
static const uint16_t SB_LOGGING_SERVICE_UUID = 0x1898;
static const uint16_t SB_LOGGING_CHARACTERISTIC_UUID = 0x2B98;
static const uint16_t CLIENT_CHARACTERISTIC_CONFIG_DESCRIPTOR_UUID = 0x2902;

static const int COMMAND_DELAY = 100;

void SmartBoiler::restore_state_() {
  SavedSmartBoilerSettings recovered{};
  this->pref_ = global_preferences->make_preference<SavedSmartBoilerSettings>(this->thermostat_->get_object_id_hash());
  bool restored = this->pref_.load(&recovered);
  if (restored) {
    this->uid_ = recovered.uid;
    ESP_LOGD(TAG, "using stored UID: %s", this->uid_.c_str());
  }
}

void SmartBoiler::save_state_() {
  SavedSmartBoilerSettings state{};
  strcpy(state.uid, this->uid_.c_str());
  this->pref_.save(&state);
}

void SmartBoiler::setup() {
  ESP_LOGD(TAG, "Setup()");
  this->restore_state_();
  if (this->uid_.size() == 0) {
    this->uid_ = this->generateUUID();
    ESP_LOGD(TAG, "generated new UUID: %s", this->uid_.c_str());
    this->save_state_();
  }
  this->state_txt_->publish_state(this->state_to_string(this->state_));
  // Pairing diagnostics start from a defined value (not null) so the module can
  // tell "no events yet" from "entity missing".
  if (this->pair_trace_txt_)
    this->pair_trace_txt_->publish_state("-");
  if (this->last_disconnect_txt_)
    this->last_disconnect_txt_->publish_state("-");
  if (this->pair_attempts_sensor_)
    this->pair_attempts_sensor_->publish_state(0);
  if (this->connect_count_sensor_)
    this->connect_count_sensor_->publish_state(0);
}

// ---- Pairing diagnostics (LOCAL MODIFICATION) --------------------------------
// Fixed ring of TRACE_SLOTS short events, each "TAG@<uptime_ms>". No heap
// growth: the ring is a member array, formatting uses snprintf into it. The
// published string is assembled (oldest -> newest) into a stack buffer only
// when something changed, from loop(). PIN digits are never recorded.

const char *SmartBoiler::state_code_(ConnectionState state) {
  switch (state) {
    case ConnectionState::CONNECTED:
      return "CO";
    case ConnectionState::NEED_PIN:
      return "NP";
    case ConnectionState::AUTHENTICATING:
      return "AU";
    case ConnectionState::DISCONNECTED:
      return "DI";
    default:
      return "??";
  }
}

const char *SmartBoiler::state_name_(ConnectionState state) {
  switch (state) {
    case ConnectionState::CONNECTED:
      return "CONNECTED";
    case ConnectionState::NEED_PIN:
      return "NEED_PIN";
    case ConnectionState::AUTHENTICATING:
      return "AUTHENTICATING";
    case ConnectionState::DISCONNECTED:
      return "DISCONNECTED";
    default:
      return "UNKNOWN";
  }
}

void SmartBoiler::trace_(const char *fmt, ...) {
  char tag[16];
  va_list ap;
  va_start(ap, fmt);
  vsnprintf(tag, sizeof(tag), fmt, ap);
  va_end(ap);
  snprintf(this->trace_ring_[this->trace_head_], TRACE_SLOT_LEN, "%s@%u", tag, (unsigned) millis());
  this->trace_head_ = (this->trace_head_ + 1) % TRACE_SLOTS;
  if (this->trace_n_ < TRACE_SLOTS)
    this->trace_n_++;
  this->trace_dirty_ = true;
}

void SmartBoiler::trace_publish_() {
  if (!this->trace_dirty_)
    return;
  this->trace_dirty_ = false;
  if (this->pair_trace_txt_ == nullptr)
    return;
  // Worst case: 10 * 25 chars + 9 * 3 separators + NUL = 278 < 288.
  char out[288];
  size_t o = 0;
  out[0] = '\0';
  uint8_t start = (this->trace_head_ + TRACE_SLOTS - this->trace_n_) % TRACE_SLOTS;
  for (uint8_t i = 0; i < this->trace_n_; i++) {
    const char *ev = this->trace_ring_[(start + i) % TRACE_SLOTS];
    int n = snprintf(out + o, sizeof(out) - o, "%s%s", i ? " | " : "", ev);
    if (n < 0 || (size_t) n >= sizeof(out) - o)
      break;
    o += n;
  }
  this->pair_trace_txt_->publish_state(out);
}

void SmartBoiler::record_disconnect_(int reason) {
  uint32_t now = millis();
  char since_conn[16];
  char since_pin[16];
  if (this->connect_count_ > 0)
    snprintf(since_conn, sizeof(since_conn), "%u", (unsigned) (now - this->connect_ms_));
  else
    snprintf(since_conn, sizeof(since_conn), "-");
  if (this->pin_tx_valid_)
    snprintf(since_pin, sizeof(since_pin), "%u", (unsigned) (now - this->pin_tx_ms_));
  else
    snprintf(since_pin, sizeof(since_pin), "-");
  char buf[112];
  snprintf(buf, sizeof(buf), "reason=%d state=%s uptime_ms=%u since_connect_ms=%s since_pin_tx_ms=%s", reason,
           this->state_name_(this->state_), (unsigned) now, since_conn, since_pin);
  ESP_LOGI(TAG, "Last disconnect: %s", buf);
  if (this->last_disconnect_txt_)
    this->last_disconnect_txt_->publish_state(buf);
}

void SmartBoiler::dump_config() {
  ESP_LOGCONFIG(TAG, "SmartBoiler:");
  LOG_SENSOR("  ", "Temp1", temperature_sensor_1_sensor_);
  LOG_SENSOR("  ", "Temp2", temperature_sensor_2_sensor_);
  LOG_SENSOR("  ", "Consumption", consumption_sensor_);
  LOG_BINARY_SENSOR("  ", "HDO", hdo_low_tariff_sensor_);
  LOG_SELECT("  ", "Mode", mode_select_);
  LOG_CLIMATE("  ", "Thermostat", thermostat_);
  LOG_NUMBER("  ", "Pairing PIN", mPin_);
  LOG_TEXT_SENSOR("  ", "Version", version_);
  LOG_TEXT_SENSOR("  ", "State", state_txt_);
  LOG_TEXT_SENSOR("  ", "Boiler Time", boiler_time_txt_);
  LOG_TEXT_SENSOR("  ", "Pair Trace", pair_trace_txt_);
  LOG_TEXT_SENSOR("  ", "Last Disconnect", last_disconnect_txt_);
  LOG_SENSOR("  ", "Pair Attempts", pair_attempts_sensor_);
  LOG_SENSOR("  ", "Connect Count", connect_count_sensor_);
}

void SmartBoiler::loop() {
  this->process_command_queue_();
  // Event-driven: publishes only if a pairing event was recorded since the last loop.
  this->trace_publish_();
}

void SmartBoiler::send_to_boiler(SBProtocolRequest request) {
  this->last_command_timestamp_ = millis();
  // Do not log the raw PIN packet: DATA of a GLOBAL_PAIRPIN request contains the PIN.
  if (request.mRqType == SBPacket::SBC_PACKET_GLOBAL_PAIRPIN)
    ESP_LOGD(TAG, "Sending: REQ: %d DATA=[<pin redacted>]", request.mRqType);
  else
    ESP_LOGD(TAG, "Sending: REQ: %d DATA=[%s]", request.mRqType, format_hex_pretty(request.mData).c_str());
  auto status = esp_ble_gattc_write_char(this->parent_->get_gattc_if(), this->parent_->get_conn_id(),
                                         this->char_handle_, request.mData.size(), request.mData.data(),
                                         ESP_GATT_WRITE_TYPE_NO_RSP, ESP_GATT_AUTH_REQ_NONE);

  if (status)
    ESP_LOGW(TAG, "[%s] esp_ble_gattc_write_char failed, status=%d", this->parent_->address_str().c_str(), status);

  // Pairing diagnostics: when the packet was ACTUALLY written (after the queue delay).
  if (request.mRqType == SBPacket::SBC_PACKET_GLOBAL_PAIRPIN) {
    this->pin_tx_ms_ = this->last_command_timestamp_;
    this->pin_tx_valid_ = true;
    if (status)
      this->trace_("PIN_TX_ERR(%d)", (int) status);
    else
      this->trace_("PIN_TX");
  } else if (request.mRqType == SBPacket::SBC_PACKET_RQ_GLOBAL_MAC) {
    if (status)
      this->trace_("AUTH_TX_ERR(%d)", (int) status);
    else
      this->trace_("AUTH_TX");
  }
}

void SmartBoiler::on_set_temperature(uint8_t temp) {
  if (temp < MIN_TEMP || temp > MAX_TEMP) {
    ESP_LOGW(TAG, "Invalid set temperature: %d", temp);
    return;
  }
  auto cmd = SBProtocolRequest(SBC_PACKET_HOME_SETNORMALTEMPERATURE, this->mPacketUid++);
  cmd.write_le((uint32_t) temp);
  this->enqueue_command_(cmd);
}

void SmartBoiler::on_set_mode(const std::string &payload) {
  auto mode = this->convert_action_to_mode(payload);
  auto cmd = SBProtocolRequest(SBC_PACKET_HOME_SETMODE, this->mPacketUid++);
  cmd.write_le(uint32_t(mode));
  this->enqueue_command_(cmd);
}

void SmartBoiler::on_set_hdo_enabled(const std::string &payload) {
  auto hdoOpt = parse_number<int>(payload);
  if (!hdoOpt) {
    ESP_LOGW(TAG, "Invalid set_hdo_enabled value: %s", payload.c_str());
    return;
  }
  auto cmd = SBProtocolRequest(SBC_PACKET_HDO_SET_ONOFF, this->mPacketUid++);
  cmd.write_le(uint32_t(*hdoOpt ? 1 : 0));
  this->enqueue_command_(cmd);
}

void SmartBoiler::gattc_event_handler(esp_gattc_cb_event_t event, esp_gatt_if_t gattc_if,
                                      esp_ble_gattc_cb_param_t *param) {
  switch (event) {
    case ESP_GATTC_OPEN_EVT: {
      if (param->open.status == ESP_GATT_OK) {
        ESP_LOGI(TAG, "[%s] Connected", this->parent_->address_str().c_str());
        this->connect_count_++;
        this->connect_ms_ = millis();
        this->pin_tx_valid_ = false;
        if (this->connect_count_sensor_)
          this->connect_count_sensor_->publish_state((float) this->connect_count_);
        this->trace_("CONN#%u", (unsigned) this->connect_count_);
      } else {
        this->trace_("OPEN_FAIL(%d)", (int) param->open.status);
      }
      break;
    }
    case ESP_GATTC_DISCONNECT_EVT: {
      ESP_LOGI(TAG, "[%s] Disconnected", this->parent_->address_str().c_str());
      // Record BEFORE changing state so the state at the moment of disconnect is captured.
      int reason = (int) param->disconnect.reason;
      this->trace_("DISC(r=%d,%s)", reason, this->state_code_(this->state_));
      this->record_disconnect_(reason);
      this->set_state(ConnectionState::DISCONNECTED);
      break;
    }
    case ESP_GATTC_SEARCH_CMPL_EVT: {
      auto chr = this->parent_->get_characteristic(SB_MAIN_SERVICE_UUID, SB_MAIN_CHARACTERISTIC_UUID);
      if (chr == nullptr) {
        ESP_LOGE(TAG, "[%s] No main service found at device, not a SmartBoiler..?",
                 this->parent_->address_str().c_str());
        this->parent_->disconnect();
        break;
      }

      this->char_handle_ = chr->handle;

      chr = this->parent_->get_characteristic(SB_LOGGING_SERVICE_UUID, SB_LOGGING_CHARACTERISTIC_UUID);

      if (chr == nullptr) {
        ESP_LOGE(TAG, "[%s] No logging service found at device, not a SmartBoiler..?",
                 this->parent_->address_str().c_str());
        this->parent_->disconnect();
        break;
      }
      auto status = esp_ble_gattc_register_for_notify(this->parent()->get_gattc_if(), this->parent()->get_remote_bda(),
                                                      chr->handle);
      if (status) {
        ESP_LOGW(TAG, "esp_ble_gattc_register_for_notify failed, status=%d", status);
        break;
      }

      break;
    }
    case ESP_GATTC_REG_FOR_NOTIFY_EVT: {
      this->authenticate();
      break;
    }
    case ESP_GATTC_NOTIFY_EVT: {
      handle_incoming(param->notify.value, param->notify.value_len);
      break;
    }
    default:
      break;
  }
}

void SmartBoiler::authenticate() {
  ESP_LOGD(TAG, "Sending authentication request.");
  this->trace_("AUTH_Q");
  auto cmd = SBProtocolRequest(SBPacket::SBC_PACKET_RQ_GLOBAL_MAC);
  cmd.writeString(this->uid_);
  this->enqueue_command_(cmd);
  this->set_state(ConnectionState::AUTHENTICATING);
}

void SmartBoiler::getInitData() {
  ESP_LOGD(TAG, "Requesting initial data from water heater");
  this->request_value(SBPacket::SBC_PACKET_HOME_MODE);
  this->request_value(SBPacket::SBC_PACKET_HOME_BOILERMODEL);
  this->request_value(SBPacket::SBC_PACKET_HOME_CAPACITY);
  this->request_value(SBPacket::SBC_PACKET_HOME_BOILERNAME);
  this->request_value(SBPacket::SBC_PACKET_HOME_TEMPERATURE);
  this->request_value(SBPacket::SBC_PACKET_HOME_SENSOR1);
  this->request_value(SBPacket::SBC_PACKET_HOME_SENSOR2);
  this->request_value(SBPacket::SBC_PACKET_HOME_HSRCSTATE);
  this->request_value(SBPacket::SBC_PACKET_HDO_ONOFF);
  // LOCAL: boiler internal clock. getInitData() runs on every (re)connect, so
  // the time is refreshed whenever the link is (re)established after an outage,
  // and alongside the other data on a fresh read.
  this->request_value(SBPacket::SBC_PACKET_HOME_TIME);
}

void SmartBoiler::update() {
  if (this->state_ == ConnectionState::CONNECTED) {
    ESP_LOGD(TAG, "Requesting consumption");
    auto cmd = SBProtocolRequest(SBC_PACKET_STATISTICS_GETALL, this->mPacketUid++);
    this->enqueue_command_(cmd);
  }
}

void SmartBoiler::request_value(SBPacket sensor, uint16_t uid) {
  this->enqueue_command_(SBProtocolRequest(sensor, uid));
}

void SmartBoiler::handle_incoming(const uint8_t *value, uint16_t value_len) {
  auto result = SBProtocolResult(value, value_len);

  ESP_LOGD(TAG, "Received: REQ: %d DATA=[%s]", result.mRqType, format_hex_pretty(value, value_len).c_str());

  switch (result.mRqType) {
    case SBPacket::SBC_PACKET_GLOBAL_PAIRPIN: {
      ESP_LOGD(TAG, "PIN pairing required.");
      // water heater requires pairing of this client via PIN
      this->pair_attempts_++;
      if (this->pair_attempts_sensor_)
        this->pair_attempts_sensor_->publish_state((float) this->pair_attempts_);
      this->trace_("REQ_PIN");
      this->set_state(ConnectionState::NEED_PIN);
      break;
    }
    case SBPacket::SBC_PACKET_GLOBAL_CONFIRMUID: {
      // all setting commands are sent with unique packet UID and confirmation from BT
      // server is expected. Most of them contain no additional data, with exception of
      // SBC_PACKET_STATISTICS_GETALL
      ESP_LOGD(TAG, "Received confirmation for packet with UID: %0X", result.mUid);
      ESP_LOGD(TAG, "mByteData: DATA=[%s]", format_hex_pretty(result.mByteData).c_str());

      // find original request in queue of sent packets
      auto originalRequest = std::find_if(this->sent_queue_.begin(), this->sent_queue_.end(),
                                          [&](const SBProtocolRequest& req) { return req.mUid == result.mUid; });
      if (originalRequest != this->sent_queue_.end()) {
        ESP_LOGD(TAG, "original request was: %d", (*originalRequest).mRqType);
        // remove the sent request from the queue as it was sucessfully accepted
        this->sent_queue_.erase(originalRequest);

        // handle some special confirm packets
        switch ((*originalRequest).mRqType) {
          case SBPacket::SBC_PACKET_STATISTICS_GETALL: {
            uint32_t consumption = result.load_uint32_le(0);
            uint32_t timestamp = result.load_uint32_le(4);
            if (this->consumption_sensor_)
              this->consumption_sensor_->publish_state((float) consumption / 1000);
            break;
          }
          default:
            break;
        }
      }
      break;
    }
    case SBPacket::SBC_PACKET_GLOBAL_DEVICEBONDED: {
      ESP_LOGI(TAG, "Device is already paired with the water heater.");
      this->set_state(ConnectionState::CONNECTED);
      this->getInitData();
      break;
    }
    case SBPacket::SBC_PACKET_GLOBAL_PINRESULT: {
      auto pinResult = parse_number<int>(result.mString);
      {
        // Raw response, truncated to 6 chars and reduced to a safe charset.
        char raw[8];
        size_t r = 0;
        for (size_t i = 0; i < result.mString.size() && r < 6; i++) {
          char c = result.mString[i];
          bool ok = (c >= '0' && c <= '9') || (c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z') || c == '-' ||
                    c == '_' || c == '.';
          raw[r++] = ok ? c : '?';
        }
        raw[r] = '\0';
        this->trace_("RES=%s", r ? raw : "empty");
      }
      if (pinResult.has_value() && pinResult.value() == 1) {
        ESP_LOGI(TAG, "PIN is correct.");
        this->set_state(ConnectionState::CONNECTED);
        this->getInitData();
      } else {
        ESP_LOGW(TAG, "Wrong PIN provided, water heater response: %s", result.mString.c_str());
      }
      break;
    }

    case SBPacket::SBC_PACKET_HOME_FWVERSION: {
      // Format: firmware;board revision;serial number
      auto firstSemicol = result.mString.find(';');
      if (firstSemicol != std::string::npos) {
        auto secondSemicol = result.mString.find(';', firstSemicol + 1);
        if (secondSemicol != std::string::npos) {
          auto fwVersion = result.mString.substr(0, firstSemicol);
          auto boardRev = result.mString.substr(firstSemicol + 1, secondSemicol - firstSemicol - 1);
          auto serial = result.mString.substr(secondSemicol + 1);
          char buffer[100];
          sprintf(buffer, "fw:%s, board: %s, S/N: %s", fwVersion.c_str(), boardRev.c_str(), serial.c_str());
          std::string version(buffer);
          this->version_->publish_state(version);
          break;
        }
      }
      ESP_LOGW(TAG, "Bad FW info format: %s", result.mString.c_str());
      break;
    }

    case SBPacket::SBC_PACKET_HOME_MODE: {
      auto modeOpt = parse_number<int>(result.mString);
      if (!modeOpt.has_value()) {
        ESP_LOGW(TAG, "Bad mode string from water heater: %s", result.mString.c_str());
        break;
      }
      auto mode = modeOpt.value();
      auto modeAsString = convert_mode_to_action(mode);
      if (!modeAsString.empty()) {
        if (mode_select_)
          mode_select_->publish_state(modeAsString);
      } else
        ESP_LOGW(TAG, "Bad mode value from water heater: %d", mode);
      break;
    }
    case SBPacket::SBC_PACKET_HOME_TEMPERATURE: {
      auto tempOpt = parse_number<float>(result.mString);
      if (tempOpt.has_value()) {
        this->thermostat_->publish_target_temp(*tempOpt);
      }
      break;
    }
    case SBPacket::SBC_PACKET_HOME_SENSOR1: {
      auto tempOpt = parse_number<float>(result.mString);
      if (tempOpt.has_value()) {
        this->temperature_sensor_1_sensor_->publish_state(*tempOpt);
      }
      break;
    }
    case SBPacket::SBC_PACKET_HOME_SENSOR2: {
      auto tempOpt = parse_number<float>(result.mString);
      if (tempOpt.has_value()) {
        this->temperature_sensor_2_sensor_->publish_state(*tempOpt);
        if (this->thermostat_)
          this->thermostat_->publish_current_temp(*tempOpt);
      }
      break;
    }
    case SBPacket::SBC_PACKET_HOME_HSRCSTATE: {
      auto heatOpt = parse_number<int>(result.mString);
      auto is_heating = *heatOpt == 1;
      this->heat_on_sensor_->publish_state(is_heating);
      if (thermostat_)
        this->thermostat_->publish_action(is_heating);
      break;
    }
    case SBPacket::SBC_PACKET_HDO_ONOFF: {
      auto hdoOpt = parse_number<int>(result.mString);
      this->isHdoEnabled = hdoOpt.value() == 1;
      this->hdo_low_tariff_sensor_->publish_state(this->isHdoEnabled);
      ESP_LOGI(TAG, "Internal HDO decoder is %s.", this->isHdoEnabled ? "enabled" : "disabled");
      break;
    }
    case SBPacket::SBC_PACKET_HOME_BOILERNAME: {
      this->name_->publish_state(result.mString);
      break;
    }
    case SBPacket::SBC_PACKET_HOME_ERROR: {
      ESP_LOGW(TAG, "water heater indicates that the last request has failed");
      break;
    }
    case SBPacket::SBC_PACKET_HOME_TIME: {
      // time is in format D.HH:MM:SS
      // first byte is day
      auto day = result.mString[0] - '0';
      // the rest is a string
      auto time = result.mString.substr(2,8);
      char buffer[30];
      sprintf(buffer, "%s %s", this->day_to_string(day), time.c_str());
      ESP_LOGD(TAG, "Water heater internal time: %s", buffer);
      // LOCAL: publish so the PHP module can compare it with the server clock.
      // Stable, human-readable form "Day HH:MM:SS" (e.g. "Mon 14:03:27").
      if (this->boiler_time_txt_)
        this->boiler_time_txt_->publish_state(buffer);
      break;
    }
    default:
      break;
  }
}

void SmartBoilerModeSelect::control(const std::string &value) { get_parent()->on_set_mode(value); }

void SmartBoilerThermostat::control(const esphome::climate::ClimateCall &call) {
  if (call.get_target_temperature().has_value()) {
    float tt = *call.get_target_temperature();
    uint8_t target_temp = (uint8_t) tt;
    get_parent()->on_set_temperature(target_temp);
  }
}

esphome::climate::ClimateTraits SmartBoilerThermostat::traits() {
  esphome::climate::ClimateTraits rv;

  rv.set_visual_min_temperature(MIN_TEMP);
  rv.set_visual_max_temperature(MAX_TEMP);
  rv.set_visual_temperature_step(1);
  rv.set_supports_current_temperature(true);
  rv.set_supports_action(true);
  rv.set_supported_modes({climate::CLIMATE_MODE_OFF, climate::CLIMATE_MODE_HEAT});

  return rv;
}

void SmartBoilerThermostat::publish_target_temp(float temp) {
  this->target_temperature = temp;
  this->publish_state();
}

void SmartBoilerThermostat::publish_current_temp(float temp) {
  this->current_temperature = temp;
  this->publish_state();
}

void SmartBoilerThermostat::publish_action(bool heating) {
  if (get_parent()->mode_select_->state == "STOP")
    this->action = esphome::climate::CLIMATE_ACTION_OFF;
  else if (heating)
    this->action = esphome::climate::CLIMATE_ACTION_HEATING;
  else
    this->action = esphome::climate::CLIMATE_ACTION_IDLE;
  this->publish_state();
}

void SmartBoiler::enqueue_command_(const SBProtocolRequest &command) {
  this->command_queue_.push_back(command);
  this->process_command_queue_();
}

void SmartBoiler::process_command_queue_() {
  uint32_t now = millis();
  uint32_t cmdDelay = now - this->last_command_timestamp_;

  if (cmdDelay > COMMAND_DELAY && !this->command_queue_.empty()) {
    auto nextCmd = this->command_queue_.front();
    this->send_to_boiler(nextCmd);
    if (nextCmd.mUid) {
      this->sent_queue_.push_back(nextCmd);
    }
    this->command_queue_.erase(this->command_queue_.begin());
    ESP_LOGD(TAG, "Queue status - QUEUE=[%d], SENT_QUEUE=[%d]", this->command_queue_.size(), this->sent_queue_.size());
  }
}

/**
 * Generate random UUID for this device.
 */
std::string SmartBoiler::generateUUID() {
  char sbuf[16];
  md5::MD5Digest md5{};
  md5.init();
  sprintf(sbuf, "%08X", random_uint32());
  md5.add(sbuf, 8);
  md5.calculate();
  md5.get_hex(sbuf);
  ESP_LOGV(TAG, "Auth: Nonce is %s", sbuf);
  std::string s(sbuf);
  return s.substr(0, 6);
}

void SmartBoiler::send_pin(uint32_t pin) {
  ESP_LOGD(TAG, "Sending PIN to water heater.");
  this->trace_("PIN_Q");
  auto cmd = SBProtocolRequest(SBC_PACKET_GLOBAL_PAIRPIN, this->mPacketUid++);
  cmd.write_le(pin);
  this->enqueue_command_(cmd);
}

const char *SmartBoiler::state_to_string(ConnectionState state) {
  switch (state) {
    case ConnectionState::CONNECTED:
      return "Connected";
    case ConnectionState::NEED_PIN:
      return "Require PIN";
    case ConnectionState::AUTHENTICATING:
      return "Authenticating";
    case ConnectionState::DISCONNECTED:
      return "Disconnected";
    default:
      return "Unknown";
  }
}

const char *SmartBoiler::day_to_string(uint8_t day) {
  // 3-letter abbreviations: compact, stable, easy for the PHP module to parse.
  // Boiler day index is 0=Monday .. 6=Sunday.
  switch (day) {
    case 0:
      return "Mon";
    case 1:
      return "Tue";
    case 2:
      return "Wed";
    case 3:
      return "Thu";
    case 4:
      return "Fri";
    case 5:
      return "Sat";
    case 6:
      return "Sun";
    default:
      return "Unknown";
  }
}

void SmartBoiler::set_state(ConnectionState newState) {
  this->state_ = newState;
  this->state_txt_->publish_state(this->state_to_string(newState));
}

void SmartBoilerPinInput::control(const float value) {
  SmartBoiler *p = get_parent();
  if (p->state_ == ConnectionState::NEED_PIN) {
    uint32_t pin = floor(value);
    // Digit count only (leading zeros are not representable in the number); never the digits.
    unsigned digits = 1;
    for (uint32_t t = pin; t >= 10; t /= 10)
      digits++;
    p->trace_("PIN_RX(%ud)", digits);
    p->send_pin(pin);
  } else {
    ESP_LOGW(TAG, "Device is not in pairing mode, PIN change is ignored.");
    p->trace_("PIN_IGNORED(%s)", p->state_code_(p->state_));
  }
}

/**
 * Convert string value from HA to code used by water heaters.
 * Modes SMART and MANUAL are converted to SMART_HDO/HDO in case
 * the water heater is configured to use HDO internal decoder.
 */
uint8_t SmartBoiler::convert_action_to_mode(const std::string &payload) {
  uint8_t mode = Mode::STOP;
  auto modeStr = str_upper_case(payload);

  if (modeStr == MODE_ANTIFREEZE)
    mode = Mode::ANTIFREEZE;
  else if (modeStr == MODE_SMART)
    mode = this->isHdoEnabled ? Mode::SMART_HDO : Mode::SMART;
  else if (modeStr == MODE_PROG)
    mode = Mode::PROG;
  else if (modeStr == MODE_MANUAL)
    mode = this->isHdoEnabled ? Mode::HDO : Mode::MANUAL;
  else {
    ESP_LOGW(TAG, "Unknown water heater mode: %s", payload.c_str());
  }
  return mode;
}

std::string SmartBoiler::convert_mode_to_action(const uint8_t mode) {
  switch (mode) {
    case Mode::ANTIFREEZE:
      return MODE_ANTIFREEZE;
    case Mode::SMART:
      return MODE_SMART;
    case Mode::SMART_HDO:
      return MODE_SMART;
    case Mode::PROG:
      return MODE_PROG;
    case Mode::MANUAL:
      return MODE_MANUAL;
    case Mode::HDO:
      return MODE_MANUAL;
    default:
      ESP_LOGW(TAG, "Unknown water heater mode: %d", mode);
      break;
  }
  return MODE_ANTIFREEZE;
}

}  // namespace sb
}  // namespace esphome
