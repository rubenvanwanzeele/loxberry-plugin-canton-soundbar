<?php
require_once "/opt/loxberry/libs/phplib/loxberry_system.php";
require_once "/opt/loxberry/libs/phplib/loxberry_web.php";
require_once "/opt/loxberry/libs/phplib/loxberry_io.php";

$lbpconfigdir = $lbpconfigdir ?? "/opt/loxberry/config/plugins/cantonbar";
$lbplogdir    = $lbplogdir    ?? "/opt/loxberry/log/plugins/cantonbar";
$cfgfile      = "$lbpconfigdir/cantonbar.cfg";
$logfile      = "$lbplogdir/monitor.log";

function cfg_read(string $file): array {
    $plugin_cfg = parse_ini_file($file, true);
    return $plugin_cfg ?: [];
}

function cfg_write(string $file, array $plugin_cfg): void {
    $out = "";
    foreach ($plugin_cfg as $section => $pairs) {
        $out .= "[$section]\n";
        foreach ($pairs as $key => $value) {
            $out .= $key . '=' . str_replace(["\r", "\n"], '', (string)$value) . "\n";
        }
        $out .= "\n";
    }
    file_put_contents($file, $out);
}

function cfg_get(array $plugin_cfg, string $section, string $key, string $default = ''): string {
    return isset($plugin_cfg[$section][$key]) ? (string)$plugin_cfg[$section][$key] : $default;
}

function ffaa_default_map(): array {
    return [
        '0' => '01,03,BDP',
        '1' => '02,04,SAT',
        '2' => '03,0E,PS',
        '3' => '06,02,TV',
        '4' => '07,05,CD',
        '5' => '0B,06,DVD',
        '6' => '0F,12,AUX',
        '7' => '17,13,NET',
        '8' => '15,14,BT',
    ];
}

function ffaa_map_to_text(array $section): string {
    if (empty($section)) {
        $section = ffaa_default_map();
    }
    uksort($section, function($a, $b) {
        if (ctype_digit((string)$a) && ctype_digit((string)$b)) {
            return (int)$a <=> (int)$b;
        }
        return strcmp((string)$a, (string)$b);
    });
    $lines = [];
    foreach ($section as $sourceId => $value) {
        $lines[] = trim((string)$sourceId) . '=' . trim((string)$value);
    }
    return implode("\n", $lines);
}

function ffaa_map_from_text(string $text): array {
    $map = [];
    $lines = preg_split('/\R+/', $text) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$sourceId, $value] = array_map('trim', explode('=', $line, 2));
        if ($sourceId === '' || $value === '') {
            continue;
        }
        $map[$sourceId] = $value;
    }
    return $map ?: ffaa_default_map();
}

function ffaa_names_json(array $map): string {
    $out = [];
    foreach ($map as $sourceId => $value) {
        $parts = array_values(array_filter(array_map('trim', explode(',', (string)$value)), 'strlen'));
        $out[(string)$sourceId] = $parts[2] ?? ('SRC_' . $sourceId);
    }
    ksort($out, SORT_NATURAL);
    return json_encode($out);
}

function get_mqtt_details(): array {
    if (function_exists('mqtt_connectiondetails')) {
        $mqtt_cred = mqtt_connectiondetails();
        return [
            'host' => !empty($mqtt_cred['brokerhost']) ? $mqtt_cred['brokerhost'] : 'localhost',
            'port' => !empty($mqtt_cred['brokerport']) ? (string)$mqtt_cred['brokerport'] : '1883',
            'user' => !empty($mqtt_cred['brokeruser']) ? $mqtt_cred['brokeruser'] : '',
            'pass' => !empty($mqtt_cred['brokerpass']) ? $mqtt_cred['brokerpass'] : '',
        ];
    }

    $general = @json_decode(@file_get_contents('/opt/loxberry/config/system/general.json'), true);
    return [
        'host' => $general['Mqtt']['Brokerhost'] ?? 'localhost',
        'port' => (string)($general['Mqtt']['Brokerport'] ?? '1883'),
        'user' => $general['Mqtt']['Brokeruser'] ?? '',
        'pass' => $general['Mqtt']['Brokerpass'] ?? '',
    ];
}

