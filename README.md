# Canton Smart Soundbar LoxBerry Plugin

Local MQTT integration for Canton Smart Soundbar (FFAA over TCP port `50006`).

## MQTT Contract

All commands go to one configured command topic (default: `loxberry/plugin/cantonbar/cmd`).

### State topics (plugin -> MQTT)

- `loxberry/plugin/cantonbar/state`: `on` | `standby`
- `loxberry/plugin/cantonbar/volume`: `0..100`
- `loxberry/plugin/cantonbar/mute`: `on` | `off` | `unsupported`
- `loxberry/plugin/cantonbar/input`: source id (`0..8` by default)
- `loxberry/plugin/cantonbar/input_name`: friendly source name (`ARC`, `DVD`, ...)
- `loxberry/plugin/cantonbar/input_map`: JSON source map
- `loxberry/plugin/cantonbar/sound_mode`: `Stereo` | `Movie` | `Music`

### Commands (MQTT -> plugin)

- Power: `power_on`, `power_off`
- Volume: `volume_set_N`, `volume_up`, `volume_down`
- Mute: `mute_on`, `mute_off`, `mute_toggle` (HTTP fallback while FFAA mute is not yet confirmed)
- Input by id: `input_3`
- Input by name alias: `input_arc`, `input_dvd`, `input_bt`, ...
- Play mode: `mode_stereo`, `mode_movie`, `mode_music`

## FFAA Notes

- Play mode is implemented with `CMD_INPUT_MODE (0x0003)` and payload byte 3 (`1=Stereo`, `2=Movie`, `3=Music`).
- Input switching uses the same `CMD_INPUT_MODE (0x0003)` with bytes 1+2 from `[FFAA_INPUTS]`.
- Mute over FFAA is still unconfirmed in this repo; mute commands currently use optional HTTP fallback.

## Default Input Mapping

Configured in `config/cantonbar.cfg` section `[FFAA_INPUTS]`:

- `0=17,13,NET`
- `1=01,03,BDP`
- `2=02,04,SAT`
- `3=06,02,ARC`
- `4=03,0E,CD`
- `5=07,05,DVD`
- `6=0F,12,AUX`
- `7=15,14,BT`
- `8=0B,06,COAX`

You can edit these from the web UI (`FFAA input map`) if your device reports different tuples.

## Dev: regenerate icons

```bash
python3 icons/generate_icons.py
```

