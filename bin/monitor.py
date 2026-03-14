#!/usr/bin/env python3
"""
monitor.py — Main daemon for Canton Smart Soundbar LoxBerry plugin.

Polls the soundbar every POLL_INTERVAL seconds via the LibreKNX HTTP API
(port 1904) and publishes state to MQTT. Handles commands from Loxone.

API used:
  GET  http://<IP>:1904/canton?action=status       → Volume, MuteStatus, PlayStatus
  GET  http://<IP>:1904/canton?action=powerstatus  → PowerStatus: ON | STANDBY
  GET  http://<IP>:1904/canton?action=input        → InputSource: N
  POST http://<IP>:1904/canton?action=mute         body: {"mute": true/false}
  POST http://<IP>:1904/canton?action=volume       body: {"volume": 0-100}
  POST http://<IP>:1904/canton?action=standby      body: {"standby": true}  (to verify)
  POST http://<IP>:1904/canton?action=input        body: {"inputsource": "N"} (to verify)

Wake-on-LAN is used for power_on when soundbar is in standby.

Usage:
    python3 monitor.py --config /path/to/cantonbar.cfg --logfile /path/to/monitor.log
"""

import argparse
import configparser
import json
import logging
import os
import signal
import shutil
import subprocess
import sys
import threading
import time
from logging.handlers import RotatingFileHandler

import requests

try:
    import paho.mqtt.client as mqtt
except ImportError:
    print("ERROR: paho-mqtt not installed. Run: pip3 install paho-mqtt")
    sys.exit(1)

try:
    import wakeonlan
except ImportError:
    print("ERROR: wakeonlan not installed. Run: pip3 install wakeonlan")
    sys.exit(1)


# ---------------------------------------------------------------------------
# Globals
# ---------------------------------------------------------------------------

log = logging.getLogger("cantonbar")

_config: configparser.ConfigParser = None
_mqtt_client: mqtt.Client = None
_shutdown = threading.Event()
_api_lock = threading.Lock()

# Health / recovery runtime state
_api_fail_streak = 0
_last_recovery_ts = 0.0
_health = {
    "api": "unknown",
    "adb": "unknown",
    "libreknx": "unknown",
    "token": "unknown",
    "token_value": "",
}
_last_health_pub = {
    "api": "",
    "adb": "",
    "libreknx": "",
    "token": "",
}

# Last published values — only publish on change
_last_state: str = ""
_last_volume: str = ""
_last_mute: str = ""
_last_input: str = ""
_volume_override: int | None = None
_volume_override_ts: float = 0.0
_trace_seq = 0


# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------

def setup_logging(logfile: str, loglevel: int) -> None:
    level_map = {1: logging.CRITICAL, 2: logging.ERROR, 3: logging.WARNING,
                 4: logging.INFO, 5: logging.DEBUG, 6: logging.DEBUG}
    level = level_map.get(loglevel, logging.INFO)

    fmt = logging.Formatter("%(asctime)s [%(levelname)s] %(message)s",
                             datefmt="%Y-%m-%d %H:%M:%S")

    os.makedirs(os.path.dirname(logfile), exist_ok=True)
    fh = RotatingFileHandler(logfile, maxBytes=5 * 1024 * 1024, backupCount=3)
    fh.setFormatter(fmt)

    sh = logging.StreamHandler(sys.stdout)
    sh.setFormatter(fmt)

    log.setLevel(level)
    log.addHandler(fh)
    log.addHandler(sh)


# ---------------------------------------------------------------------------
# Soundbar HTTP API
# ---------------------------------------------------------------------------

def _api_url() -> str:
    ip = _config.get("SOUNDBAR", "IP")
    return f"http://{ip}:1904/canton"


