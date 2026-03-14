<?php
require_once "/opt/loxberry/libs/phplib/loxberry_system.php";
require_once "/opt/loxberry/libs/phplib/loxberry_web.php";
require_once "/opt/loxberry/libs/phplib/loxberry_io.php";

$lbpconfigdir = $lbpconfigdir ?? "/opt/loxberry/config/plugins/cantonbar";
$lbplogdir    = $lbplogdir    ?? "/opt/loxberry/log/plugins/cantonbar";

// -------------------------------------------------------------------------
// Helper: read MQTT broker connection details from LoxBerry general.json
// -------------------------------------------------------------------------
function get_mqtt_details(): array {
    // LoxBerry-native helper (same approach as Samsung plugin UI)
    if (function_exists('mqtt_connectiondetails')) {
        $mqtt_cred = mqtt_connectiondetails();
        return [
            'host' => !empty($mqtt_cred['brokerhost']) ? $mqtt_cred['brokerhost'] : 'localhost',
            'port' => !empty($mqtt_cred['brokerport']) ? (string)$mqtt_cred['brokerport'] : '1883',
            'user' => !empty($mqtt_cred['brokeruser']) ? $mqtt_cred['brokeruser'] : '',
            'pass' => !empty($mqtt_cred['brokerpass']) ? $mqtt_cred['brokerpass'] : '',
        ];
    }

    // Defensive fallback for environments where helper is unavailable
    $gen = @json_decode(@file_get_contents('/opt/loxberry/config/system/general.json'), true);
    return [
        'host' => $gen['Mqtt']['Brokerhost'] ?? 'localhost',
        'port' => (string)($gen['Mqtt']['Brokerport'] ?? '1883'),
        'user' => $gen['Mqtt']['Brokeruser'] ?? '',
        'pass' => $gen['Mqtt']['Brokerpass'] ?? '',
    ];
}

function mqsub(array $mq, string $topic): string {
    $auth = $mq['user'] !== ''
        ? '-u ' . escapeshellarg($mq['user']) . ' -P ' . escapeshellarg($mq['pass']) . ' '
        : '';
    $cmd = 'mosquitto_sub -h ' . escapeshellarg($mq['host'])
         . ' -p ' . (int)$mq['port']
         . ' ' . $auth
         . '-t ' . escapeshellarg($topic) . ' -C 1 -W 2 2>/dev/null';
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
         . ' -m ' . escapeshellarg($payload) . ' 2>/dev/null';
    shell_exec($cmd);
}

// -------------------------------------------------------------------------
// AJAX: live status
// -------------------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'status') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    $plugin_cfg = parse_ini_file("$lbpconfigdir/cantonbar.cfg", true) ?: [];
    $state_t    = $plugin_cfg['MQTT']['STATE_TOPIC']  ?? 'loxberry/plugin/cantonbar/state';
    $volume_t   = $plugin_cfg['MQTT']['VOLUME_TOPIC'] ?? 'loxberry/plugin/cantonbar/volume';
    $mute_t     = $plugin_cfg['MQTT']['MUTE_TOPIC']   ?? 'loxberry/plugin/cantonbar/mute';
    $input_t    = $plugin_cfg['MQTT']['INPUT_TOPIC']  ?? 'loxberry/plugin/cantonbar/input';
    $input_name_t = $plugin_cfg['MQTT']['INPUT_NAME_TOPIC'] ?? 'loxberry/plugin/cantonbar/input_name';
    $health_base = $plugin_cfg['MQTT']['HEALTH_BASE_TOPIC'] ?? 'loxberry/plugin/cantonbar/health';
    $api_health_t = $plugin_cfg['MQTT']['API_HEALTH_TOPIC'] ?? ($health_base . '/api');
    $adb_health_t = $plugin_cfg['MQTT']['ADB_HEALTH_TOPIC'] ?? ($health_base . '/adb');
    $libreknx_health_t = $plugin_cfg['MQTT']['LIBREKNX_HEALTH_TOPIC'] ?? ($health_base . '/libreknx');
    $token_health_t = $plugin_cfg['MQTT']['TOKEN_HEALTH_TOPIC'] ?? ($health_base . '/token');

    $mq = get_mqtt_details();
    echo json_encode([
        'state'   => mqsub($mq, $state_t)  ?: 'unknown',
        'volume'  => mqsub($mq, $volume_t) ?: '-',
        'mute'    => mqsub($mq, $mute_t)   ?: '-',
        'input'   => mqsub($mq, $input_t)  ?: '-',
        'input_name' => mqsub($mq, $input_name_t) ?: '-',
        'api'     => mqsub($mq, $api_health_t) ?: 'unknown',
        'adb'     => mqsub($mq, $adb_health_t) ?: 'unknown',
        'libreknx'=> mqsub($mq, $libreknx_health_t) ?: 'unknown',
        'token'   => mqsub($mq, $token_health_t) ?: 'unknown',
        'updated' => date('H:i:s'),
    ]);
    exit;
}

