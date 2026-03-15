#!/usr/bin/env python3
"""monitor.py — FFAA-based daemon for the Canton Smart Soundbar plugin."""

from __future__ import annotations

import argparse
import configparser
import json
import logging
import os
import signal
import socket
import struct
import sys
import threading
import time
from dataclasses import dataclass
from logging.handlers import RotatingFileHandler
from urllib.parse import urlencode

import requests

try:
    import paho.mqtt.client as mqtt
except ImportError:
    print("ERROR: paho-mqtt not installed. Run: pip3 install paho-mqtt")
    sys.exit(1)


log = logging.getLogger("cantonbar")

_config: configparser.ConfigParser | None = None
_mqtt_client: mqtt.Client | None = None
_shutdown = threading.Event()

_last_state = ""
_last_volume = ""
_last_mute = ""
_last_input = ""
_last_sound_mode_text = ""

_last_volume_raw = 0
_last_volume_max = 70
_last_input_bytes = (0, 0)
_last_sound_mode = 2
_logged_unknown_inputs: set[str] = set()

SOUND_MODE_NAMES = {1: "Stereo", 2: "Movie", 3: "Music"}
SOUND_MODE_COMMANDS = {
    "stereo": 1,
    "movie": 2,
    "music": 3,
}
DEFAULT_INPUT_MAPPINGS = {
    "0": "01,03,BDP",
    "1": "02,04,SAT",
    "2": "03,0E,PS",
    "3": "06,02,TV",
    "4": "07,05,CD",
    "5": "0B,06,DVD",
    "6": "0F,12,AUX",
    "7": "17,13,NET",
    "8": "15,14,BT",
}


@dataclass(frozen=True)
class InputMapping:
    source_id: str
    byte1: int
    byte2: int
    name: str


class FfaaClient:
    CMD_STATUS = 0x0002
    CMD_INPUT_MODE = 0x0003
    CMD_POWER = 0x0006
    CMD_MUTE = 0x0009
    CMD_VOLUME = 0x000C

    def __init__(self, host: str, port: int, timeout: float = 2.0) -> None:
        self.host = host
        self.port = port
        self.timeout = timeout
        self._lock = threading.Lock()

    @staticmethod
    def build_frame(cmd: int, type_byte: int, data: bytes = b"") -> bytes:
        return b"\xff\xaa" + struct.pack(">H", cmd) + bytes([type_byte]) + struct.pack(">H", len(data)) + data

    @staticmethod
    def parse_frames(raw: bytes) -> list[dict]:
        frames: list[dict] = []
        pos = 0
        while pos + 7 <= len(raw):
            if raw[pos : pos + 2] != b"\xff\xaa":
                idx = raw.find(b"\xff\xaa", pos + 1)
                if idx < 0:
                    break
                pos = idx
                continue

            cmd = struct.unpack(">H", raw[pos + 2 : pos + 4])[0]
            typ = raw[pos + 4]
            dlen = struct.unpack(">H", raw[pos + 5 : pos + 7])[0]
            if pos + 7 + dlen > len(raw):
                break
            data = raw[pos + 7 : pos + 7 + dlen]
            frames.append({"cmd": cmd, "type": typ, "data": data})
            pos += 7 + dlen
        return frames

    def transact(self, payload: bytes, settle_s: float = 0.25) -> list[dict]:
        with self._lock:
            with socket.create_connection((self.host, self.port), timeout=self.timeout) as sock:
                sock.settimeout(self.timeout)
                sock.sendall(payload)
                time.sleep(settle_s)

                chunks: list[bytes] = []
                while True:
                    try:
                        data = sock.recv(4096)
                        if not data:
                            break
                        chunks.append(data)
                        if len(data) < 4096:
                            sock.settimeout(0.15)
                    except socket.timeout:
                        break

        raw = b"".join(chunks)
        if not raw:
            return []
        return self.parse_frames(raw)

    def query_state(self, include_supported_inputs: bool = True) -> dict:
        cmds = []
        if include_supported_inputs:
            cmds.append(self.CMD_STATUS)
        cmds.extend([self.CMD_POWER, self.CMD_INPUT_MODE, self.CMD_MUTE, self.CMD_VOLUME])
        frames = b"".join(self.build_frame(cmd, 0x02) for cmd in cmds)
        parsed = self.transact(frames)

        result = {
            "power_on": None,
            "input_b1": None,
            "input_b2": None,
            "sound_mode": None,
            "mute_on": None,
            "volume_raw": None,
            "volume_max": 70,
            "supported_inputs": [],
        }

        for frame in parsed:
            data = frame["data"]
            if frame["cmd"] == self.CMD_POWER and len(data) >= 1:
                result["power_on"] = data[0] == 0x01
            elif frame["cmd"] == self.CMD_INPUT_MODE and frame["type"] == 0x01 and len(data) >= 3:
                result["input_b1"] = data[0]
                result["input_b2"] = data[1]
                result["sound_mode"] = data[2]
            elif frame["cmd"] == self.CMD_MUTE and len(data) >= 1:
                result["mute_on"] = data[0] == 0x01
            elif frame["cmd"] == self.CMD_VOLUME and len(data) >= 1:
                result["volume_raw"] = data[0]
                if len(data) >= 2 and data[1] > 0:
                    result["volume_max"] = data[1]
            elif frame["cmd"] == self.CMD_STATUS and frame["type"] == 0x01 and len(data) >= 3 and len(data) % 3 == 0:
                result["supported_inputs"] = [tuple(data[i : i + 3]) for i in range(0, len(data), 3)]

        return result

    def set_power(self, on: bool) -> list[dict]:
        return self.transact(self.build_frame(self.CMD_POWER, 0x01, bytes([0x01 if on else 0x00])))

    def set_mute(self, on: bool) -> list[dict]:
        return self.transact(self.build_frame(self.CMD_MUTE, 0x01, bytes([0x01 if on else 0x00])))

    def set_volume_raw(self, level: int) -> list[dict]:
        level = max(0, min(70, int(level)))
        return self.transact(self.build_frame(self.CMD_VOLUME, 0x01, bytes([level])))

    def set_input(self, byte1: int, byte2: int, sound_mode: int) -> list[dict]:
        payload = bytes([byte1 & 0xFF, byte2 & 0xFF, max(1, min(3, int(sound_mode)))])
        return self.transact(self.build_frame(self.CMD_INPUT_MODE, 0x01, payload))