def api_get(action: str, timeout: int = 4) -> dict:
    """GET /canton?action=ACTION. Returns parsed JSON dict or {} on error."""
    with _api_lock:
        try:
            r = requests.get(_api_url(), params={"action": action}, timeout=timeout)
            r.raise_for_status()
            data = r.json()
            log.debug(f"GET {action}: {data}")
            return data
        except requests.exceptions.JSONDecodeError:
            body = (r.text or "").strip()[:200] if 'r' in locals() else ""
            log.warning(f"GET {action} returned non-JSON (HTTP {getattr(r, 'status_code', '?')}): {body!r}")
            _trace_publish("error", _trace_payload("api_get_non_json", action=action, http=getattr(r, "status_code", "?"), body=body), retain=True)
            return {}
        except Exception as e:
            log.warning(f"GET {action} failed: {type(e).__name__}: {e}")
            _trace_publish("error", _trace_payload("api_get_failed", action=action, error=f"{type(e).__name__}: {e}"), retain=True)
            return {}


def api_post(action: str, body: dict, timeout: int = 4) -> dict:
    """POST /canton?action=ACTION with JSON body. Returns parsed JSON or {} on error."""
    with _api_lock:
        try:
            r = requests.post(_api_url(), params={"action": action},
                              json=body, timeout=timeout)
            r.raise_for_status()
            data = r.json()
            log.debug(f"POST {action} {body}: {data}")
            return data
        except requests.exceptions.JSONDecodeError:
            body_txt = (r.text or "").strip()[:200] if 'r' in locals() else ""
            log.warning(f"POST {action} {body} returned non-JSON (HTTP {getattr(r, 'status_code', '?')}): {body_txt!r}")
            _trace_publish("error", _trace_payload("api_post_non_json", action=action, request=body, http=getattr(r, "status_code", "?"), body=body_txt), retain=True)
            return {}
        except Exception as e:
            log.warning(f"POST {action} {body} failed: {type(e).__name__}: {e}")
            _trace_publish("error", _trace_payload("api_post_failed", action=action, request=body, error=f"{type(e).__name__}: {e}"), retain=True)
            return {}


def api_ok(result: dict) -> bool:
    """LibreKNX success response is usually status=101 (ERROR-OK)."""
    return isinstance(result, dict) and result.get("status") == 101


def set_volume_override(volume: int) -> None:
    global _volume_override, _volume_override_ts
    _volume_override = max(0, min(100, int(volume)))
    _volume_override_ts = time.time()


def get_volume_override(max_age_s: int = 1800) -> int | None:
    if _volume_override is None:
        return None
    if time.time() - _volume_override_ts > max_age_s:
        return None
    return _volume_override


def _run_cmd(cmd: list[str], timeout: int = 8) -> tuple[int, str, str]:
    try:
        cp = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
        return cp.returncode, (cp.stdout or "").strip(), (cp.stderr or "").strip()
    except Exception as e:
        return 1, "", f"{type(e).__name__}: {e}"


def _health_topics() -> dict:
    base = _config.get("MQTT", "HEALTH_BASE_TOPIC", fallback="loxberry/plugin/cantonbar/health")
    return {
        "api": _config.get("MQTT", "API_HEALTH_TOPIC", fallback=f"{base}/api"),
        "adb": _config.get("MQTT", "ADB_HEALTH_TOPIC", fallback=f"{base}/adb"),
        "libreknx": _config.get("MQTT", "LIBREKNX_HEALTH_TOPIC", fallback=f"{base}/libreknx"),
        "token": _config.get("MQTT", "TOKEN_HEALTH_TOPIC", fallback=f"{base}/token"),
    }


def _trace_topics() -> dict:
    base = _config.get("MQTT", "TRACE_BASE_TOPIC", fallback="loxberry/plugin/cantonbar/trace")
    return {
        "event": _config.get("MQTT", "TRACE_EVENT_TOPIC", fallback=f"{base}/event"),
        "command": _config.get("MQTT", "TRACE_LAST_COMMAND_TOPIC", fallback=f"{base}/last_command"),
        "result": _config.get("MQTT", "TRACE_LAST_RESULT_TOPIC", fallback=f"{base}/last_result"),
        "error": _config.get("MQTT", "TRACE_LAST_ERROR_TOPIC", fallback=f"{base}/last_error"),
    }


