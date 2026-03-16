# Canton Smart Soundbar – LoxBerry Plugin

A [LoxBerry 3](https://www.loxberry.de) plugin for two-way **local** integration between a
Canton Smart Soundbar and [Loxone](https://www.loxone.com) via MQTT.

No cloud. Direct local control only via the FFAA protocol over TCP port `50006`.

---

## Features

- Detects soundbar state — **on / standby** — and publishes to MQTT every 5 seconds
- Reports **volume** (0–100), **mute** state, **active input** and **play mode**
- Sends commands from Loxone: power, volume, mute, input switching and play mode
- Alias-based input commands — use names like `input_tv`, `input_bt` instead of raw IDs
- Configurable input source map in the web UI — adapt to your firmware if needed
- Web UI with live status, quick test controls and log viewer
- Systemd-based daemon with clean LoxBerry 3 install and upgrade behaviour
- Configuration is preserved across plugin upgrades

---

## Requirements

| Requirement | Details |
|---|---|
| LoxBerry | 3.0.1.3 or newer |
| Canton Smart Soundbar | Reachable on local network |
| Loxone Miniserver | Any model with MQTT support |

---

## Installation

In LoxBerry: **System → Plugin Manager → Install from URL**

```
https://github.com/rubenvanwanzeele/loxberry-plugin-canton-soundbar/archive/refs/tags/v1.0.0.zip
```

Python dependencies (`paho-mqtt`, `requests`, `wakeonlan`) are installed automatically.

---

## Setup

### 1. Configure

Open the plugin page in LoxBerry.

- Enter the **IP address** of your soundbar (find it in your router's DHCP list).
- Leave the **FFAA port** at `50006` unless your firmware uses a different port.
- Set the **volume step** for `volume_up` / `volume_down` commands (default: 5).
- Adjust MQTT topics if you need them to match an existing Loxone setup.
- Click **Save Configuration** — the daemon restarts automatically.

### 2. Verify

After saving, the **Live Status** row at the top of the page updates every 5 seconds.
You should see the soundbar's current power state, volume, input and play mode within a few seconds.

### 3. Configure input sources (optional)

The **FFAA input map** in the configuration form defines which source IDs map to which names.
The defaults work for most Canton Smart Soundbar 10 units but the FFAA byte tuples can vary by firmware.
If an input switch command does not change the source, use the quick test buttons and check the log to identify the correct tuples, then update the map.

---

## MQTT Topics

### State (plugin → Loxone)

| Topic | Values | Notes |
|---|---|---|
| `loxberry/plugin/cantonbar/state` | `on` / `standby` | retained |
| `loxberry/plugin/cantonbar/volume` | `0`–`100` | retained, integer |
| `loxberry/plugin/cantonbar/mute` | `on` / `off` | retained |
| `loxberry/plugin/cantonbar/input` | friendly name, e.g. `TV` | retained |
| `loxberry/plugin/cantonbar/sound_mode` | `Stereo` / `Movie` / `Music` | retained |

All topics are **retained** — Loxone receives the last known value immediately on connect.

### Commands (Loxone → plugin)

Publish any of the following payloads to the command topic (default: `loxberry/plugin/cantonbar/cmd`):

| Payload | Action |
|---|---|
| `power_on` | Power on the soundbar |
| `power_off` | Put the soundbar in standby |
| `volume_set_40` | Set volume to 40 (replace with any value 0–100) |
| `volume_up` | Increase volume by one step |
| `volume_down` | Decrease volume by one step |
| `mute_on` | Mute |
| `mute_off` | Unmute |
| `mute_toggle` | Toggle mute |
| `input_tv` | Switch to TV input |
| `input_dvd` | Switch to DVD input |
| `input_bt` | Switch to Bluetooth |
| `input_aux` | Switch to AUX |
| `input_<name>` | Switch to any configured input by its alias name |
| `mode_stereo` | Set play mode to Stereo |
| `mode_movie` | Set play mode to Movie |
| `mode_music` | Set play mode to Music |

The command topic is configurable in the plugin web UI.

---

## Loxone Integration

### Receive soundbar state

1. In Loxone Config add a **Virtual Input** → type **Text** → **MQTT**
2. Set the topic, e.g. `loxberry/plugin/cantonbar/state`
3. Enable **Retain**
4. Use a **Formula** block to convert to a number if needed:
   - `IF(AQ == "on", 1, 0)` — 1 when the soundbar is on

For volume, use the `loxberry/plugin/cantonbar/volume` topic and parse it as a number directly.

### Send commands

1. Add a **Virtual Output** → type **MQTT**
2. Topic: `loxberry/plugin/cantonbar/cmd`
3. Set the payload to the command you want to send, e.g. `power_off`

### Example automations

- TV turns on → switch soundbar input to `input_tv` and power it on
- Leaving the house → publish `power_off`
- It's movie night → publish `mode_movie` then `volume_set_35`
- No presence in TV area → publish `power_off` after a delay

---

## Default Input Sources

These are the defaults that ship with the plugin, matching a Canton Smart Soundbar 10:

| ID | Name | FFAA bytes |
|---|---|---|
| 0 | BDP | `01,03` |
| 1 | SAT | `02,04` |
| 2 | PS | `03,0E` |
| 3 | TV | `06,02` |
| 4 | CD | `07,05` |
| 5 | DVD | `0B,06` |
| 6 | AUX | `0F,12` |
| 7 | NET | `17,13` |
| 8 | BT | `15,14` |

You can rename sources and adjust byte tuples in the **FFAA input map** section of the web UI.
Use the name (e.g. `TV`) as the alias in commands: `input_tv`.

---

## Upgrades

Plugin upgrades preserve your configuration automatically — IP, topics, poll interval, volume step and input map are all kept. You do not need to re-enter settings after an upgrade.

---

## Troubleshooting

**State stuck at "unknown" after install**
The daemon may not be running or the soundbar is unreachable. Check:
```bash
systemctl status cantonbar.service
tail -f /opt/loxberry/log/plugins/cantonbar/monitor.log
```

**Input switch command has no effect**
The FFAA byte tuples for your soundbar's firmware may differ from the defaults.
Check the log after sending a command and compare the response bytes with the FFAA input map in the web UI.

**Volume commands work but the change is very small or very large**
Adjust the **volume step** in the plugin configuration. The default is 5 (out of 100).

**`power_on` doesn't work**
The soundbar must be in network standby (not fully unplugged) for `power_on` to work.
The soundbar responds to FFAA power-on when it is reachable on the network.

**Daemon keeps restarting**
Check the log for Python errors. The most common cause is an incorrect IP address or a firewall blocking TCP port `50006`.

---

## Tested on

- **Soundbar**: Canton Smart Soundbar 10
- **LoxBerry**: v3.0.1.3, Raspberry Pi
- **Loxone**: Miniserver Gen 2

---

## License

MIT
