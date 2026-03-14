# CLAUDE.md — Developer Context for Canton Smart Soundbar Plugin

---

## What This Plugin Does

Two-way local integration between a Canton Smart Soundbar 10 and Loxone via MQTT.
No cloud. Direct local HTTP API only.

- **State reading**: power (on/standby), volume (0-100), mute (on/off), input source → MQTT
- **Command sending**: MQTT commands → soundbar HTTP API

---

## Plugin Identity (NEVER change after first release)

| Field         | Value              |
|---------------|--------------------|
| PLUGIN NAME   | `cantonbar`        |
| PLUGIN FOLDER | `cantonbar`        |
| PLUGIN TITLE  | `Canton Smart Soundbar` |
| LB_MINIMUM    | `3.0.1.3`          |
| INTERFACE     | `2.0`              |

---

## Hardware Under Test

- **Soundbar**: Canton Smart Soundbar 10
- **Soundbar IP**: `192.168.1.20` (wired ethernet)
- **Soundbar MAC**: `CC:90:93:1D:82:F4` (for Wake-on-LAN)

---

## Technology Stack

| Layer          | Choice           |
|----------------|------------------|
| Backend daemon | Python 3         |
| Soundbar comms | `requests` HTTP  |
| Wake-on-LAN    | `wakeonlan`      |
| MQTT client    | `paho-mqtt`      |
| Web UI         | PHP (LoxBerry SDK) |
| Config format  | INI              |

---

## Soundbar Local API (LibreKNX HTTP, port 1904)

All requests go to `http://<IP>:1904/canton`.

### Confirmed working (GET)

| Action          | Response                                                |
|-----------------|---------------------------------------------------------|
| `status`        | `{"Volume":42,"MuteStatus":false,"PlayStatus":"STOP","PlaybackSource":0,...}` |
| `powerstatus`   | `{"PowerStatus":"ON"}` or `{"PowerStatus":"STANDBY"}`  |
| `input`         | `{"InputSource":"3"}`                                   |
| `info`          | `{"DeviceName":"...","ModelName":"Smart Soundbar 10",...}` |
| `connectionstatus` | `{"ConnectionStatus":"Active ETH Connected"}`        |

### Confirmed working (POST with JSON body)

| Action  | Body                  | Effect              |
|---------|-----------------------|---------------------|
| `mute`  | `{"mute": true}`      | Mute soundbar ✓     |
| `mute`  | `{"mute": false}`     | Unmute soundbar ✓   |

### To verify during testing

| Action     | Body                    | Expected effect        |
|------------|-------------------------|------------------------|
| `volume`   | `{"volume": 50}`        | Set volume — returned "ERROR-OK" but not confirmed audibly (LibreKNX crashed before verification) |
| `standby`  | `{"standby": true}`     | Network standby — unverified |
| `input`    | `{"inputsource": "N"}`  | Switch input — returned ERROR-INVALID-ACTION; correct body format unknown |

### Response codes

- `{"status": 101, "statusString": "ERROR-OK"}` = success
- `{"status": 302, "statusString": "ERROR-INVALID-ACTION"}` = wrong action or body
- `{"status": 422, "statusString": "ERROR-MISSING-ACTION"}` = no action parameter

### LibreKNX crash note

The LibreKNX service (which serves port 1904) crashed when receiving a POST with
an empty body. Always send a valid JSON body. On next soundbar reboot it restarts
automatically via LibreManager.

---

## Input Source Numbers

Input source numbers (from `action=input`) are not yet mapped to friendly names.
Known: `3` was active during initial testing (source unknown — possibly ARC/HDMI).
Needs investigation to map numbers to: HDMI ARC, AUX, Bluetooth, Optical, etc.

---

## MQTT Topics

### State (plugin → Loxone)

| Topic                              | Values           | Notes    |
|------------------------------------|------------------|----------|
| `loxberry/plugin/cantonbar/state`  | `on` / `standby` | retained |
| `loxberry/plugin/cantonbar/volume` | `0`–`100`        | retained |
| `loxberry/plugin/cantonbar/mute`   | `on` / `off`     | retained |
| `loxberry/plugin/cantonbar/input`  | source number    | retained |

### Commands (Loxone → plugin)

| Payload          | Action                              |
|------------------|-------------------------------------|
| `power_on`       | Wake-on-LAN to MAC                  |
| `power_off`      | Network standby via HTTP API        |
| `volume_set_N`   | Set volume to N (0-100)             |
| `volume_up`      | Increase by VOLUME_STEP (default 5) |
| `volume_down`    | Decrease by VOLUME_STEP             |
| `mute_on`        | Mute                                |
| `mute_off`       | Unmute                              |
| `mute_toggle`    | Toggle mute                         |
| `input_N`        | Switch to input source N            |