def _trace_payload(event: str, **fields) -> dict:
    global _trace_seq
    _trace_seq += 1
    payload = {"ts": int(time.time()), "seq": _trace_seq, "event": event}
    payload.update(fields)
    return payload


def _trace_publish(topic_key: str, payload: dict, retain: bool = True) -> None:
    try:
        topic = _trace_topics()[topic_key]
        _publish(topic, json.dumps(payload, separators=(",", ":")), retain=retain)
    except Exception as e:
        log.debug(f"Trace publish failed: {e}")


def trace_event(event: str, level: str = "info", retain_event: bool = False, **fields) -> None:
    payload = _trace_payload(event, **fields)
    text = f"TRACE {payload['seq']} {event}: {fields}"
    if level == "warning":
        log.warning(text)
    elif level == "error":
        log.error(text)
    elif level == "debug":
        log.debug(text)
    else:
        log.info(text)
    _trace_publish("event", payload, retain=retain_event)


def publish_health(force: bool = False) -> None:
    topics = _health_topics()
    for key in ("api", "adb", "libreknx", "token"):
        val = _health.get(key, "unknown")
        if force or _last_health_pub.get(key) != val:
            _publish(topics[key], val)
            _last_health_pub[key] = val


def _adb_binary() -> str:
    return shutil.which("adb") or ""


def _adb_connect(ip: str) -> bool:
    adb = _adb_binary()
    if not adb:
        _health["adb"] = "unavailable"
        log.warning("ADB binary not found on system; cannot auto-recover LibreKNX")
        return False

    rc, out, err = _run_cmd([adb, "connect", f"{ip}:5555"], timeout=10)
    txt = f"{out} {err}".lower()
    if rc == 0 and ("connected" in txt or "already connected" in txt):
        _health["adb"] = "connected"
        return True

    _health["adb"] = "failed"
    log.warning(f"ADB connect failed: rc={rc}, out={out!r}, err={err!r}")
    return False


def _is_libreknx_running(ip: str) -> bool:
    adb = _adb_binary()
    if not adb:
        return False
    cmd = [adb, "-s", f"{ip}:5555", "shell", "ps | grep -F LibreKNX | grep -v grep"]
    rc, out, err = _run_cmd(cmd, timeout=8)
    if rc == 0 and out:
        _health["libreknx"] = "running"
        return True
    if err and "device offline" in err.lower():
        _health["adb"] = "failed"
    _health["libreknx"] = "down"
    return False


def _start_libreknx(ip: str) -> bool:
    adb = _adb_binary()
    if not adb:
        return False

    # Method 1: detached remote launch that should survive shell exit.
    cmd = [
        adb,
        "-s",
        f"{ip}:5555",
        "shell",
        "sh -c 'nohup /system/bin/LibreKNX >/dev/null 2>&1 </dev/null &'",
    ]
    rc, out, err = _run_cmd(cmd, timeout=8)
    if rc == 0:
        time.sleep(2)
        if _wait_for_api(ip, retries=3, delay_s=1):
            return True

    log.warning(f"Detached LibreKNX start via adb shell did not recover API yet: rc={rc}, out={out!r}, err={err!r}")

    # Method 2: keep a dedicated adb client alive with LibreKNX in foreground.
    # This is heavier, but more reliable on devices where the remote shell kills background jobs.
    try:
        subprocess.Popen(
            [adb, "-s", f"{ip}:5555", "shell", "/system/bin/LibreKNX"],
            stdin=subprocess.DEVNULL,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            start_new_session=True,
        )
    except Exception as e:
        log.warning(f"Failed to spawn persistent adb client for LibreKNX: {type(e).__name__}: {e}")
        _health["libreknx"] = "start_failed"
        return False

    time.sleep(3)
    ok = _wait_for_api(ip, retries=3, delay_s=1)
    if not ok:
        _health["libreknx"] = "start_failed"
    return ok


