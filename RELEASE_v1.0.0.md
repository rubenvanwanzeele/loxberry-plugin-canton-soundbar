## What's new

First stable release of the Canton Smart Soundbar plugin for LoxBerry.
Built and tested with LoxBerry 3.0.1.3 and a Canton Smart Soundbar 10 using local FFAA control (TCP port `50006`).

## Features

- **Full FFAA control path** — power, volume, input switching, mute, and play mode via local FFAA protocol (`50006`)
- **Two-way MQTT integration** — retained outbound state topics for `state`, `volume`, `mute`, `input`, `sound_mode`
- **Single inbound command topic** — all commands on one topic (`.../cmd`) with clean alias-based input commands (`input_tv`, `input_dvd`, ...)
- **Input source mapping support** — configurable source tuples in web UI (`FFAA input map`) with default order aligned to real-device testing
- **Live web UI controls** — quick command buttons, active input highlighting, clickable power badge, and live state refresh
- **Systemd-based daemon setup** — clean LoxBerry 3 install/upgrade behavior with service management and config persistence

## MQTT command set

- Power: `power_on`, `power_off`
- Volume: `volume_set_N`, `volume_up`, `volume_down`
- Mute: `mute_on`, `mute_off`, `mute_toggle`
- Inputs (alias based): `input_bdp`, `input_sat`, `input_ps`, `input_tv`, `input_cd`, `input_dvd`, `input_aux`, `input_net`, `input_bt`
- Play mode: `mode_stereo`, `mode_movie`, `mode_music`

## Installation

In LoxBerry: **System -> Plugin Manager -> Install from URL**

https://github.com/rubenvanwanzeele/loxberry-plugin-canton-soundbar/archive/refs/tags/v1.0.0.zip

## Requirements

- LoxBerry 3.0.1.3 or newer
- Canton Smart Soundbar reachable on local network
- Loxone / MQTT-capable automation side

## Notes

- Integration is fully local; no cloud dependency.
- Input tuple mappings can vary by firmware/model and are editable in plugin configuration.
- Legacy HTTP fallback for mute remains available as backup, but FFAA mute (`0x0009`) is implemented and used as primary path.