---

## Known Gotchas

- **LibreKNX owns port 1904** — this is a Canton/LibreWireless internal service, not
  a public documented API. It may change with firmware updates.
- **LibreKNX single-threaded** — do not send concurrent requests; it handles one at a time.
- **Power-off = network standby** — the soundbar never fully powers off over the network.
  It stays reachable on the network in standby (responds to `powerstatus` → STANDBY).
- **Wake-on-LAN** — confirmed MAC CC:90:93:1D:82:F4. Requires "Power On with Mobile"
  or equivalent enabled in soundbar settings.
- **Volume POST unconfirmed** — returned ERROR-OK but audible change not verified.
  May need a different field name (e.g. `Volume` vs `volume`).

---

## Other Discovered APIs

- **Cast API** (port 8008/8443): eureka_info, device info. `set_volume` did not work.
- **ADB** (port 5555): full Android shell access. `amixer set Master X%` confirmed working
  for volume. ADB is a fallback only — not used in production daemon.
- **AirPlay** (port 7000): AirTunes/366.0 — media playback only.
- **UPnP** (port 80): description.xml present, control endpoints return 404.

---

## LoxBerry 3 Plugin Development — Key Rules

Learned from building the Samsung Frame TV plugin. Violating these causes silent failures.

### Directory structure — NO doubling

LoxBerry prepends `plugins/FOLDER/` to every path automatically. Never add an
extra `cantonbar/` subfolder inside `bin/`, `config/`, `webfrontend/htmlauth/`, or `templates/`.

| Repo path                          | Installed path                                      |
|------------------------------------|-----------------------------------------------------|
| `bin/monitor.py`                   | `/opt/loxberry/bin/plugins/cantonbar/monitor.py`    |
| `config/cantonbar.cfg`             | `/opt/loxberry/config/plugins/cantonbar/cantonbar.cfg` |
| `webfrontend/htmlauth/index.php`   | `/opt/loxberry/webfrontend/htmlauth/plugins/cantonbar/index.php` |
| `templates/lang/en.json`           | `/opt/loxberry/templates/plugins/cantonbar/lang/en.json` |

Variables available in shell scripts and PHP:
- `$LBPBIN` = `/opt/loxberry/bin/plugins/cantonbar`
- `$LBPCONFIG` = `/opt/loxberry/config/plugins/cantonbar`
- `$LBPLOG` = `/opt/loxberry/log/plugins/cantonbar`

### PHP — reserved variable name

LoxBerry's `loxberry_system.php` declares a global `$cfg` (stdClass). **Never use `$cfg`
for your own config** — it will silently break. Use `$plugin_cfg` or any other name.

### MQTT credentials

Not in a plugin config file. Read from `/opt/loxberry/config/system/general.json`
under `Mqtt.Brokerhost`, `Mqtt.Brokerport`, `Mqtt.Brokeruser`, `Mqtt.Brokerpass`.
In PHP: `mqtt_connectiondetails()` from `loxberry_io.php`.
In Python (as used in `bin/monitor.py`): parse the JSON directly.

### No daemon/ scripts in LoxBerry 3

LoxBerry 3 runs on DietPi (systemd). There is no `daemon/` mechanism.
Use `postroot.sh` to create a systemd service instead (as this plugin does).

### postroot.sh vs postinstall.sh

- `postroot.sh` runs as **root** — use for systemd, sudoers, system-level setup.
- `postinstall.sh` runs as the **loxberry user** — use for pip installs, file setup.

### Config is overwritten on every install/upgrade

LoxBerry replaces plugin config files on every install. The `preupgrade.sh` /
`postupgrade.sh` scripts back up and restore `cantonbar.cfg` to preserve user settings.

### 4 icon sizes required

`icon_64.png`, `icon_128.png`, `icon_256.png`, `icon_512.png` — all must exist.
Missing sizes cause install warnings (currently using placeholder icons).

### Blank PHP page = suppressed fatal error

Add at the top of `index.php` for debugging:
```php
error_reporting(E_ALL); ini_set('display_errors', 1);
```
Remove before release.

### journalctl not available

The `loxberry` user has no journal read access. Always use file-based logging
(the daemon writes to `$LBPLOG/monitor.log` via Python's RotatingFileHandler).

### sudoers file

To allow the `loxberry` user to run `systemctl` for the plugin service,
a sudoers file is provided at `sudoers/cantonbar`. LoxBerry installs it to
`/etc/sudoers.d/cantonbar` automatically.
