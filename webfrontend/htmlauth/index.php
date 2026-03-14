<?php
require_once "/opt/loxberry/libs/phplib/loxberry_system.php";
require_once "/opt/loxberry/libs/phplib/loxberry_web.php";

$lbpconfigdir = $lbpconfigdir ?? "/opt/loxberry/config/plugins/cantonbar";
$lbplogdir    = $lbplogdir    ?? "/opt/loxberry/log/plugins/cantonbar";

// -------------------------------------------------------------------------
// AJAX: live status
// -------------------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'status') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    $state   = trim(shell_exec("mosquitto_sub -h localhost -t 'loxberry/plugin/cantonbar/state'  -C 1 -W 2 2>/dev/null") ?: "unknown");
    $volume  = trim(shell_exec("mosquitto_sub -h localhost -t 'loxberry/plugin/cantonbar/volume' -C 1 -W 2 2>/dev/null") ?: "-");
    $mute    = trim(shell_exec("mosquitto_sub -h localhost -t 'loxberry/plugin/cantonbar/mute'   -C 1 -W 2 2>/dev/null") ?: "-");
    $input   = trim(shell_exec("mosquitto_sub -h localhost -t 'loxberry/plugin/cantonbar/input'  -C 1 -W 2 2>/dev/null") ?: "-");

    echo json_encode([
        'state'   => $state,
        'volume'  => $volume,
        'mute'    => $mute,
        'input'   => $input,
        'updated' => date('H:i:s'),
    ]);
    exit;
}

// -------------------------------------------------------------------------
// Load config
// -------------------------------------------------------------------------
$plugin_cfg = parse_ini_file("$lbpconfigdir/cantonbar.cfg", true);
if ($plugin_cfg === false) {
    $plugin_cfg = [];
}
$sb_ip       = $plugin_cfg['SOUNDBAR']['IP']           ?? '';
$sb_mac      = $plugin_cfg['SOUNDBAR']['MAC']          ?? '';
$vol_step    = $plugin_cfg['SOUNDBAR']['VOLUME_STEP']  ?? '5';
$state_topic = $plugin_cfg['MQTT']['STATE_TOPIC']      ?? 'loxberry/plugin/cantonbar/state';
$volume_topic= $plugin_cfg['MQTT']['VOLUME_TOPIC']     ?? 'loxberry/plugin/cantonbar/volume';
$mute_topic  = $plugin_cfg['MQTT']['MUTE_TOPIC']       ?? 'loxberry/plugin/cantonbar/mute';
$input_topic = $plugin_cfg['MQTT']['INPUT_TOPIC']      ?? 'loxberry/plugin/cantonbar/input';
$cmd_topic   = $plugin_cfg['MQTT']['CMD_TOPIC']        ?? 'loxberry/plugin/cantonbar/cmd';
$poll_int    = $plugin_cfg['MONITOR']['POLL_INTERVAL'] ?? '5';
$loglevel    = $plugin_cfg['MONITOR']['LOGLEVEL']      ?? '3';

$save_msg = '';
$cmd_sent = false;

// -------------------------------------------------------------------------
// Save config
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_config') {
    $new_ip       = trim($_POST['sb_ip']       ?? '');
    $new_mac      = trim($_POST['sb_mac']       ?? '');
    $new_step     = (int)($_POST['vol_step']    ?? 5);
    $new_state_t  = trim($_POST['state_topic']  ?? $state_topic);
    $new_vol_t    = trim($_POST['volume_topic'] ?? $volume_topic);
    $new_mute_t   = trim($_POST['mute_topic']   ?? $mute_topic);
    $new_input_t  = trim($_POST['input_topic']  ?? $input_topic);
    $new_cmd_t    = trim($_POST['cmd_topic']    ?? $cmd_topic);
    $new_poll     = max(1, (int)($_POST['poll_int']  ?? 5));
    $new_ll       = max(1, min(6, (int)($_POST['loglevel'] ?? 3)));

    // Auto-discover MAC via ARP if not supplied
    if (empty($new_mac) && !empty($new_ip)) {
        $arp_out = shell_exec("arp -n " . escapeshellarg($new_ip) . " 2>/dev/null");
        if (preg_match('/([0-9a-f]{2}(?::[0-9a-f]{2}){5})/i', $arp_out, $m)) {
            $new_mac = $m[1];
        }
    }

    $cfg = "[SOUNDBAR]\n";
    $cfg .= "IP=$new_ip\n";
    $cfg .= "MAC=$new_mac\n";
    $cfg .= "VOLUME_STEP=$new_step\n\n";
    $cfg .= "[MQTT]\n";
    $cfg .= "STATE_TOPIC=$new_state_t\n";
    $cfg .= "VOLUME_TOPIC=$new_vol_t\n";
    $cfg .= "MUTE_TOPIC=$new_mute_t\n";
    $cfg .= "INPUT_TOPIC=$new_input_t\n";
    $cfg .= "CMD_TOPIC=$new_cmd_t\n\n";
    $cfg .= "[MONITOR]\n";
    $cfg .= "POLL_INTERVAL=$new_poll\n";
    $cfg .= "LOGLEVEL=$new_ll\n";

    @mkdir($lbpconfigdir, 0755, true);
    file_put_contents("$lbpconfigdir/cantonbar.cfg", $cfg);

    // Restart daemon so new config takes effect
    shell_exec("sudo /bin/systemctl restart cantonbar.service 2>&1");

    // Update local vars for display
    $sb_ip = $new_ip; $sb_mac = $new_mac; $vol_step = $new_step;
    $state_topic = $new_state_t; $volume_topic = $new_vol_t;
    $mute_topic = $new_mute_t; $input_topic = $new_input_t;
    $cmd_topic = $new_cmd_t;
    $poll_int = $new_poll; $loglevel = $new_ll;
    $save_msg = "Configuration saved. Daemon restarted.";
}