def setup_logging(logfile: str, loglevel: int) -> None:
    level_map = {1: logging.CRITICAL, 2: logging.ERROR, 3: logging.WARNING, 4: logging.INFO, 5: logging.DEBUG, 6: logging.DEBUG}
    level = level_map.get(loglevel, logging.INFO)
    fmt = logging.Formatter("%(asctime)s [%(levelname)s] %(message)s", datefmt="%Y-%m-%d %H:%M:%S")

    os.makedirs(os.path.dirname(logfile), exist_ok=True)
    fh = RotatingFileHandler(logfile, maxBytes=5 * 1024 * 1024, backupCount=3)
    fh.setFormatter(fmt)
    sh = logging.StreamHandler(sys.stdout)
    sh.setFormatter(fmt)

    log.setLevel(level)
    log.handlers.clear()
    log.addHandler(fh)
    log.addHandler(sh)


def parse_hex_byte(token: str) -> int:
    text = (token or "").strip()
    if not text:
        raise ValueError("empty byte token")
    if text.lower().startswith("0x"):
        value = int(text, 16)
    else:
        value = int(text, 16)
    if not 0 <= value <= 0xFF:
        raise ValueError(f"byte out of range: {token}")
    return value


def load_input_mappings() -> dict[str, InputMapping]:
    raw_items = dict(DEFAULT_INPUT_MAPPINGS)
    if _config and _config.has_section("FFAA_INPUTS"):
        for source_id, value in _config.items("FFAA_INPUTS"):
            raw_items[str(source_id).strip()] = value

    mappings: dict[str, InputMapping] = {}
    for source_id, value in raw_items.items():
        parts = [part.strip() for part in str(value).split(",") if part.strip()]
        if len(parts) < 3:
            log.warning(f"Skipping invalid FFAA input mapping for source {source_id!r}: {value!r}")
            continue
        try:
            byte1 = parse_hex_byte(parts[0])
            byte2 = parse_hex_byte(parts[1])
        except ValueError as e:
            log.warning(f"Skipping invalid FFAA byte mapping for source {source_id!r}: {e}")
            continue

        name = ",".join(parts[2:]).strip() or f"SRC_{source_id}"
        mappings[str(source_id)] = InputMapping(str(source_id), byte1, byte2, name)

    return dict(sorted(mappings.items(), key=lambda item: int(item[0]) if item[0].isdigit() else item[0]))


