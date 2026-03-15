# Next Session Handoff

## 2026-03-15 Checkpoint (latest)

- Major breakthrough: the Soundbar 10 speaks the same **FFAA binary protocol over TCP port `50006`** as the public Home Assistant `ha-canton-smart-connect` project.
- Physically confirmed on the real device:
  - `FFAA CMD_POWER (0x0006)` payload `0x00` → standby works
  - `FFAA CMD_POWER (0x0006)` payload `0x01` → power on works
  - `FFAA CMD_VOLUME (0x000C)` payload raw volume byte → volume changes audibly
  - `FFAA CMD_INPUT_MODE (0x0003)` works for at least these tuples:
    - source `0` / `NET` → bytes `17,13` with mode `02`
    - source `3` / `ARC` → bytes `06,02` with mode `02`
- Pure FFAA rewrite started:
  - `bin/monitor.py` was rewritten to use TCP `50006` for power/input/volume polling and commands.
  - `webfrontend/htmlauth/index.php` was redesigned around the new FFAA backend with a Samsung-inspired card layout.
  - `config/cantonbar.cfg` now defaults to `PORT=50006` and has an `[FFAA_INPUTS]` section.
  - `plugin.cfg` version bumped to `0.2.0`.
- Important limitation:
  - mute is **not yet reverse-engineered on FFAA**; new daemon publishes `unsupported` instead of faking mute state.

### Next validation focus

1. Install the pure-FFAA rewrite on LoxBerry and verify end-to-end MQTT commands:
   - `power_on`
   - `power_off`
   - `volume_up` / `volume_down` / `volume_set_N`
   - `input_0` / `input_3`
2. Confirm the redesigned UI feels aligned enough with the Samsung plugin.
3. Reverse-engineer FFAA mute (or consciously keep it unsupported in pure-FFAA mode).
4. Expand `[FFAA_INPUTS]` mappings with more physically confirmed tuples if needed.

## 2026-03-14 Checkpoint (latest)

- `bin/monitor.py` now includes a post-power-command transition window with target tracking.
- Auto-recovery suppression is limited to `power_off` standby grace only.
- `power_on` still allows normal API-failure counting/recovery during wake path.
- Explicit trace/log added: `api_down_expected_after_standby` when API loss is expected.
- Default grace config added: `MONITOR.POWER_TRANSITION_GRACE_SECONDS=25` in `config/cantonbar.cfg`.

### Remaining before release/install URL

1. Validate with real device logs that `power_off` no longer triggers recovery inside grace.
2. Sanity-check that `power_on` still recovers if API stays down unexpectedly.
3. Commit and push to `origin/master`.
4. Publish the install URL (test URL: `master.zip`; release URL after tagging).

This file tells the next AI agent exactly where we left off and what to do next.

---

## Current State

The plugin skeleton is **complete and committed**. All files are in place (see structure below).
The plugin has NOT been installed on LoxBerry yet — it still needs to be packaged and tested.

The soundbar was turned off at the end of the session. On next session:
1. Turn on the soundbar (Canton Smart Soundbar 10 at 192.168.1.20)
2. Reboot or wait — LibreKNX (port 1904) should restart automatically
3. Run the verification tests below before doing anything else

---

## What Needs Verification (do this first)

The LibreKNX HTTP API at `http://192.168.1.20:1904/canton` is the primary control interface.
Three things were not confirmed working before the session ended:

### 1. Volume SET

**Test:**
```bash
curl -s -X POST "http://192.168.1.20:1904/canton?action=volume" \
  -H "Content-Type: application/json" -d '{"volume": 60}'
# Listen: does the soundbar volume change?
```
Expected response (success): `{"status": 101, "statusString": "ERROR-OK", "KNXError": 0}`

If volume doesn't change, try uppercase field name:
```bash
curl -s -X POST "http://192.168.1.20:1904/canton?action=volume" \
  -H "Content-Type: application/json" -d '{"Volume": 60}'
```