function mqsub(array $mq, string $topic): string {
    $auth = $mq['user'] !== ''
        ? '-u ' . escapeshellarg($mq['user']) . ' -P ' . escapeshellarg($mq['pass']) . ' '
        : '';
    $cmd = 'mosquitto_sub -h ' . escapeshellarg($mq['host'])
         . ' -p ' . (int)$mq['port']
         . ' ' . $auth
         . '-t ' . escapeshellarg($topic)
         . ' -C 1 -W 2 2>/dev/null';
    return trim(shell_exec($cmd) ?: '');
}

function mqpub(array $mq, string $topic, string $payload): void {
    $auth = $mq['user'] !== ''
        ? '-u ' . escapeshellarg($mq['user']) . ' -P ' . escapeshellarg($mq['pass']) . ' '
        : '';
    $cmd = 'mosquitto_pub -h ' . escapeshellarg($mq['host'])
         . ' -p ' . (int)$mq['port']
         . ' ' . $auth
         . '-t ' . escapeshellarg($topic)
         . ' -m ' . escapeshellarg($payload)
         . ' 2>/dev/null';
    shell_exec($cmd);
}

$plugin_cfg = cfg_read($cfgfile);
$ffaa_map_section = $plugin_cfg['FFAA_INPUTS'] ?? ffaa_default_map();
$ffaa_map_text = ffaa_map_to_text($ffaa_map_section);

if (isset($_GET['ajax']) && $_GET['ajax'] === 'status') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $stateTopic = cfg_get($plugin_cfg, 'MQTT', 'STATE_TOPIC', 'loxberry/plugin/cantonbar/state');
    $volumeTopic = cfg_get($plugin_cfg, 'MQTT', 'VOLUME_TOPIC', 'loxberry/plugin/cantonbar/volume');
    $muteTopic = cfg_get($plugin_cfg, 'MQTT', 'MUTE_TOPIC', 'loxberry/plugin/cantonbar/mute');
    $inputTopic = cfg_get($plugin_cfg, 'MQTT', 'INPUT_TOPIC', 'loxberry/plugin/cantonbar/input');
    $soundModeTopic = cfg_get($plugin_cfg, 'MQTT', 'SOUND_MODE_TOPIC', 'loxberry/plugin/cantonbar/sound_mode');

    $mq = get_mqtt_details();
    $fallbackInputMapJson = ffaa_names_json($plugin_cfg['FFAA_INPUTS'] ?? ffaa_default_map());

    echo json_encode([
        'state' => mqsub($mq, $stateTopic) ?: 'unknown',
        'volume' => mqsub($mq, $volumeTopic) ?: '-',
        'mute' => mqsub($mq, $muteTopic) ?: 'unsupported',
        'input' => mqsub($mq, $inputTopic) ?: '-',
        'input_map' => $fallbackInputMapJson,
        'sound_mode' => mqsub($mq, $soundModeTopic) ?: '-',
        'backend' => 'FFAA / TCP 50006',
        'updated' => date('H:i:s'),
    ]);
    exit;
}

$sb_ip = cfg_get($plugin_cfg, 'SOUNDBAR', 'IP', '');
$sb_port = cfg_get($plugin_cfg, 'SOUNDBAR', 'PORT', '50006');
$sb_mac = cfg_get($plugin_cfg, 'SOUNDBAR', 'MAC', '');
$vol_step = cfg_get($plugin_cfg, 'SOUNDBAR', 'VOLUME_STEP', '5');
$state_topic = cfg_get($plugin_cfg, 'MQTT', 'STATE_TOPIC', 'loxberry/plugin/cantonbar/state');
$volume_topic = cfg_get($plugin_cfg, 'MQTT', 'VOLUME_TOPIC', 'loxberry/plugin/cantonbar/volume');
$mute_topic = cfg_get($plugin_cfg, 'MQTT', 'MUTE_TOPIC', 'loxberry/plugin/cantonbar/mute');
$input_topic = cfg_get($plugin_cfg, 'MQTT', 'INPUT_TOPIC', 'loxberry/plugin/cantonbar/input');
$sound_mode_topic = cfg_get($plugin_cfg, 'MQTT', 'SOUND_MODE_TOPIC', 'loxberry/plugin/cantonbar/sound_mode');
$cmd_topic = cfg_get($plugin_cfg, 'MQTT', 'CMD_TOPIC', 'loxberry/plugin/cantonbar/cmd');
$poll_int = cfg_get($plugin_cfg, 'MONITOR', 'POLL_INTERVAL', '5');
$loglevel = cfg_get($plugin_cfg, 'MONITOR', 'LOGLEVEL', '4');
$status_timeout = cfg_get($plugin_cfg, 'MONITOR', 'STATUS_TIMEOUT', '2');