def _stop_libreknx(ip: str) -> bool:
    adb = _adb_binary()
    if not adb:
        return False

    # Best-effort stop; some builds may not have pkill/killall.
    cmd = [
        adb,
        "-s",
        f"{ip}:5555",
        "shell",
        "sh -c 'pkill -f LibreKNX >/dev/null 2>&1 || killall LibreKNX >/dev/null 2>&1 || true'",
    ]
    _run_cmd(cmd, timeout=8)
    time.sleep(1)
    return not _is_libreknx_running(ip)


def _wait_for_api(ip: str, retries: int = 8, delay_s: int = 2) -> bool:
    url = f"http://{ip}:1904/canton"
    for _ in range(retries):
        try:
            r = requests.get(url, params={"action": "powerstatus"}, timeout=3)
            r.raise_for_status()
            data = r.json()
            if isinstance(data, dict) and "PowerStatus" in data:
                return True
        except Exception:
            pass
        time.sleep(delay_s)
    return False


def refresh_api_token() -> None:
    token_action = _config.get("RECOVERY", "TOKEN_ACTION", fallback="").strip()
    if not token_action:
        _health["token"] = "disabled"
        return

    token_data = api_get(token_action)
    if not token_data:
        _health["token"] = "error"
        return

    token_value = ""
    for key in ("token", "Token", "apiToken", "ApiToken", "access_token", "accessToken"):
        value = token_data.get(key)
        if isinstance(value, str) and value.strip():
            token_value = value.strip()
            break

    if token_value:
        _health["token_value"] = token_value
        _health["token"] = "ok"
        log.warning("API token refreshed successfully")
    else:
        _health["token"] = "missing"


def maybe_recover_libreknx() -> None:
    global _last_recovery_ts

    cooldown = _config.getint("RECOVERY", "COOLDOWN_SECONDS", fallback=90)
    now = time.time()
    if now - _last_recovery_ts < cooldown:
        return
    _last_recovery_ts = now

    ip = _config.get("SOUNDBAR", "IP", fallback="").strip()
    if not ip:
        return

    _health["api"] = "recovering"
    _health["libreknx"] = "restarting"
    publish_health()
    trace_event("recovery_started", level="warning", api=_health["api"], libreknx=_health["libreknx"])

    log.warning("LibreKNX API appears down; attempting ADB-based recovery")
    if not _adb_connect(ip):
        _health["api"] = "down"
        _health["libreknx"] = "unknown"
        publish_health()
        _trace_publish("error", _trace_payload("recovery_failed", stage="adb_connect"), retain=True)
        return

    running_before = _is_libreknx_running(ip)
    if running_before:
        log.warning("ADB connected and LibreKNX process is running but API is still down; forcing LibreKNX restart")
        _stop_libreknx(ip)

    if _start_libreknx(ip):
        log.warning("LibreKNX start/restart command sent via ADB")
        trace_event("recovery_restart_sent", level="warning")
    else:
        _health["api"] = "down"
        _health["libreknx"] = "start_failed"
        log.warning("LibreKNX start/restart via ADB failed")
        _trace_publish("error", _trace_payload("recovery_failed", stage="start_libreknx"), retain=True)

    if _wait_for_api(ip):
        _health["api"] = "up"
        _health["libreknx"] = "running"
        log.warning("LibreKNX API recovered after restart")
        trace_event("recovery_succeeded", level="warning", api="up")
    else:
        _health["api"] = "down"
        _health["libreknx"] = "running" if _is_libreknx_running(ip) else "down"
        log.warning("LibreKNX process recovery attempted, but API is still down")
        _trace_publish("error", _trace_payload("recovery_failed", stage="api_not_recovered", libreknx=_health["libreknx"]), retain=True)

    publish_health()