Check current volume after:
```bash
curl -s "http://192.168.1.20:1904/canton?action=status" | python3 -m json.tool
```

**Update in `bin/monitor.py`:** The `volume_set_X`, `volume_up`, `volume_down` command handlers
use `{"volume": N}` (lowercase). Change to `{"Volume": N}` if uppercase is needed.

---

### 2. Network Standby (power_off)

**Test:**
```bash
curl -s -X POST "http://192.168.1.20:1904/canton?action=standby" \
  -H "Content-Type: application/json" -d '{"standby": true}'
# Does the soundbar go into standby?
```

If that returns ERROR-INVALID-ACTION, try:
```bash
# Try different action names
curl -s -X POST "http://192.168.1.20:1904/canton?action=networkstandby" \
  -H "Content-Type: application/json" -d '{}'

curl -s -X POST "http://192.168.1.20:1904/canton?action=poweroff" \
  -H "Content-Type: application/json" -d '{}'
```

You can also find the correct action by checking the LibreKNX binary on the soundbar via ADB:
```bash
adb connect 192.168.1.20:5555
adb -s 192.168.1.20:5555 shell "strings /system/bin/LibreKNX 2>/dev/null | grep -iE 'standby|power|networkstandby'" | sort -u
```

**Update in `bin/monitor.py`:** The `power_off` handler (around line 108) currently uses
`api_post("standby", {"standby": True})`. Update action name/body based on test result.

---

### 3. Input Source Switching

**Test:**
```bash
# First check current input
curl -s "http://192.168.1.20:1904/canton?action=input"
# → {"InputSource":"3"}  (current source is 3)

# Try switching sources
curl -s -X POST "http://192.168.1.20:1904/canton?action=input" \
  -H "Content-Type: application/json" -d '{"InputSource": "1"}'
# Does the input change?
```

Note: `{"inputsource": "3"}` (lowercase) returned ERROR-INVALID-ACTION before.
Try `{"InputSource": "N"}` (capitalized to match the GET response key).

To find all available sources, explore via ADB:
```bash
adb -s 192.168.1.20:5555 shell "strings /system/bin/LibreKNX 2>/dev/null | grep -iE 'hdmi|optical|bluetooth|aux|analog|usb|source' | sort -u"
```

**Update in `bin/monitor.py`:** The `input_N` handler (around line 128) uses
`api_post("input", {"inputsource": source})`. Fix body key if needed.

---

## API Reference (confirmed working)

All requests: `http://192.168.1.20:1904/canton`

### GET requests (all confirmed)

```bash
# Power state: ON or STANDBY
curl -s "http://192.168.1.20:1904/canton?action=powerstatus"
# → {"PowerStatus":"ON"}

# Full status (volume, mute, playback)
curl -s "http://192.168.1.20:1904/canton?action=status"
# → {"Volume":42,"MuteStatus":false,"PlayStatus":"STOP","PlaybackSource":0,...}

# Current input source
curl -s "http://192.168.1.20:1904/canton?action=input"
# → {"InputSource":"3"}

# Device info
curl -s "http://192.168.1.20:1904/canton?action=info"
# → {"DeviceName":"Living Room speaker","ModelName":"Smart Soundbar 10","MACAddress":"cc:90:93:1d:82:f4",...}

# Connection status
curl -s "http://192.168.1.20:1904/canton?action=connectionstatus"
# → {"ConnectionStatus":"Active ETH Connected"}
```

### POST requests (confirmed working)

```bash
# Mute (CONFIRMED - audibly verified)
curl -s -X POST "http://192.168.1.20:1904/canton?action=mute" \
  -H "Content-Type: application/json" -d '{"mute": true}'
# → {"status": 101, "statusString": "ERROR-OK", "KNXError": 0}

# Unmute (CONFIRMED)
curl -s -X POST "http://192.168.1.20:1904/canton?action=mute" \
  -H "Content-Type: application/json" -d '{"mute": false}'
```

### Response codes