$message = '';
$messageType = 'info';
$cmdSent = false;
$refresh_after_cmd = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_config') {
    $plugin_cfg['SOUNDBAR']['IP'] = trim($_POST['sb_ip'] ?? '');
    $plugin_cfg['SOUNDBAR']['PORT'] = (string)max(1, min(65535, (int)($_POST['sb_port'] ?? 50006)));
    $plugin_cfg['SOUNDBAR']['MAC'] = strtoupper(str_replace('-', ':', trim($_POST['sb_mac'] ?? '')));
    $plugin_cfg['SOUNDBAR']['VOLUME_STEP'] = (string)max(1, min(20, (int)($_POST['vol_step'] ?? 5)));

    $plugin_cfg['MQTT']['STATE_TOPIC'] = trim($_POST['state_topic'] ?? $state_topic);
    $plugin_cfg['MQTT']['VOLUME_TOPIC'] = trim($_POST['volume_topic'] ?? $volume_topic);
    $plugin_cfg['MQTT']['MUTE_TOPIC'] = trim($_POST['mute_topic'] ?? $mute_topic);
    $plugin_cfg['MQTT']['INPUT_TOPIC'] = trim($_POST['input_topic'] ?? $input_topic);
    $plugin_cfg['MQTT']['SOUND_MODE_TOPIC'] = trim($_POST['sound_mode_topic'] ?? $sound_mode_topic);
    $plugin_cfg['MQTT']['CMD_TOPIC'] = trim($_POST['cmd_topic'] ?? $cmd_topic);

    $plugin_cfg['MONITOR']['POLL_INTERVAL'] = (string)max(1, min(60, (int)($_POST['poll_int'] ?? 5)));
    $plugin_cfg['MONITOR']['LOGLEVEL'] = (string)max(3, min(5, (int)($_POST['loglevel'] ?? 4)));
    $plugin_cfg['MONITOR']['STATUS_TIMEOUT'] = (string)max(1, min(10, (int)($_POST['status_timeout'] ?? 2)));
    $plugin_cfg['FFAA_INPUTS'] = ffaa_map_from_text($_POST['ffaa_map'] ?? '');

    @mkdir($lbpconfigdir, 0755, true);
    cfg_write($cfgfile, $plugin_cfg);
    shell_exec('sudo /bin/systemctl restart cantonbar.service 2>&1');

    $plugin_cfg = cfg_read($cfgfile);
    $ffaa_map_text = ffaa_map_to_text($plugin_cfg['FFAA_INPUTS'] ?? ffaa_default_map());
    $sb_ip = cfg_get($plugin_cfg, 'SOUNDBAR', 'IP', '');
    $sb_port = cfg_get($plugin_cfg, 'SOUNDBAR', 'PORT', '50006');
    $sb_mac = cfg_get($plugin_cfg, 'SOUNDBAR', 'MAC', '');
    $vol_step = cfg_get($plugin_cfg, 'SOUNDBAR', 'VOLUME_STEP', '5');
    $state_topic = cfg_get($plugin_cfg, 'MQTT', 'STATE_TOPIC', 'loxberry/plugin/cantonbar/state');
    $volume_topic = cfg_get($plugin_cfg, 'MQTT', 'VOLUME_TOPIC', 'loxberry/plugin/cantonbar/volume');
    $mute_topic = cfg_get($plugin_cfg, 'MQTT', 'MUTE_TOPIC', 'loxberry/plugin/cantonbar/mute');
    $input_topic = cfg_get($plugin_cfg, 'MQTT', 'INPUT_TOPIC', 'loxberry/plugin/cantonbar/input');
    $sound_mode_topic = cfg_get($plugin_cfg, 'MQTT', 'SOUND_MODE_TOPIC', 'loxberry/plugin/cantonbar/sound_mode');
    $cmd_topic = cfg_get($plugin_cfg, 'MQTT', 'CMD_TOPIC', 'loxberry/plugin/cantonbar/cmd');
    $poll_int = cfg_get($plugin_cfg, 'MONITOR', 'POLL_INTERVAL', '5');
    $loglevel = cfg_get($plugin_cfg, 'MONITOR', 'LOGLEVEL', '4');
    $status_timeout = cfg_get($plugin_cfg, 'MONITOR', 'STATUS_TIMEOUT', '2');

    $message = 'Configuration saved. FFAA daemon restarted.';
    $messageType = 'success';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_cmd') {
    $testCmd = trim($_POST['test_cmd'] ?? '');
    if ($testCmd !== '' && $cmd_topic !== '') {
        mqpub(get_mqtt_details(), $cmd_topic, $testCmd);
        $cmdSent = true;
        $refresh_after_cmd = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'custom_cmd') {
    $payload = trim($_POST['custom_cmd_payload'] ?? '');
    if ($payload !== '' && $cmd_topic !== '') {
        mqpub(get_mqtt_details(), $cmd_topic, $payload);
        $cmdSent = true;
        $refresh_after_cmd = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restart_daemon') {
    shell_exec('sudo /bin/systemctl restart cantonbar.service 2>&1');
    $message = 'Daemon restarted.';
    $messageType = 'success';
}

LBWeb::lbheader('Canton Smart Soundbar', 'cantonbar', 'help.html');
?>

<style>
/* ── Canton Smart Soundbar – aligned with Samsung Frame TV plugin style ── */
.sf-card { background:#fff; border-radius:6px; box-shadow:0 1px 4px rgba(0,0,0,.12); padding:20px 24px; margin-bottom:24px; }
.sf-card h3 { margin-top:0; border-bottom:1px solid #eee; padding-bottom:8px; font-size:1.1em; }
.sf-msg { padding:10px 14px; border-radius:4px; margin-bottom:16px; font-weight:500; }
.sf-msg.success { background:#d5f5e3; color:#1e8449; }
.sf-msg.error   { background:#fadbd8; color:#922b21; }
.sf-msg.info    { background:#d6eaf8; color:#1a5276; }
.sf-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px 24px; }
.sf-grid label { font-weight:500; display:block; margin-bottom:4px; }
.sf-grid input, .sf-grid select, .sf-grid textarea {
    width:100%; padding:6px 8px; border:1px solid #ccc; border-radius:4px;
    font-size:.95em; box-sizing:border-box;
}
.sf-grid textarea { min-height:120px; font-family:monospace; font-size:.88em; }
.sf-grid .sf-help { color:#7f8c8d; font-size:.82em; margin-top:3px; }
.sf-btn { padding:7px 16px; border:none; border-radius:4px; cursor:pointer; font-size:.9em; font-weight:500; }
.sf-btn-primary { background:#2980b9; color:#fff; }
.sf-btn-success { background:#27ae60; color:#fff; }
.sf-btn-warning { background:#e67e22; color:#fff; }
.sf-btn-danger  { background:#c0392b; color:#fff; }
.sf-btn-purple  { background:#8e44ad; color:#fff; }
.sf-btn-grey    { background:#7f8c8d; color:#fff; }
.sf-btn-outline { background:#fff; color:#2980b9; border:1px solid #2980b9; }
.sf-btn-busy    { background:#2980b9; color:#fff; }
.sf-btn:hover { opacity:.87; }
.sf-btn-row { display:flex; flex-wrap:wrap; gap:8px; }
.sf-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.sf-actions form { margin:0; }
.sf-actions .sf-btn { width:auto; display:inline-block; white-space:nowrap; }
.sf-muted { color:#7f8c8d; font-size:.9em; }
.sf-state-badge { display:inline-block; padding:6px 18px; border-radius:20px; color:#fff; font-weight:600; font-size:1em; cursor:pointer; border:none; }
.sf-state-on      { background:#27ae60; }
.sf-state-standby { background:#e67e22; }
.sf-state-unknown { background:#95a5a6; }
.sf-state-busy    { background:#2980b9; }
.sf-summary-table { width:100%; border-collapse:collapse; }
.sf-summary-table th { padding:8px 8px; border-bottom:2px solid #eee; text-align:left; font-size:.85em; text-transform:uppercase; letter-spacing:.04em; color:#7f8c8d; }
.sf-summary-table td { padding:12px 8px; border-bottom:1px solid #eee; text-align:left; vertical-align:middle; font-size:1em; }
.sf-summary-table td strong { font-size:1.05em; }
.sf-section-label { font-weight:700; font-size:.9em; margin:16px 0 8px; color:#444; }
.sf-pre { background:#f4f4f4; border-radius:4px; padding:10px 12px; font-size:.82em; max-height:320px; overflow:auto; white-space:pre-wrap; margin:0; }
@media (max-width:900px) { .sf-grid { grid-template-columns:1fr; } }
</style>

<?php if ($message !== ''): ?>
<div class="sf-msg <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($cmdSent): ?>
<div class="sf-msg info">Command sent to <code><?= htmlspecialchars($cmd_topic) ?></code>.</div>
<?php endif; ?>
<div class="sf-card">
    <h3>Live Status</h3>
    <p class="sf-muted">Pure FFAA backend &middot; auto-refresh every 5 seconds</p>
    <table class="sf-summary-table">
        <thead>
            <tr>
                <th>Power</th>
                <th>Input</th>
                <th>Volume</th>
                <th>Mute</th>
                <th>Play Mode</th>
                <th>Last updated</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><button type="button" id="st-state" class="sf-state-badge sf-state-unknown" title="Click to toggle power">UNKNOWN</button></td>
                <td><strong id="st-input-main">&#8211;</strong></td>
                <td id="st-volume">&#8211;</td>
                <td id="st-mute">&#8211;</td>
                <td id="st-sound-mode">&#8211;</td>
                <td id="st-time" class="sf-muted">&#8211;</td>
            </tr>
        </tbody>
    </table>
    <div class="sf-actions" style="margin-top:14px;">
        <form method="post" style="display:inline">
            <input type="hidden" name="action" value="restart_daemon">
            <button type="submit" class="sf-btn sf-btn-warning">Restart Daemon</button>
        </form>
        <form method="get" action="help.html" target="_blank" style="display:inline">
            <button type="submit" class="sf-btn sf-btn-warning">Help</button>
        </form>
    </div>
</div>
<div class="sf-card">
    <h3>Configuration</h3>
    <form method="post">
        <input type="hidden" name="action" value="save_config">
        <p class="sf-section-label">Soundbar</p>
        <div class="sf-grid">
            <div>
                <label for="sb_ip">IP address</label>
                <input type="text" id="sb_ip" name="sb_ip" value="<?= htmlspecialchars($sb_ip) ?>" placeholder="192.168.1.x">
            </div>
            <div>
                <label for="sb_port">FFAA TCP port</label>
                <input type="number" id="sb_port" name="sb_port" value="<?= htmlspecialchars($sb_port) ?>" min="1" max="65535">
                <div class="sf-muted" style="margin-top:3px;">Default is <code>50006</code>.</div>
            </div>
            <div>
                <label for="sb_mac">MAC address <small>(legacy, optional)</small></label>
                <input type="text" id="sb_mac" name="sb_mac" value="<?= htmlspecialchars($sb_mac) ?>" placeholder="optional">
                <div class="sf-muted" style="margin-top:3px;">Not used for power control; kept for compatibility.</div>
            </div>
            <div>
                <label for="vol_step">Volume step (%)</label>
                <input type="number" id="vol_step" name="vol_step" value="<?= htmlspecialchars($vol_step) ?>" min="1" max="20">
            </div>
        </div>
        <p class="sf-section-label">MQTT Topics (plugin &rarr; broker)</p>
        <div class="sf-grid">
            <div><label>State topic</label><input type="text" name="state_topic" value="<?= htmlspecialchars($state_topic) ?>"></div>
            <div><label>Volume topic</label><input type="text" name="volume_topic" value="<?= htmlspecialchars($volume_topic) ?>"></div>
            <div><label>Mute topic</label><input type="text" name="mute_topic" value="<?= htmlspecialchars($mute_topic) ?>"></div>
            <div><label>Input topic</label><input type="text" name="input_topic" value="<?= htmlspecialchars($input_topic) ?>"></div>
            <div><label>Sound mode topic</label><input type="text" name="sound_mode_topic" value="<?= htmlspecialchars($sound_mode_topic) ?>"></div>
        </div>
        <p class="sf-section-label">MQTT Topics (broker &rarr; plugin)</p>
        <div class="sf-grid">
            <div style="grid-column:1 / -1;"><label>Command topic</label><input type="text" name="cmd_topic" value="<?= htmlspecialchars($cmd_topic) ?>"></div>
        </div>
        <p class="sf-section-label">Daemon</p>
        <div class="sf-grid">
            <div>
                <label for="poll_int">Poll interval (seconds)</label>
                <input type="number" id="poll_int" name="poll_int" value="<?= htmlspecialchars($poll_int) ?>" min="1" max="60">
            </div>
            <div>
                <label for="loglevel">Log level</label>
                <select id="loglevel" name="loglevel">
                    <?php foreach ([3 => 'Warning (3)', 4 => 'Info (4)', 5 => 'Debug (5)'] as $value => $label): ?>
                        <option value="<?= $value ?>"<?= (int)$loglevel === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="status_timeout">Socket timeout (seconds)</label>
                <input type="number" id="status_timeout" name="status_timeout" value="<?= htmlspecialchars($status_timeout) ?>" min="1" max="10">
                <div class="sf-muted" style="margin-top:3px;">Used for FFAA TCP reads on port 50006.</div>
            </div>
            <div>
                <label for="ffaa_map">FFAA input map</label>
                <textarea id="ffaa_map" name="ffaa_map" spellcheck="false"><?= htmlspecialchars($ffaa_map_text) ?></textarea>
                <div class="sf-muted" style="margin-top:3px;">One mapping per line: <code>id=BYTE1,BYTE2,Name</code> &mdash; e.g. <code>3=06,02,TV</code>.</div>
            </div>
        </div>
        <div style="margin-top:14px;">
            <button type="submit" class="sf-btn sf-btn-primary">Save Configuration</button>
        </div>
    </form>
</div>
<div class="sf-card">
    <h3>Quick Test Commands</h3>
    <strong>Power</strong>
    <div class="sf-btn-row" style="margin:8px 0 16px;">
        <form method="post" style="display:inline"><input type="hidden" name="action" value="test_cmd">
            <button type="submit" name="test_cmd" value="power_on" class="sf-btn sf-btn-success">Power On</button></form>
        <form method="post" style="display:inline"><input type="hidden" name="action" value="test_cmd">
            <button type="submit" name="test_cmd" value="power_off" class="sf-btn sf-btn-danger">Standby</button></form>
    </div>
    <strong>Volume</strong>
    <div class="sf-btn-row" style="margin:8px 0 16px;">
        <form method="post" style="display:inline"><input type="hidden" name="action" value="test_cmd">
            <button type="submit" name="test_cmd" value="volume_down" class="sf-btn sf-btn-grey">Vol &minus;</button></form>
        <form method="post" style="display:inline"><input type="hidden" name="action" value="test_cmd">
            <button type="submit" name="test_cmd" value="volume_up" class="sf-btn sf-btn-grey">Vol +</button></form>
    </div>
    <strong>Mute</strong>
    <div class="sf-btn-row" style="margin:8px 0 16px;">
        <form method="post" style="display:inline"><input type="hidden" name="action" value="test_cmd">
            <button type="submit" name="test_cmd" value="mute_on" class="sf-btn sf-btn-grey">Mute</button></form>
        <form method="post" style="display:inline"><input type="hidden" name="action" value="test_cmd">
            <button type="submit" name="test_cmd" value="mute_toggle" class="sf-btn sf-btn-grey">Mute Toggle</button></form>
        <form method="post" style="display:inline"><input type="hidden" name="action" value="test_cmd">
            <button type="submit" name="test_cmd" value="mute_off" class="sf-btn sf-btn-grey">Unmute</button></form>
    </div>
    <strong>Play Mode</strong>
    <div class="sf-btn-row" style="margin:8px 0 16px;">
        <form method="post" style="display:inline"><input type="hidden" name="action" value="test_cmd">
            <button type="submit" name="test_cmd" value="mode_stereo" class="sf-btn sf-btn-grey">Stereo</button></form>
        <form method="post" style="display:inline"><input type="hidden" name="action" value="test_cmd">
            <button type="submit" name="test_cmd" value="mode_movie" class="sf-btn sf-btn-grey">Movie</button></form>
        <form method="post" style="display:inline"><input type="hidden" name="action" value="test_cmd">
            <button type="submit" name="test_cmd" value="mode_music" class="sf-btn sf-btn-grey">Music</button></form>
    </div>
    <strong>Input Sources</strong>
    <p class="sf-muted">Active source is highlighted green. Buttons are generated from your FFAA map.</p>
    <form method="post" id="input-buttons-form" style="margin-bottom:16px;">
        <input type="hidden" name="action" value="test_cmd">
        <input type="hidden" name="test_cmd" id="input-buttons-cmd" value="">
        <div id="input-buttons" class="sf-btn-row">
            <span class="sf-muted">Waiting for FFAA input map&#8230;</span>
        </div>
    </form>
    <strong>Custom Command</strong>
    <p class="sf-muted">Publish to <code><?= htmlspecialchars($cmd_topic) ?></code> &mdash; e.g. <code>volume_set_40</code>, <code>input_tv</code>, <code>mode_movie</code></p>
    <form method="post" style="display:flex; gap:8px; flex-wrap:wrap; margin-top:6px;">
        <input type="hidden" name="action" value="custom_cmd">
        <input type="text" name="custom_cmd_payload" placeholder="e.g. volume_set_40, input_tv, mode_movie" style="padding:6px 8px; border:1px solid #ccc; border-radius:4px; width:300px;">
        <button type="submit" class="sf-btn sf-btn-primary">Send</button>
    </form>
</div>
<div class="sf-card">
    <h3>Recent Log</h3>
    <p class="sf-muted">Showing the last 80 lines from <code><?= htmlspecialchars($logfile) ?></code>.</p>
    <pre class="sf-pre"><?php
if (file_exists($logfile) && filesize($logfile) > 0) {
    $lines = file($logfile);
    echo htmlspecialchars(implode('', array_slice($lines, -80)));
} elseif (file_exists($logfile)) {
    echo "Log file exists but is empty.\n";
    echo "\u2192 Enter the Soundbar IP above and save the configuration.\n";
    echo "\u2192 Then restart the daemon if needed.";
} else {
    echo "Log file not found: $logfile\n";
    echo "\u2192 Check whether cantonbar.service is installed and running.";
}
?></pre>
</div>
<script>
var lastKnownPowerState = 'unknown';
var powerCommandPending = false;
function htmlEscape(s) {
    return String(s || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
function stateBadgeClass(state) {
    if (state === 'on') return 'sf-state-badge sf-state-on';
    if (state === 'standby') return 'sf-state-badge sf-state-standby';
    return 'sf-state-badge sf-state-unknown';
}
function prettyMute(value) {
    if (value === 'unsupported') return 'UNSUPPORTED';
    if (value === 'on') return 'ON';
    if (value === 'off') return 'OFF';
    return '\u2013';
}
function renderInputButtons(inputMapRaw, currentInput) {
    var holder = document.getElementById('input-buttons');
    if (!holder) return;
    var parsed = {};
    try { parsed = inputMapRaw ? JSON.parse(inputMapRaw) : {}; } catch (e) { parsed = {}; }
    var ids = Object.keys(parsed).sort(function(a, b) {
        var ai = parseInt(a, 10), bi = parseInt(b, 10);
        if (!isNaN(ai) && !isNaN(bi)) return ai - bi;
        return String(a).localeCompare(String(b));
    });
    if (!ids.length) {
        holder.innerHTML = '<span class="sf-muted">No FFAA input mappings configured yet.</span>';
        return;
    }
    var current = String(currentInput || '');
    holder.innerHTML = ids.map(function(id) {
        var name = String(parsed[id] || '');
        var active = name.toLowerCase() === current.toLowerCase();
        var cls = active ? 'sf-btn sf-btn-success' : 'sf-btn sf-btn-grey';
        return '<button type="button" class="' + cls + '" data-input-name="' + htmlEscape(name) + '">' + htmlEscape(name) + '</button>';
    }).join('');
}
function sendInputCommand(inputName) {
    var cmdField = document.getElementById('input-buttons-cmd');
    var form = document.getElementById('input-buttons-form');
    if (!cmdField || !form) return;
    var token = String(inputName || '').toLowerCase().replace(/[^a-z0-9]/g, '');
    if (!token) return;
    cmdField.value = 'input_' + token;
    form.submit();
}
function sendQuickCommand(command) {
    var form = document.createElement('form');
    form.method = 'post'; form.action = 'index.php';
    var a = document.createElement('input'); a.type = 'hidden'; a.name = 'action'; a.value = 'test_cmd'; form.appendChild(a);
    var c = document.createElement('input'); c.type = 'hidden'; c.name = 'test_cmd'; c.value = command; form.appendChild(c);
    document.body.appendChild(form); form.submit();
}
function updateStatus() {
    fetch('index.php?ajax=status&_=' + Date.now())
        .then(function(r) { return r.json(); })
        .then(function(d) {
            var state = String(d.state || 'unknown');
            lastKnownPowerState = state;
            var badge = document.getElementById('st-state');
            badge.className = powerCommandPending ? 'sf-state-badge sf-state-busy' : stateBadgeClass(state);
            badge.textContent = powerCommandPending ? 'SENDING\u2026' : state.toUpperCase();
            document.getElementById('st-input-main').textContent = (d.input && d.input !== '-') ? String(d.input) : '\u2013';
            document.getElementById('st-volume').textContent = d.volume !== '-' ? (String(d.volume) + '%') : '\u2013';
            document.getElementById('st-mute').textContent = prettyMute(String(d.mute || 'unsupported'));
            document.getElementById('st-sound-mode').textContent = (d.sound_mode && d.sound_mode !== '-') ? String(d.sound_mode) : '\u2013';
            document.getElementById('st-time').textContent = d.updated || '\u2013';
            renderInputButtons(d.input_map || '{}', d.input || '');
            if (powerCommandPending) powerCommandPending = false;
        })
        .catch(function() {});
}
document.addEventListener('click', function(ev) {
    var btn = ev.target.closest('button[data-input-name]');
    if (btn) { sendInputCommand(btn.getAttribute('data-input-name')); return; }
    var powerBtn = ev.target.closest('#st-state');
    if (powerBtn) {
        powerCommandPending = true;
        powerBtn.className = 'sf-state-badge sf-state-busy';
        powerBtn.textContent = 'SENDING\u2026';
        sendQuickCommand(lastKnownPowerState === 'on' ? 'power_off' : 'power_on');
    }
});
updateStatus();
setInterval(updateStatus, 5000);
<?php if ($refresh_after_cmd): ?>
setTimeout(updateStatus, 1800);
setTimeout(updateStatus, 4200);
<?php endif; ?>
</script>
<?php LBWeb::lbfooter(); ?>