# ---------------------------------------------------------------------------
# State polling
# ---------------------------------------------------------------------------

def get_soundbar_state() -> dict:
    """
    Poll soundbar for current state. Returns dict with:
      power:  "on" | "standby"
      volume: int 0-100
      mute:   "on" | "off"
      input:  str (input source number)
    """
    fallback_volume = get_volume_override() or (int(_last_volume) if _last_volume.isdigit() else 0)
    fallback_mute = _last_mute if _last_mute in ("on", "off") else "off"
    fallback_input = _last_input if _last_input else "0"
    state = {
        "power": "standby",
        "volume": fallback_volume,
        "mute": fallback_mute,
        "input": fallback_input,
        "_api_ok": False,
    }

    power_data = api_get("powerstatus")
    if power_data:
        state["_api_ok"] = True
    if power_data.get("PowerStatus", "").upper() != "ON":
        return state  # standby — no point polling the rest

    state["power"] = "on"

    status_timeout = _config.getint("MONITOR", "STATUS_TIMEOUT", fallback=2)
    status = api_get("status", timeout=status_timeout)
    if status:
        state["volume"] = int(status.get("Volume", 0))
        state["mute"] = "on" if status.get("MuteStatus", False) else "off"

    override_volume = get_volume_override()
    if override_volume is not None and state["power"] == "on":
        if state["volume"] != override_volume:
            log.debug(f"Using local commanded volume override {override_volume} instead of stale API value {state['volume']}")
        state["volume"] = override_volume

    inp = api_get("input")
    if inp:
        state["input"] = str(inp.get("InputSource", "0"))

    return state


# ---------------------------------------------------------------------------
# MQTT publishing
# ---------------------------------------------------------------------------

def _publish(topic: str, value: str, retain: bool = True) -> None:
    try:
        _mqtt_client.publish(topic, value, qos=1, retain=retain)
    except Exception as e:
        log.error(f"MQTT publish failed: {e}")


def publish_state(state: dict) -> None:
    global _last_state, _last_volume, _last_mute, _last_input

    power  = state["power"]
    volume = str(state["volume"])
    mute   = state["mute"]
    inp    = state["input"]

    state_topic  = _config.get("MQTT", "STATE_TOPIC",  fallback="loxberry/plugin/cantonbar/state")
    volume_topic = _config.get("MQTT", "VOLUME_TOPIC", fallback="loxberry/plugin/cantonbar/volume")
    mute_topic   = _config.get("MQTT", "MUTE_TOPIC",   fallback="loxberry/plugin/cantonbar/mute")
    input_topic  = _config.get("MQTT", "INPUT_TOPIC",  fallback="loxberry/plugin/cantonbar/input")

    if power != _last_state:
        _publish(state_topic, power)
        log.info(f"State → {power!r}")
        _last_state = power

    if volume != _last_volume:
        _publish(volume_topic, volume)
        log.info(f"Volume → {volume}")
        _last_volume = volume

    if mute != _last_mute:
        _publish(mute_topic, mute)
        log.info(f"Mute → {mute!r}")
        _last_mute = mute

    if inp != _last_input:
        _publish(input_topic, inp)
        log.info(f"Input → {inp!r}")
        _last_input = inp


def republish_all() -> None:
    """Clear cached values so everything is re-published on next poll (e.g. after MQTT reconnect)."""
    global _last_state, _last_volume, _last_mute, _last_input
    _last_state = _last_volume = _last_mute = _last_input = ""


# ---------------------------------------------------------------------------
# MQTT command handler
# ---------------------------------------------------------------------------

def on_mqtt_connect(client, userdata, flags, rc) -> None:
    if rc == 0:
        cmd_topic = _config.get("MQTT", "CMD_TOPIC", fallback="loxberry/plugin/cantonbar/cmd")
        client.subscribe(cmd_topic, qos=1)
        log.info(f"MQTT connected, subscribed to {cmd_topic}")
        trace_event("mqtt_connected", cmd_topic=cmd_topic)
        republish_all()
    else:
        log.error(f"MQTT connection failed, rc={rc}")
        _trace_publish("error", _trace_payload("mqtt_connect_failed", rc=rc), retain=True)