def find_mapping_by_bytes(byte1: int, byte2: int, mappings: dict[str, InputMapping]) -> InputMapping | None:
    for mapping in mappings.values():
        if mapping.byte1 == byte1 and mapping.byte2 == byte2:
            return mapping
    return None


def sanitize_name_token(value: str) -> str:
    cleaned = []
    for ch in (value or "").strip().lower():
        if ch.isalnum():
            cleaned.append(ch)
    return "".join(cleaned)


def find_mapping_by_alias(alias: str, mappings: dict[str, InputMapping]) -> InputMapping | None:
    token = sanitize_name_token(alias)
    if not token:
        return None
    for mapping in mappings.values():
        if sanitize_name_token(mapping.name) == token:
            return mapping
    return None


def parse_sound_mode_from_command(cmd: str) -> int | None:
    if not cmd:
        return None
    if cmd.startswith("mode_"):
        return SOUND_MODE_COMMANDS.get(cmd[5:])
    if cmd.startswith("playmode_"):
        return SOUND_MODE_COMMANDS.get(cmd[9:])
    if cmd.startswith("play_mode_"):
        return SOUND_MODE_COMMANDS.get(cmd[10:])
    return None


def raw_volume_to_percent(level: int, maximum: int) -> int:
    maximum = max(1, int(maximum or 70))
    return max(0, min(100, round((int(level) * 100) / maximum)))


def percent_to_raw_volume(percent: int, maximum: int) -> int:
    maximum = max(1, int(maximum or 70))
    pct = max(0, min(100, int(percent)))
    return max(0, min(maximum, round((pct * maximum) / 100)))


def ffaa_client() -> FfaaClient:
    ip = (_config.get("SOUNDBAR", "IP", fallback="") if _config else "").strip()
    port = _config.getint("SOUNDBAR", "PORT", fallback=50006) if _config else 50006
    timeout = max(1, _config.getint("MONITOR", "STATUS_TIMEOUT", fallback=2) if _config else 2)
    return FfaaClient(ip, port, timeout=float(timeout))


def legacy_http_enabled() -> bool:
    if not _config:
        return True
    return _config.getboolean("LEGACY_HTTP", "ENABLE_MUTE_FALLBACK", fallback=True)


def legacy_http_base_url() -> str:
    ip = (_config.get("SOUNDBAR", "IP", fallback="") if _config else "").strip()
    port = _config.getint("LEGACY_HTTP", "PORT", fallback=1904) if _config else 1904
    path = (_config.get("LEGACY_HTTP", "PATH", fallback="/canton") if _config else "/canton").strip() or "/canton"
    if not path.startswith("/"):
        path = f"/{path}"
    return f"http://{ip}:{port}{path}"


def legacy_http_status_timeout() -> float:
    return float(_config.getint("LEGACY_HTTP", "STATUS_TIMEOUT", fallback=2) if _config else 2)


def legacy_http_get(action: str) -> dict | None:
    if not legacy_http_enabled():
        return None
    try:
        url = f"{legacy_http_base_url()}?{urlencode({'action': action})}"
        response = requests.get(url, timeout=legacy_http_status_timeout())
        if response.status_code != 200:
            return None
        payload = response.json()
        return payload if isinstance(payload, dict) else None
    except Exception:
        return None


def legacy_http_post(action: str, body: dict) -> bool:
    if not legacy_http_enabled():
        return False
    try:
        url = f"{legacy_http_base_url()}?{urlencode({'action': action})}"
        response = requests.post(url, json=body, timeout=legacy_http_status_timeout())
        payload = response.json() if response.status_code == 200 else {}
        return isinstance(payload, dict) and int(payload.get("status", 0)) == 101
    except Exception:
        return False


def get_mute_state() -> str:
    payload = legacy_http_get("status")
    if not payload or "MuteStatus" not in payload:
        return "unsupported"
    return "on" if bool(payload.get("MuteStatus")) else "off"


def set_mute_state(target: bool) -> bool:
    return legacy_http_post("mute", {"mute": bool(target)})


def publish_mute_command_state(mute_state: str) -> None:
    global _last_mute

    if mute_state not in ("on", "off"):
        return

    mute_topic = _config.get("MQTT", "MUTE_TOPIC", fallback="loxberry/plugin/cantonbar/mute")
    if mute_state != _last_mute:
        _publish(mute_topic, mute_state)
        log.info(f"Mute → {mute_state!r} (command sync)")
    _last_mute = mute_state