// -------------------------------------------------------------------------
// Load config
// -------------------------------------------------------------------------
$plugin_cfg   = parse_ini_file("$lbpconfigdir/cantonbar.cfg", true) ?: [];
$sb_ip        = $plugin_cfg['SOUNDBAR']['IP']           ?? '';
$sb_mac       = $plugin_cfg['SOUNDBAR']['MAC']          ?? '';
$vol_step     = $plugin_cfg['SOUNDBAR']['VOLUME_STEP']  ?? '5';
$state_topic  = $plugin_cfg['MQTT']['STATE_TOPIC']      ?? 'loxberry/plugin/cantonbar/state';
$volume_topic = $plugin_cfg['MQTT']['VOLUME_TOPIC']     ?? 'loxberry/plugin/cantonbar/volume';
$mute_topic   = $plugin_cfg['MQTT']['MUTE_TOPIC']       ?? 'loxberry/plugin/cantonbar/mute';
$input_topic  = $plugin_cfg['MQTT']['INPUT_TOPIC']      ?? 'loxberry/plugin/cantonbar/input';
$input_name_topic = $plugin_cfg['MQTT']['INPUT_NAME_TOPIC'] ?? 'loxberry/plugin/cantonbar/input_name';
$input_map_topic  = $plugin_cfg['MQTT']['INPUT_MAP_TOPIC']  ?? 'loxberry/plugin/cantonbar/input_map';
$cmd_topic    = $plugin_cfg['MQTT']['CMD_TOPIC']        ?? 'loxberry/plugin/cantonbar/cmd';
$poll_int     = $plugin_cfg['MONITOR']['POLL_INTERVAL'] ?? '5';
$loglevel     = $plugin_cfg['MONITOR']['LOGLEVEL']      ?? '4';
$status_timeout = $plugin_cfg['MONITOR']['STATUS_TIMEOUT'] ?? '2';
$recover_threshold = $plugin_cfg['RECOVERY']['FAILURE_THRESHOLD'] ?? '3';
$recover_cooldown  = $plugin_cfg['RECOVERY']['COOLDOWN_SECONDS']  ?? '90';
$token_action      = $plugin_cfg['RECOVERY']['TOKEN_ACTION']      ?? '';
$enable_power_off = !empty($plugin_cfg['EXPERIMENTAL']['ENABLE_POWER_OFF']) && $plugin_cfg['EXPERIMENTAL']['ENABLE_POWER_OFF'] !== '0';
$enable_input_switching = !empty($plugin_cfg['EXPERIMENTAL']['ENABLE_INPUT_SWITCHING']) && $plugin_cfg['EXPERIMENTAL']['ENABLE_INPUT_SWITCHING'] !== '0';
$enable_unsafe_http_input = !empty($plugin_cfg['EXPERIMENTAL']['ENABLE_UNSAFE_HTTP_INPUT']) && $plugin_cfg['EXPERIMENTAL']['ENABLE_UNSAFE_HTTP_INPUT'] !== '0';

