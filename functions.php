<?php
/**
 * Terror en Rapel — Funciones Core
 * Seguridad: XSS, CSRF, SQL injection prevention, session management
 */

// ── Output sanitization ───────────────────────────────────────
function h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function j(mixed $v): string {
    return json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}

function sanitize_input(string $input, int $maxlen = 1000): string {
    $input = trim($input);
    $input = mb_substr($input, 0, $maxlen);
    return $input;
}

function sanitize_url(string $url): string {
    $url = filter_var(trim($url), FILTER_SANITIZE_URL);
    if (!filter_var($url, FILTER_VALIDATE_URL)) return '';
    $parsed = parse_url($url);
    if (!in_array($parsed['scheme'] ?? '', ['http', 'https'])) return '';
    return $url;
}

function sanitize_email(string $email): string {
    return filter_var(trim($email), FILTER_SANITIZE_EMAIL);
}

// ── CSRF ──────────────────────────────────────────────────────
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_LENGTH));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function csrf_verify(): bool {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals(csrf_token(), $token);
}

function csrf_check(): void {
    if (!csrf_verify()) {
        http_response_code(403);
        die(json_encode(['error' => 'Token CSRF inválido.']));
    }
}

// ── Session ───────────────────────────────────────────────────
function session_start_secure(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
    // Regenerate session ID periodically
    if (!isset($_SESSION['last_regen']) || time() - $_SESSION['last_regen'] > 300) {
        session_regenerate_id(true);
        $_SESSION['last_regen'] = time();
    }
    // Check session timeout
    if (isset($_SESSION['last_active']) && (time() - $_SESSION['last_active'] > SESSION_TIMEOUT)) {
        session_unset();
        session_destroy();
        session_start_secure();
    }
    $_SESSION['last_active'] = time();
}

// ── Data Storage (JSON flat-file) ─────────────────────────────
function data_path(string $file): string {
    return DATA_PATH . '/' . preg_replace('/[^a-z0-9_\-]/', '', $file) . '.json';
}

function data_read(string $file, mixed $default = []): mixed {
    $path = data_path($file);
    if (!file_exists($path)) return $default;
    $json = file_get_contents($path);
    if ($json === false) return $default;
    $data = json_decode($json, true);
    return $data ?? $default;
}

function data_write(string $file, mixed $data): bool {
    $path = data_path($file);
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $tmp = $path . '.tmp.' . getmypid();
    if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) return false;
    return rename($tmp, $path);
}

// ── Config ────────────────────────────────────────────────────
function config_load(): array {
    global $DEFAULT_CONFIG;
    $saved = data_read('config', []);
    return array_merge($DEFAULT_CONFIG, $saved);
}

function config_save(array $cfg): bool {
    return data_write('config', $cfg);
}

function cfg(string $key, mixed $default = null): mixed {
    static $config = null;
    if ($config === null) $config = config_load();
    return $config[$key] ?? $default;
}

// ── Users ─────────────────────────────────────────────────────
function users_load(): array {
    global $DEFAULT_ADMIN_USERS;
    $users = data_read('users', []);
    if (empty($users)) {
        data_write('users', $DEFAULT_ADMIN_USERS);
        return $DEFAULT_ADMIN_USERS;
    }
    return $users;
}

function users_save(array $users): bool {
    return data_write('users', $users);
}

function user_get(string $username): ?array {
    $users = users_load();
    return $users[$username] ?? null;
}

function user_has_perm(array $user, string $perm): bool {
    global $ROLES;
    $role = $ROLES[$user['role']] ?? null;
    if (!$role) return false;
    $perms = array_merge($role['permissions'], $user['permissions'] ?? []);
    return in_array(PERM_ALL, $perms) || in_array($perm, $perms);
}

function current_user(): ?array {
    if (!isset($_SESSION['admin_user'])) return null;
    return user_get($_SESSION['admin_user']);
}

function require_perm(string $perm): void {
    $user = current_user();
    if (!$user || !user_has_perm($user, $perm)) {
        http_response_code(403);
        die(json_encode(['error' => 'Sin permiso para esta acción.']));
    }
}

// ── Login / Auth ──────────────────────────────────────────────
function get_login_attempts(string $username): array {
    $attempts = data_read('login_attempts', []);
    return $attempts[$username] ?? ['count' => 0, 'last' => 0];
}