def on_mqtt_disconnect(client, userdata, rc) -> None:
    if rc != 0:
        log.warning(f"MQTT disconnected unexpectedly (rc={rc}), will auto-reconnect")
        _trace_publish("error", _trace_payload("mqtt_disconnected", rc=rc), retain=True)


def on_mqtt_message(client, userdata, msg) -> None:
    payload = msg.payload.decode("utf-8", errors="ignore").strip()
    log.info(f"MQTT command received: {payload!r}")
    _trace_publish("command", _trace_payload("command_received", topic=msg.topic, command=payload), retain=True)
    handle_command(payload)


def handle_command(cmd: str) -> None:
    mac = _config.get("SOUNDBAR", "MAC", fallback="")

    try:
        started = time.time()

        def cmd_done(success: bool, detail: dict) -> None:
            elapsed_ms = int((time.time() - started) * 1000)
            payload = _trace_payload("command_result", command=cmd, ok=success, elapsed_ms=elapsed_ms, **detail)
            _trace_publish("result", payload, retain=True)
            if not success:
                _trace_publish("error", payload, retain=True)

        if cmd == "power_on":
            if mac:
                wakeonlan.send_magic_packet(mac)
                log.info(f"Wake-on-LAN sent to {mac}")
                cmd_done(True, {"action": "wol", "mac": mac})
            else:
                log.warning("power_on: no MAC configured for Wake-on-LAN")
                cmd_done(False, {"action": "wol", "error": "no_mac_configured"})

        elif cmd == "power_off":
            if not _config.getboolean("EXPERIMENTAL", "ENABLE_POWER_OFF", fallback=False):
                log.warning("power_off ignored: network standby is not verified for this device and is disabled by default")
                cmd_done(False, {"action": "standby", "error": "disabled_by_config"})
                return
            result = api_post("standby", {"standby": True})
            log.info(f"Standby: {result}")
            cmd_done(api_ok(result), {"action": "standby", "response": result})

        elif cmd.startswith("volume_set_"):
            try:
                vol = int(cmd.split("_", 2)[2])
                vol = max(0, min(100, vol))
                result = api_post("volume", {"volume": vol})
                if not api_ok(result):
                    # Fallback noted in NEXT_SESSION (possible uppercase field name)
                    result = api_post("volume", {"Volume": vol})
                if api_ok(result):
                    set_volume_override(vol)
                log.info(f"Volume set {vol}: {result}")
                cmd_done(api_ok(result), {"action": "volume_set", "target": vol, "response": result})
            except (ValueError, IndexError):
                log.warning(f"Invalid volume command: {cmd!r}")
                cmd_done(False, {"action": "volume_set", "error": "invalid_command"})

        elif cmd == "volume_up":
            status = api_get("status")
            current = get_volume_override() or int(status.get("Volume", 50))
            step = _config.getint("SOUNDBAR", "VOLUME_STEP", fallback=5)
            new_vol = min(100, current + step)
            result = api_post("volume", {"volume": new_vol})
            if not api_ok(result):
                result = api_post("volume", {"Volume": new_vol})
            if api_ok(result):
                set_volume_override(new_vol)
            log.info(f"Volume up: {current} → {new_vol}: {result}")
            cmd_done(api_ok(result), {"action": "volume_up", "from": current, "to": new_vol, "response": result})

        elif cmd == "volume_down":
            status = api_get("status")
            current = get_volume_override() or int(status.get("Volume", 50))
            step = _config.getint("SOUNDBAR", "VOLUME_STEP", fallback=5)
            new_vol = max(0, current - step)
            result = api_post("volume", {"volume": new_vol})
            if not api_ok(result):
                result = api_post("volume", {"Volume": new_vol})
            if api_ok(result):
                set_volume_override(new_vol)
            log.info(f"Volume down: {current} → {new_vol}: {result}")
            cmd_done(api_ok(result), {"action": "volume_down", "from": current, "to": new_vol, "response": result})

        elif cmd == "mute_on":
            power = api_get("powerstatus").get("PowerStatus", "").upper()
            if power == "STANDBY":
                log.warning("mute_on requested while soundbar is STANDBY; command may be ignored by device")
            result = api_post("mute", {"mute": True})
            log.info(f"Mute on: {result}")
            cmd_done(api_ok(result), {"action": "mute_on", "response": result})

        elif cmd == "mute_off":
            power = api_get("powerstatus").get("PowerStatus", "").upper()
            if power == "STANDBY":
                log.warning("mute_off requested while soundbar is STANDBY; command may be ignored by device")
            result = api_post("mute", {"mute": False})
            log.info(f"Mute off: {result}")
            cmd_done(api_ok(result), {"action": "mute_off", "response": result})

        elif cmd == "mute_toggle":
            status = api_get("status")
            currently_muted = status.get("MuteStatus", False)
            result = api_post("mute", {"mute": not currently_muted})
            log.info(f"Mute toggle (was {currently_muted}): {result}")
            cmd_done(api_ok(result), {"action": "mute_toggle", "was": bool(currently_muted), "response": result})

        elif cmd.startswith("input_"):
            if not _config.getboolean("EXPERIMENTAL", "ENABLE_INPUT_SWITCHING", fallback=False):
                log.warning(f"{cmd} ignored: input switching is not verified for this device and is disabled by default")
                cmd_done(False, {"action": "input_switch", "error": "disabled_by_config"})
                return
            if not _config.getboolean("EXPERIMENTAL", "ENABLE_UNSAFE_HTTP_INPUT", fallback=False):
                log.warning(f"{cmd} ignored: unsafe HTTP input switching is disabled (it currently destabilizes LibreKNX on this firmware)")
                cmd_done(False, {"action": "input_switch", "error": "unsafe_http_input_disabled"})
                return
            source = cmd[6:]  # "input_3" → "3"
            # Try capitalized key first (as hinted by NEXT_SESSION), then lowercase fallback.
            result = api_post("input", {"InputSource": source})
            if not api_ok(result):
                result = api_post("input", {"inputsource": source})
            log.info(f"Input → {source!r}: {result}")
            cmd_done(api_ok(result), {"action": "input_switch", "target": source, "response": result})

        else:
            log.warning(f"Unknown command: {cmd!r}")
            cmd_done(False, {"action": "unknown", "error": "unknown_command"})

    except Exception as e:
        log.error(f"Command '{cmd}' failed: {type(e).__name__}: {e}")
        _trace_publish("error", _trace_payload("command_exception", command=cmd, error=f"{type(e).__name__}: {e}"), retain=True)