$save_msg = '';
$save_ok  = true;
$cmd_sent = false;
$refresh_after_cmd = false;

// -------------------------------------------------------------------------
// Save config
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_config') {
    $new_ip      = trim($_POST['sb_ip']       ?? '');
    $new_mac     = trim($_POST['sb_mac']       ?? '');
    $new_step    = max(1, min(20, (int)($_POST['vol_step']    ?? 5)));
    $new_state_t = trim($_POST['state_topic']  ?? $state_topic);
    $new_vol_t   = trim($_POST['volume_topic'] ?? $volume_topic);
    $new_mute_t  = trim($_POST['mute_topic']   ?? $mute_topic);
    $new_input_t = trim($_POST['input_topic']  ?? $input_topic);
    $new_input_name_t = trim($_POST['input_name_topic'] ?? $input_name_topic);
    $new_input_map_t  = trim($_POST['input_map_topic']  ?? $input_map_topic);
    $new_cmd_t   = trim($_POST['cmd_topic']    ?? $cmd_topic);
    $new_poll    = max(1, min(60, (int)($_POST['poll_int']  ?? 5)));
    $new_ll      = max(1, min(6,  (int)($_POST['loglevel'] ?? 4)));
    $new_status_timeout = max(1, min(10, (int)($_POST['status_timeout'] ?? 2)));
    $new_fail_threshold = max(1, min(20, (int)($_POST['recover_threshold'] ?? 3)));
    $new_cooldown = max(10, min(3600, (int)($_POST['recover_cooldown'] ?? 90)));
    $new_token_action = trim($_POST['token_action'] ?? '');
    $new_enable_power_off = isset($_POST['enable_power_off']) ? 1 : 0;
    $new_enable_input_switching = isset($_POST['enable_input_switching']) ? 1 : 0;
    $new_enable_unsafe_http_input = isset($_POST['enable_unsafe_http_input']) ? 1 : 0;

    // Normalize manually entered MAC.
    if ($new_mac !== '') {
        $new_mac = strtoupper(str_replace('-', ':', $new_mac));
    }

    // Auto-fill MAC from the device's own API (safer than ARP, which can return unrelated MACs).
    if ($new_mac === '' && $new_ip !== '') {
        $ctx = stream_context_create(['http' => ['timeout' => 2]]);
        $info_raw = @file_get_contents("http://$new_ip:1904/canton?action=info", false, $ctx);
        if ($info_raw !== false) {
            $info_json = @json_decode($info_raw, true);
            $api_mac = $info_json['MACAddress'] ?? '';
            if (is_string($api_mac) && preg_match('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/i', $api_mac)) {
                $new_mac = strtoupper($api_mac);
            }
        }
    }

    // NOTE: named $cfg_content — never use $cfg (reserved by LoxBerry SDK for its own global)
    $cfg_content  = "[SOUNDBAR]\n";
    $cfg_content .= "IP=$new_ip\n";
    $cfg_content .= "MAC=$new_mac\n";
    $cfg_content .= "VOLUME_STEP=$new_step\n\n";
    $cfg_content .= "[MQTT]\n";
    $cfg_content .= "STATE_TOPIC=$new_state_t\n";
    $cfg_content .= "VOLUME_TOPIC=$new_vol_t\n";
    $cfg_content .= "MUTE_TOPIC=$new_mute_t\n";
    $cfg_content .= "INPUT_TOPIC=$new_input_t\n";
    $cfg_content .= "INPUT_NAME_TOPIC=$new_input_name_t\n";
    $cfg_content .= "INPUT_MAP_TOPIC=$new_input_map_t\n";
    $cfg_content .= "CMD_TOPIC=$new_cmd_t\n\n";
    $cfg_content .= "[MONITOR]\n";
    $cfg_content .= "POLL_INTERVAL=$new_poll\n";
    $cfg_content .= "LOGLEVEL=$new_ll\n";
    $cfg_content .= "STATUS_TIMEOUT=$new_status_timeout\n\n";
    $cfg_content .= "[RECOVERY]\n";
    $cfg_content .= "FAILURE_THRESHOLD=$new_fail_threshold\n";
    $cfg_content .= "COOLDOWN_SECONDS=$new_cooldown\n";
    $cfg_content .= "TOKEN_ACTION=$new_token_action\n\n";
    $cfg_content .= "[EXPERIMENTAL]\n";
    $cfg_content .= "ENABLE_POWER_OFF=$new_enable_power_off\n";
    $cfg_content .= "ENABLE_INPUT_SWITCHING=$new_enable_input_switching\n";
    $cfg_content .= "ENABLE_UNSAFE_HTTP_INPUT=$new_enable_unsafe_http_input\n";

    @mkdir($lbpconfigdir, 0755, true);
    $written = file_put_contents("$lbpconfigdir/cantonbar.cfg", $cfg_content);

    if ($written === false) {
        $save_msg = "Error: could not write config file to $lbpconfigdir/cantonbar.cfg";
        $save_ok  = false;
    } else {
        shell_exec("sudo /bin/systemctl restart cantonbar.service 2>&1");
        $sb_ip = $new_ip; $sb_mac = $new_mac; $vol_step = $new_step;
        $state_topic = $new_state_t; $volume_topic = $new_vol_t;
        $mute_topic  = $new_mute_t;  $input_topic  = $new_input_t;
        $input_name_topic = $new_input_name_t; $input_map_topic = $new_input_map_t;
        $cmd_topic   = $new_cmd_t;   $poll_int = $new_poll; $loglevel = $new_ll;
        $status_timeout = $new_status_timeout;
        $recover_threshold = $new_fail_threshold;
        $recover_cooldown = $new_cooldown;
        $token_action = $new_token_action;
        $enable_power_off = (bool)$new_enable_power_off;
        $enable_input_switching = (bool)$new_enable_input_switching;
        $enable_unsafe_http_input = (bool)$new_enable_unsafe_http_input;
        $save_msg = "Configuration saved. Daemon restarted.";
    }
}