function record_login_attempt(string $username, bool $success): void {
    $attempts = data_read('login_attempts', []);
    if ($success) {
        unset($attempts[$username]);
    } else {
        $current = $attempts[$username] ?? ['count' => 0, 'last' => 0];
        // Reset if lockout expired
        if (time() - $current['last'] > LOGIN_LOCKOUT_TIME) {
            $current = ['count' => 0, 'last' => 0];
        }
        $current['count']++;
        $current['last'] = time();
        $attempts[$username] = $current;
    }
    data_write('login_attempts', $attempts);
}

function is_locked_out(string $username): bool {
    $a = get_login_attempts($username);
    if ($a['count'] < MAX_LOGIN_ATTEMPTS) return false;
    return (time() - $a['last']) < LOGIN_LOCKOUT_TIME;
}

function attempt_login(string $username, string $password): array {
    $username = sanitize_input($username, 50);
    if (is_locked_out($username)) {
        return ['ok' => false, 'msg' => 'Cuenta bloqueada por exceso de intentos. Espera 15 minutos.'];
    }
    $user = user_get($username);
    if (!$user || !$user['active']) {
        record_login_attempt($username, false);
        return ['ok' => false, 'msg' => 'Usuario o contraseña incorrectos.'];
    }
    if (!password_verify($password, $user['password'])) {
        record_login_attempt($username, false);
        $left = MAX_LOGIN_ATTEMPTS - get_login_attempts($username)['count'];
        return ['ok' => false, 'msg' => "Contraseña incorrecta. Intentos restantes: $left"];
    }
    record_login_attempt($username, true);
    session_regenerate_id(true);
    $_SESSION['admin_user'] = $username;
    $_SESSION['admin_login_time'] = time();
    // Update last_login
    $users = users_load();
    $users[$username]['last_login'] = date('Y-m-d H:i:s');
    users_save($users);
    return ['ok' => true, 'msg' => 'Bienvenido, ' . $user['display_name']];
}

// ── RSS Feed Parser ───────────────────────────────────────────
function detect_feed_provider(string $url): string {
    if (str_contains($url, 'spreaker.com')) return 'spreaker';
    if (str_contains($url, 'anchor.fm') || str_contains($url, 'spotify.com/show')) return 'anchor';
    if (str_contains($url, 'buzzsprout.com')) return 'buzzsprout';
    if (str_contains($url, 'podbean.com')) return 'podbean';
    if (str_contains($url, 'libsyn.com')) return 'libsyn';
    if (str_contains($url, 'simplecast.com')) return 'simplecast';
    if (str_contains($url, 'transistor.fm')) return 'transistor';
    if (str_contains($url, 'captivate.fm')) return 'captivate';
    return 'rss';
}

function fetch_feed(string $url, int $timeout = 15): ?string {
    $url = sanitize_url($url);
    if (!$url) return null;
    $ctx = stream_context_create([
        'http' => [
            'timeout'       => $timeout,
            'user_agent'    => 'TerrorEnRapel/2.0 RSS Reader',
            'follow_location' => 1,
            'max_redirects' => 5,
            'ignore_errors' => false,
        ],
        'ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
        ],
    ]);
    $content = @file_get_contents($url, false, $ctx);
    return $content !== false ? $content : null;
}