// -------------------------------------------------------------------------
// Test command
// -------------------------------------------------------------------------
$refresh_after_cmd = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_cmd') {
    $test_cmd = trim($_POST['test_cmd'] ?? '');
    if ($test_cmd !== '' && $cmd_topic !== '') {
        shell_exec("mosquitto_pub -h localhost -t " . escapeshellarg($cmd_topic) . " -m " . escapeshellarg($test_cmd) . " 2>/dev/null");
        $cmd_sent = true;
        $refresh_after_cmd = true;
    }
}

// -------------------------------------------------------------------------
// Restart daemon
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restart_daemon') {
    shell_exec("sudo /bin/systemctl restart cantonbar.service 2>&1");
    $save_msg = "Daemon restarted.";
}

// -------------------------------------------------------------------------
// Page header
// -------------------------------------------------------------------------
LBWeb::lbheader("Canton Smart Soundbar", "cantonbar", "help.html");
?>

<style>
.cb-section { background:#fff; border:1px solid #ddd; border-radius:6px; padding:20px 24px; margin-bottom:20px; }
.cb-section h3 { margin:0 0 14px; font-size:1.05em; color:#333; border-bottom:1px solid #eee; padding-bottom:8px; }
.cb-grid { display:grid; grid-template-columns:220px 1fr; gap:8px 12px; align-items:center; }
.cb-grid label { font-size:.9em; color:#555; text-align:right; }
.cb-grid input[type=text], .cb-grid input[type=number], .cb-grid select {
    padding:6px 8px; border:1px solid #ccc; border-radius:4px; font-size:.9em; width:100%; box-sizing:border-box;
}
.cb-hint { font-size:.8em; color:#888; grid-column:2; margin-top:-4px; }
.cb-btn { padding:7px 18px; background:#4a90d9; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:.9em; }
.cb-btn:hover { background:#357abd; }
.cb-btn-danger { background:#c0392b; }
.cb-btn-danger:hover { background:#96281b; }
.cb-msg { padding:8px 14px; border-radius:4px; margin-bottom:12px; font-size:.9em; }
.cb-msg-ok { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.status-badge { display:inline-block; padding:4px 12px; border-radius:12px; font-weight:bold; font-size:.85em; }
.badge-on       { background:#d4edda; color:#155724; }
.badge-standby  { background:#f8d7da; color:#721c24; }
.badge-unknown  { background:#e2e3e5; color:#383d41; }
.status-row { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:6px; }
.status-card { background:#f8f9fa; border:1px solid #dee2e6; border-radius:6px; padding:12px; text-align:center; }
.status-card .sc-label { font-size:.75em; color:#6c757d; text-transform:uppercase; letter-spacing:.05em; }
.status-card .sc-value { font-size:1.4em; font-weight:bold; color:#333; margin-top:4px; }
</style>

<div style="max-width:820px;">

<?php if ($save_msg): ?>
<div class="cb-msg cb-msg-ok"><?= htmlspecialchars($save_msg) ?></div>
<?php endif; ?>

<!-- Live Status -->
<div class="cb-section">
    <h3>Live Status</h3>
    <div class="status-row" id="status-row">
        <div class="status-card">
            <div class="sc-label">Power</div>
            <div class="sc-value" id="st-state"><span class="status-badge badge-unknown">…</span></div>
        </div>
        <div class="status-card">
            <div class="sc-label">Volume</div>
            <div class="sc-value" id="st-volume">…</div>
        </div>
        <div class="status-card">
            <div class="sc-label">Mute</div>
            <div class="sc-value" id="st-mute">…</div>
        </div>
        <div class="status-card">
            <div class="sc-label">Input</div>
            <div class="sc-value" id="st-input">…</div>
        </div>
    </div>
    <div style="font-size:.8em;color:#999;margin-top:4px;">Last updated: <span id="st-time">–</span> &nbsp;(auto-refreshes every 5s)</div>
</div>

<!-- Configuration -->
<div class="cb-section">
    <h3>Configuration</h3>
    <form method="post">
    <input type="hidden" name="action" value="save_config">
    <div class="cb-grid">
        <label>Soundbar IP Address</label>
        <input type="text" name="sb_ip" value="<?= htmlspecialchars($sb_ip) ?>" placeholder="192.168.1.x">

        <label>MAC Address (WoL)</label>
        <input type="text" name="sb_mac" value="<?= htmlspecialchars($sb_mac) ?>">
        <div class="cb-hint">Auto-discovered via ARP on save if left blank. Required for power_on.</div>

        <label>Volume Step</label>
        <input type="number" name="vol_step" value="<?= (int)$vol_step ?>" min="1" max="20" style="width:80px;">

        <label>Poll Interval (s)</label>
        <input type="number" name="poll_int" value="<?= (int)$poll_int ?>" min="1" max="60" style="width:80px;">

        <label>Log Level</label>
        <select name="loglevel" style="width:180px;">
            <?php foreach([3=>'Warning',4=>'Info',5=>'Debug'] as $v=>$l): ?>
            <option value="<?=$v?>"<?=$v==(int)$loglevel?' selected':''?>><?=$l?></option>
            <?php endforeach; ?>
        </select>
        <div class="cb-hint">Change takes effect after daemon restart.</div>
    </div>

    <h3 style="margin-top:20px;">MQTT Topics</h3>
    <div class="cb-grid">
        <label>State topic</label>
        <input type="text" name="state_topic" value="<?= htmlspecialchars($state_topic) ?>">

        <label>Volume topic</label>
        <input type="text" name="volume_topic" value="<?= htmlspecialchars($volume_topic) ?>">

        <label>Mute topic</label>
        <input type="text" name="mute_topic" value="<?= htmlspecialchars($mute_topic) ?>">

        <label>Input topic</label>
        <input type="text" name="input_topic" value="<?= htmlspecialchars($input_topic) ?>">

        <label>Command topic</label>
        <input type="text" name="cmd_topic" value="<?= htmlspecialchars($cmd_topic) ?>">
    </div>

    <div style="margin-top:16px;">
        <button type="submit" class="cb-btn">Save Configuration</button>
    </div>
    </form>
</div>

<!-- Test Controls -->
<div class="cb-section">
    <h3>Test Controls</h3>
    <?php if ($cmd_sent): ?>
    <div class="cb-msg cb-msg-ok">Command sent.</div>
    <?php endif; ?>
    <form method="post">
    <input type="hidden" name="action" value="test_cmd">
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
        <button type="submit" name="test_cmd" value="power_on"    class="cb-btn">Power On (WoL)</button>
        <button type="submit" name="test_cmd" value="power_off"   class="cb-btn cb-btn-danger">Standby</button>
        <button type="submit" name="test_cmd" value="volume_up"   class="cb-btn">Vol +</button>
        <button type="submit" name="test_cmd" value="volume_down" class="cb-btn">Vol −</button>
        <button type="submit" name="test_cmd" value="mute_on"     class="cb-btn">Mute</button>
        <button type="submit" name="test_cmd" value="mute_off"    class="cb-btn">Unmute</button>
        <button type="submit" name="test_cmd" value="mute_toggle" class="cb-btn">Mute Toggle</button>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
        <input type="text" name="test_cmd" id="custom_cmd" placeholder="e.g. volume_set_40 or input_3" style="width:280px;padding:6px 8px;border:1px solid #ccc;border-radius:4px;font-size:.9em;">
        <button type="submit" class="cb-btn">Send</button>
    </div>
    </form>

    <div style="margin-top:14px;">
        <form method="post">
        <input type="hidden" name="action" value="restart_daemon">
        <button type="submit" class="cb-btn" style="background:#6c757d;"
            onclick="this.disabled=true;this.textContent='Restarting…'">Restart Daemon</button>
        </form>
    </div>
</div>

<!-- Log -->
<div class="cb-section">
    <h3>Log</h3>
    <pre style="background:#1e1e1e;color:#d4d4d4;padding:14px;border-radius:4px;font-size:.78em;max-height:300px;overflow-y:auto;white-space:pre-wrap;"><?php
$logfile = "$lbplogdir/monitor.log";
if (file_exists($logfile)) {
    $lines = file($logfile);
    echo htmlspecialchars(implode("", array_slice($lines, -60)));
} else {
    echo "Log file not found: $logfile";
}
?></pre>
</div>

</div><!-- max-width -->

<script>
function updateStatus() {
    fetch('index.php?ajax=status&_=' + Date.now())
        .then(r => r.json())
        .then(d => {
            var badgeClass = d.state === 'on' ? 'badge-on' : (d.state === 'standby' ? 'badge-standby' : 'badge-unknown');
            document.getElementById('st-state').innerHTML = '<span class="status-badge ' + badgeClass + '">' + d.state + '</span>';
            document.getElementById('st-volume').textContent = d.volume !== '-' ? d.volume + '%' : '–';
            document.getElementById('st-mute').textContent = d.mute !== '-' ? (d.mute === 'on' ? '🔇' : '🔊') : '–';
            document.getElementById('st-input').textContent = d.input !== '-' ? d.input : '–';
            document.getElementById('st-time').textContent = d.updated;
        })
        .catch(() => {});
}
updateStatus();
setInterval(updateStatus, 5000);

<?php if ($refresh_after_cmd): ?>
setTimeout(updateStatus, 3000);
setTimeout(updateStatus, 6000);
<?php endif; ?>
</script>

<?php LBWeb::lbfooter(); ?>