// -------------------------------------------------------------------------
// Quick test command  (buttons-only form — NO text input inside, so button
// values cannot be silently overwritten by an empty text field)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_cmd') {
    $test_cmd = trim($_POST['test_cmd'] ?? '');
    if ($test_cmd !== '' && $cmd_topic !== '') {
        mqpub(get_mqtt_details(), $cmd_topic, $test_cmd);
        $cmd_sent = true;
        $refresh_after_cmd = true;
    }
}

// Custom free-text command (separate form, separate field name)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'custom_cmd') {
    $test_cmd = trim($_POST['custom_cmd_payload'] ?? '');
    if ($test_cmd !== '' && $cmd_topic !== '') {
        mqpub(get_mqtt_details(), $cmd_topic, $test_cmd);
        $cmd_sent = true;
        $refresh_after_cmd = true;
    }
}

// -------------------------------------------------------------------------
// Restart daemon
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restart_daemon') {
    shell_exec("sudo /bin/systemctl restart cantonbar.service 2>&1");
    $save_msg = "Daemon restarted.";
    $save_ok  = true;
}

// -------------------------------------------------------------------------
// Page output
// -------------------------------------------------------------------------
LBWeb::lbheader("Canton Smart Soundbar", "cantonbar", "help.html");
?>

<div class="container-fluid" style="max-width:860px;">

<?php if ($save_msg): ?>
<div class="alert alert-<?= $save_ok ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($save_msg) ?>
    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
</div>
<?php endif; ?>

<?php if ($cmd_sent): ?>
<div class="alert alert-info alert-dismissible fade show" role="alert">
    Command sent to MQTT topic <code><?= htmlspecialchars($cmd_topic) ?></code>.
    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
</div>
<?php endif; ?>