function parse_rss(string $xml): array {
    // Suppress errors, parse XML safely
    $prev = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$doc) return ['info' => [], 'episodes' => []];

    $ns_itunes = $doc->channel->children('http://www.itunes.com/dtds/podcast-1.0.dtd');
    $ns_atom   = $doc->channel->children('http://www.w3.org/2005/Atom');

    // Podcast info
    $info = [
        'title'       => (string)($doc->channel->title ?? ''),
        'description' => strip_tags((string)($doc->channel->description ?? '')),
        'link'        => sanitize_url((string)($doc->channel->link ?? '')),
        'language'    => (string)($doc->channel->language ?? 'es'),
        'author'      => (string)($ns_itunes->author ?? ''),
        'image'       => '',
        'categories'  => [],
        'explicit'    => (string)($ns_itunes->explicit ?? 'no'),
        'total'       => 0,
    ];

    // Image
    if (isset($doc->channel->image->url)) {
        $info['image'] = sanitize_url((string)$doc->channel->image->url);
    }
    if (isset($ns_itunes->image)) {
        $img_attrs = $ns_itunes->image->attributes();
        if (isset($img_attrs['href'])) {
            $info['image'] = sanitize_url((string)$img_attrs['href']);
        }
    }

    // Episodes
    $episodes = [];
    foreach ($doc->channel->item as $item) {
        $it = $item->children('http://www.itunes.com/dtds/podcast-1.0.dtd');
        $enc = $item->enclosure ?? null;
        $audio_url = '';
        $audio_type = '';
        $audio_length = 0;
        if ($enc) {
            $ea = $enc->attributes();
            $audio_url = sanitize_url((string)($ea['url'] ?? ''));
            $audio_type = (string)($ea['type'] ?? '');
            $audio_length = (int)($ea['length'] ?? 0);
        }
        if (!$audio_url) continue;

        $ep_image = '';
        if (isset($it->image)) {
            $ia = $it->image->attributes();
            if (isset($ia['href'])) {
                $ep_image = sanitize_url((string)$ia['href']);
            }
        }

        $pub_date = (string)($item->pubDate ?? '');
        $timestamp = $pub_date ? strtotime($pub_date) : 0;

        $duration_raw = (string)($it->duration ?? '0');
        $duration_sec = parse_duration($duration_raw);

        $guid = (string)($item->guid ?? $audio_url);

        $episodes[] = [
            'guid'        => md5($guid),
            'title'       => strip_tags((string)($item->title ?? '')),
            'description' => strip_tags((string)($item->description ?? '')),
            'content'     => (string)($item->children('http://purl.org/rss/1.0/modules/content/')->encoded ?? ''),
            'pub_date'    => $pub_date,
            'timestamp'   => $timestamp,
            'audio_url'   => $audio_url,
            'audio_type'  => $audio_type,
            'audio_length'=> $audio_length,
            'duration'    => $duration_sec,
            'duration_fmt'=> format_duration($duration_sec),
            'image'       => $ep_image ?: $info['image'],
            'episode_num' => (int)($it->episode ?? 0),
            'season'      => (int)($it->season ?? 0),
            'explicit'    => (string)($it->explicit ?? 'no'),
            'ep_type'     => (string)($it->episodeType ?? 'full'),
            'link'        => sanitize_url((string)($item->link ?? '')),
        ];
    }

    $info['total'] = count($episodes);
    return ['info' => $info, 'episodes' => $episodes];
}

function parse_duration(string $d): int {
    if (is_numeric($d)) return (int)$d;
    $parts = explode(':', $d);
    $parts = array_reverse($parts);
    $sec = 0;
    foreach ($parts as $i => $p) $sec += (int)$p * (60 ** $i);
    return $sec;
}

function format_duration(int $sec): string {
    if ($sec <= 0) return '0:00';
    $h = floor($sec / 3600);
    $m = floor(($sec % 3600) / 60);
    $s = $sec % 60;
    if ($h > 0) return sprintf('%d:%02d:%02d', $h, $m, $s);
    return sprintf('%d:%02d', $m, $s);
}

// ── Feed Cache ────────────────────────────────────────────────
function cache_key(string $url): string {
    return CACHE_PATH . '/feed_' . md5($url) . '.json';
}

function cache_get(string $url, int $ttl): ?array {
    $path = cache_key($url);
    if (!file_exists($path)) return null;
    if (time() - filemtime($path) > $ttl) return null;
    $data = json_decode(file_get_contents($path), true);
    return $data ?: null;
}

function cache_set(string $url, array $data): void {
    $path = cache_key($url);
    if (!is_dir(CACHE_PATH)) mkdir(CACHE_PATH, 0750, true);
    file_put_contents($path, json_encode($data), LOCK_EX);
}

function cache_clear(): void {
    foreach (glob(CACHE_PATH . '/*.json') as $f) unlink($f);
}

function get_episodes(bool $force = false): array {
    $cfg = config_load();
    $url = $cfg['feed_url'];
    $ttl = (int)($cfg['feed_cache_ttl'] ?? 900);
    $timeout = (int)($cfg['feed_timeout'] ?? 15);

    if (empty($cfg['feed_provider'])) {
        $cfg['feed_provider'] = detect_feed_provider($url);
    }

    if (!$force) {
        $cached = cache_get($url, $ttl);
        if ($cached) return $cached;
    }

    $xml = fetch_feed($url, $timeout);
    if (!$xml && !empty($cfg['feed_backup_url'])) {
        $xml = fetch_feed($cfg['feed_backup_url'], $timeout);
    }
    if (!$xml) {
        $cached = cache_get($url, PHP_INT_MAX);
        return $cached ?? ['info' => [], 'episodes' => []];
    }
    $data = parse_rss($xml);
    $data['info']['provider'] = $cfg['feed_provider'] ?? detect_feed_provider($url);
    cache_set($url, $data);

    // Track analytics
    $today = date('Y-m-d');
    $stats = data_read('analytics', []);
    $stats['feed_fetches'][$today] = ($stats['feed_fetches'][$today] ?? 0) + 1;
    data_write('analytics', $stats);

    return $data;
}

