/*
 * Drazice SMART admin: AJAX tabs.
 *
 * The page is rendered by the server from the cached ESP state (instant paint,
 * works without JavaScript). This file then refreshes the live values through
 * the admin-only JSON ops of the module and submits mutating forms without a
 * page reload.
 *
 * Rules: ES5, no jQuery. All user-facing strings come from window.DS_I18N,
 * configuration from window.DS_CFG. Values coming from the server or the ESP
 * are rendered only with textContent / setAttribute / createElement.
 */
(function () {
  'use strict';

  var CFG = window.DS_CFG || {};
  var I18N = window.DS_I18N || {};
  var OPS = CFG.ops || {};
  var MUTATING_OPS = OPS.mutating || [];
  var INTERVALS = CFG.intervals || {};
  var STATUS_MS = INTERVALS.statusMs || 10000;
  var PAIRING_MS = INTERVALS.pairingMs || 2000;
  var IDLE_MS = INTERVALS.idleMs || 10000;
  var MAX_BACKOFF_MS = 60000;
  var READ_TIMEOUT_MS = 8000;
  var BLE_TIMEOUT_MS = 15000;
  var WRITE_TIMEOUT_MS = 30000;
  var TAB = CFG.tab || '';

  window.DraziceSmart = { version: CFG.version || '' };

  if (!window.XMLHttpRequest || !window.URLSearchParams || !window.FormData) {
    return;
  }

  // ---------------------------------------------------------------- helpers

  function t(key) {
    var v = I18N[key];
    return (typeof v === 'string') ? v : '';
  }

  function inList(list, value) {
    var i;
    for (i = 0; i < list.length; i++) {
      if (list[i] === value) {
        return true;
      }
    }
    return false;
  }

  function byId(id) {
    return document.getElementById(id);
  }

  function all(selector, root) {
    return (root || document).querySelectorAll(selector);
  }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) {
      n.setAttribute('class', cls);
    }
    if (text !== undefined && text !== null) {
      n.textContent = text;
    }
    return n;
  }

  function clear(node) {
    while (node && node.firstChild) {
      node.removeChild(node.firstChild);
    }
  }

  function isPrimitive(v) {
    return v === null || typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean';
  }

  // Text for one value. Missing data is shown as "unavailable", never as 0.
  function textOf(v) {
    if (v === null || v === undefined) {
      return t('UNAVAILABLE');
    }
    if (typeof v === 'boolean') {
      return v ? t('YES') : t('NO');
    }
    return String(v);
  }

  function safe(fn) {
    try {
      fn();
    } catch (e) {
      // A rendering problem must never break the page.
    }
  }

  // Same page URL (keeps the MajorDoMo routing params md/pd/inst) plus ajax=1.
  function url(extra) {
    var p = new URLSearchParams(window.location.search);
    var drop = ['ajax', 'op', 'force', 'csrf'];
    var i;
    var k;
    for (i = 0; i < drop.length; i++) {
      p.delete(drop[i]);
    }
    p.set('ajax', '1');
    if (extra) {
      for (k in extra) {
        if (Object.prototype.hasOwnProperty.call(extra, k)) {
          p.set(k, extra[k]);
        }
      }
    }
    return window.location.pathname + '?' + p.toString();
  }

  // cb(err, resp): err is null or {network: true}; resp is the parsed envelope.
  function call(op, params, method, timeoutMs, cb) {
    var xhr = new XMLHttpRequest();
    var isPost = (method === 'POST');
    var query = { op: op };
    var body = null;
    var done = false;

    function finish(err, resp) {
      if (done) {
        return;
      }
      done = true;
      safe(function () {
        cb(err, resp);
      });
    }

    if (isPost) {
      body = (params instanceof URLSearchParams) ? params : new URLSearchParams();
      if (!body.has('csrf')) {
        body.set('csrf', CFG.csrf || '');
      }
      body.set('op', op);
    } else if (params) {
      var k;
      for (k in params) {
        if (Object.prototype.hasOwnProperty.call(params, k)) {
          query[k] = params[k];
        }
      }
    }

    try {
      xhr.open(method, url(query), true);
      xhr.timeout = timeoutMs;
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      if (isPost) {
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
      }
    } catch (e) {
      finish({ network: true }, null);
      return;
    }

    xhr.onload = function () {
      var resp = null;
      try {
        resp = JSON.parse(xhr.responseText);
      } catch (e) {
        resp = null;
      }
      if (resp === null || typeof resp !== 'object') {
        finish({ network: true }, null);
        return;
      }
      finish(null, resp);
    };
    xhr.onerror = function () {
      finish({ network: true }, null);
    };
    xhr.ontimeout = function () {
      finish({ network: true }, null);
    };
    xhr.onabort = function () {
      finish({ network: true }, null);
    };

    try {
      xhr.send(isPost ? body.toString() : null);
    } catch (e2) {
      finish({ network: true }, null);
    }
  }

  // ---------------------------------------------------------------- spinner

  var pending = 0;

  function spinnerShow() {
    pending++;
    spinnerSync();
  }

  function spinnerHide() {
    pending = (pending > 0) ? pending - 1 : 0;
    spinnerSync();
  }

  function spinnerSync() {
    var nodes = all('.ds-spinner');
    var i;
    for (i = 0; i < nodes.length; i++) {
      nodes[i].hidden = (pending === 0);
    }
  }

  // ----------------------------------------------------------------- banner

  var bannerSource = '';

  function bannerClear() {
    var box = byId('ds-banner');
    clear(box);
    bannerSource = '';
  }

  // Returns the alert element so callers can append details to it.
  function bannerShow(kind, text, source) {
    var box = byId('ds-banner');
    if (!box) {
      return null;
    }
    var icons = {
      success: 'glyphicon glyphicon-ok-sign',
      danger: 'glyphicon glyphicon-remove-sign',
      warning: 'glyphicon glyphicon-alert',
      info: 'glyphicon glyphicon-info-sign'
    };
    if (!icons[kind]) {
      kind = 'info';
    }
    var alertEl = el('div', 'alert alert-' + kind);
    alertEl.setAttribute('role', (kind === 'danger') ? 'alert' : 'status');
    alertEl.appendChild(el('span', icons[kind]));
    alertEl.appendChild(document.createTextNode(' '));
    alertEl.appendChild(el('strong', '', text));
    clear(box);
    box.appendChild(alertEl);
    bannerSource = source || '';
    return alertEl;
  }

  // ------------------------------------------------------------------- meta

  var meta = null;

  function applyMeta(m) {
    if (!m || typeof m !== 'object') {
      return;
    }
    meta = {
      has: !!m.has_data,
      age: (typeof m.age_s === 'number') ? m.age_s : null,
      at: Date.now(),
      stale: !!m.stale,
      fetchOk: m.fetch_ok
    };
    renderMeta();
  }

  function setShown(node, shown) {
    if (node) {
      node.style.display = shown ? '' : 'none';
    }
  }

  function renderMeta() {
    var box = byId('ds-meta');
    if (!box || !meta) {
      return;
    }
    var ageEl = box.querySelector('[data-ds-meta="age"]');
    var text = '';
    if (meta.has && meta.age !== null) {
      var age = meta.age + Math.floor((Date.now() - meta.at) / 1000);
      text = t('DATA_UPDATED') + ' ' + age + ' ' + t('DATA_AGO');
    }
    if (ageEl) {
      ageEl.textContent = text;
    }
    setShown(box.querySelector('[data-ds-meta="stale"]'), meta.has && meta.stale);
    setShown(box.querySelector('[data-ds-meta="fail"]'), meta.fetchOk === false);
    setShown(box.querySelector('[data-ds-meta="none"]'), !meta.has);
  }

  // --------------------------------------------------------------- rendering

  function validKey(k) {
    return /^[A-Za-z0-9_]+$/.test(k);
  }

  // Raw firmware / planner codes -> [colour, i18n key]. Colour follows the
  // MEANING (green = fine, yellow = attention, red = real fault, grey = neutral),
  // never the element type. The raw code stays available as the tooltip.
  var STATE_MAP = {
    boiler: {
      'Connected': ['ok', 'STB_CONNECTED'],
      'Require PIN': ['warn', 'STB_REQUIRE_PIN'],
      'Authenticating': ['muted', 'STB_AUTH'],
      'Disconnected': ['bad', 'STB_DISCONNECTED']
    },
    guard: {
      'BOOT_SAFE': ['muted', 'STG_BOOT_SAFE'],
      'IDLE': ['ok', 'STG_IDLE'],
      'HEATING_ALLOWED': ['ok', 'STG_HEATING_ALLOWED'],
      'PAUSE_REQUESTED': ['warn', 'STG_PAUSE_REQUESTED'],
      'COOLDOWN': ['warn', 'STG_COOLDOWN'],
      'LEASE_EXPIRED': ['muted', 'STG_LEASE_EXPIRED'],
      'DISCONNECTED': ['bad', 'STG_DISCONNECTED'],
      'FAULT': ['bad', 'STG_FAULT']
    },
    reason: {
      'boot_safe': ['plain', 'STR_BOOT_SAFE'],
      'idle_after_boot': ['plain', 'STR_IDLE_AFTER_BOOT'],
      'lease_expired_no_new_heating': ['plain', 'STR_LEASE_EXPIRED'],
      'lease_renewed': ['plain', 'STR_LEASE_RENEWED'],
      'cooldown_complete': ['plain', 'STR_COOLDOWN_COMPLETE'],
      'max_continuous_heat_reached': ['plain', 'STR_MAX_HEAT'],
      'heat_not_stopped_in_cooldown': ['plain', 'STR_HEAT_NOT_STOPPED'],
      'cooldown_restored_full_after_reboot': ['plain', 'STR_COOLDOWN_RESTORED'],
      'ble_reconnect_requested': ['plain', 'STR_BLE_RECONNECT']
    },
    action: {
      'heat': ['ok', 'ACT_HEAT'],
      'hold': ['muted', 'ACT_HOLD']
    }
  };

  function trimText(s) {
    return String(s === null || s === undefined ? '' : s).replace(/^\s+|\s+$/g, '');
  }

  function decorateState(node) {
    var kind = node.getAttribute('data-ds-st');
    var raw = trimText(node.textContent);
    var entry;
    var label;
    if (raw !== '' && raw === node.getAttribute('data-ds-label')) {
      return; // already decorated, nothing new written
    }
    entry = (STATE_MAP[kind] || {})[raw];
    label = (entry && t(entry[1])) ? t(entry[1]) : raw;
    node.textContent = label;
    node.setAttribute('data-ds-label', label);
    if (raw !== '' && raw !== label) {
      node.setAttribute('title', raw);
    }
    node.setAttribute('class', 'ds-st ds-st-' + (entry ? entry[0] : (kind === 'reason' ? 'plain' : 'muted')));
  }

  function decorateAll() {
    var nodes = all('[data-ds-st]');
    var i;
    for (i = 0; i < nodes.length; i++) {
      decorateState(nodes[i]);
    }
  }

  // Writes every primitive of data into the nodes tagged with attr="key".
  function applyData(attr, data) {
    var k;
    var nodes;
    var i;
    if (!data || typeof data !== 'object') {
      return;
    }
    for (k in data) {
      if (Object.prototype.hasOwnProperty.call(data, k) && validKey(k) && isPrimitive(data[k])) {
        nodes = all('[' + attr + '="' + k + '"]');
        for (i = 0; i < nodes.length; i++) {
          nodes[i].textContent = textOf(data[k]);
          if (nodes[i].hasAttribute('data-ds-st')) {
            nodes[i].removeAttribute('data-ds-label');
            decorateState(nodes[i]);
          }
        }
      }
    }
  }

  function renderStatus(d) {
    var nodes;
    var i;
    applyData('data-ds', d);
    nodes = all('[data-ds-conn]');
    for (i = 0; i < nodes.length; i++) {
      setShown(nodes[i], nodes[i].getAttribute('data-ds-conn') === d.conn);
    }
  }

  function renderPairing(d) {
    var badges = all('[data-ds-pairbadge]');
    var kind = 'other';
    var i;
    var cc = all('[data-ds-pair-cc]');
    var shown = {};
    var k;
    // connect_count is '' when unknown (hidden-field contract); the visible
    // value must then read "unavailable", never blank or 0.
    for (k in d) {
      if (Object.prototype.hasOwnProperty.call(d, k)) {
        shown[k] = d[k];
      }
    }
    if (shown.connect_count === '') {
      shown.connect_count = null;
    }
    applyData('data-ds', shown);
    if (d.boiler_state === 'Require PIN') {
      kind = 'req';
    } else if (d.boiler_state === 'Connected') {
      kind = 'ok';
    }
    for (i = 0; i < badges.length; i++) {
      setShown(badges[i], badges[i].getAttribute('data-ds-pairbadge') === kind);
    }
    setShown(byId('ds-pair-notrace'), d.has_trace === false);
    // The stale-PIN baseline is what the user SAW when he started typing. Once
    // the PIN field has been focused the baseline is frozen: polling must not
    // overwrite it, otherwise a reconnect during typing is never caught.
    if (pinBaseline === null) {
      if (typeof d.connect_count === 'string') {
        for (i = 0; i < cc.length; i++) {
          cc[i].value = d.connect_count;
        }
      }
      pinStaleShow(false);
    } else {
      pinStaleShow(typeof d.connect_count === 'string' && d.connect_count !== '' && d.connect_count !== pinBaseline);
    }
  }

  // ------------------------------------------------- stale-PIN baseline

  var pinBaseline = null;

  function pinStaleShow(on) {
    var box = byId('ds-banner');
    var node = byId('ds-pin-stale');
    var warn;
    if (!on) {
      if (node && node.parentNode) {
        node.parentNode.removeChild(node);
      }
      return;
    }
    if (node || !box) {
      return;
    }
    warn = el('div', 'alert alert-warning');
    warn.id = 'ds-pin-stale';
    warn.setAttribute('role', 'alert');
    warn.appendChild(el('span', 'glyphicon glyphicon-alert'));
    warn.appendChild(document.createTextNode(' ' + t('PAIR_STALE_WARN')));
    box.insertBefore(warn, box.firstChild);
  }

  function pinFreeze() {
    var cc = all('[data-ds-pair-cc]');
    if (pinBaseline === null && cc.length > 0) {
      pinBaseline = String(cc[0].value);
    }
  }

  function pinBaselineReset() {
    pinBaseline = null;
    pinStaleShow(false);
  }

  document.addEventListener('focusin', function (ev) {
    if (ev.target && ev.target.name === 'pin') {
      pinFreeze();
    }
  });
  document.addEventListener('input', function (ev) {
    if (ev.target && ev.target.name === 'pin') {
      pinFreeze();
      if (ev.target.value === '') {
        pinBaselineReset();
      }
    }
  });

  function renderDiag(d) {
    applyData('data-ds-dg', d.dg);
    if (d.pair) {
      applyData('data-ds', d.pair);
    }
  }

  function renderPolicy(d) {
    var fc = d.forecast || {};
    var nodes = all('[data-ds-fc]');
    var i;
    var key;
    applyData('data-ds', { today_f: d.today_f, tomorrow_f: d.tomorrow_f });
    for (i = 0; i < nodes.length; i++) {
      key = nodes[i].getAttribute('data-ds-fc');
      nodes[i].textContent = textOf(isPrimitive(fc[key]) ? fc[key] : null);
    }
    setShown(byId('ds-fc-ok'), !!fc.available);
    setShown(byId('ds-fc-none'), !fc.available);
  }

  function fillRows(tableId, rows) {
    var table = byId(tableId);
    var body = table ? table.querySelector('tbody') : null;
    var i;
    var j;
    var tr;
    var td;
    var cell;
    if (!body) {
      return;
    }
    clear(body);
    for (i = 0; i < rows.length; i++) {
      tr = el('tr');
      for (j = 0; j < rows[i].length; j++) {
        cell = rows[i][j];
        td = el('td');
        if (cell.code) {
          td.appendChild(el('code', '', cell.text));
        } else {
          td.textContent = cell.text;
        }
        tr.appendChild(td);
      }
      body.appendChild(tr);
    }
  }

  function renderLog(d) {
    var dec = [];
    var cmd = [];
    var i;
    var a = d.decisions || [];
    var c = d.commands || [];
    for (i = 0; i < a.length; i++) {
      dec.push([{ text: textOf(a[i].time) }, { text: textOf(a[i].action), code: true }, { text: textOf(a[i].reason) }]);
    }
    for (i = 0; i < c.length; i++) {
      cmd.push([{ text: textOf(c[i].time) }, { text: textOf(c[i].kind) }, { text: textOf(c[i].status) }]);
    }
    fillRows('ds-log-decisions', dec);
    fillRows('ds-log-commands', cmd);
  }

  // ------------------------------------------------------------- BLE / scan

  function selectBoiler(mac, button) {
    var params = new URLSearchParams();
    params.set('mac', mac);
    sendMutation('select_boiler', params, button, null);
  }

  function renderBle(d) {
    var box = byId('ds-ble-result');
    var list = d.list || [];
    var table;
    var head;
    var body;
    var tr;
    var td;
    var btn;
    var i;
    var item;
    clear(box);
    setShown(byId('ds-ble-server'), false);
    if (!box) {
      return;
    }
    if (!d.available) {
      box.appendChild(el('div', 'alert alert-info', t('BLE_UNAVAILABLE')));
      return;
    }
    if (d.selected_mac) {
      var sel = el('p');
      sel.appendChild(document.createTextNode(t('BLE_SELECTED') + ': '));
      sel.appendChild(el('code', '', d.selected_mac));
      box.appendChild(sel);
    }
    if (list.length === 0) {
      box.appendChild(el('div', 'alert alert-info', t('BLE_EMPTY')));
      return;
    }
    table = el('table', 'table table-condensed table-striped');
    head = el('thead');
    tr = el('tr');
    tr.appendChild(el('th', '', t('BLE_MAC')));
    tr.appendChild(el('th', '', t('BLE_NAME')));
    tr.appendChild(el('th', '', t('BLE_RSSI')));
    tr.appendChild(el('th', ''));
    head.appendChild(tr);
    table.appendChild(head);
    body = el('tbody');
    for (i = 0; i < list.length; i++) {
      item = list[i];
      tr = el('tr', item.boiler ? 'success' : '');
      td = el('td');
      td.appendChild(el('code', '', textOf(item.mac)));
      if (item.boiler) {
        td.appendChild(document.createTextNode(' '));
        td.appendChild(el('span', 'label label-success', t('BLE_IS_BOILER')));
      }
      tr.appendChild(td);
      tr.appendChild(el('td', '', textOf(item.name)));
      tr.appendChild(el('td', '', (typeof item.rssi === 'number') ? item.rssi + ' ' + t('UNIT_DBM') : t('UNAVAILABLE')));
      td = el('td');
      btn = el('button', 'btn btn-xs btn-primary', t('BLE_CHOOSE'));
      btn.setAttribute('type', 'button');
      (function (mac, button) {
        button.addEventListener('click', function () {
          selectBoiler(mac, button);
        });
      }(String(item.mac), btn));
      td.appendChild(btn);
      tr.appendChild(td);
      body.appendChild(tr);
    }
    table.appendChild(body);
    box.appendChild(table);
  }

  function renderScan(devices) {
    var box = byId('ds-scan-result');
    var table;
    var body;
    var tr;
    var td;
    var i;
    clear(box);
    if (!box || !devices || devices.length === 0) {
      return;
    }
    table = el('table', 'table table-condensed');
    body = el('tbody');
    for (i = 0; i < devices.length; i++) {
      tr = el('tr');
      td = el('td');
      td.appendChild(el('code', '', textOf(devices[i].host)));
      tr.appendChild(td);
      td = el('td');
      td.appendChild(el('span', devices[i].is_boiler ? 'label label-success' : 'label label-default',
        devices[i].is_boiler ? t('SCAN_IS_BOILER') : t('SCAN_ESPHOME')));
      tr.appendChild(td);
      body.appendChild(tr);
    }
    table.appendChild(body);
    box.appendChild(table);
  }

  // ------------------------------------------------------------- read loads

  // Each loader: (force, done) and calls done(true|false) when finished.
  function readOp(op, force, timeoutMs, render, done) {
    var params = force ? { force: '1' } : null;
    spinnerShow();
    call(op, params, 'GET', timeoutMs, function (err, resp) {
      spinnerHide();
      if (err || !resp || resp.ok !== true || !resp.data) {
        bannerShow('danger', t('FETCH_ERROR'), 'fetch');
        if (done) {
          done(false);
        }
        return;
      }
      if (bannerSource === 'fetch') {
        bannerClear();
      }
      applyMeta(resp.meta);
      safe(function () {
        render(resp.data);
      });
      if (done) {
        done(true, resp.data);
      }
    });
  }

  function loadStatus(force, done) {
    readOp('status', force, READ_TIMEOUT_MS, renderStatus, done);
  }

  function loadPairing(force, done) {
    // Pairing is polled fast, so every poll asks for a fresh ESP read.
    readOp('pairing', true, READ_TIMEOUT_MS, renderPairing, done);
  }

  function loadDiag(force, done) {
    readOp('diag', force, READ_TIMEOUT_MS, renderDiag, done);
  }

  function loadPolicy(force, done) {
    readOp('policy', false, READ_TIMEOUT_MS, renderPolicy, done);
  }

  function loadLog(force, done) {
    readOp('log', false, READ_TIMEOUT_MS, renderLog, done);
  }

  function loadMetaOnly(force, done) {
    readOp('status', force, READ_TIMEOUT_MS, function () {}, done);
  }

  function loaderForTab() {
    if (TAB === 'status') {
      return loadStatus;
    }
    if (TAB === 'setup') {
      return loadPairing;
    }
    if (TAB === 'diag') {
      return loadDiag;
    }
    if (TAB === 'policy') {
      return loadPolicy;
    }
    if (TAB === 'log') {
      return loadLog;
    }
    return loadMetaOnly;
  }

  // ---------------------------------------------------------------- polling

  // Polls loader(force, done) while the page is visible. delayFn(data) gives
  // the base delay; failures back off exponentially up to 60 s.
  function startPolling(loader, delayFn) {
    var timer = null;
    var failures = 0;
    var running = false;
    var lastData = null;

    function next() {
      var base = delayFn(lastData);
      var ms = base;
      if (failures > 0) {
        ms = Math.min(MAX_BACKOFF_MS, base * Math.pow(2, failures));
      }
      clearTimeout(timer);
      timer = setTimeout(run, ms);
    }

    function run() {
      clearTimeout(timer);
      timer = null;
      if (document.hidden || running) {
        return;
      }
      running = true;
      loader(false, function (ok, data) {
        running = false;
        failures = ok ? 0 : failures + 1;
        if (ok) {
          lastData = data;
        }
        if (!document.hidden) {
          next();
        }
      });
    }

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) {
        run();
      } else {
        clearTimeout(timer);
        timer = null;
      }
    });

    run();
  }

  // -------------------------------------------------------------- mutations

  function pinTraceText(arr) {
    if (!arr || typeof arr.length !== 'number') {
      return textOf(null);
    }
    var parts = [];
    var i;
    for (i = 0; i < arr.length; i++) {
      parts.push(textOf(arr[i]));
    }
    return parts.length ? parts.join(' | ') : textOf(null);
  }

  function disconnectText(obj) {
    var parts = [];
    var k;
    // send_pin returns the raw "Boiler Last Disconnect" text; the pairing op
    // returns it parsed into an object.
    if (typeof obj === 'string') {
      return (obj !== '' && obj !== '-') ? obj : textOf(null);
    }
    if (obj && typeof obj === 'object') {
      for (k in obj) {
        if (Object.prototype.hasOwnProperty.call(obj, k) && isPrimitive(obj[k])) {
          parts.push(k + '=' + textOf(obj[k]));
        }
      }
    }
    return parts.length ? parts.join(' ') : textOf(null);
  }

  function showPinTrace(result) {
    var box = byId('ds-pin-trace');
    var nodes;
    var i;
    if (!box) {
      return;
    }
    nodes = all('[data-ds-pin]', box);
    for (i = 0; i < nodes.length; i++) {
      if (nodes[i].getAttribute('data-ds-pin') === 'trace_tail') {
        nodes[i].textContent = pinTraceText(result.trace_tail);
      } else {
        nodes[i].textContent = disconnectText(result.last_disconnect);
      }
    }
    box.style.display = 'block';
  }

  function writeBackRejected(form, rejected) {
    var name;
    var field;
    if (!form || !rejected || typeof rejected !== 'object') {
      return;
    }
    for (name in rejected) {
      if (Object.prototype.hasOwnProperty.call(rejected, name) && name !== 'ESP_PASS' && isPrimitive(rejected[name])) {
        field = form.elements[name];
        if (field && field.type !== 'password' && field.type !== 'checkbox' && field.type !== 'hidden') {
          field.value = (rejected[name] === null) ? '' : String(rejected[name]);
        }
      }
    }
  }

  function bannerFromEnvelope(resp, fallbackKind) {
    var b = resp.banner || {};
    var kind = b.kind || fallbackKind;
    var key = b.key || ((kind === 'danger') ? 'FETCH_ERROR' : 'SAVE_OK');
    return bannerShow(kind, t(key), '');
  }

  function failureBanner(resp) {
    if (resp.error === 'csrf') {
      bannerShow('danger', t('ERROR_CSRF'), '');
    } else if (resp.error === 'bad_input' && resp.banner) {
      bannerFromEnvelope(resp, 'warning');
    } else {
      bannerShow('danger', t('FETCH_ERROR'), '');
    }
  }

  // ------------------------------------------------- invalid field highlight

  // The input named `name`: inside the submitted form first, else anywhere on the page.
  function findField(form, name) {
    var f = null;
    if (!name) {
      return null;
    }
    if (form && form.elements) {
      f = form.elements[name] || null;
      if (f && f.length !== undefined && !f.tagName) {
        f = f[0] || null; // RadioNodeList
      }
    }
    if (!f) {
      f = document.querySelector('[name="' + name.replace(/["\\]/g, '') + '"]');
    }
    return f;
  }

  // Nearest .form-group (or the direct parent) of a field.
  function fieldGroup(f) {
    var n = f;
    while (n && n !== document.body) {
      if (n.className && (' ' + n.className + ' ').indexOf(' form-group ') !== -1) {
        return n;
      }
      n = n.parentNode;
    }
    return f ? f.parentNode : null;
  }

  // Human label of a field (text of its <label>), '' if none.
  function fieldLabel(f) {
    var g = fieldGroup(f);
    var lab = g ? g.querySelector('label') : null;
    var s = lab ? String(lab.textContent || '').replace(/\s+/g, ' ').trim() : '';
    return s.replace(/[:\s]+$/, '');
  }

  function markFieldError(f) {
    var g = fieldGroup(f);
    if (g && (' ' + g.className + ' ').indexOf(' has-error ') === -1) {
      g.className = (g.className ? g.className + ' ' : '') + 'has-error ds-field-error';
    }
    f.setAttribute('aria-invalid', 'true');
    if (!f.getAttribute('data-ds-err-bound')) {
      f.setAttribute('data-ds-err-bound', '1');
      var off = function () { unmarkFieldError(f); };
      f.addEventListener('input', off);
      f.addEventListener('change', off);
    }
  }

  function unmarkFieldError(f) {
    var g = fieldGroup(f);
    if (g && g.className) {
      g.className = (' ' + g.className + ' ').replace(/ has-error /g, ' ').replace(/ ds-field-error /g, ' ').trim();
    }
    f.removeAttribute('aria-invalid');
  }

  function clearFieldErrors(root) {
    var nodes = all('[aria-invalid="true"]', root || document);
    var i;
    for (i = 0; i < nodes.length; i++) {
      unmarkFieldError(nodes[i]);
    }
  }

  function revealField(f) {
    if (!f) {
      return;
    }
    if (f.scrollIntoView) {
      f.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
    try { f.focus({ preventScroll: true }); } catch (e) { f.focus(); }
  }

  // One banner line: clickable field label + technical name + reason + what was entered.
  function fillErrorItem(li, form, name, reason, entered) {
    var f = findField(form, name);
    var label = f ? fieldLabel(f) : '';
    var a;
    if (f) {
      markFieldError(f);
      a = el('a', '', label || name);
      a.setAttribute('href', '#');
      a.style.fontWeight = 'bold';
      a.addEventListener('click', function (ev) {
        ev.preventDefault();
        revealField(f);
      });
      li.appendChild(a);
      if (label) {
        li.appendChild(document.createTextNode(' '));
        li.appendChild(el('code', '', name));
      }
    } else {
      li.appendChild(el('code', '', name));
    }
    li.appendChild(document.createTextNode(' - ' + reason));
    if (entered !== undefined) {
      li.appendChild(document.createTextNode(' (' + t('SAVE_ENTERED') + ' '));
      li.appendChild(el('code', '', (entered === null || entered === '') ? t('SAVE_EMPTY') : String(entered)));
      li.appendChild(document.createTextNode(')'));
    }
  }

  function focusFirstError(form, name) {
    revealField(findField(form, name));
  }

  // Server-rendered (non-AJAX) error banner: <li data-ds-err-field="NAME">.
  function decorateServerErrors() {
    var items = all('[data-ds-err-field]');
    var i;
    var first = '';
    var li;
    var name;
    var reason;
    for (i = 0; i < items.length; i++) {
      li = items[i];
      name = li.getAttribute('data-ds-err-field');
      reason = li.getAttribute('data-ds-err-reason') || '';
      clear(li);
      fillErrorItem(li, null, name, reason, undefined);
      if (!first) {
        first = name;
      }
    }
    if (first) {
      focusFirstError(null, first);
    }
  }

  // <input type="checkbox" data-ds-toggles="ID">: the <fieldset id="ID"> is
  // disabled while the box is unchecked. Disabled inputs are not submitted, so
  // the stored values stay as they were and cannot fail validation.
  function bindToggles() {
    var boxes = all('input[type="checkbox"][data-ds-toggles]');
    var i;
    for (i = 0; i < boxes.length; i++) {
      (function (box) {
        var target = byId(box.getAttribute('data-ds-toggles'));
        if (!target) {
          return;
        }
        var sync = function () {
          target.disabled = !box.checked;
          if (target.disabled) {
            clearFieldErrors(target);
          }
        };
        box.addEventListener('change', sync);
        sync();
      })(boxes[i]);
    }
  }

  var RELOAD_OPS = ['enable_automation', 'disable_automation', 'set_dry_run'];

  function handleMutation(op, form, resp) {
    var result = resp.result || {};
    var alertEl;
    var list;
    var ul;
    var li;
    var i;

    if (resp.ok !== true) {
      failureBanner(resp);
      if (resp.result) {
        writeBackRejected(form, resp.result.rejected);
      }
      return;
    }
    applyMeta(resp.meta);

    if (op === 'save_settings') {
      writeBackRejected(form, result.rejected);
      clearFieldErrors(form);
      list = result.errors || [];
      if (list.length > 0) {
        alertEl = bannerShow('danger', t('SAVE_FAIL'), '');
        if (alertEl) {
          ul = el('ul');
          ul.style.margin = '6px 0 0 0';
          for (i = 0; i < list.length; i++) {
            li = el('li');
            fillErrorItem(li, form, String(list[i].field), list[i].reason === 'json' ? t('SAVE_ERR_JSON') : t('SAVE_ERR_NUM'),
              (result.rejected && isPrimitive(result.rejected[list[i].field])) ? result.rejected[list[i].field] : undefined);
            ul.appendChild(li);
          }
          alertEl.appendChild(ul);
        }
        focusFirstError(form, list.length ? String(list[0].field) : '');
        return;
      }
      bannerShow('success', t('SAVE_OK'), '');
      return;
    }

    alertEl = bannerFromEnvelope(resp, 'success');

    if (op === 'send_pin') {
      if (result.stale) {
        var warn = el('div', 'alert alert-warning');
        warn.setAttribute('role', 'alert');
        warn.appendChild(el('span', 'glyphicon glyphicon-alert'));
        warn.appendChild(document.createTextNode(' ' + t('PAIR_STALE_WARN')));
        var box = byId('ds-banner');
        if (box) {
          box.insertBefore(warn, box.firstChild);
        }
      }
      showPinTrace(result);
      return;
    }

    if (op === 'probe_host' && alertEl && result.host) {
      alertEl.appendChild(document.createTextNode(' '));
      alertEl.appendChild(el('code', '', String(result.host)));
      return;
    }

    if (op === 'scan_network') {
      renderScan(result.devices);
    }
  }

  // Sends one mutating op. Never re-sends on a network error: the action may
  // already have been executed (a PIN must not be sent twice).
  function sendMutation(op, params, button, form) {
    var timeout = WRITE_TIMEOUT_MS;
    if (button) {
      button.disabled = true;
    }
    bannerShow('info', t('SAVING'), 'saving');
    spinnerShow();
    call(op, params, 'POST', timeout, function (err, resp) {
      spinnerHide();
      if (button) {
        button.disabled = false;
      }
      if (err || !resp) {
        bannerShow('danger', t('AJAX_NETWORK_ERROR'), '');
        return;
      }
      handleMutation(op, form, resp);
      if (resp.ok === true) {
        if (inList(RELOAD_OPS, op)) {
          // These ops swap whole server-rendered blocks: reload to show them.
          window.location.reload();
          return;
        }
        if (op === 'provision_simple_devices') {
          // The link-status table is server-rendered: reload after the banner
          // has been visible for a moment.
          window.setTimeout(function () { window.location.reload(); }, 1500);
          return;
        }
        if (op === 'send_pin' && form) {
          var pin = form.elements.pin;
          if (pin) {
            pin.value = '';
          }
          pinBaselineReset();
          loadPairing(true, null);
          return;
        }
        loaderForTab()(true, null);
      }
    });
  }

  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    var opField;
    var params;
    var button;
    if (!form || form.tagName !== 'FORM') {
      return;
    }
    opField = form.elements ? form.elements.op : null;
    if (!opField || typeof opField.value !== 'string' || !inList(MUTATING_OPS, opField.value)) {
      return;
    }
    try {
      params = new URLSearchParams(new FormData(form));
    } catch (e) {
      return; // Unsupported browser: native POST (no-JS fallback).
    }
    ev.preventDefault();
    button = ev.submitter || form.querySelector('button[type="submit"], input[type="submit"]');
    sendMutation(opField.value, params, button, form);
  });

  // ------------------------------------------------------------------ start

  function start() {
    var refresh = byId('ds-refresh');
    var bleBtn = byId('ds-ble-scan');
    var loader = loaderForTab();

    decorateAll(); // server-rendered raw state codes -> translated badges
    decorateServerErrors(); // highlight fields named in a server-rendered save error
    bindToggles(); // master checkboxes that enable/disable a whole block

    if (refresh) {
      refresh.addEventListener('click', function () {
        refresh.disabled = true;
        loader(true, function () {
          refresh.disabled = false;
        });
      });
    }

    setInterval(renderMeta, 1000);

    if (bleBtn) {
      bleBtn.addEventListener('click', function () {
        bleBtn.disabled = true;
        spinnerShow();
        call('ble_scan', null, 'GET', BLE_TIMEOUT_MS, function (err, resp) {
          spinnerHide();
          bleBtn.disabled = false;
          if (err || !resp || resp.ok !== true || !resp.data) {
            bannerShow('danger', t('FETCH_ERROR'), 'fetch');
            return;
          }
          if (bannerSource === 'fetch') {
            bannerClear();
          }
          applyMeta(resp.meta);
          safe(function () {
            renderBle(resp.data);
          });
        });
      });
    }

    if (TAB === 'status') {
      startPolling(loadStatus, function () {
        return STATUS_MS;
      });
    } else if (TAB === 'setup') {
      startPolling(loadPairing, function (data) {
        return (data && data.require_pin === true) ? PAIRING_MS : IDLE_MS;
      });
    } else if (TAB === 'diag' || TAB === 'policy' || TAB === 'log') {
      loader(false, null);
    }
    // control and advanced: no automatic ESP call.
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
}());