<!-- ===== Live Status ===== -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Live Status</span>
        <small class="text-muted">Auto-refreshes every 5&thinsp;s &nbsp;&bull;&nbsp; Last updated: <span id="st-time">–</span></small>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6 mb-2 mb-md-0">
                <div class="d-flex justify-content-between align-items-center border rounded px-3 py-2 bg-light">
                    <strong>Power</strong>
                    <span id="st-state"><span class="badge badge-secondary">unknown</span></span>
                </div>
            </div>
            <div class="col-md-6 mb-2 mb-md-0">
                <div class="d-flex justify-content-between align-items-center border rounded px-3 py-2 bg-light">
                    <strong>Volume</strong>
                    <span class="font-weight-bold" id="st-volume">–</span>
                </div>
            </div>
            <div class="col-md-6 mt-md-2 mb-2 mb-md-0">
                <div class="d-flex justify-content-between align-items-center border rounded px-3 py-2 bg-light">
                    <strong>Mute</strong>
                    <span class="font-weight-bold" id="st-mute">–</span>
                </div>
            </div>
            <div class="col-md-6 mt-md-2">
                <div class="d-flex justify-content-between align-items-center border rounded px-3 py-2 bg-light">
                    <strong>Input</strong>
                    <span class="font-weight-bold" id="st-input">–</span>
                </div>
            </div>
            <div class="col-md-6 mt-md-2">
                <div class="d-flex justify-content-between align-items-center border rounded px-3 py-2 bg-light">
                    <strong>API</strong>
                    <span id="st-api"><span class="badge badge-secondary">unknown</span></span>
                </div>
            </div>
            <div class="col-md-6 mt-md-2">
                <div class="d-flex justify-content-between align-items-center border rounded px-3 py-2 bg-light">
                    <strong>ADB</strong>
                    <span id="st-adb"><span class="badge badge-secondary">unknown</span></span>
                </div>
            </div>
            <div class="col-md-6 mt-md-2">
                <div class="d-flex justify-content-between align-items-center border rounded px-3 py-2 bg-light">
                    <strong>LibreKNX</strong>
                    <span id="st-libreknx"><span class="badge badge-secondary">unknown</span></span>
                </div>
            </div>
            <div class="col-md-6 mt-md-2">
                <div class="d-flex justify-content-between align-items-center border rounded px-3 py-2 bg-light">
                    <strong>Token</strong>
                    <span id="st-token"><span class="badge badge-secondary">unknown</span></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== Configuration ===== -->