def _publish(topic: str, value: str, retain: bool = True) -> None:
    if not _mqtt_client:
        return
    try:
        _mqtt_client.publish(topic, value, qos=1, retain=retain)
    except Exception as e:
        log.error(f"MQTT publish failed: {e}")


def get_soundbar_state() -> dict:
    global _last_volume_raw, _last_volume_max, _last_input_bytes, _last_sound_mode

    mappings = load_input_mappings()
    fallback_power = _last_state if _last_state in ("on", "standby") else "standby"
    fallback_volume = int(_last_volume) if _last_volume.isdigit() else raw_volume_to_percent(_last_volume_raw, _last_volume_max)
    fallback_input = _last_input if _last_input else "Unknown"

    try:
        state = ffaa_client().query_state(include_supported_inputs=True)
    except Exception as e:
        log.warning(f"FFAA state query failed: {type(e).__name__}: {e}")
        return {
            "power": fallback_power,
            "volume": fallback_volume,
            "mute": _last_mute if _last_mute in ("on", "off") else "unsupported",
            "input": fallback_input,
            "sound_mode": SOUND_MODE_NAMES.get(_last_sound_mode, f"Mode {_last_sound_mode}"),
            "_ok": False,
        }

    power = "on" if state.get("power_on") else "standby"
    volume_raw = int(state.get("volume_raw") if state.get("volume_raw") is not None else _last_volume_raw)
    volume_max = int(state.get("volume_max") if state.get("volume_max") is not None else _last_volume_max or 70)
    input_b1 = int(state.get("input_b1") if state.get("input_b1") is not None else _last_input_bytes[0])
    input_b2 = int(state.get("input_b2") if state.get("input_b2") is not None else _last_input_bytes[1])
    sound_mode = int(state.get("sound_mode") if state.get("sound_mode") is not None else _last_sound_mode or 2)

    _last_volume_raw = volume_raw
    _last_volume_max = volume_max
    _last_input_bytes = (input_b1, input_b2)
    _last_sound_mode = sound_mode

    mapping = find_mapping_by_bytes(input_b1, input_b2, mappings)
    if mapping:
        input_value = mapping.name
    else:
        input_value = f"RAW {input_b1:02X}:{input_b2:02X}"

    for triple in state.get("supported_inputs", []):
        if len(triple) < 2:
            continue
        key = f"{triple[0]:02X}:{triple[1]:02X}"
        if not find_mapping_by_bytes(triple[0], triple[1], mappings) and key not in _logged_unknown_inputs:
            log.info(f"Discovered unmapped FFAA input tuple {key} (mode={triple[2] if len(triple) > 2 else '?'})")
            _logged_unknown_inputs.add(key)

    mute_flag = state.get("mute_on")
    if mute_flag is not None:
        mute = "on" if bool(mute_flag) else "off"
    else:
        mute = get_mute_state()
        if mute == "unsupported" and _last_mute in ("on", "off"):
            mute = _last_mute

    return {
        "power": power,
        "volume": raw_volume_to_percent(volume_raw, volume_max),
        "mute": mute,
        "input": input_value,
        "sound_mode": SOUND_MODE_NAMES.get(sound_mode, f"Mode {sound_mode}"),
        "_ok": True,
    }


def publish_state(state: dict) -> None:
    global _last_state, _last_volume, _last_mute, _last_input, _last_sound_mode_text

    state_topic = _config.get("MQTT", "STATE_TOPIC", fallback="loxberry/plugin/cantonbar/state")
    volume_topic = _config.get("MQTT", "VOLUME_TOPIC", fallback="loxberry/plugin/cantonbar/volume")
    mute_topic = _config.get("MQTT", "MUTE_TOPIC", fallback="loxberry/plugin/cantonbar/mute")
    input_topic = _config.get("MQTT", "INPUT_TOPIC", fallback="loxberry/plugin/cantonbar/input")
    mode_topic = _config.get("MQTT", "SOUND_MODE_TOPIC", fallback="loxberry/plugin/cantonbar/sound_mode")

    power = state["power"]
    volume = str(state["volume"])
    mute = state.get("mute", "unsupported")
    inp = state["input"]
    sound_mode = state.get("sound_mode", "Unknown")

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

    if sound_mode != _last_sound_mode_text:
        _publish(mode_topic, sound_mode)
        log.info(f"Sound mode → {sound_mode!r}")
        _last_sound_mode_text = sound_mode


