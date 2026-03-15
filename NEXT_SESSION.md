# Next Session Handoff

## 2026-03-15 Checkpoint (latest)

### Release status

- Stable release prepared and shipped as `v1.0.0`.
- `master` includes the `1.0.0` version bump in `plugin.cfg`.
- Git tag created and pushed: `v1.0.0`.
- Install URL (tagged):
  - `https://github.com/rubenvanwanzeele/loxberry-plugin-canton-soundbar/archive/refs/tags/v1.0.0.zip`

### Current architecture

- Control path is FFAA-first over TCP `50006`.
- Confirmed working over FFAA:
  - Power (`0x0006`)
  - Volume (`0x000C`)
  - Input/mode (`0x0003`)
  - Mute (`0x0009`)
- HTTP on `1904` remains fallback-only for mute when needed.
- MQTT contract simplified:
  - Outbound: `state`, `volume`, `mute`, `input`, `sound_mode`
  - Inbound: single command topic `cmd`
- Input commands are alias-first (`input_tv`, `input_dvd`, etc.).

### UI and config state

- Web UI includes:
  - Live status card with clickable power badge
  - Input buttons with active source highlight
  - Mute buttons: `Mute`, `Mute Toggle`, `Unmute`
  - Command center examples using alias-style inputs
- Input order aligned to tested user preference:
  - `BDP`, `SAT`, `PS`, `TV`, `CD`, `DVD`, `AUX`, `NET`, `BT`
- Obsolete topic fields (`input_name`, `input_map`, trace topics) removed from active config/UI flow.

---

## What To Do Next (future change prep)

### 1) Keep release quality high

- For each protocol/UI change, test all command families end-to-end:
  - `power_*`, `volume_*`, `mute_*`, `input_*`, `mode_*`
- Validate both:
  - MQTT command ingestion
  - MQTT retained state publication
- Keep changes documented in `README.md` and `webfrontend/htmlauth/help.html` in the same PR.

### 2) Input mapping lifecycle

- Treat `[FFAA_INPUTS]` as device/firmware dependent.
- When discovering tuple changes:
  - update default map in `bin/monitor.py`, `config/cantonbar.cfg`, and `webfrontend/htmlauth/index.php`
  - keep aliases human-first (`TV`, `DVD`, etc.)
- Prefer alias commands in docs and automations (`input_tv`) over numeric IDs.

### 3) Mute robustness follow-up

- FFAA mute (`0x0009`) is now primary and confirmed.
- If users report regressions:
  - capture FFAA readback for `0x0009` before/after command
  - only then adjust fallback behavior
- Keep `unsupported` as a valid temporary state only during communication loss.

### 4) Suggested post-1.0 improvements

- Add lightweight diagnostics section in UI:
  - last successful poll timestamp
  - last command result summary
- Optional: add a "capabilities" state topic (e.g. reports available commands/features).
- Optional: add integration tests for command parsing and mapping resolution in `bin/monitor.py`.

---

## Quick release workflow (next versions)

1. Update functionality + docs
2. Validate on device
3. Bump `plugin.cfg` version
4. Commit and push `master`
5. Create and push annotated tag (`vX.Y.Z`)
6. Publish release notes + tag ZIP install URL

---

## Local workspace note

At handoff time, this repository may still contain local-only artifacts outside release commits (for example session notes or testing files). Keep release commits scoped to plugin runtime/UI/docs assets only.