<div class="card mb-4">
    <div class="card-header">Configuration</div>
    <div class="card-body">
        <form method="post">
        <input type="hidden" name="action" value="save_config">

        <h6 class="text-muted mb-3">Soundbar</h6>
        <div class="form-group row">
            <label class="col-sm-4 col-form-label">IP Address</label>
            <div class="col-sm-5">
                <input type="text" name="sb_ip" class="form-control" value="<?= htmlspecialchars($sb_ip) ?>" placeholder="192.168.1.x">
            </div>
        </div>
        <div class="form-group row">
            <label class="col-sm-4 col-form-label">MAC Address <small class="text-muted">(WoL)</small></label>
            <div class="col-sm-5">
                <input type="text" name="sb_mac" class="form-control" value="<?= htmlspecialchars($sb_mac) ?>" placeholder="auto-discovered">
                <small class="form-text text-muted">Leave blank to auto-discover via ARP on save. Required for <code>power_on</code>.</small>
            </div>
        </div>
        <div class="form-group row">
            <label class="col-sm-4 col-form-label">Volume Step</label>
            <div class="col-sm-2">
                <input type="number" name="vol_step" class="form-control" value="<?= (int)$vol_step ?>" min="1" max="20">
            </div>
        </div>

        <hr>
        <h6 class="text-muted mb-3">MQTT Topics</h6>
        <div class="form-group row">
            <label class="col-sm-4 col-form-label">State topic</label>
            <div class="col-sm-8"><input type="text" name="state_topic" class="form-control" value="<?= htmlspecialchars($state_topic) ?>"></div>
        </div>
        <div class="form-group row">
            <label class="col-sm-4 col-form-label">Volume topic</label>
            <div class="col-sm-8"><input type="text" name="volume_topic" class="form-control" value="<?= htmlspecialchars($volume_topic) ?>"></div>
        </div>
        <div class="form-group row">
            <label class="col-sm-4 col-form-label">Mute topic</label>
            <div class="col-sm-8"><input type="text" name="mute_topic" class="form-control" value="<?= htmlspecialchars($mute_topic) ?>"></div>
        </div>
        <div class="form-group row">
            <label class="col-sm-4 col-form-label">Input topic</label>
            <div class="col-sm-8"><input type="text" name="input_topic" class="form-control" value="<?= htmlspecialchars($input_topic) ?>"></div>
        </div>
        <div class="form-group row">
            <label class="col-sm-4 col-form-label">Input name topic</label>
            <div class="col-sm-8"><input type="text" name="input_name_topic" class="form-control" value="<?= htmlspecialchars($input_name_topic) ?>"></div>
        </div>
        <div class="form-group row">
            <label class="col-sm-4 col-form-label">Input map topic</label>
            <div class="col-sm-8"><input type="text" name="input_map_topic" class="form-control" value="<?= htmlspecialchars($input_map_topic) ?>"></div>
        </div>
        <div class="form-group row">
            <label class="col-sm-4 col-form-label">Command topic</label>
            <div class="col-sm-8"><input type="text" name="cmd_topic" class="form-control" value="<?= htmlspecialchars($cmd_topic) ?>"></div>
        </div>

        <hr>
        <h6 class="text-muted mb-3">Daemon</h6>
        <div class="form-group row">
            <label class="col-sm-4 col-form-label">Poll Interval (s)</label>
            <div class="col-sm-2">
                <input type="number" name="poll_int" class="form-control" value="<?= (int)$poll_int ?>" min="1" max="60">
            </div>
        </div>
        <div class="form-group row">
            <label class="col-sm-4 col-form-label">Log Level</label>
            <div class="col-sm-4">
                <select name="loglevel" class="form-control">
                    <?php foreach ([3 => 'Warning (3)', 4 => 'Info (4)', 5 => 'Debug (5)'] as $v => $l): ?>
                    <option value="<?= $v ?>"<?= $v === (int)$loglevel ? ' selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="form-text text-muted">Change takes effect after daemon restart.</small>
            </div>
        </div>
        <div class="form-group row">
            <label class="col-sm-4 col-form-label">Status timeout (s)</label>
            <div class="col-sm-2">
                <input type="number" name="status_timeout" class="form-control" value="<?= (int)$status_timeout ?>" min="1" max="10">
            </div>
            <div class="col-sm-6"><small class="form-text text-muted">Timeout for <code>action=status</code>. Lower values reduce UI lag if LibreKNX hangs.</small></div>
        </div>
        <div class="form-group row">
            <label class="col-sm-4 col-form-label">API failure threshold</label>
            <div class="col-sm-2">
                <input type="number" name="recover_threshold" class="form-control" value="<?= (int)$recover_threshold ?>" min="1" max="20">
            </div>
            <div class="col-sm-6"><small class="form-text text-muted">How many failed API checks before auto-recovery via ADB.</small></div>
        </div>
        <div class="form-group row">
            <label class="col-sm-4 col-form-label">Recovery cooldown (s)</label>
            <div class="col-sm-2">
                <input type="number" name="recover_cooldown" class="form-control" value="<?= (int)$recover_cooldown ?>" min="10" max="3600">
            </div>
            <div class="col-sm-6"><small class="form-text text-muted">Minimum delay between recovery attempts.</small></div>
        </div>
        <div class="form-group row">
            <label class="col-sm-4 col-form-label">Token action</label>
            <div class="col-sm-4">
                <input type="text" name="token_action" class="form-control" value="<?= htmlspecialchars($token_action) ?>" placeholder="optional, e.g. token">
                <small class="form-text text-muted">If set, daemon calls this action after recovery/start and stores token health status.</small>
            </div>
        </div>
        <div class="form-group row">
            <label class="col-sm-4 col-form-label">Experimental power off</label>
            <div class="col-sm-8">
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" name="enable_power_off" id="enable_power_off" value="1"<?= $enable_power_off ? ' checked' : '' ?>>
                    <label class="form-check-label" for="enable_power_off">Enable network standby command</label>
                </div>
                <small class="form-text text-muted">Disabled by default because current LibreKNX standby action returns HTTP 400 and can destabilize the API.</small>
            </div>
        </div>
        <div class="form-group row">
            <label class="col-sm-4 col-form-label">Experimental input switching</label>
            <div class="col-sm-8">
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" name="enable_input_switching" id="enable_input_switching" value="1"<?= $enable_input_switching ? ' checked' : '' ?>>
                    <label class="form-check-label" for="enable_input_switching">Enable input_N commands</label>
                </div>
                <small class="form-text text-muted">Disabled by default because tested input actions are currently unverified and may crash LibreKNX.</small>
            </div>
        </div>
        <div class="form-group row">
            <label class="col-sm-4 col-form-label">Unsafe HTTP input write</label>
            <div class="col-sm-8">
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" name="enable_unsafe_http_input" id="enable_unsafe_http_input" value="1"<?= $enable_unsafe_http_input ? ' checked' : '' ?>>
                    <label class="form-check-label" for="enable_unsafe_http_input">Allow <code>POST action=input</code> attempts</label>
                </div>
                <small class="form-text text-danger">Only enable for diagnostics. On current firmware this call frequently crashes LibreKNX.</small>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Save Configuration</button>
        </form>
    </div>