def republish_all() -> None:
    global _last_state, _last_volume, _last_mute, _last_input, _last_sound_mode_text
    _last_state = _last_volume = _last_mute = _last_input = ""
    _last_sound_mode_text = ""


def current_sound_mode() -> int:
    if _last_sound_mode in (1, 2, 3):
        return _last_sound_mode
    try:
        state = ffaa_client().query_state(include_supported_inputs=False)
        mode = int(state.get("sound_mode") or 2)
        return mode if mode in (1, 2, 3) else 2
    except Exception:
        return 2


def current_volume_target() -> tuple[int, int]:
    if _last_volume_max > 0:
        return _last_volume_raw, _last_volume_max
    try:
        state = ffaa_client().query_state(include_supported_inputs=False)
        raw = int(state.get("volume_raw") or 0)
        maximum = int(state.get("volume_max") or 70)
        return raw, maximum
    except Exception:
        return 0, 70


def on_mqtt_connect(client, userdata, flags, rc) -> None:
    if rc == 0:
        cmd_topic = _config.get("MQTT", "CMD_TOPIC", fallback="loxberry/plugin/cantonbar/cmd")
        client.subscribe(cmd_topic, qos=1)
        log.info(f"MQTT connected, subscribed to {cmd_topic}")
        republish_all()
    else:
        log.error(f"MQTT connection failed, rc={rc}")


def on_mqtt_disconnect(client, userdata, rc) -> None:
    if rc != 0:
        log.warning(f"MQTT disconnected unexpectedly (rc={rc}), will auto-reconnect")


def on_mqtt_message(client, userdata, msg) -> None:
    payload = msg.payload.decode("utf-8", errors="ignore").strip()
    log.info(f"MQTT command received: {payload!r}")
    handle_command(payload)


def handle_command(cmd: str) -> None:
    try:
        cmd = (cmd or "").strip().lower()
        if not cmd:
            return

        client = ffaa_client()
        mappings = load_input_mappings()

        if cmd == "power_on":
            response = client.set_power(True)
            log.info(f"Power on (FFAA): {response}")
            return

        if cmd == "power_off":
            response = client.set_power(False)
            log.info(f"Power off (FFAA): {response}")
            return

        if cmd.startswith("volume_set_"):
            try:
                pct = max(0, min(100, int(cmd.split("_", 2)[2])))
            except (ValueError, IndexError):
                log.warning(f"Invalid volume command: {cmd!r}")
                return
            _, maximum = current_volume_target()
            raw = percent_to_raw_volume(pct, maximum)
            response = client.set_volume_raw(raw)
            log.info(f"Volume set (FFAA): {pct}% → raw {raw}: {response}")
            return

        if cmd == "volume_up":
            current_raw, maximum = current_volume_target()
            step_pct = _config.getint("SOUNDBAR", "VOLUME_STEP", fallback=5)
            target_pct = min(100, raw_volume_to_percent(current_raw, maximum) + step_pct)
            raw = percent_to_raw_volume(target_pct, maximum)
            response = client.set_volume_raw(raw)
            log.info(f"Volume up (FFAA): raw {current_raw} → {raw} ({target_pct}%): {response}")
            return

        if cmd == "volume_down":
            current_raw, maximum = current_volume_target()
            step_pct = _config.getint("SOUNDBAR", "VOLUME_STEP", fallback=5)
            target_pct = max(0, raw_volume_to_percent(current_raw, maximum) - step_pct)
            raw = percent_to_raw_volume(target_pct, maximum)
            response = client.set_volume_raw(raw)
            log.info(f"Volume down (FFAA): raw {current_raw} → {raw} ({target_pct}%): {response}")
            return

        if cmd in ("mute_on", "mute_off", "mute_toggle"):
            if cmd == "mute_toggle":
                if _last_mute in ("on", "off"):
                    current = _last_mute
                else:
                    try:
                        state = client.query_state(include_supported_inputs=False)
                        if state.get("mute_on") is None:
                            current = get_mute_state()
                        else:
                            current = "on" if bool(state.get("mute_on")) else "off"
                    except Exception:
                        current = get_mute_state()
                target = current != "on"
            else:
                target = cmd == "mute_on"

            desired = "on" if target else "off"
            try:
                response = client.set_mute(target)
                publish_mute_command_state(desired)
                log.info(f"Mute set (FFAA): {desired}: {response}")
                return
            except Exception as e:
                log.warning(f"FFAA mute command failed, trying legacy HTTP fallback: {type(e).__name__}: {e}")

            if set_mute_state(target):
                confirmed = get_mute_state()
                effective = confirmed if confirmed in ("on", "off") else desired
                publish_mute_command_state(effective)
                log.info(f"Mute set via legacy HTTP fallback: desired={desired}, confirmed={confirmed if confirmed != 'unsupported' else 'n/a'}")
                return

            log.warning("Mute command failed (FFAA and legacy HTTP fallback unavailable)")
            return

        mode = parse_sound_mode_from_command(cmd)
        if mode:
            state = client.query_state(include_supported_inputs=False)
            byte1 = int(state.get("input_b1") if state.get("input_b1") is not None else _last_input_bytes[0])
            byte2 = int(state.get("input_b2") if state.get("input_b2") is not None else _last_input_bytes[1])
            response = client.set_input(byte1, byte2, mode)
            log.info(f"Sound mode change (FFAA): input {byte1:02X},{byte2:02X} → mode {mode}: {response}")
            return

        if cmd.startswith("input_"):
            source_id = cmd[6:]
            mapping = mappings.get(source_id)
            if not mapping:
                mapping = find_mapping_by_alias(source_id, mappings)
            if not mapping:
                log.warning(f"{cmd} ignored: no FFAA mapping configured for source {source_id!r}")
                return
            mode = current_sound_mode()
            response = client.set_input(mapping.byte1, mapping.byte2, mode)
            log.info(
                f"Input switch (FFAA): source {source_id} ({mapping.name}) → "
                f"{mapping.byte1:02X},{mapping.byte2:02X}, mode {mode}: {response}"
            )
            return

        log.warning(f"Unknown command: {cmd!r}")
    except Exception as e:
        log.error(f"Command '{cmd}' failed: {type(e).__name__}: {e}")