// ── Analytics ─────────────────────────────────────────────────
function track_play(string $episode_guid): void {
    $stats = data_read('analytics', []);
    $today = date('Y-m-d');
    $stats['plays'][$today][$episode_guid] = ($stats['plays'][$today][$episode_guid] ?? 0) + 1;
    $stats['plays_total'][$episode_guid] = ($stats['plays_total'][$episode_guid] ?? 0) + 1;
    $stats['page_views'][$today] = ($stats['page_views'][$today] ?? 0);
    data_write('analytics', $stats);
}

function track_pageview(): void {
    $stats = data_read('analytics', []);
    $today = date('Y-m-d');
    $stats['page_views'][$today] = ($stats['page_views'][$today] ?? 0) + 1;
    data_write('analytics', $stats);
}

// ── Historias de oyentes ──────────────────────────────────────
function story_submit(array $data): array {
    $name    = sanitize_input($data['name'] ?? 'Anónimo', 100);
    $email   = sanitize_email($data['email'] ?? '');
    $title   = sanitize_input($data['title'] ?? '', 200);
    $story   = sanitize_input($data['story'] ?? '', (int)cfg('stories_max_length', 5000));
    $location= sanitize_input($data['location'] ?? '', 100);

    if (strlen($story) < 50) return ['ok' => false, 'msg' => 'La historia debe tener al menos 50 caracteres.'];
    if (empty($title)) return ['ok' => false, 'msg' => 'El título es requerido.'];

    $stories = data_read('stories', []);
    $id = uniqid('st_', true);
    $stories[$id] = [
        'id'        => $id,
        'name'      => $name,
        'email'     => $email,
        'title'     => $title,
        'story'     => $story,
        'location'  => $location,
        'status'    => cfg('stories_require_approval', true) ? 'pending' : 'approved',
        'created_at'=> date('Y-m-d H:i:s'),
        'ip'        => hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''),
    ];
    data_write('stories', $stories);
    return ['ok' => true, 'msg' => cfg('stories_success_msg', '¡Historia recibida!')];
}

// ── Newsletter ────────────────────────────────────────────────
function newsletter_subscribe(string $email): array {
    $email = sanitize_email($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'msg' => 'Email inválido.'];
    }
    $subs = data_read('subscribers', []);
    if (isset($subs[$email])) {
        return ['ok' => false, 'msg' => 'Ya estás suscrito.'];
    }
    $subs[$email] = ['email' => $email, 'date' => date('Y-m-d H:i:s'), 'active' => true];
    data_write('subscribers', $subs);
    return ['ok' => true, 'msg' => '¡Suscripción exitosa!'];
}

// ── Rate limiting ─────────────────────────────────────────────
function rate_limit(string $action, int $max = 5, int $window = 60): bool {
    $ip = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
    $key = "rl_{$action}_{$ip}";
    $limits = data_read('rate_limits', []);
    $now = time();
    $entry = $limits[$key] ?? ['count' => 0, 'reset' => $now + $window];
    if ($now > $entry['reset']) {
        $entry = ['count' => 0, 'reset' => $now + $window];
    }
    $entry['count']++;
    $limits[$key] = $entry;
    data_write('rate_limits', $limits);
    return $entry['count'] <= $max;
}

// ── Helpers ───────────────────────────────────────────────────
function time_ago(int $ts): string {
    $diff = time() - $ts;
    if ($diff < 60) return 'hace un momento';
    if ($diff < 3600) return 'hace ' . floor($diff/60) . ' min';
    if ($diff < 86400) return 'hace ' . floor($diff/3600) . ' h';
    if ($diff < 2592000) return 'hace ' . floor($diff/86400) . ' días';
    if ($diff < 31536000) return 'hace ' . floor($diff/2592000) . ' meses';
    return 'hace ' . floor($diff/31536000) . ' años';
}

function format_bytes(int $bytes): string {
    if ($bytes < 1024) return "{$bytes} B";
    if ($bytes < 1048576) return round($bytes/1024, 1) . ' KB';
    return round($bytes/1048576, 1) . ' MB';
}

function is_ajax(): bool {
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || ($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json';
}

function json_response(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}