# ---------------------------------------------------------------------------
# MQTT setup
# ---------------------------------------------------------------------------

def get_mqtt_connection() -> tuple:
    """Read MQTT host/port/credentials from LoxBerry's general.json."""
    try:
        with open("/opt/loxberry/config/system/general.json") as f:
            data = json.load(f)
        mqtt_cfg = data.get("Mqtt", {})
        host = mqtt_cfg.get("Brokerhost", "localhost") or "localhost"
        port = int(mqtt_cfg.get("Brokerport", 1883) or 1883)
        user = mqtt_cfg.get("Brokeruser", "")
        password = mqtt_cfg.get("Brokerpass", "")
        return host, port, user, password
    except Exception as e:
        log.debug(f"Could not read general.json: {e} — using defaults")
        return "localhost", 1883, "", ""


def setup_mqtt() -> mqtt.Client:
    try:
        # paho-mqtt 2.x
        client = mqtt.Client(
            mqtt.CallbackAPIVersion.VERSION1,
            client_id="cantonbar-monitor",
            clean_session=True,
        )
    except AttributeError:
        # paho-mqtt 1.x
        client = mqtt.Client(client_id="cantonbar-monitor", clean_session=True)
    client.on_connect = on_mqtt_connect
    client.on_disconnect = on_mqtt_disconnect
    client.on_message = on_mqtt_message

    host, port, user, password = get_mqtt_connection()
    if user:
        client.username_pw_set(user, password)
        log.info(f"MQTT using credentials for user '{user}'")

    state_topic = _config.get("MQTT", "STATE_TOPIC", fallback="loxberry/plugin/cantonbar/state")
    client.will_set(state_topic, "standby", qos=1, retain=True)

    client.connect_async(host, port, keepalive=60)
    client.loop_start()
    log.info(f"MQTT connecting to {host}:{port}")
    return client