| status | statusString          | Meaning              |
|--------|-----------------------|----------------------|
| 101    | ERROR-OK              | Success              |
| 302    | ERROR-INVALID-ACTION  | Wrong action or body |
| 422    | ERROR-MISSING-ACTION  | No action parameter  |

---

## Plugin File Structure

```
loxberry-plugin-canton-soundbar/
├── plugin.cfg                  NAME=cantonbar, VERSION=0.1.0
├── apt12                       python3-pip
├── postinstall.sh              pip3 install paho-mqtt wakeonlan requests
├── postroot.sh                 creates systemd service cantonbar.service
├── preupgrade.sh               backs up config, stops service
├── postupgrade.sh              restores config, re-installs pip deps
├── sudoers/cantonbar           systemctl start/stop/restart/status as loxberry
├── uninstall/uninstall.sh      removes systemd service
├── bin/monitor.py              main daemon (see below)
├── config/cantonbar.cfg        default config (IP/MAC blank, topics set)
├── webfrontend/htmlauth/
│   ├── index.php               web UI (config, live status, test controls)
│   └── help.html               help page
├── templates/lang/
│   ├── en.json
│   └── nl.json
└── icons/                      placeholder icons (replace with Canton icons)
```

Systemd service: `cantonbar.service`
- ExecStart: `python3 /opt/loxberry/bin/plugins/cantonbar/monitor.py --config ... --logfile ...`
- Restart: on-failure, RestartSec: 10

---

## MQTT Topics

All configurable in the web UI. Defaults:

| Topic                              | Direction        | Values           |
|------------------------------------|------------------|------------------|
| `loxberry/plugin/cantonbar/state`  | plugin → Loxone  | `on` / `standby` |
| `loxberry/plugin/cantonbar/volume` | plugin → Loxone  | `0`–`100`        |
| `loxberry/plugin/cantonbar/mute`   | plugin → Loxone  | `on` / `off`     |
| `loxberry/plugin/cantonbar/input`  | plugin → Loxone  | source number    |
| `loxberry/plugin/cantonbar/cmd`    | Loxone → plugin  | command string   |

Commands:
`power_on`, `power_off`, `volume_set_N`, `volume_up`, `volume_down`,
`mute_on`, `mute_off`, `mute_toggle`, `input_N`

---

## After Verification: What Else To Do

1. **Fix the 3 unverified commands** based on test results (see above)
2. **Map input source numbers to names** — add a config field or static lookup in the web UI
   so users can see "HDMI ARC" instead of "3"
3. **Replace placeholder icons** with Canton-branded icons (64/128/256/512 px)
4. **Package and install** on LoxBerry: `zip -r plugin.zip . -x '*.git*'`
5. **Test end-to-end** on LoxBerry with real MQTT broker

---

## Full Discovery Results (from initial probing session)

### Network / Open Ports

IP: `192.168.1.20`, MAC: `CC:90:93:1D:82:F4` (wired ethernet)

| Port  | Protocol | Service              | Notes |
|-------|----------|----------------------|-------|
| 22    | TCP      | SSH                  | OpenSSH, accessible |
| 80    | TCP      | HTTP (GoAhead)       | Serves `/lsync/web/` — admin UI only (device name, firmware, logs). UPnP description.xml here but control endpoints return 404 |
| 1904  | TCP      | LibreKNX HTTP API    | **Primary control API** — see above |
| 5555  | TCP      | ADB                  | Android Debug Bridge — shell access confirmed |
| 7000  | TCP      | AirPlay              | AirTunes/366.0 |
| 7777  | TCP      | LUCI service         | Binary protocol, not HTTP |
| 8008  | TCP      | Google Cast (HTTP)   | Cast discovery, eureka_info works |
| 8009  | TCP      | Google Cast (HTTPS)  | Cast encrypted channel |
| 8012  | TCP      | Cast (IPv6)          | |
| 8443  | TCP      | Cast HTTPS           | TLS cert: "Canton Elektronik IQ Soundbar 10", eureka_info works here too |
| 8800  | TCP      | Cast WebSocket       | Hangs on HTTP GET |
| 9000  | TCP      | Unknown              | Times out |
| 9095  | TCP      | eSDK server          | Returns 404 for all paths tried |