</div>

<!-- ===== Test Controls ===== -->
<div class="card mb-4">
    <div class="card-header">Test Controls</div>
    <div class="card-body">

        <!-- Quick commands: buttons only, NO text input in this form -->
        <form method="post" class="mb-3">
        <input type="hidden" name="action" value="test_cmd">
        <div class="d-flex flex-wrap" style="gap:6px;">
            <button type="submit" name="test_cmd" value="power_on"    class="btn btn-success">Power On (WoL)</button>
            <button type="submit" name="test_cmd" value="power_off"   class="btn btn-danger"<?= $enable_power_off ? '' : ' disabled title="Enable Experimental power off in Configuration first"' ?>>Standby</button>
            <button type="submit" name="test_cmd" value="volume_up"   class="btn btn-secondary">Vol +</button>
            <button type="submit" name="test_cmd" value="volume_down" class="btn btn-secondary">Vol −</button>
            <button type="submit" name="test_cmd" value="mute_on"     class="btn btn-warning">Mute</button>
            <button type="submit" name="test_cmd" value="mute_off"    class="btn btn-info text-white">Unmute</button>
            <button type="submit" name="test_cmd" value="mute_toggle" class="btn btn-outline-secondary">Mute Toggle</button>
        </div>
        </form>

        <div class="small text-muted mb-3">
            <strong>Note:</strong> <code>power_off</code> and <code>input_N</code> are experimental and disabled by default because current LibreKNX behavior can crash the API.
        </div>

        <!-- Custom command: separate form, different field name -->
        <form method="post" class="mb-3">
        <input type="hidden" name="action" value="custom_cmd">
        <div class="input-group" style="max-width:420px;">
            <input type="text" name="custom_cmd_payload" class="form-control" placeholder="e.g. volume_set_40 or input_3">
            <div class="input-group-append">
                <button type="submit" class="btn btn-outline-primary">Send</button>
            </div>
        </div>
        </form>

        <!-- Restart daemon -->
        <form method="post">
        <input type="hidden" name="action" value="restart_daemon">
        <button type="submit" class="btn btn-secondary"
                onclick="this.disabled=true; this.textContent='Restarting…'">Restart Daemon</button>
        </form>

    </div>