# ---------------------------------------------------------------------------
# Main loop
# ---------------------------------------------------------------------------

def run_poll_loop() -> None:
    global _api_fail_streak
    poll_interval = _config.getint("MONITOR", "POLL_INTERVAL", fallback=5)
    log.info(f"Starting poll loop (interval={poll_interval}s)")
    publish_health(force=True)

    while not _shutdown.is_set():
        config_path = _config.get("_meta", "config_path")
        _config.read(config_path)
        poll_interval = _config.getint("MONITOR", "POLL_INTERVAL", fallback=5)

        state = get_soundbar_state()
        if state.get("_api_ok"):
            _api_fail_streak = 0
            _health["api"] = "up"
            _health["libreknx"] = "running"
        else:
            _api_fail_streak += 1
            _health["api"] = "down"

        threshold = _config.getint("RECOVERY", "FAILURE_THRESHOLD", fallback=3)
        if _api_fail_streak >= threshold:
            maybe_recover_libreknx()

        if _health["api"] == "up":
            refresh_api_token()

        publish_state(state)
        publish_health()

        _shutdown.wait(timeout=poll_interval)

    log.info("Poll loop exiting.")


def handle_signal(signum, frame) -> None:
    log.info(f"Signal {signum} received, shutting down...")
    _shutdown.set()


def main() -> None:
    global _config, _mqtt_client

    parser = argparse.ArgumentParser(description="Canton Smart Soundbar monitor daemon")
    parser.add_argument("--config", required=True, help="Path to cantonbar.cfg")
    parser.add_argument("--logfile", required=True, help="Path to log file")
    args = parser.parse_args()

    _config = configparser.ConfigParser()
    _config.read(args.config)
    if not _config.has_section("_meta"):
        _config.add_section("_meta")
    _config.set("_meta", "config_path", args.config)

    loglevel = _config.getint("MONITOR", "LOGLEVEL", fallback=4)
    setup_logging(args.logfile, loglevel)

    log.warning("Canton Smart Soundbar monitor starting")
    log.info(f"Config: {args.config}  |  Log level: {loglevel}")

    ip = _config.get("SOUNDBAR", "IP", fallback="").strip()
    if not ip:
        log.warning("Soundbar IP is not configured — HTTP commands will fail. Set IP in the web UI.")
    else:
        log.info(f"Soundbar IP: {ip}")

    signal.signal(signal.SIGTERM, handle_signal)
    signal.signal(signal.SIGINT, handle_signal)

    _mqtt_client = setup_mqtt()

    for _ in range(20):
        if _mqtt_client.is_connected():
            break
        time.sleep(0.5)
    else:
        log.warning("MQTT did not connect within 10s — continuing anyway")

    refresh_api_token()

    try:
        run_poll_loop()
    finally:
        log.info("Disconnecting MQTT...")
        _mqtt_client.loop_stop()
        _mqtt_client.disconnect()
        log.info("Canton Smart Soundbar monitor stopped.")


if __name__ == "__main__":
    main()