def get_mqtt_connection() -> tuple[str, int, str, str]:
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
        client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION1, client_id="cantonbar-monitor", clean_session=True)
    except AttributeError:
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


def run_poll_loop() -> None:
    poll_interval = _config.getint("MONITOR", "POLL_INTERVAL", fallback=5)
    log.info(f"Starting FFAA poll loop (interval={poll_interval}s)")

    while not _shutdown.is_set():
        config_path = _config.get("_meta", "config_path")
        _config.read(config_path)
        poll_interval = _config.getint("MONITOR", "POLL_INTERVAL", fallback=5)

        state = get_soundbar_state()
        publish_state(state)
        _shutdown.wait(timeout=poll_interval)

    log.info("Poll loop exiting.")


def handle_signal(signum, frame) -> None:
    log.info(f"Signal {signum} received, shutting down...")
    _shutdown.set()


def main() -> None:
    global _config, _mqtt_client

    parser = argparse.ArgumentParser(description="Canton Smart Soundbar FFAA monitor daemon")
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

    log.warning("Canton Smart Soundbar FFAA monitor starting")
    log.info(f"Config: {args.config}  |  Log level: {loglevel}")

    ip = _config.get("SOUNDBAR", "IP", fallback="").strip()
    port = _config.getint("SOUNDBAR", "PORT", fallback=50006)
    if not ip:
        log.warning("Soundbar IP is not configured — FFAA commands will fail. Set IP in the web UI.")
    else:
        log.info(f"Soundbar FFAA target: {ip}:{port}")

    mapping_names = {source_id: item.name for source_id, item in load_input_mappings().items()}
    log.info(f"Configured FFAA input mappings: {json.dumps(mapping_names, sort_keys=True, separators=(',', ':'))}")

    signal.signal(signal.SIGTERM, handle_signal)
    signal.signal(signal.SIGINT, handle_signal)

    _mqtt_client = setup_mqtt()

    for _ in range(20):
        if _mqtt_client.is_connected():
            break
        time.sleep(0.5)
    else:
        log.warning("MQTT did not connect within 10s — continuing anyway")


    try:
        run_poll_loop()
    finally:
        log.info("Disconnecting MQTT...")
        _mqtt_client.loop_stop()
        _mqtt_client.disconnect()
        log.info("Canton Smart Soundbar monitor stopped.")


if __name__ == "__main__":
    main()
