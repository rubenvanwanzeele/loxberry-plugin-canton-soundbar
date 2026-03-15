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
.cb-wrap { max-width: 980px; margin: 0 auto; }
.cb-card {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 1px 6px rgba(0,0,0,.10);
    padding: 22px 24px;
    margin-bottom: 24px;
}
.cb-card h3 {
    margin: 0 0 14px 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #eceff3;
    font-size: 1.08rem;
    font-weight: 600;
}
.cb-msg {
    padding: 11px 14px;
    border-radius: 6px;
    margin-bottom: 16px;
    font-weight: 500;
}
.cb-msg.success { background: #d5f5e3; color: #1e8449; }
.cb-msg.error   { background: #fadbd8; color: #922b21; }
.cb-msg.info    { background: #d6eaf8; color: #1a5276; }
.cb-hero {
    display: grid;
    grid-template-columns: 1.3fr .7fr;
    gap: 16px;
    align-items: stretch;
}
.cb-hero-main {
    background: linear-gradient(135deg, #1f2a44 0%, #293a5a 100%);
    color: #fff;
    border-radius: 10px;
    padding: 18px 20px;
}
.cb-hero-main small { color: rgba(255,255,255,.72); }
.cb-power-badge {
    display: inline-block;
    padding: 8px 18px;
    border-radius: 999px;
    font-size: 1.05rem;
    font-weight: 700;
    letter-spacing: .03em;
    border: 0;
}
.cb-power-badge.cb-power-clickable { cursor: pointer; }
.cb-power-on { background: #27ae60; color: #fff; }
.cb-power-standby { background: #f39c12; color: #fff; }
.cb-power-unknown { background: #7f8c8d; color: #fff; }
.cb-power-busy { background: #3b82f6; color: #fff; }
.cb-status-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0,1fr));
    gap: 12px;
}
.cb-tile {
    border: 1px solid #e6eaef;
    border-radius: 10px;
    background: #f8fafc;
    padding: 14px 16px;
}
.cb-tile-label {
    font-size: .76rem;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: #6b7785;
    margin-bottom: 6px;
}
.cb-tile-value {
    font-size: 1.05rem;
    font-weight: 700;
    color: #243447;
}
.cb-subtle { color: #6b7785; font-size: .9rem; }
.cb-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0,1fr));
    gap: 14px 20px;
}
.cb-field label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
    color: #243447;
}
.cb-field input,
.cb-field select,
.cb-field textarea {
    width: 100%;
    box-sizing: border-box;
    border: 1px solid #cfd7e3;
    border-radius: 6px;
    padding: 8px 10px;
    background: #fff;
}
.cb-field textarea { min-height: 120px; font-family: monospace; font-size: .92rem; }
.cb-help { margin-top: 5px; color: #718096; font-size: .85rem; }
.cb-section-title {
    margin: 16px 0 10px;
    font-size: .88rem;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #7b8794;
    font-weight: 700;
}
.cb-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.cb-actions .btn { border-radius: 999px; }
#input-buttons { margin-bottom: 0; }
.cb-log {
    background: #151a21;
    color: #d8dee9;
    padding: 16px 18px;
    border-radius: 8px;
    font-size: .8rem;
    max-height: 380px;
    overflow-y: auto;
    white-space: pre-wrap;
    margin: 0;
}
@media (max-width: 900px) {
    .cb-hero, .cb-grid, .cb-status-grid { grid-template-columns: 1fr; }
}
</style>

<div class="cb-wrap">

<?php if ($message !== ''): ?>
    <div class="cb-msg <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php if ($cmdSent): ?>
    <div class="cb-msg info">
        Command sent to <code><?= htmlspecialchars($cmd_topic) ?></code>.
    </div>
<?php endif; ?>

<div class="cb-card">
    <h3>Live Status</h3>
    <div class="cb-help" style="margin-top:-6px; margin-bottom:12px;">
        Need command/topic reference? <a href="help.html" target="_blank" rel="noopener">Open help page</a>.
    </div>
    <div class="cb-hero">
        <div class="cb-hero-main">
            <div class="cb-subtle">Pure FFAA backend &middot; auto-refresh every 5 seconds</div>
            <div style="margin-top:14px; margin-bottom:10px;">
                <button type="button" id="st-state" class="cb-power-badge cb-power-unknown cb-power-clickable" title="Click to toggle power">UNKNOWN</button>
            </div>
            <div style="font-size:1.1rem; font-weight:600;" id="st-input-main">Input &ndash;</div>
            <small>Last updated: <span id="st-time">&ndash;</span></small>
        </div>
        <div class="cb-status-grid">
            <div class="cb-tile">
                <div class="cb-tile-label">Volume</div>
                <div class="cb-tile-value" id="st-volume">&ndash;</div>
            </div>
            <div class="cb-tile">
                <div class="cb-tile-label">Mute</div>
                <div class="cb-tile-value" id="st-mute">FFAA</div>
            </div>
            <div class="cb-tile">
                <div class="cb-tile-label">Current Input</div>
                <div class="cb-tile-value" id="st-input">&ndash;</div>
            </div>
            <div class="cb-tile">
                <div class="cb-tile-label">Play Mode</div>
                <div class="cb-tile-value" id="st-sound-mode">&ndash;</div>
            </div>
            <div class="cb-tile">
                <div class="cb-tile-label">Transport</div>
                <div class="cb-tile-value" id="st-backend">FFAA / TCP 50006</div>
            </div>
        </div>
    </div>
</div>

<div class="cb-card">
    <h3>Configuration</h3>
    <form method="post">
        <input type="hidden" name="action" value="save_config">

        <div class="cb-section-title">Soundbar</div>
        <div class="cb-grid">
            <div class="cb-field">
                <label for="sb_ip">IP address</label>
                <input type="text" id="sb_ip" name="sb_ip" value="<?= htmlspecialchars($sb_ip) ?>" placeholder="192.168.1.x">
            </div>
            <div class="cb-field">
                <label for="sb_port">FFAA TCP port</label>
                <input type="number" id="sb_port" name="sb_port" value="<?= htmlspecialchars($sb_port) ?>" min="1" max="65535">
                <div class="cb-help">Default is <code>50006</code>.</div>
            </div>
            <div class="cb-field">
                <label for="sb_mac">MAC address <small class="text-muted">(legacy)</small></label>
                <input type="text" id="sb_mac" name="sb_mac" value="<?= htmlspecialchars($sb_mac) ?>" placeholder="optional">
                <div class="cb-help">Kept for compatibility, but the current backend uses direct FFAA power on/off instead of WoL.</div>
            </div>
            <div class="cb-field">
                <label for="vol_step">Volume step (%)</label>
                <input type="number" id="vol_step" name="vol_step" value="<?= htmlspecialchars($vol_step) ?>" min="1" max="20">
            </div>
        </div>

        <div class="cb-section-title">MQTT (plugin → broker)</div>
        <div class="cb-grid">
            <div class="cb-field"><label>State topic</label><input type="text" name="state_topic" value="<?= htmlspecialchars($state_topic) ?>"></div>
            <div class="cb-field"><label>Volume topic</label><input type="text" name="volume_topic" value="<?= htmlspecialchars($volume_topic) ?>"></div>
            <div class="cb-field"><label>Mute topic</label><input type="text" name="mute_topic" value="<?= htmlspecialchars($mute_topic) ?>"></div>
            <div class="cb-field"><label>Input topic</label><input type="text" name="input_topic" value="<?= htmlspecialchars($input_topic) ?>"></div>
            <div class="cb-field"><label>Sound mode topic</label><input type="text" name="sound_mode_topic" value="<?= htmlspecialchars($sound_mode_topic) ?>"></div>
        </div>

        <div class="cb-section-title">MQTT (broker → plugin)</div>
        <div class="cb-grid">
            <div class="cb-field" style="grid-column:1 / -1;"><label>Command topic</label><input type="text" name="cmd_topic" value="<?= htmlspecialchars($cmd_topic) ?>"></div>
        </div>

        <div class="cb-section-title">Daemon</div>
        <div class="cb-grid">
            <div class="cb-field">
                <label for="poll_int">Poll interval (seconds)</label>
                <input type="number" id="poll_int" name="poll_int" value="<?= htmlspecialchars($poll_int) ?>" min="1" max="60">
            </div>
            <div class="cb-field">
                <label for="loglevel">Log level</label>
                <select id="loglevel" name="loglevel">
                    <?php foreach ([3 => 'Warning (3)', 4 => 'Info (4)', 5 => 'Debug (5)'] as $value => $label): ?>
                        <option value="<?= $value ?>"<?= (int)$loglevel === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cb-field">
                <label for="status_timeout">Socket timeout (seconds)</label>
                <input type="number" id="status_timeout" name="status_timeout" value="<?= htmlspecialchars($status_timeout) ?>" min="1" max="10">
                <div class="cb-help">Used for FFAA TCP reads on port 50006.</div>
            </div>
            <div class="cb-field">
                <label for="ffaa_map">FFAA input map</label>
                <textarea id="ffaa_map" name="ffaa_map" spellcheck="false"><?= htmlspecialchars($ffaa_map_text) ?></textarea>
                <div class="cb-help">One mapping per line: <code>source_id=BYTE1,BYTE2,Name</code> &mdash; example: <code>3=06,02,ARC</code>.</div>
            </div>
        </div>

        <div style="margin-top:18px;">
            <button type="submit" class="btn btn-primary">Save Configuration</button>
        </div>
    </form>
</div>

<div class="cb-card">
    <h3>Command Center</h3>

    <div class="cb-section-title">Quick Actions</div>
    <form method="post" class="mb-3">
        <input type="hidden" name="action" value="test_cmd">
        <div class="cb-actions">
            <button type="submit" name="test_cmd" value="power_on" class="btn btn-success">Power On</button>
            <button type="submit" name="test_cmd" value="power_off" class="btn btn-danger">Standby</button>
            <button type="submit" name="test_cmd" value="volume_down" class="btn btn-outline-secondary">Volume &minus;</button>
            <button type="submit" name="test_cmd" value="volume_up" class="btn btn-outline-secondary">Volume +</button>
            <button type="submit" name="test_cmd" value="mute_on" class="btn btn-outline-secondary">Mute</button>
            <button type="submit" name="test_cmd" value="mute_toggle" class="btn btn-outline-secondary">Mute Toggle</button>
            <button type="submit" name="test_cmd" value="mute_off" class="btn btn-outline-secondary">Unmute</button>
        </div>
    </form>

    <div class="cb-section-title">Play Mode</div>
    <form method="post" class="mb-3">
        <input type="hidden" name="action" value="test_cmd">
        <div class="cb-actions">
            <button type="submit" name="test_cmd" value="mode_stereo" class="btn btn-outline-primary">Stereo</button>
            <button type="submit" name="test_cmd" value="mode_movie" class="btn btn-outline-primary">Movie</button>
            <button type="submit" name="test_cmd" value="mode_music" class="btn btn-outline-primary">Music</button>
        </div>
    </form>

    <div class="cb-section-title">Input Sources</div>
    <form method="post" id="input-buttons-form" style="margin-bottom:4px;">
        <input type="hidden" name="action" value="test_cmd">
        <input type="hidden" name="test_cmd" id="input-buttons-cmd" value="">
        <div id="input-buttons" class="cb-actions">
            <span class="cb-subtle">Waiting for FFAA input map…</span>
        </div>
    </form>
    <div class="cb-help" style="margin-top:0; margin-bottom:10px;">Buttons are generated from your FFAA map (default order: BDP, SAT, PS, TV, CD, DVD, AUX, NET, BT).</div>

    <div class="cb-section-title">Single Command Topic Contract</div>
    <div class="cb-help" style="margin-bottom:10px;">Publish all payloads to <code><?= htmlspecialchars($cmd_topic) ?></code>.</div>
    <div class="cb-help" style="line-height:1.65;">
        <code>power_on</code>, <code>power_off</code>, <code>volume_up</code>, <code>volume_down</code>, <code>volume_set_N</code>,<br>
        <code>mode_stereo</code>, <code>mode_movie</code>, <code>mode_music</code>,<br>
        <code>input_bdp</code>, <code>input_sat</code>, <code>input_tv</code>, <code>input_dvd</code>, ...<br>
        <code>mute_on</code>, <code>mute_off</code>, <code>mute_toggle</code> (FFAA, with HTTP fallback only if needed)
    </div>

    <div class="cb-section-title">Custom Command</div>
    <form method="post" class="mb-3">
        <input type="hidden" name="action" value="custom_cmd">
        <div class="input-group" style="max-width:460px;">
            <input type="text" name="custom_cmd_payload" class="form-control" placeholder="e.g. volume_set_40, input_tv, mode_movie">
            <div class="input-group-append">
                <button type="submit" class="btn btn-outline-primary">Send</button>
            </div>
        </div>
    </form>

    <form method="post">
        <input type="hidden" name="action" value="restart_daemon">
        <button type="submit" class="btn btn-secondary" onclick="this.disabled=true; this.textContent='Restarting…';">Restart Daemon</button>
    </form>
</div>

<div class="cb-card">
    <h3>Log</h3>
    <pre class="cb-log"><?php
if (file_exists($logfile) && filesize($logfile) > 0) {
    $lines = file($logfile);
    echo htmlspecialchars(implode('', array_slice($lines, -80)));
} elseif (file_exists($logfile)) {
    echo "Log file exists but is empty.\n";
    echo "→ Enter the Soundbar IP above and save the configuration.\n";
    echo "→ Then restart the daemon if needed.";
} else {
    echo "Log file not found: $logfile\n";
    echo "→ Check whether cantonbar.service is installed and running.";
}
?></pre>
</div>

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

function powerBadgeClass(state) {
    if (state === 'on') return 'cb-power-badge cb-power-on';
    if (state === 'standby') return 'cb-power-badge cb-power-standby';
    return 'cb-power-badge cb-power-unknown';
}

function prettyMute(value) {
    if (value === 'unsupported') return 'UNSUPPORTED';
    if (value === 'on') return 'ON';
    if (value === 'off') return 'OFF';
    return '–';
}

function renderInputButtons(inputMapRaw, currentInput) {
    var holder = document.getElementById('input-buttons');
    if (!holder) return;

    var parsed = {};
    try {
        parsed = inputMapRaw ? JSON.parse(inputMapRaw) : {};
    } catch (e) {
        parsed = {};
    }

    var ids = Object.keys(parsed).sort(function(a, b) {
        var ai = parseInt(a, 10);
        var bi = parseInt(b, 10);
        if (!isNaN(ai) && !isNaN(bi)) return ai - bi;
        return String(a).localeCompare(String(b));
    });

    if (!ids.length) {
        holder.innerHTML = '<span class="cb-subtle">No FFAA input mappings configured yet.</span>';
        return;
    }

    var current = String(currentInput || '');
    holder.innerHTML = ids.map(function(id) {
        var name = String(parsed[id] || '');
        var active = name.toLowerCase() === current.toLowerCase();
        var cls = active ? 'btn btn-success' : 'btn btn-outline-primary';
        return '<button type="button" class="' + cls + '" data-input-name="' + htmlEscape(name) + '">'
            + htmlEscape(name)
            + '</button>';
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
    form.method = 'post';
    form.action = 'index.php';

    var action = document.createElement('input');
    action.type = 'hidden';
    action.name = 'action';
    action.value = 'test_cmd';
    form.appendChild(action);

    var cmd = document.createElement('input');
    cmd.type = 'hidden';
    cmd.name = 'test_cmd';
    cmd.value = command;
    form.appendChild(cmd);

    document.body.appendChild(form);
    form.submit();
}

function updateStatus() {
    fetch('index.php?ajax=status&_=' + Date.now())
        .then(function(r) { return r.json(); })
        .then(function(d) {
            var state = String(d.state || 'unknown');
            lastKnownPowerState = state;
            document.getElementById('st-state').className = powerBadgeClass(state);
            document.getElementById('st-state').classList.add('cb-power-clickable');
            if (powerCommandPending) {
                document.getElementById('st-state').classList.add('cb-power-busy');
            }
            document.getElementById('st-state').textContent = state.toUpperCase();
            document.getElementById('st-volume').textContent = d.volume !== '-' ? (String(d.volume) + '%') : '–';
            document.getElementById('st-mute').textContent = prettyMute(String(d.mute || 'unsupported'));
            document.getElementById('st-input').textContent = d.input !== '-' ? String(d.input) : '–';
            document.getElementById('st-sound-mode').textContent = d.sound_mode && d.sound_mode !== '-' ? String(d.sound_mode) : '–';
            document.getElementById('st-backend').textContent = d.backend || 'FFAA / TCP 50006';
            document.getElementById('st-time').textContent = d.updated || '–';

            var inputMain = 'Input –';
            if (d.input && d.input !== '-') {
                inputMain = String(d.input);
            }
            document.getElementById('st-input-main').textContent = inputMain;

            renderInputButtons(d.input_map || '{}', d.input || '');
            if (powerCommandPending) {
                powerCommandPending = false;
            }
        })
        .catch(function() {});
}

document.addEventListener('click', function(ev) {
    var btn = ev.target.closest('button[data-input-name]');
    if (btn) {
        sendInputCommand(btn.getAttribute('data-input-name'));
        return;
    }

    var powerBtn = ev.target.closest('#st-state');
    if (powerBtn) {
        powerCommandPending = true;
        powerBtn.className = 'cb-power-badge cb-power-busy cb-power-clickable';
        powerBtn.textContent = 'SENDING...';
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