</div>

<!-- ===== Log viewer ===== -->
<div class="card mb-4">
    <div class="card-header">Log <small class="text-muted ml-1">(last 60 lines)</small></div>
    <div class="card-body p-0">
        <pre class="m-0 p-3" style="background:#1e1e1e;color:#d4d4d4;font-size:.78em;max-height:340px;overflow-y:auto;border-radius:0 0 .25rem .25rem;white-space:pre-wrap;"><?php
$logfile = "$lbplogdir/monitor.log";
if (file_exists($logfile) && filesize($logfile) > 0) {
    $lines = file($logfile);
    echo htmlspecialchars(implode("", array_slice($lines, -60)));
} elseif (file_exists($logfile)) {
    echo "Log file exists but is empty.\n";
    echo "→ Enter the Soundbar IP above and click Save Configuration.\n";
    echo "→ Then check:  systemctl status cantonbar.service";
} else {
    echo "Log file not found: $logfile\n";
    echo "→ Check:  systemctl status cantonbar.service";
}
?></pre>
    </div>
</div>

</div><!-- /container-fluid -->

<script>
function healthBadgeClass(v) {
    if (v === 'up' || v === 'running' || v === 'connected' || v === 'ok') return 'badge-success';
    if (v === 'recovering' || v === 'restarting') return 'badge-warning';
    if (v === 'disabled') return 'badge-info';
    if (v === 'unknown') return 'badge-secondary';
    return 'badge-danger';
}

function updateStatus() {
    fetch('index.php?ajax=status&_=' + Date.now())
        .then(function(r) { return r.json(); })
        .then(function(d) {
            var s = d.state;
            var cls = s === 'on'      ? 'badge-success' :
                      s === 'standby' ? 'badge-warning'  : 'badge-secondary';
            document.getElementById('st-state').innerHTML =
                '<span class="badge ' + cls + ' badge-pill px-3 py-2">' + s.toUpperCase() + '</span>';
            document.getElementById('st-volume').textContent =
                d.volume !== '-' ? d.volume + '%' : '–';
            document.getElementById('st-mute').textContent =
                d.mute !== '-' ? (d.mute === 'on' ? 'ON' : 'OFF') : '–';
            if (d.input !== '-') {
                var inputText = String(d.input);
                if (d.input_name && d.input_name !== '-' && d.input_name !== inputText) {
                    inputText += ' (' + d.input_name + ')';
                }
                document.getElementById('st-input').textContent = inputText;
            } else {
                document.getElementById('st-input').textContent = '–';
            }

            document.getElementById('st-api').innerHTML =
                '<span class="badge ' + healthBadgeClass(d.api) + ' badge-pill px-3 py-2">' + String(d.api).toUpperCase() + '</span>';
            document.getElementById('st-adb').innerHTML =
                '<span class="badge ' + healthBadgeClass(d.adb) + ' badge-pill px-3 py-2">' + String(d.adb).toUpperCase() + '</span>';
            document.getElementById('st-libreknx').innerHTML =
                '<span class="badge ' + healthBadgeClass(d.libreknx) + ' badge-pill px-3 py-2">' + String(d.libreknx).toUpperCase() + '</span>';
            document.getElementById('st-token').innerHTML =
                '<span class="badge ' + healthBadgeClass(d.token) + ' badge-pill px-3 py-2">' + String(d.token).toUpperCase() + '</span>';

            document.getElementById('st-time').textContent = d.updated;
        })
        .catch(function() {});
}
updateStatus();
setInterval(updateStatus, 5000);
<?php if ($refresh_after_cmd): ?>
setTimeout(updateStatus, 2500);
setTimeout(updateStatus, 5500);
<?php endif; ?>
</script>

<?php LBWeb::lbfooter(); ?>