### Cast API (port 8443) — Tested

`GET https://192.168.1.20:8443/setup/eureka_info` works and returns full device JSON:
```json
{
  "device_info": {
    "manufacturer": "Canton Elektronik GmbH + Co. KG",
    "model_name": "Smart Soundbar 10",
    "mac_address": "CC:90:93:1D:82:F4",
    "capabilities": { "input_management_supported": true, ... }
  },
  "settings": { "network_standby": 0, "wake_on_cast": 1 },
  "name": "Living Room speaker"
}
```

`POST https://192.168.1.20:8443/setup/set_volume` — **did not work** (empty response, no effect).
Cast volume control requires a local auth token that we don't have.

### UPnP (port 80) — Tested

`GET http://192.168.1.20/description.xml` works — confirms RenderingControl + AVTransport + QPlay.
BUT all control URLs (`/RenderingControl`, `/AVTransport`) return 404 on port 80.
Internal description.xml (at `/system/usr/description.xml` via ADB) shows the same structure —
the GoAhead webserver simply doesn't implement UPnP control.

### ADB Session — Key Findings

Device runs Android (build: `chickentikka-eng 1.52`). Root shell via `adb -s 192.168.1.20:5555 shell`.

Key running services found in `/system/bin/`:
- `LibreKNX` — serves port 1904, started by `LibreManager`
- `luci_service` — LUCI binary protocol on port 7777
- `ampservice` — amplifier control service
- `airplay_v2` — AirPlay server
- `spotifyhifi` — Spotify Connect
- `castlucicomm` — Cast ↔ LUCI bridge
- `LSDeviceService` — LUCI discovery and stereo pairing

Volume via ALSA (ADB only, not used by daemon):
```bash
amixer set Master 50%     # set volume 0-100 — CONFIRMED WORKING
amixer set Master mute    # CONFIRMED WORKING
amixer set Master unmute  # CONFIRMED WORKING
```

System properties of interest:
```
current_source = 19     # NOTE: differs from LibreKNX InputSource=3 (see below)
VolumeFeedback = TRUE:19
GlobalVolume = 0
MRAMode = APMS
```

### Input Source Discrepancy

`getprop current_source` returns `19`, but `GET /canton?action=input` returns `{"InputSource":"3"}`.
These are different numbering schemes. The LibreKNX API uses its own source IDs.
Input source 3 was active during testing but its friendly name is unknown.
Investigate by switching physical inputs and watching which number changes.

### LibreKNX Crash Note

LibreKNX (`/system/bin/LibreKNX`, PID ~1613) crashed during testing when a POST
with empty body was sent. Port 1904 went offline and the process disappeared.
LibreManager normally starts it at boot — **a soundbar reboot restores it**.
After the crash, manual restart attempts (`nohup LibreKNX &`) did not work,
likely because LibreManager sets up required context before launching it.

---

## ADB Access (for debugging only)

The soundbar runs Android and exposes ADB on port 5555:
```bash
adb connect 192.168.1.20:5555
adb -s 192.168.1.20:5555 shell
```

Useful ADB commands:
```bash
# Direct volume control (fallback if HTTP API fails)
adb -s 192.168.1.20:5555 shell "amixer set Master 50%"
adb -s 192.168.1.20:5555 shell "amixer set Master mute"
adb -s 192.168.1.20:5555 shell "amixer set Master unmute"

# Get current state
adb -s 192.168.1.20:5555 shell "getprop current_source"
adb -s 192.168.1.20:5555 shell "getprop VolumeFeedback"

# If LibreKNX (port 1904) is down, restart via LibreManager
adb -s 192.168.1.20:5555 shell "ps | grep LibreKNX"
# If not running, reboot the soundbar to restore it
```

LibreKNX is started by LibreManager and restarts on soundbar reboot.
It crashed once during testing when an empty POST body was sent — always include a JSON body.
