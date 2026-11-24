<?php
/**
 * Terror en Rapel — Panel de Control
 * Acceso: /admin.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

session_start_secure();

// ── Manejo de AJAX/API ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize_input($_POST['action'] ?? $_GET['action'] ?? '', 50);

    // Login (no requiere autenticación previa)
    if ($action === 'login') {
        $result = attempt_login($_POST['username'] ?? '', $_POST['password'] ?? '');
        json_response($result);
    }

    // Logout
    if ($action === 'logout') {
        session_unset();
        session_destroy();
        json_response(['ok' => true]);
    }

    // Todas las demás acciones requieren estar logueado
    $user = current_user();
    if (!$user) json_response(['error' => 'No autenticado.'], 401);

    csrf_check();

    switch ($action) {
        case 'save_config':
            require_perm(PERM_SETTINGS_GENERAL);
            $cfg = config_load();
            $allowed_keys = array_keys($cfg);
            foreach ($_POST as $k => $v) {
                if ($k === 'action' || $k === 'csrf_token') continue;
                if (!in_array($k, $allowed_keys)) continue;
                if (is_bool($cfg[$k])) { $cfg[$k] = ($v === '1' || $v === 'true'); }
                elseif (is_int($cfg[$k]))  { $cfg[$k] = (int)$v; }
                elseif (is_array($cfg[$k])){ /* handled separately */ }
                else { $cfg[$k] = sanitize_input((string)$v, 2000); }
            }
            // Handle URL fields specifically
            $url_fields = ['feed_url','feed_backup_url','site_web_url','host_website','host_image',
                           'funding_url','og_image','site_logo_url','favicon_url','hero_custom_banner_url',
                           'canonical_url','supporters_club_url','support_patreon_url','support_paypal_url',
                           'support_ko_fi_url','support_buy_me_coffee_url','support_mercado_pago_url'];
            foreach ($url_fields as $f) {
                if (isset($_POST[$f])) $cfg[$f] = sanitize_url($_POST[$f]);
            }
            // Social URLs
            foreach ($cfg as $k => $v) {
                if (str_starts_with($k, 'social_') && isset($_POST[$k])) {
                    $cfg[$k] = sanitize_url($_POST[$k]);
                }
            }
            config_save($cfg);
            json_response(['ok' => true, 'msg' => 'Configuración guardada.']);

        case 'clear_cache':
            require_perm(PERM_CACHE_CLEAR);
            cache_clear();
            json_response(['ok' => true, 'msg' => 'Caché limpiado.']);

        case 'refresh_feed':
            require_perm(PERM_CACHE_CLEAR);
            $data = get_episodes(true);
            json_response(['ok' => true, 'msg' => 'Feed actualizado.', 'count' => count($data['episodes'])]);

        case 'save_sponsors':
            require_perm(PERM_SPONSORS_EDIT);
            $cfg = config_load();
            $sponsors = json_decode($_POST['sponsors'] ?? '[]', true);
            if (!is_array($sponsors)) json_response(['error' => 'Datos inválidos.'], 400);
            $clean = [];
            foreach ($sponsors as $s) {
                $clean[] = [
                    'name'        => sanitize_input($s['name'] ?? '', 100),
                    'description' => sanitize_input($s['description'] ?? '', 300),
                    'image'       => sanitize_url($s['image'] ?? ''),
                    'url'         => sanitize_url($s['url'] ?? ''),
                    'label'       => sanitize_input($s['label'] ?? 'Ver más', 50),
                    'category'    => sanitize_input($s['category'] ?? '', 50),
                    'active'      => (bool)($s['active'] ?? true),
                ];
            }
            $cfg['sponsors'] = $clean;
            config_save($cfg);
            json_response(['ok' => true, 'msg' => 'Patrocinadores guardados.']);

        case 'save_user':
            require_perm(PERM_USERS_EDIT);
            $users = users_load();
            $uname = sanitize_input($_POST['username'] ?? '', 50);
            $is_new = !isset($users[$uname]);
            if ($is_new && empty($_POST['password'])) json_response(['error' => 'Contraseña requerida.'], 400);
            $entry = $users[$uname] ?? [
                'username'    => $uname,
                'created_at'  => date('Y-m-d'),
                'last_login'  => null,
                'can_delete'  => true,
            ];
            $entry['display_name'] = sanitize_input($_POST['display_name'] ?? $uname, 100);
            $entry['email']        = sanitize_email($_POST['email'] ?? '');
            $entry['role']         = sanitize_input($_POST['role'] ?? 'viewer', 30);
            $entry['active']       = ($_POST['active'] ?? '1') === '1';
            if (!empty($_POST['password'])) {
                if (strlen($_POST['password']) < 8) json_response(['error' => 'Contraseña mínimo 8 caracteres.'], 400);
                $entry['password'] = password_hash($_POST['password'], PASSWORD_BCRYPT);
            }
            $users[$uname] = $entry;
            users_save($users);
            json_response(['ok' => true, 'msg' => $is_new ? 'Usuario creado.' : 'Usuario actualizado.']);

        case 'delete_user':
            require_perm(PERM_USERS_DELETE);
            $users = users_load();
            $uname = sanitize_input($_POST['username'] ?? '', 50);
            if (!isset($users[$uname])) json_response(['error' => 'Usuario no encontrado.'], 404);
            if (!($users[$uname]['can_delete'] ?? true)) json_response(['error' => 'No se puede eliminar este usuario.'], 403);
            if ($uname === ($_SESSION['admin_user'] ?? '')) json_response(['error' => 'No puedes eliminarte a ti mismo.'], 403);
            unset($users[$uname]);
            users_save($users);
            json_response(['ok' => true, 'msg' => 'Usuario eliminado.']);

        case 'get_analytics':
            require_perm(PERM_ANALYTICS);
            $stats = data_read('analytics', []);
            $stories_count = count(data_read('stories', []));
            $subs_count = count(data_read('subscribers', []));
            $days = 30;
            $labels = [];
            $views = [];
            $plays = [];
            for ($i = $days-1; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-$i days"));
                $labels[] = date('d/m', strtotime($d));
                $views[] = $stats['page_views'][$d] ?? 0;
                $plays_day = $stats['plays'][$d] ?? [];
                $plays[] = array_sum($plays_day);
            }
            $top_episodes = [];
            foreach ($stats['plays_total'] ?? [] as $guid => $cnt) {
                $top_episodes[] = ['guid' => $guid, 'plays' => $cnt];
            }
            usort($top_episodes, fn($a,$b) => $b['plays'] <=> $a['plays']);
            json_response([
                'ok' => true,
                'labels' => $labels,
                'views' => $views,
                'plays' => $plays,
                'total_views' => array_sum($stats['page_views'] ?? []),
                'total_plays' => array_sum($stats['plays_total'] ?? []),
                'stories' => $stories_count,
                'subscribers' => $subs_count,
                'top_episodes' => array_slice($top_episodes, 0, 10),
            ]);

        case 'get_stories':
            require_perm(PERM_STORIES_VIEW);
            $stories = data_read('stories', []);
            $filter = sanitize_input($_POST['filter'] ?? 'all', 20);
            if ($filter !== 'all') {
                $stories = array_filter($stories, fn($s) => $s['status'] === $filter);
            }
            usort($stories, fn($a,$b) => strcmp($b['created_at'], $a['created_at']));
            json_response(['ok' => true, 'stories' => array_values($stories)]);

        case 'update_story':
            require_perm(PERM_STORIES_EDIT);
            $stories = data_read('stories', []);
            $id = sanitize_input($_POST['id'] ?? '', 50);
            $status = sanitize_input($_POST['status'] ?? 'pending', 20);
            if (!isset($stories[$id])) json_response(['error' => 'Historia no encontrada.'], 404);
            $stories[$id]['status'] = in_array($status, ['pending','approved','rejected']) ? $status : 'pending';
            data_write('stories', $stories);
            json_response(['ok' => true, 'msg' => 'Historia actualizada.']);

        case 'delete_story':
            require_perm(PERM_STORIES_DELETE);
            $stories = data_read('stories', []);
            $id = sanitize_input($_POST['id'] ?? '', 50);
            unset($stories[$id]);
            data_write('stories', $stories);
            json_response(['ok' => true, 'msg' => 'Historia eliminada.']);

        case 'export_data':
            require_perm(PERM_EXPORT_DATA);
            $type = sanitize_input($_POST['type'] ?? '', 20);
            $data = match($type) {
                'subscribers' => data_read('subscribers', []),
                'stories'     => data_read('stories', []),
                'analytics'   => data_read('analytics', []),
                'config'      => config_load(),
                default       => null,
            };
            if ($data === null) json_response(['error' => 'Tipo inválido.'], 400);
            json_response(['ok' => true, 'data' => $data]);

        case 'maintenance':
            require_perm(PERM_MAINTENANCE);
            $cfg = config_load();
            $cfg['maintenance_mode'] = ($_POST['enabled'] ?? '0') === '1';
            config_save($cfg);
            json_response(['ok' => true, 'msg' => $cfg['maintenance_mode'] ? 'Modo mantenimiento activado.' : 'Modo mantenimiento desactivado.']);

        default:
            json_response(['error' => 'Acción desconocida.'], 400);
    }
}

// ── GET: Verificar sesión ─────────────────────────────────────
if (isset($_GET['check'])) {
    $user = current_user();
    json_response(['logged' => (bool)$user, 'user' => $user ? [
        'username'     => $user['username'],
        'display_name' => $user['display_name'],
        'role'         => $user['role'],
        'avatar'       => $user['avatar'] ?? '',
    ] : null]);
}

// ── Renderizar panel ──────────────────────────────────────────
$user = current_user();
$cfg  = config_load();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Panel — <?= h($cfg['site_title']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
    --bg: #060612;
    --surface: #0d0d22;
    --surface2: #13132e;
    --surface3: #1a1a3e;
    --border: rgba(124,58,237,0.15);
    --border-hover: rgba(124,58,237,0.4);
    --primary: #7c3aed;
    --primary-light: #9d6fff;
    --accent: #f59e0b;
    --success: #10b981;
    --danger: #ef4444;
    --warning: #f59e0b;
    --info: #06b6d4;
    --text: #e2e8ff;
    --text-muted: #6b7280;
    --text-muted2: #94a3b8;
    --sidebar-w: 250px;
    --header-h: 60px;
    --radius: 12px;
    --radius-sm: 8px;
    --font: 'DM Sans', sans-serif;
    --font-mono: 'JetBrains Mono', monospace;
    --shadow: 0 4px 24px rgba(0,0,0,0.4);
    --glow: 0 0 20px rgba(124,58,237,0.3);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
    font-family: var(--font);
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    font-size: 14px;
    line-height: 1.5;
}
/* Scrollbar */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: var(--surface); }
::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 3px; }

/* ── Login Screen ─── */
#login-screen {
    position: fixed; inset: 0;
    background: radial-gradient(ellipse at 30% 20%, #1a0a3a 0%, var(--bg) 60%);
    display: flex; align-items: center; justify-content: center;
    z-index: 9999;
    transition: opacity 0.4s;
}
#login-screen.hidden { opacity: 0; pointer-events: none; }
.login-box {
    background: var(--surface);
    border: 1px solid var(--border-hover);
    border-radius: var(--radius);
    padding: 2.5rem;
    width: 100%;
    max-width: 400px;
    box-shadow: var(--glow), var(--shadow);
}
.login-logo {
    text-align: center;
    margin-bottom: 2rem;
}
.login-logo .skull { font-size: 3rem; color: var(--primary); display: block; margin-bottom: 0.5rem; }
.login-logo h1 { font-size: 1.4rem; font-weight: 700; color: var(--text); }
.login-logo p { font-size: 0.8rem; color: var(--text-muted); margin-top: 0.25rem; }

/* ── Form elements ─── */
.form-group { margin-bottom: 1rem; }
.form-group label { display: block; margin-bottom: 0.4rem; font-size: 0.8rem; font-weight: 600; color: var(--text-muted2); text-transform: uppercase; letter-spacing: 0.05em; }
.form-control {
    width: 100%;
    background: var(--surface2);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 0.6rem 0.9rem;
    border-radius: var(--radius-sm);
    font-family: var(--font);
    font-size: 0.9rem;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(124,58,237,0.15); }
.form-control::placeholder { color: var(--text-muted); }
select.form-control { cursor: pointer; }
textarea.form-control { resize: vertical; min-height: 80px; }

/* ── Buttons ─── */
.btn {
    display: inline-flex; align-items: center; gap: 0.5rem;
    padding: 0.55rem 1.1rem;
    border: none; border-radius: var(--radius-sm);
    font-family: var(--font); font-size: 0.875rem; font-weight: 600;
    cursor: pointer; text-decoration: none;
    transition: all 0.2s; white-space: nowrap;
}
.btn-primary   { background: var(--primary); color: #fff; }
.btn-primary:hover { background: var(--primary-light); transform: translateY(-1px); }
.btn-danger    { background: var(--danger); color: #fff; }
.btn-danger:hover  { opacity: 0.85; }
.btn-success   { background: var(--success); color: #fff; }
.btn-ghost     { background: transparent; color: var(--text-muted2); border: 1px solid var(--border); }
.btn-ghost:hover { border-color: var(--primary); color: var(--primary-light); }
.btn-sm { padding: 0.35rem 0.7rem; font-size: 0.8rem; }
.btn-full { width: 100%; justify-content: center; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }

/* ── Layout ─── */
#app { display: flex; min-height: 100vh; }

/* Sidebar */
.sidebar {
    width: var(--sidebar-w);
    background: var(--surface);
    border-right: 1px solid var(--border);
    display: flex; flex-direction: column;
    position: fixed; top: 0; left: 0; bottom: 0;
    z-index: 100;
    transition: transform 0.3s;
    overflow-y: auto;
}
.sidebar-header {
    padding: 1.25rem 1rem;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 0.75rem;
}
.sidebar-logo { font-size: 1.5rem; color: var(--primary); }
.sidebar-title { font-size: 0.95rem; font-weight: 700; line-height: 1.2; }
.sidebar-subtitle { font-size: 0.7rem; color: var(--text-muted); }

.sidebar-nav { flex: 1; padding: 0.75rem 0; }
.nav-section { margin-bottom: 0.25rem; }
.nav-section-label {
    font-size: 0.65rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.08em; color: var(--text-muted);
    padding: 0.5rem 1rem 0.25rem;
}
.nav-item {
    display: flex; align-items: center; gap: 0.7rem;
    padding: 0.55rem 1rem;
    color: var(--text-muted2);
    cursor: pointer;
    border-radius: 0;
    transition: all 0.15s;
    font-size: 0.875rem;
    font-weight: 500;
    border-left: 3px solid transparent;
    text-decoration: none;
    user-select: none;
}
.nav-item:hover { background: var(--surface2); color: var(--text); }
.nav-item.active { background: rgba(124,58,237,0.12); color: var(--primary-light); border-left-color: var(--primary); }
.nav-item i { width: 18px; text-align: center; font-size: 0.9rem; flex-shrink: 0; }

.sidebar-footer {
    padding: 0.75rem 1rem;
    border-top: 1px solid var(--border);
}
.user-info {
    display: flex; align-items: center; gap: 0.75rem;
    padding: 0.5rem;
    border-radius: var(--radius-sm);
    background: var(--surface2);
    cursor: pointer;
    transition: background 0.2s;
    margin-bottom: 0.5rem;
}
.user-info:hover { background: var(--surface3); }
.user-avatar {
    width: 34px; height: 34px; border-radius: 50%;
    background: var(--primary); display: flex; align-items: center; justify-content: center;
    font-size: 0.9rem; font-weight: 700; color: #fff; flex-shrink: 0;
    overflow: hidden;
}
.user-avatar img { width: 100%; height: 100%; object-fit: cover; }
.user-meta { min-width: 0; flex: 1; }
.user-name { font-size: 0.85rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-role { font-size: 0.7rem; color: var(--text-muted); }

/* Main content */
.main-content {
    margin-left: var(--sidebar-w);
    flex: 1;
    display: flex; flex-direction: column;
    min-height: 100vh;
}
.topbar {
    height: var(--header-h);
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 1.5rem;
    position: sticky; top: 0; z-index: 50;
}
.topbar-title { font-size: 1rem; font-weight: 700; }
.topbar-actions { display: flex; gap: 0.5rem; align-items: center; }

.content-area {
    flex: 1;
    padding: 1.5rem;
    max-width: 1200px;
}

/* ── Panels ─── */
.panel {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    margin-bottom: 1.5rem;
}
.panel-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
    gap: 1rem;
}
.panel-title { font-size: 0.9rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; }
.panel-body { padding: 1.25rem; }

/* ── Grid ─── */
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; }
.grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; }

/* ── Stats cards ─── */
.stat-card {
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1.25rem;
    display: flex; align-items: center; gap: 1rem;
}
.stat-icon {
    width: 48px; height: 48px;
    border-radius: var(--radius-sm);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}
.stat-info .num { font-size: 1.6rem; font-weight: 700; line-height: 1; }
.stat-info .label { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem; }

/* ── Tables ─── */
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 0.65rem 0.9rem; text-align: left; border-bottom: 1px solid var(--border); font-size: 0.85rem; }
th { color: var(--text-muted2); font-weight: 600; text-transform: uppercase; font-size: 0.7rem; letter-spacing: 0.05em; background: var(--surface2); }
tr:hover td { background: rgba(124,58,237,0.04); }

/* ── Badges ─── */
.badge {
    display: inline-flex; align-items: center; gap: 0.3rem;
    padding: 0.2rem 0.6rem;
    border-radius: 999px;
    font-size: 0.7rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.05em;
}
.badge-primary { background: rgba(124,58,237,0.2); color: var(--primary-light); }
.badge-success { background: rgba(16,185,129,0.2); color: #34d399; }
.badge-danger  { background: rgba(239,68,68,0.2); color: #fca5a5; }
.badge-warning { background: rgba(245,158,11,0.2); color: #fcd34d; }
.badge-info    { background: rgba(6,182,212,0.2); color: #67e8f9; }
.badge-ghost   { background: rgba(107,114,128,0.2); color: var(--text-muted2); }

/* ── Toggle ─── */
.toggle-wrap { display: flex; align-items: center; gap: 0.75rem; }
.toggle {
    position: relative; width: 40px; height: 22px;
    flex-shrink: 0;
}
.toggle input { opacity: 0; width: 0; height: 0; position: absolute; }
.toggle-slider {
    position: absolute; inset: 0;
    background: var(--surface3);
    border-radius: 999px;
    cursor: pointer;
    transition: background 0.2s;
}
.toggle-slider::before {
    content: ''; position: absolute;
    width: 16px; height: 16px;
    left: 3px; bottom: 3px;
    background: #fff; border-radius: 50%;
    transition: transform 0.2s;
}
.toggle input:checked + .toggle-slider { background: var(--primary); }
.toggle input:checked + .toggle-slider::before { transform: translateX(18px); }
.toggle-label { font-size: 0.875rem; font-weight: 500; }

/* ── Color input ─── */
.color-input-wrap { display: flex; align-items: center; gap: 0.5rem; }
.color-input-wrap input[type=color] { width: 36px; height: 36px; padding: 2px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface2); cursor: pointer; }
.color-input-wrap .form-control { flex: 1; font-family: var(--font-mono); }

/* ── Alert/Toast ─── */
#toast-container {
    position: fixed; bottom: 1.5rem; right: 1.5rem;
    z-index: 9999;
    display: flex; flex-direction: column; gap: 0.5rem;
}
.toast {
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 0.75rem 1rem;
    min-width: 260px; max-width: 380px;
    display: flex; align-items: center; gap: 0.75rem;
    box-shadow: var(--shadow);
    animation: slideIn 0.3s ease;
}
.toast.success { border-color: var(--success); }
.toast.error   { border-color: var(--danger); }
.toast.info    { border-color: var(--info); }
.toast-icon { font-size: 1rem; flex-shrink: 0; }
.toast.success .toast-icon { color: var(--success); }
.toast.error .toast-icon   { color: var(--danger); }
.toast.info .toast-icon    { color: var(--info); }
.toast-msg { font-size: 0.85rem; }
@keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

/* ── Section pages ─── */
.section-page { display: none; }
.section-page.active { display: block; }

/* ── Feed preview ─── */
.episode-preview {
    display: flex; gap: 1rem; align-items: flex-start;
    padding: 0.75rem; border-radius: var(--radius-sm);
    border: 1px solid var(--border);
    background: var(--surface2);
    margin-bottom: 0.5rem;
    transition: border-color 0.2s;
}
.episode-preview:hover { border-color: var(--border-hover); }
.ep-thumb { width: 60px; height: 60px; border-radius: 8px; object-fit: cover; flex-shrink: 0; background: var(--surface3); }
.ep-info { flex: 1; min-width: 0; }
.ep-title { font-weight: 600; font-size: 0.875rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ep-meta { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem; }
.ep-desc { font-size: 0.8rem; color: var(--text-muted2); margin-top: 0.25rem; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

/* ── Sponsor card ─── */
.sponsor-card {
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 1rem;
    display: flex; align-items: center; gap: 1rem;
    margin-bottom: 0.5rem;
    position: relative;
}
.sponsor-card img { width: 50px; height: 50px; border-radius: 50%; object-fit: cover; background: var(--surface3); }
.sponsor-card .sp-info { flex: 1; min-width: 0; }
.sponsor-card .sp-name { font-weight: 700; font-size: 0.9rem; }
.sponsor-card .sp-desc { font-size: 0.8rem; color: var(--text-muted); }

/* ── Responsive ─── */
@media (max-width: 900px) {
    :root { --sidebar-w: 0px; }
    .sidebar { transform: translateX(-250px); width: 250px; }
    .sidebar.open { transform: translateX(0); }
    .main-content { margin-left: 0; }
    .grid-4 { grid-template-columns: 1fr 1fr; }
    .grid-3 { grid-template-columns: 1fr 1fr; }
    .grid-2 { grid-template-columns: 1fr; }
}
@media (max-width: 600px) {
    .grid-4, .grid-3 { grid-template-columns: 1fr; }
    .content-area { padding: 1rem; }
}

/* ── Misc ─── */
.divider { border: none; border-top: 1px solid var(--border); margin: 1rem 0; }
.text-muted { color: var(--text-muted); }
.text-small { font-size: 0.8rem; }
.d-flex { display: flex; }
.align-center { align-items: center; }
.gap-1 { gap: 0.5rem; }
.gap-2 { gap: 1rem; }
.mb-1 { margin-bottom: 0.5rem; }
.mb-2 { margin-bottom: 1rem; }
.mt-1 { margin-top: 0.5rem; }
.chip {
    display: inline-flex; align-items: center; gap: 0.25rem;
    padding: 0.2rem 0.5rem;
    background: var(--surface3); border-radius: 999px;
    font-size: 0.75rem; color: var(--text-muted2);
}
.loading-spinner {
    width: 20px; height: 20px;
    border: 2px solid var(--border);
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    display: inline-block;
}
@keyframes spin { to { transform: rotate(360deg); } }
.empty-state {
    text-align: center; padding: 3rem 1rem; color: var(--text-muted);
}
.empty-state i { font-size: 2.5rem; margin-bottom: 1rem; display: block; }
.mono { font-family: var(--font-mono); font-size: 0.8rem; }
</style>
</head>
<body>
<div id="toast-container"></div>

<!-- Login -->
<div id="login-screen" class="<?= $user ? 'hidden' : '' ?>">
    <div class="login-box">
        <div class="login-logo">
            <i class="fa-solid fa-skull login-logo skull"></i>
            <h1><?= h($cfg['site_title']) ?></h1>
            <p>Panel de Control</p>
        </div>
        <div id="login-error" style="display:none;background:rgba(239,68,68,0.1);border:1px solid #ef4444;border-radius:8px;padding:0.75rem;margin-bottom:1rem;font-size:0.85rem;color:#fca5a5;"></div>
        <form id="login-form" onsubmit="doLogin(event)">
            <div class="form-group">
                <label>Usuario</label>
                <input type="text" class="form-control" id="login-user" autocomplete="username" required placeholder="admin">
            </div>
            <div class="form-group">
                <label>Contraseña</label>
                <input type="password" class="form-control" id="login-pass" autocomplete="current-password" required placeholder="••••••••">
            </div>
            <button type="submit" class="btn btn-primary btn-full" id="login-btn">
                <i class="fa-solid fa-right-to-bracket"></i> Ingresar
            </button>
        </form>
        <p class="text-muted text-small" style="text-align:center;margin-top:1rem">
            <?= h(APP_NAME) ?> v<?= h(APP_VERSION) ?>
        </p>
    </div>
</div>

<!-- App -->
<div id="app" <?= !$user ? 'style="display:none"' : '' ?>>
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <i class="fa-solid fa-skull sidebar-logo"></i>
            <div>
                <div class="sidebar-title"><?= h($cfg['site_title']) ?></div>
                <div class="sidebar-subtitle">Panel de Control</div>
            </div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-section-label">General</div>
                <div class="nav-item active" onclick="goTo('dashboard')"><i class="fa-solid fa-gauge"></i> Dashboard</div>
                <div class="nav-item" onclick="goTo('feed')"><i class="fa-solid fa-rss"></i> Feed & Episodios</div>
                <div class="nav-item" onclick="goTo('analytics')"><i class="fa-solid fa-chart-line"></i> Analíticas</div>
            </div>
            <div class="nav-section">
                <div class="nav-section-label">Contenido</div>
                <div class="nav-item" onclick="goTo('stories')"><i class="fa-solid fa-ghost"></i> Historias</div>
                <div class="nav-item" onclick="goTo('sponsors')"><i class="fa-solid fa-handshake"></i> Patrocinadores</div>
                <div class="nav-item" onclick="goTo('newsletter')"><i class="fa-solid fa-envelope"></i> Newsletter</div>
            </div>
            <div class="nav-section">
                <div class="nav-section-label">Configuración</div>
                <div class="nav-item" onclick="goTo('cfg-general')"><i class="fa-solid fa-gear"></i> General</div>
                <div class="nav-item" onclick="goTo('cfg-design')"><i class="fa-solid fa-palette"></i> Diseño</div>
                <div class="nav-item" onclick="goTo('cfg-player')"><i class="fa-solid fa-play"></i> Reproductor</div>
                <div class="nav-item" onclick="goTo('cfg-social')"><i class="fa-solid fa-share-nodes"></i> Redes Sociales</div>
                <div class="nav-item" onclick="goTo('cfg-seo')"><i class="fa-solid fa-magnifying-glass"></i> SEO</div>
                <div class="nav-item" onclick="goTo('cfg-support')"><i class="fa-solid fa-heart"></i> Apoyo</div>
                <div class="nav-item" onclick="goTo('cfg-advanced')"><i class="fa-solid fa-code"></i> Avanzado</div>
            </div>
            <div class="nav-section">
                <div class="nav-section-label">Sistema</div>
                <div class="nav-item" onclick="goTo('users')"><i class="fa-solid fa-users"></i> Usuarios</div>
                <div class="nav-item" onclick="goTo('maintenance')"><i class="fa-solid fa-screwdriver-wrench"></i> Mantenimiento</div>
                <div class="nav-item" onclick="window.open('<?= h($cfg['site_web_url'] ?: 'index.php') ?>','_blank')"><i class="fa-solid fa-arrow-up-right-from-square"></i> Ver sitio</div>
            </div>
        </nav>
        <div class="sidebar-footer">
            <div class="user-info" onclick="goTo('cfg-general')">
                <div class="user-avatar" id="sb-avatar">?</div>
                <div class="user-meta">
                    <div class="user-name" id="sb-name">Cargando...</div>
                    <div class="user-role" id="sb-role"></div>
                </div>
            </div>
            <button class="btn btn-ghost btn-sm btn-full" onclick="doLogout()"><i class="fa-solid fa-right-from-bracket"></i> Cerrar sesión</button>
        </div>
    </aside>

    <!-- Main -->
    <div class="main-content">
        <header class="topbar">
            <div style="display:flex;align-items:center;gap:1rem">
                <button class="btn btn-ghost btn-sm" id="menu-toggle" onclick="toggleSidebar()" style="display:none"><i class="fa-solid fa-bars"></i></button>
                <span class="topbar-title" id="topbar-title">Dashboard</span>
            </div>
            <div class="topbar-actions">
                <span id="maintenance-badge" style="display:none" class="badge badge-danger"><i class="fa-solid fa-triangle-exclamation"></i> Mantenimiento</span>
                <button class="btn btn-ghost btn-sm" onclick="apiPost('clear_cache')" title="Limpiar caché"><i class="fa-solid fa-broom"></i></button>
                <button class="btn btn-primary btn-sm" onclick="refreshFeed()"><i class="fa-solid fa-arrows-rotate"></i> Actualizar feed</button>
            </div>
        </header>

        <div class="content-area">

            <!-- ── DASHBOARD ── -->
            <div class="section-page active" id="page-dashboard">
                <div class="grid-4 mb-2" id="stat-cards">
                    <div class="stat-card"><div class="stat-icon" style="background:rgba(124,58,237,0.15);color:var(--primary)"><i class="fa-solid fa-podcast"></i></div><div class="stat-info"><div class="num" id="stat-eps">—</div><div class="label">Episodios</div></div></div>
                    <div class="stat-card"><div class="stat-icon" style="background:rgba(16,185,129,0.15);color:var(--success)"><i class="fa-solid fa-play"></i></div><div class="stat-info"><div class="num" id="stat-plays">—</div><div class="label">Reproducciones</div></div></div>
                    <div class="stat-card"><div class="stat-icon" style="background:rgba(6,182,212,0.15);color:var(--info)"><i class="fa-solid fa-ghost"></i></div><div class="stat-info"><div class="num" id="stat-stories">—</div><div class="label">Historias</div></div></div>
                    <div class="stat-card"><div class="stat-icon" style="background:rgba(245,158,11,0.15);color:var(--warning)"><i class="fa-solid fa-envelope"></i></div><div class="stat-info"><div class="num" id="stat-subs">—</div><div class="label">Suscriptores</div></div></div>
                </div>
                <div class="grid-2">
                    <div class="panel">
                        <div class="panel-header"><span class="panel-title"><i class="fa-solid fa-podcast"></i> Últimos Episodios</span></div>
                        <div class="panel-body" id="dash-episodes"><div class="loading-spinner"></div></div>
                    </div>
                    <div class="panel">
                        <div class="panel-header"><span class="panel-title"><i class="fa-solid fa-ghost"></i> Historias Recientes</span></div>
                        <div class="panel-body" id="dash-stories"><div class="loading-spinner"></div></div>
                    </div>
                </div>
                <div class="panel">
                    <div class="panel-header"><span class="panel-title"><i class="fa-solid fa-info-circle"></i> Estado del Sistema</span></div>
                    <div class="panel-body">
                        <div class="grid-3" id="sys-info">
                            <div><span class="text-muted text-small">Versión</span><br><strong><?= h(APP_VERSION) ?></strong></div>
                            <div><span class="text-muted text-small">PHP</span><br><strong><?= h(PHP_VERSION) ?></strong></div>
                            <div><span class="text-muted text-small">Proveedor Feed</span><br><strong id="sys-provider">—</strong></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── FEED ── -->
            <div class="section-page" id="page-feed">
                <div class="panel mb-2">
                    <div class="panel-header"><span class="panel-title"><i class="fa-solid fa-rss"></i> Configuración del Feed</span>
                    <button class="btn btn-primary btn-sm" onclick="saveFeedConfig()"><i class="fa-solid fa-floppy-disk"></i> Guardar</button></div>
                    <div class="panel-body">
                        <div class="form-group">
                            <label>URL del Feed RSS</label>
                            <input type="url" class="form-control" id="cfg-feed_url" value="<?= h($cfg['feed_url']) ?>" placeholder="https://...">
                            <div class="text-small text-muted mt-1">Compatible con Spreaker, Anchor, Buzzsprout, Podbean, Libsyn, Simplecast, y cualquier RSS estándar.</div>
                        </div>
                        <div class="grid-2">
                            <div class="form-group">
                                <label>URL de Respaldo (opcional)</label>
                                <input type="url" class="form-control" id="cfg-feed_backup_url" value="<?= h($cfg['feed_backup_url']) ?>" placeholder="https://...">
                            </div>
                            <div class="form-group">
                                <label>Proveedor detectado</label>
                                <input type="text" class="form-control" id="feed-provider-detected" readonly value="<?= h(detect_feed_provider($cfg['feed_url'])) ?>">
                            </div>
                        </div>
                        <div class="grid-3">
                            <div class="form-group">
                                <label>Caché (segundos)</label>
                                <input type="number" class="form-control" id="cfg-feed_cache_ttl" value="<?= h($cfg['feed_cache_ttl']) ?>" min="60" max="86400">
                            </div>
                            <div class="form-group">
                                <label>Timeout (segundos)</label>
                                <input type="number" class="form-control" id="cfg-feed_timeout" value="<?= h($cfg['feed_timeout']) ?>" min="5" max="60">
                            </div>
                            <div class="form-group">
                                <label>Episodios por página (0 = todos)</label>
                                <input type="number" class="form-control" id="cfg-episodes_per_page" value="<?= h($cfg['episodes_per_page']) ?>" min="0">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="panel">
                    <div class="panel-header">
                        <span class="panel-title"><i class="fa-solid fa-list"></i> Episodios del Feed <span class="badge badge-ghost" id="ep-count-badge">0</span></span>
                        <button class="btn btn-ghost btn-sm" onclick="loadEpisodes()"><i class="fa-solid fa-arrows-rotate"></i> Recargar</button>
                    </div>
                    <div class="panel-body" id="episodes-list"><div class="loading-spinner"></div></div>
                </div>
            </div>

            <!-- ── ANALYTICS ── -->
            <div class="section-page" id="page-analytics">
                <div class="grid-4 mb-2">
                    <div class="stat-card"><div class="stat-icon" style="background:rgba(124,58,237,0.15);color:var(--primary)"><i class="fa-solid fa-eye"></i></div><div class="stat-info"><div class="num" id="an-views">—</div><div class="label">Vistas totales</div></div></div>
                    <div class="stat-card"><div class="stat-icon" style="background:rgba(16,185,129,0.15);color:var(--success)"><i class="fa-solid fa-play"></i></div><div class="stat-info"><div class="num" id="an-plays">—</div><div class="label">Reproducciones</div></div></div>
                    <div class="stat-card"><div class="stat-icon" style="background:rgba(6,182,212,0.15);color:var(--info)"><i class="fa-solid fa-ghost"></i></div><div class="stat-info"><div class="num" id="an-stories">—</div><div class="label">Historias</div></div></div>
                    <div class="stat-card"><div class="stat-icon" style="background:rgba(245,158,11,0.15);color:var(--warning)"><i class="fa-solid fa-envelope"></i></div><div class="stat-info"><div class="num" id="an-subs">—</div><div class="label">Suscriptores</div></div></div>
                </div>
                <div class="panel mb-2">
                    <div class="panel-header"><span class="panel-title"><i class="fa-solid fa-chart-line"></i> Actividad (30 días)</span></div>
                    <div class="panel-body">
                        <canvas id="analytics-chart" height="80"></canvas>
                    </div>
                </div>
                <div class="panel">
                    <div class="panel-header"><span class="panel-title"><i class="fa-solid fa-trophy"></i> Top Episodios</span>
                    <button class="btn btn-ghost btn-sm" onclick="exportData('analytics')"><i class="fa-solid fa-download"></i> Exportar</button></div>
                    <div class="panel-body table-wrap" id="top-episodes-table"></div>
                </div>
            </div>

            <!-- ── STORIES ── -->
            <div class="section-page" id="page-stories">
                <div class="panel">
                    <div class="panel-header">
                        <span class="panel-title"><i class="fa-solid fa-ghost"></i> Historias de Oyentes</span>
                        <div style="display:flex;gap:0.5rem">
                            <select class="form-control" id="stories-filter" onchange="loadStories()" style="width:auto">
                                <option value="all">Todas</option>
                                <option value="pending">Pendientes</option>
                                <option value="approved">Aprobadas</option>
                                <option value="rejected">Rechazadas</option>
                            </select>
                            <button class="btn btn-ghost btn-sm" onclick="exportData('stories')"><i class="fa-solid fa-download"></i> Exportar</button>
                        </div>
                    </div>
                    <div class="panel-body" id="stories-list"><div class="loading-spinner"></div></div>
                </div>
            </div>

            <!-- ── SPONSORS ── -->
            <div class="section-page" id="page-sponsors">
                <div class="panel">
                    <div class="panel-header">
                        <span class="panel-title"><i class="fa-solid fa-handshake"></i> Patrocinadores</span>
                        <div style="display:flex;gap:0.5rem">
                            <button class="btn btn-ghost btn-sm" onclick="addSponsor()"><i class="fa-solid fa-plus"></i> Agregar</button>
                            <button class="btn btn-primary btn-sm" onclick="saveSponsors()"><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
                        </div>
                    </div>
                    <div class="panel-body" id="sponsors-list"></div>
                </div>
            </div>

            <!-- ── NEWSLETTER ── -->
            <div class="section-page" id="page-newsletter">
                <div class="panel mb-2">
                    <div class="panel-header"><span class="panel-title"><i class="fa-solid fa-envelope"></i> Suscriptores</span>
                    <button class="btn btn-ghost btn-sm" onclick="exportData('subscribers')"><i class="fa-solid fa-download"></i> Exportar CSV</button></div>
                    <div class="panel-body" id="subscribers-list"><div class="loading-spinner"></div></div>
                </div>
            </div>

            <!-- ── CFG GENERAL ── -->
            <div class="section-page" id="page-cfg-general">
                <div class="panel">
                    <div class="panel-header"><span class="panel-title"><i class="fa-solid fa-gear"></i> Configuración General</span>
                    <button class="btn btn-primary btn-sm" onclick="saveSection('general')"><i class="fa-solid fa-floppy-disk"></i> Guardar</button></div>
                    <div class="panel-body">
                        <div class="grid-2">
                            <div class="form-group"><label>Título del sitio</label><input type="text" class="form-control cfg-field" data-key="site_title" value="<?= h($cfg['site_title']) ?>"></div>
                            <div class="form-group"><label>Subtítulo</label><input type="text" class="form-control cfg-field" data-key="site_subtitle" value="<?= h($cfg['site_subtitle']) ?>"></div>
                            <div class="form-group"><label>Tagline</label><input type="text" class="form-control cfg-field" data-key="site_tagline" value="<?= h($cfg['site_tagline']) ?>"></div>
                            <div class="form-group"><label>URL del sitio</label><input type="url" class="form-control cfg-field" data-key="site_web_url" value="<?= h($cfg['site_web_url']) ?>"></div>
                            <div class="form-group"><label>Copyright</label><input type="text" class="form-control cfg-field" data-key="copyright_text" value="<?= h($cfg['copyright_text']) ?>"></div>
                            <div class="form-group"><label>Idioma</label>
                                <select class="form-control cfg-field" data-key="language">
                                    <option value="es" <?= $cfg['language']==='es'?'selected':'' ?>>Español</option>
                                    <option value="en" <?= $cfg['language']==='en'?'selected':'' ?>>English</option>
                                    <option value="pt" <?= $cfg['language']==='pt'?'selected':'' ?>>Português</option>
                                </select>
                            </div>
                        </div>
                        <hr class="divider">
                        <h4 style="font-size:0.85rem;margin-bottom:1rem;color:var(--text-muted2)">PERFIL DEL HOST</h4>
                        <div class="grid-2">
                            <div class="form-group"><label>Nombre del host</label><input type="text" class="form-control cfg-field" data-key="host_name" value="<?= h($cfg['host_name']) ?>"></div>
                            <div class="form-group"><label>Título/Cargo</label><input type="text" class="form-control cfg-field" data-key="host_title" value="<?= h($cfg['host_title']) ?>"></div>
                            <div class="form-group"><label>Ubicación</label><input type="text" class="form-control cfg-field" data-key="host_location" value="<?= h($cfg['host_location']) ?>"></div>
                            <div class="form-group"><label>Website del host</label><input type="url" class="form-control cfg-field" data-key="host_website" value="<?= h($cfg['host_website']) ?>"></div>
                            <div class="form-group"><label>Imagen del host (URL)</label><input type="url" class="form-control cfg-field" data-key="host_image" value="<?= h($cfg['host_image']) ?>"></div>
                        </div>
                        <div class="form-group"><label>Bio del host</label><textarea class="form-control cfg-field" data-key="host_bio" rows="3"><?= h($cfg['host_bio']) ?></textarea></div>
                        <hr class="divider">
                        <h4 style="font-size:0.85rem;margin-bottom:1rem;color:var(--text-muted2)">SECCIONES</h4>
                        <div class="grid-3">
                            <?php $sections = ['show_sponsors'=>'Patrocinadores','show_about'=>'Acerca de','show_profile'=>'Perfil','show_newsletter'=>'Newsletter','show_support_section'=>'Apoyo','show_stories_section'=>'Historias']; ?>
                            <?php foreach ($sections as $k => $label): ?>
                            <div class="toggle-wrap">
                                <label class="toggle"><input type="checkbox" class="cfg-field" data-key="<?= h($k) ?>" <?= $cfg[$k] ? 'checked' : '' ?>><span class="toggle-slider"></span></label>
                                <span class="toggle-label"><?= h($label) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── CFG DESIGN ── -->
            <div class="section-page" id="page-cfg-design">
                <div class="panel">
                    <div class="panel-header"><span class="panel-title"><i class="fa-solid fa-palette"></i> Diseño</span>
                    <button class="btn btn-primary btn-sm" onclick="saveSection('design')"><i class="fa-solid fa-floppy-disk"></i> Guardar</button></div>
                    <div class="panel-body">
                        <div class="grid-3">
                            <?php $colors = ['primary_color'=>'Color primario','secondary_color'=>'Color secundario','accent_color'=>'Color acento','success_color'=>'Éxito','danger_color'=>'Peligro','body_bg'=>'Fondo','surface_color'=>'Superficie','text_color'=>'Texto']; ?>
                            <?php foreach ($colors as $k => $label): ?>
                            <div class="form-group">
                                <label><?= h($label) ?></label>
                                <div class="color-input-wrap">
                                    <input type="color" value="<?= h($cfg[$k]) ?>" oninput="document.getElementById('col-<?= h($k) ?>').value=this.value">
                                    <input type="text" id="col-<?= h($k) ?>" class="form-control cfg-field" data-key="<?= h($k) ?>" value="<?= h($cfg[$k]) ?>">
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <hr class="divider">
                        <div class="grid-3">
                            <div class="form-group"><label>Fuente Títulos</label>
                                <select class="form-control cfg-field" data-key="font_heading">
                                    <?php foreach (['Creepster','Nosifer','Eater','Griffy','Pirata One','Clash Display','Bebas Neue','Oswald','Raleway'] as $f): ?>
                                    <option <?= $cfg['font_heading']===$f?'selected':'' ?>><?= h($f) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group"><label>Fuente Cuerpo</label>
                                <select class="form-control cfg-field" data-key="font_body">
                                    <?php foreach (['DM Sans','Inter','Lato','Nunito','Open Sans','Roboto','Source Sans 3'] as $f): ?>
                                    <option <?= $cfg['font_body']===$f?'selected':'' ?>><?= h($f) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group"><label>Tamaño fuente base (px)</label>
                                <input type="number" class="form-control cfg-field" data-key="font_size_base" value="<?= h($cfg['font_size_base']) ?>" min="12" max="20">
                            </div>
                        </div>
                        <div class="grid-3">
                            <div class="toggle-wrap"><label class="toggle"><input type="checkbox" class="cfg-field" data-key="card_hover_glow" <?= $cfg['card_hover_glow']?'checked':'' ?>><span class="toggle-slider"></span></label><span class="toggle-label">Brillo al hover</span></div>
                            <div class="toggle-wrap"><label class="toggle"><input type="checkbox" class="cfg-field" data-key="background_noise" <?= $cfg['background_noise']?'checked':'' ?>><span class="toggle-slider"></span></label><span class="toggle-label">Textura de fondo</span></div>
                            <div class="toggle-wrap"><label class="toggle"><input type="checkbox" class="cfg-field" data-key="animated_gradient" <?= $cfg['animated_gradient']?'checked':'' ?>><span class="toggle-slider"></span></label><span class="toggle-label">Gradiente animado</span></div>
                        </div>
                        <hr class="divider">
                        <div class="form-group"><label>Layout episodios</label>
                            <select class="form-control cfg-field" data-key="episode_layout">
                                <option value="grid" <?= $cfg['episode_layout']==='grid'?'selected':'' ?>>Grid (cuadrícula)</option>
                                <option value="list" <?= $cfg['episode_layout']==='list'?'selected':'' ?>>Lista</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── CFG PLAYER ── -->
            <div class="section-page" id="page-cfg-player">
                <div class="panel">
                    <div class="panel-header"><span class="panel-title"><i class="fa-solid fa-play"></i> Reproductor</span>
                    <button class="btn btn-primary btn-sm" onclick="saveSection('player')"><i class="fa-solid fa-floppy-disk"></i> Guardar</button></div>
                    <div class="panel-body">
                        <div class="grid-3">
                            <div class="form-group"><label>Volumen por defecto (%)</label><input type="range" min="0" max="100" class="form-control cfg-field" data-key="default_volume" value="<?= h($cfg['default_volume']) ?>"></div>
                            <div class="form-group"><label>Saltar atrás (seg)</label><input type="number" class="form-control cfg-field" data-key="skip_back_seconds" value="<?= h($cfg['skip_back_seconds']) ?>" min="5" max="60"></div>
                            <div class="form-group"><label>Saltar adelante (seg)</label><input type="number" class="form-control cfg-field" data-key="skip_forward_seconds" value="<?= h($cfg['skip_forward_seconds']) ?>" min="5" max="60"></div>
                        </div>
                        <div class="grid-3 mt-1">
                            <?php $player_opts = ['player_autoplay'=>'Autoplay','show_speed_control'=>'Control velocidad','show_skip_buttons'=>'Botones saltar','player_show_cover'=>'Mostrar portada','player_show_next'=>'Siguiente episodio','player_show_shuffle'=>'Aleatorio','player_show_repeat'=>'Repetir','player_show_queue'=>'Cola','player_show_download'=>'Descargar','player_keyboard_shortcuts'=>'Atajos de teclado','player_continuous_play'=>'Reproducción continua','player_sleep_timer'=>'Temporizador sueño','remember_position'=>'Recordar posición']; ?>
                            <?php foreach ($player_opts as $k => $label): ?>
                            <div class="toggle-wrap"><label class="toggle"><input type="checkbox" class="cfg-field" data-key="<?= h($k) ?>" <?= $cfg[$k]?'checked':'' ?>><span class="toggle-slider"></span></label><span class="toggle-label"><?= h($label) ?></span></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── CFG SOCIAL ── -->
            <div class="section-page" id="page-cfg-social">
                <div class="panel">
                    <div class="panel-header"><span class="panel-title"><i class="fa-solid fa-share-nodes"></i> Redes Sociales & Plataformas</span>
                    <button class="btn btn-primary btn-sm" onclick="saveSection('social')"><i class="fa-solid fa-floppy-disk"></i> Guardar</button></div>
                    <div class="panel-body">
                        <h4 style="font-size:0.8rem;color:var(--text-muted2);margin-bottom:1rem;text-transform:uppercase;">Plataformas de Podcast</h4>
                        <div class="grid-2">
                            <?php $podcast_platforms = ['social_spotify'=>['Spotify','fa-brands fa-spotify'],'social_apple_podcasts'=>['Apple Podcasts','fa-brands fa-apple'],'social_deezer'=>['Deezer','fa-solid fa-music'],'social_spreaker'=>['Spreaker','fa-solid fa-microphone'],'social_google_podcasts'=>['Google Podcasts','fa-brands fa-google'],'social_iheart'=>['iHeart Radio','fa-solid fa-heart'],'social_podcast_addict'=>['Podcast Addict','fa-solid fa-rss'],'social_podchaser'=>['Podchaser','fa-solid fa-headphones'],'social_amazon_music'=>['Amazon Music','fa-brands fa-amazon'],'social_pocket_casts'=>['Pocket Casts','fa-solid fa-podcast'],'social_rss'=>['Feed RSS directo','fa-solid fa-rss']]; ?>
                            <?php foreach ($podcast_platforms as $k => [$label, $icon]): ?>
                            <div class="form-group"><label><i class="<?= h($icon) ?>"></i> <?= h($label) ?></label><input type="url" class="form-control cfg-field" data-key="<?= h($k) ?>" value="<?= h($cfg[$k] ?? '') ?>" placeholder="https://..."></div>
                            <?php endforeach; ?>
                        </div>
                        <hr class="divider">
                        <h4 style="font-size:0.8rem;color:var(--text-muted2);margin-bottom:1rem;text-transform:uppercase;">Redes Sociales</h4>
                        <div class="grid-2">
                            <?php $social = ['social_instagram'=>['Instagram','fa-brands fa-instagram'],'social_youtube'=>['YouTube','fa-brands fa-youtube'],'social_twitter'=>['Twitter/X','fa-brands fa-x-twitter'],'social_tiktok'=>['TikTok','fa-brands fa-tiktok'],'social_facebook'=>['Facebook','fa-brands fa-facebook'],'social_patreon'=>['Patreon','fa-brands fa-patreon'],'social_discord'=>['Discord','fa-brands fa-discord'],'social_twitch'=>['Twitch','fa-brands fa-twitch']]; ?>
                            <?php foreach ($social as $k => [$label, $icon]): ?>
                            <div class="form-group"><label><i class="<?= h($icon) ?>"></i> <?= h($label) ?></label><input type="url" class="form-control cfg-field" data-key="<?= h($k) ?>" value="<?= h($cfg[$k] ?? '') ?>" placeholder="https://..."></div>
                            <?php endforeach; ?>
                        </div>
                        <hr class="divider">
                        <h4 style="font-size:0.8rem;color:var(--text-muted2);margin-bottom:1rem;text-transform:uppercase;">Supporters Club (Spreaker)</h4>
                        <div class="grid-2">
                            <div class="toggle-wrap mb-1"><label class="toggle"><input type="checkbox" class="cfg-field" data-key="supporters_club_enabled" <?= $cfg['supporters_club_enabled']?'checked':'' ?>><span class="toggle-slider"></span></label><span class="toggle-label">Activar Supporters Club</span></div>
                        </div>
                        <div class="grid-2">
                            <div class="form-group"><label>URL del Supporters Club</label><input type="url" class="form-control cfg-field" data-key="supporters_club_url" value="<?= h($cfg['supporters_club_url']) ?>" placeholder="https://spreaker.com/..."></div>
                            <div class="form-group"><label>Título</label><input type="text" class="form-control cfg-field" data-key="supporters_club_title" value="<?= h($cfg['supporters_club_title']) ?>"></div>
                        </div>
                        <div class="form-group"><label>Descripción</label><textarea class="form-control cfg-field" data-key="supporters_club_desc" rows="2"><?= h($cfg['supporters_club_desc']) ?></textarea></div>
                    </div>
                </div>
            </div>

            <!-- ── CFG SEO ── -->
            <div class="section-page" id="page-cfg-seo">
                <div class="panel">
                    <div class="panel-header"><span class="panel-title"><i class="fa-solid fa-magnifying-glass"></i> SEO</span>
                    <button class="btn btn-primary btn-sm" onclick="saveSection('seo')"><i class="fa-solid fa-floppy-disk"></i> Guardar</button></div>
                    <div class="panel-body">
                        <div class="form-group"><label>Meta título (vacío = título del sitio)</label><input type="text" class="form-control cfg-field" data-key="meta_title" value="<?= h($cfg['meta_title']) ?>"></div>
                        <div class="form-group"><label>Meta descripción</label><textarea class="form-control cfg-field" data-key="meta_description" rows="2"><?= h($cfg['meta_description']) ?></textarea></div>
                        <div class="form-group"><label>Keywords (separadas por coma)</label><input type="text" class="form-control cfg-field" data-key="meta_keywords" value="<?= h($cfg['meta_keywords']) ?>"></div>
                        <div class="grid-2">
                            <div class="form-group"><label>OG Image URL</label><input type="url" class="form-control cfg-field" data-key="og_image" value="<?= h($cfg['og_image']) ?>"></div>
                            <div class="form-group"><label>Twitter Handle (@)</label><input type="text" class="form-control cfg-field" data-key="twitter_handle" value="<?= h($cfg['twitter_handle']) ?>"></div>
                            <div class="form-group"><label>Google Analytics ID</label><input type="text" class="form-control cfg-field" data-key="google_analytics_id" value="<?= h($cfg['google_analytics_id']) ?>" placeholder="G-XXXXXXXXXX"></div>
                            <div class="form-group"><label>URL Canónica</label><input type="url" class="form-control cfg-field" data-key="canonical_url" value="<?= h($cfg['canonical_url']) ?>"></div>
                        </div>
                        <div class="grid-3">
                            <div class="toggle-wrap"><label class="toggle"><input type="checkbox" class="cfg-field" data-key="structured_data" <?= $cfg['structured_data']?'checked':'' ?>><span class="toggle-slider"></span></label><span class="toggle-label">Datos estructurados</span></div>
                            <div class="toggle-wrap"><label class="toggle"><input type="checkbox" class="cfg-field" data-key="robots_noindex" <?= $cfg['robots_noindex']?'checked':'' ?>><span class="toggle-slider"></span></label><span class="toggle-label">No indexar (robots)</span></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── CFG SUPPORT ── -->
            <div class="section-page" id="page-cfg-support">
                <div class="panel">
                    <div class="panel-header"><span class="panel-title"><i class="fa-solid fa-heart"></i> Apoyo & Funding</span>
                    <button class="btn btn-primary btn-sm" onclick="saveSection('support')"><i class="fa-solid fa-floppy-disk"></i> Guardar</button></div>
                    <div class="panel-body">
                        <div class="toggle-wrap mb-2"><label class="toggle"><input type="checkbox" class="cfg-field" data-key="funding_enabled" <?= $cfg['funding_enabled']?'checked':'' ?>><span class="toggle-slider"></span></label><span class="toggle-label">Activar botón de apoyo</span></div>
                        <div class="grid-2">
                            <div class="form-group"><label>URL de apoyo principal</label><input type="url" class="form-control cfg-field" data-key="funding_url" value="<?= h($cfg['funding_url']) ?>"></div>
                            <div class="form-group"><label>Etiqueta del botón</label><input type="text" class="form-control cfg-field" data-key="funding_label" value="<?= h($cfg['funding_label']) ?>"></div>
                        </div>
                        <hr class="divider">
                        <h4 style="font-size:0.8rem;color:var(--text-muted2);margin-bottom:1rem;text-transform:uppercase;">Plataformas de Apoyo</h4>
                        <div class="grid-2">
                            <?php $support_platforms = ['support_patreon_url'=>'Patreon','support_paypal_url'=>'PayPal','support_ko_fi_url'=>'Ko-fi','support_buy_me_coffee_url'=>'Buy Me a Coffee','support_mercado_pago_url'=>'Mercado Pago']; ?>
                            <?php foreach ($support_platforms as $k => $label): ?>
                            <div class="form-group"><label><?= h($label) ?></label><input type="url" class="form-control cfg-field" data-key="<?= h($k) ?>" value="<?= h($cfg[$k] ?? '') ?>" placeholder="https://..."></div>
                            <?php endforeach; ?>
                        </div>
                        <hr class="divider">
                        <h4 style="font-size:0.8rem;color:var(--text-muted2);margin-bottom:1rem;text-transform:uppercase;">Sección de Historias</h4>
                        <div class="toggle-wrap mb-2"><label class="toggle"><input type="checkbox" class="cfg-field" data-key="stories_enabled" <?= $cfg['stories_enabled']?'checked':'' ?>><span class="toggle-slider"></span></label><span class="toggle-label">Activar envío de historias</span></div>
                        <div class="toggle-wrap mb-2"><label class="toggle"><input type="checkbox" class="cfg-field" data-key="stories_require_approval" <?= $cfg['stories_require_approval']?'checked':'' ?>><span class="toggle-slider"></span></label><span class="toggle-label">Requerir aprobación antes de publicar</span></div>
                        <div class="grid-2">
                            <div class="form-group"><label>Título de la sección</label><input type="text" class="form-control cfg-field" data-key="stories_title" value="<?= h($cfg['stories_title']) ?>"></div>
                            <div class="form-group"><label>Longitud máxima (caracteres)</label><input type="number" class="form-control cfg-field" data-key="stories_max_length" value="<?= h($cfg['stories_max_length']) ?>" min="100" max="20000"></div>
                        </div>
                        <div class="form-group"><label>Descripción</label><textarea class="form-control cfg-field" data-key="stories_desc" rows="2"><?= h($cfg['stories_desc']) ?></textarea></div>
                        <div class="form-group"><label>Mensaje de éxito</label><input type="text" class="form-control cfg-field" data-key="stories_success_msg" value="<?= h($cfg['stories_success_msg']) ?>"></div>
                    </div>
                </div>
            </div>

            <!-- ── CFG ADVANCED ── -->
            <div class="section-page" id="page-cfg-advanced">
                <div class="panel">
                    <div class="panel-header"><span class="panel-title"><i class="fa-solid fa-code"></i> Avanzado</span>
                    <button class="btn btn-primary btn-sm" onclick="saveSection('advanced')"><i class="fa-solid fa-floppy-disk"></i> Guardar</button></div>
                    <div class="panel-body">
                        <div class="form-group"><label>CSS personalizado</label><textarea class="form-control cfg-field mono" data-key="custom_css" rows="6" placeholder="/* Tu CSS aquí */"><?= h($cfg['custom_css']) ?></textarea></div>
                        <div class="form-group"><label>JS personalizado</label><textarea class="form-control cfg-field mono" data-key="custom_js" rows="6" placeholder="// Tu JS aquí"><?= h($cfg['custom_js']) ?></textarea></div>
                        <div class="form-group"><label>HTML en &lt;head&gt;</label><textarea class="form-control cfg-field mono" data-key="custom_head" rows="4" placeholder="<!-- Meta tags, scripts, etc -->"><?= h($cfg['custom_head'] ?? '') ?></textarea></div>
                        <hr class="divider">
                        <div class="toggle-wrap"><label class="toggle"><input type="checkbox" class="cfg-field" data-key="cookie_notice" <?= $cfg['cookie_notice']?'checked':'' ?>><span class="toggle-slider"></span></label><span class="toggle-label">Aviso de cookies</span></div>
                        <div class="form-group mt-1"><label>Texto del aviso de cookies</label><input type="text" class="form-control cfg-field" data-key="cookie_notice_text" value="<?= h($cfg['cookie_notice_text']) ?>"></div>
                    </div>
                </div>
            </div>

            <!-- ── USERS ── -->
            <div class="section-page" id="page-users">
                <div class="panel">
                    <div class="panel-header"><span class="panel-title"><i class="fa-solid fa-users"></i> Usuarios del Panel</span>
                    <button class="btn btn-primary btn-sm" onclick="openUserModal()"><i class="fa-solid fa-plus"></i> Nuevo usuario</button></div>
                    <div class="panel-body table-wrap" id="users-table"></div>
                </div>
            </div>

            <!-- ── MAINTENANCE ── -->
            <div class="section-page" id="page-maintenance">
                <div class="panel">
                    <div class="panel-header"><span class="panel-title"><i class="fa-solid fa-screwdriver-wrench"></i> Mantenimiento</span></div>
                    <div class="panel-body">
                        <div class="grid-2">
                            <div>
                                <h4 style="margin-bottom:1rem">Modo Mantenimiento</h4>
                                <p class="text-muted text-small mb-2">Activa para mostrar una página de mantenimiento a los visitantes mientras realizas cambios.</p>
                                <div class="toggle-wrap mb-2">
                                    <label class="toggle"><input type="checkbox" id="maint-toggle" <?= $cfg['maintenance_mode']?'checked':'' ?> onchange="toggleMaintenance(this.checked)"><span class="toggle-slider"></span></label>
                                    <span class="toggle-label">Modo mantenimiento activo</span>
                                </div>
                            </div>
                            <div>
                                <h4 style="margin-bottom:1rem">Caché</h4>
                                <p class="text-muted text-small mb-2">Limpia el caché del feed y de datos. El feed se recargará automáticamente en la próxima visita.</p>
                                <button class="btn btn-ghost btn-sm mb-1" onclick="apiPost('clear_cache')"><i class="fa-solid fa-broom"></i> Limpiar caché</button><br>
                                <button class="btn btn-ghost btn-sm" onclick="refreshFeed()"><i class="fa-solid fa-arrows-rotate"></i> Forzar recarga del feed</button>
                            </div>
                        </div>
                        <hr class="divider">
                        <h4 style="margin-bottom:1rem">Exportar datos</h4>
                        <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
                            <button class="btn btn-ghost btn-sm" onclick="exportData('config')"><i class="fa-solid fa-download"></i> Config JSON</button>
                            <button class="btn btn-ghost btn-sm" onclick="exportData('subscribers')"><i class="fa-solid fa-download"></i> Suscriptores</button>
                            <button class="btn btn-ghost btn-sm" onclick="exportData('stories')"><i class="fa-solid fa-download"></i> Historias</button>
                            <button class="btn btn-ghost btn-sm" onclick="exportData('analytics')"><i class="fa-solid fa-download"></i> Analíticas</button>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /content-area -->
    </div><!-- /main-content -->
</div><!-- /app -->

<!-- User Modal -->
<div id="user-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:500;display:flex;align-items:center;justify-content:center;" onclick="if(event.target===this)closeUserModal()">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:1.5rem;width:90%;max-width:480px;max-height:90vh;overflow-y:auto;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem">
            <h3 style="font-size:1rem;font-weight:700" id="user-modal-title">Nuevo Usuario</h3>
            <button class="btn btn-ghost btn-sm" onclick="closeUserModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form onsubmit="saveUser(event)">
            <?= csrf_field() ?>
            <div class="form-group"><label>Username</label><input type="text" class="form-control" id="um-username" required pattern="[a-z0-9_]+" minlength="3" maxlength="30" placeholder="ej: editor1"></div>
            <div class="form-group"><label>Nombre a mostrar</label><input type="text" class="form-control" id="um-display_name" required></div>
            <div class="form-group"><label>Email</label><input type="email" class="form-control" id="um-email"></div>
            <div class="form-group"><label>Contraseña (dejar vacío para no cambiar)</label><input type="password" class="form-control" id="um-password" placeholder="Mínimo 8 caracteres" minlength="8"></div>
            <div class="form-group"><label>Rol</label>
                <select class="form-control" id="um-role">
                    <?php foreach ($ROLES as $rkey => $role): if ($rkey === 'superadmin' && !user_has_perm($user ?? [], PERM_ALL)) continue; ?>
                    <option value="<?= h($rkey) ?>"><?= h($role['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="toggle-wrap mb-2"><label class="toggle"><input type="checkbox" id="um-active" checked><span class="toggle-slider"></span></label><span class="toggle-label">Usuario activo</span></div>
            <div style="display:flex;gap:0.5rem;justify-content:flex-end">
                <button type="button" class="btn btn-ghost" onclick="closeUserModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
const CSRF = <?= j(csrf_token()) ?>;
let currentSection = 'dashboard';
let episodesData = [];
let sponsorsData = <?= j($cfg['sponsors']) ?>;
let analyticsChart = null;

// ── API ───────────────────────────────────────────────────────
async function apiPost(action, extra = {}) {
    try {
        const form = new FormData();
        form.append('action', action);
        form.append('csrf_token', CSRF);
        for (const [k,v] of Object.entries(extra)) form.append(k, v);
        const res = await fetch('admin.php', { method: 'POST', body: form });
        const data = await res.json();
        if (data.ok !== undefined) {
            toast(data.ok ? 'success' : 'error', data.msg || (data.ok ? 'OK' : 'Error'));
        }
        return data;
    } catch(e) {
        toast('error', 'Error de conexión');
        return { ok: false };
    }
}

async function apiGet(params = {}) {
    const qs = new URLSearchParams(params).toString();
    const res = await fetch('admin.php?' + qs);
    return res.json();
}

// ── Toast ─────────────────────────────────────────────────────
function toast(type, msg) {
    const icons = { success: 'fa-check-circle', error: 'fa-circle-xmark', info: 'fa-circle-info' };
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.innerHTML = `<i class="fa-solid ${icons[type]||icons.info} toast-icon"></i><span class="toast-msg">${escHtml(msg)}</span>`;
    document.getElementById('toast-container').appendChild(el);
    setTimeout(() => el.remove(), 4000);
}

function escHtml(s) {
    const d = document.createElement('div'); d.textContent = s; return d.innerHTML;
}

// ── Navigation ────────────────────────────────────────────────
function goTo(section) {
    document.querySelectorAll('.section-page').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    const page = document.getElementById('page-' + section);
    if (page) page.classList.add('active');
    const nav = document.querySelector(`.nav-item[onclick="goTo('${section}')"]`);
    if (nav) nav.classList.add('active');
    currentSection = section;
    document.getElementById('topbar-title').textContent = nav ? nav.textContent.trim() : section;
    // Lazy load
    if (section === 'analytics') loadAnalytics();
    if (section === 'feed') loadEpisodes();
    if (section === 'stories') loadStories();
    if (section === 'sponsors') renderSponsors();
    if (section === 'users') loadUsers();
    if (section === 'newsletter') loadSubscribers();
    if (window.innerWidth <= 900) closeSidebar();
}

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('open'); }
function closeSidebar()  { document.getElementById('sidebar').classList.remove('open'); }

// ── Auth ──────────────────────────────────────────────────────
async function doLogin(e) {
    e.preventDefault();
    const btn = document.getElementById('login-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="loading-spinner"></span> Ingresando...';
    const form = new FormData();
    form.append('action', 'login');
    form.append('username', document.getElementById('login-user').value);
    form.append('password', document.getElementById('login-pass').value);
    try {
        const res = await fetch('admin.php', { method: 'POST', body: form });
        const data = await res.json();
        if (data.ok) {
            document.getElementById('login-screen').classList.add('hidden');
            document.getElementById('app').style.display = '';
            loadUserInfo();
            loadDashboard();
        } else {
            const err = document.getElementById('login-error');
            err.style.display = '';
            err.textContent = data.msg;
        }
    } catch(e) {
        toast('error', 'Error de conexión');
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-right-to-bracket"></i> Ingresar';
}

async function doLogout() {
    await apiPost('logout');
    location.reload();
}

async function loadUserInfo() {
    const data = await apiGet({ check: 1 });
    if (!data.logged) { location.reload(); return; }
    const u = data.user;
    document.getElementById('sb-name').textContent = u.display_name;
    document.getElementById('sb-role').textContent = u.role;
    const av = document.getElementById('sb-avatar');
    if (u.avatar) av.innerHTML = `<img src="${escHtml(u.avatar)}" alt="">`;
    else av.textContent = (u.display_name[0] || '?').toUpperCase();
}

// ── Dashboard ─────────────────────────────────────────────────
async function loadDashboard() {
    const [epData, anData] = await Promise.all([
        fetchEpisodesData(),
        apiPost('get_analytics'),
    ]);
    // Stats
    document.getElementById('stat-eps').textContent = epData?.info?.total || 0;
    document.getElementById('stat-plays').textContent = anData.total_plays || 0;
    document.getElementById('stat-stories').textContent = anData.stories || 0;
    document.getElementById('stat-subs').textContent = anData.subscribers || 0;
    // Provider
    document.getElementById('sys-provider').textContent = epData?.info?.provider || 'RSS';
    // Recent episodes
    const epsEl = document.getElementById('dash-episodes');
    if (epData?.episodes?.length) {
        epsEl.innerHTML = epData.episodes.slice(0,5).map(ep => `
            <div class="episode-preview">
                <img class="ep-thumb" src="${escHtml(ep.image)}" onerror="this.style.display='none'" alt="Sin imagen">
                <div class="ep-info">
                    <div class="ep-title">${escHtml(ep.title)}</div>
                    <div class="ep-meta">${escHtml(ep.duration_fmt)} · ${ep.timestamp ? new Date(ep.timestamp*1000).toLocaleDateString('es') : ''}</div>
                </div>
            </div>`).join('');
    } else { epsEl.innerHTML = '<div class="empty-state"><i class="fa-solid fa-podcast"></i><p>Sin episodios cargados</p></div>'; }
    // Recent stories
    const stData = await apiPost('get_stories', { filter: 'pending' });
    const stEl = document.getElementById('dash-stories');
    if (stData.stories?.length) {
        stEl.innerHTML = stData.stories.slice(0,5).map(s => `
            <div class="episode-preview">
                <div class="ep-info">
                    <div class="ep-title">${escHtml(s.title)}</div>
                    <div class="ep-meta">${escHtml(s.name)} · ${escHtml(s.created_at)} <span class="badge badge-warning">Pendiente</span></div>
                    <div class="ep-desc">${escHtml(s.story.substring(0,120))}...</div>
                </div>
                <button class="btn btn-success btn-sm" onclick="updateStory('${escHtml(s.id)}','approved')"><i class="fa-solid fa-check"></i></button>
                <button class="btn btn-danger btn-sm" onclick="updateStory('${escHtml(s.id)}','rejected')"><i class="fa-solid fa-xmark"></i></button>
            </div>`).join('');
    } else { stEl.innerHTML = '<div class="empty-state"><i class="fa-solid fa-ghost"></i><p>Sin historias pendientes</p></div>'; }
}

// ── Feed / Episodes ───────────────────────────────────────────
async function fetchEpisodesData() {
    try {
        const res = await fetch('index.php?api=episodes');
        if (!res.ok) return null;
        return await res.json();
    } catch { return null; }
}

async function loadEpisodes() {
    document.getElementById('episodes-list').innerHTML = '<div class="loading-spinner"></div>';
    const data = await fetchEpisodesData();
    if (!data?.episodes?.length) {
        document.getElementById('episodes-list').innerHTML = '<div class="empty-state"><i class="fa-solid fa-podcast"></i><p>No se pudieron cargar los episodios. Verifica la URL del feed.</p></div>';
        document.getElementById('ep-count-badge').textContent = '0';
        return;
    }
    episodesData = data.episodes;
    document.getElementById('ep-count-badge').textContent = data.episodes.length;
    document.getElementById('feed-provider-detected').value = detectProvider(document.getElementById('cfg-feed_url').value);
    document.getElementById('episodes-list').innerHTML = data.episodes.map(ep => `
        <div class="episode-preview">
            <img class="ep-thumb" src="${escHtml(ep.image)}" onerror="this.style.display='none'" alt="">
            <div class="ep-info">
                <div class="ep-title">${escHtml(ep.title)}</div>
                <div class="ep-meta">
                    ${ep.duration_fmt ? `<i class="fa-solid fa-clock"></i> ${escHtml(ep.duration_fmt)}` : ''}
                    ${ep.timestamp ? ` · ${new Date(ep.timestamp*1000).toLocaleDateString('es')}` : ''}
                    ${ep.episode_num ? ` · Ep. ${ep.episode_num}` : ''}
                    ${ep.season ? ` · T${ep.season}` : ''}
                </div>
                <div class="ep-desc">${escHtml(ep.description.substring(0, 200))}</div>
            </div>
            <audio controls style="display:none" src="${escHtml(ep.audio_url)}"></audio>
        </div>`).join('');
}

function detectProvider(url) {
    if (url.includes('spreaker')) return 'spreaker';
    if (url.includes('anchor.fm')) return 'anchor';
    if (url.includes('buzzsprout')) return 'buzzsprout';
    if (url.includes('podbean')) return 'podbean';
    if (url.includes('libsyn')) return 'libsyn';
    if (url.includes('simplecast')) return 'simplecast';
    if (url.includes('transistor')) return 'transistor';
    return 'RSS genérico';
}

async function saveFeedConfig() {
    const data = {
        feed_url: document.getElementById('cfg-feed_url').value,
        feed_backup_url: document.getElementById('cfg-feed_backup_url').value,
        feed_cache_ttl: document.getElementById('cfg-feed_cache_ttl').value,
        feed_timeout: document.getElementById('cfg-feed_timeout').value,
        episodes_per_page: document.getElementById('cfg-episodes_per_page').value,
    };
    await apiPost('save_config', data);
    document.getElementById('feed-provider-detected').value = detectProvider(data.feed_url);
}

async function refreshFeed() {
    const btn = event.target.closest('button');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="loading-spinner"></span>'; }
    const data = await apiPost('refresh_feed');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-arrows-rotate"></i> Actualizar feed'; }
    if (data.ok && currentSection === 'feed') loadEpisodes();
}

// ── Analytics ─────────────────────────────────────────────────
async function loadAnalytics() {
    const data = await apiPost('get_analytics');
    if (!data.ok) return;
    document.getElementById('an-views').textContent = data.total_views;
    document.getElementById('an-plays').textContent = data.total_plays;
    document.getElementById('an-stories').textContent = data.stories;
    document.getElementById('an-subs').textContent = data.subscribers;
    // Chart
    const ctx = document.getElementById('analytics-chart').getContext('2d');
    if (analyticsChart) analyticsChart.destroy();
    analyticsChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [
                { label: 'Vistas', data: data.views, borderColor: '#7c3aed', backgroundColor: 'rgba(124,58,237,0.1)', fill: true, tension: 0.4 },
                { label: 'Plays', data: data.plays, borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.1)', fill: true, tension: 0.4 },
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { labels: { color: '#e2e8ff' } } },
            scales: {
                x: { ticks: { color: '#6b7280' }, grid: { color: 'rgba(255,255,255,0.05)' } },
                y: { ticks: { color: '#6b7280' }, grid: { color: 'rgba(255,255,255,0.05)' } },
            }
        }
    });
    // Top episodes
    const epData = await fetchEpisodesData();
    const epMap = {};
    if (epData?.episodes) epData.episodes.forEach(e => { epMap[e.guid] = e.title; });
    const tbl = document.getElementById('top-episodes-table');
    if (data.top_episodes?.length) {
        tbl.innerHTML = `<table><thead><tr><th>#</th><th>Episodio</th><th>Reproducciones</th></tr></thead><tbody>
            ${data.top_episodes.map((e,i) => `<tr><td>${i+1}</td><td>${escHtml(epMap[e.guid] || e.guid.substring(0,16)+'...')}</td><td><strong>${e.plays}</strong></td></tr>`).join('')}
        </tbody></table>`;
    } else { tbl.innerHTML = '<div class="empty-state"><i class="fa-solid fa-chart-bar"></i><p>Sin datos aún</p></div>'; }
}

// ── Stories ───────────────────────────────────────────────────
async function loadStories() {
    const filter = document.getElementById('stories-filter').value;
    document.getElementById('stories-list').innerHTML = '<div class="loading-spinner"></div>';
    const data = await apiPost('get_stories', { filter });
    const el = document.getElementById('stories-list');
    if (!data.stories?.length) { el.innerHTML = '<div class="empty-state"><i class="fa-solid fa-ghost"></i><p>Sin historias</p></div>'; return; }
    const statusColors = { pending: 'warning', approved: 'success', rejected: 'danger' };
    const statusLabels = { pending: 'Pendiente', approved: 'Aprobada', rejected: 'Rechazada' };
    el.innerHTML = data.stories.map(s => `
        <div class="episode-preview" style="margin-bottom:0.75rem;flex-direction:column;gap:0.5rem">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem">
                <div>
                    <div class="ep-title">${escHtml(s.title)}</div>
                    <div class="ep-meta">
                        <strong>${escHtml(s.name)}</strong>
                        ${s.email ? ` · ${escHtml(s.email)}` : ''}
                        ${s.location ? ` · <i class="fa-solid fa-location-dot"></i> ${escHtml(s.location)}` : ''}
                        · ${escHtml(s.created_at)}
                        <span class="badge badge-${statusColors[s.status]}">${statusLabels[s.status]}</span>
                    </div>
                </div>
                <div style="display:flex;gap:0.25rem;flex-shrink:0">
                    ${s.status !== 'approved' ? `<button class="btn btn-success btn-sm" onclick="updateStory('${escHtml(s.id)}','approved')"><i class="fa-solid fa-check"></i></button>` : ''}
                    ${s.status !== 'rejected' ? `<button class="btn btn-ghost btn-sm" onclick="updateStory('${escHtml(s.id)}','rejected')"><i class="fa-solid fa-xmark"></i></button>` : ''}
                    <button class="btn btn-danger btn-sm" onclick="deleteStory('${escHtml(s.id)}')"><i class="fa-solid fa-trash"></i></button>
                </div>
            </div>
            <div class="ep-desc" style="-webkit-line-clamp:4">${escHtml(s.story)}</div>
        </div>`).join('');
}

async function updateStory(id, status) {
    const data = await apiPost('update_story', { id, status });
    if (data.ok) loadStories();
}

async function deleteStory(id) {
    if (!confirm('¿Eliminar esta historia?')) return;
    const data = await apiPost('delete_story', { id });
    if (data.ok) loadStories();
}

// ── Sponsors ──────────────────────────────────────────────────
function renderSponsors() {
    const el = document.getElementById('sponsors-list');
    if (!sponsorsData.length) {
        el.innerHTML = '<div class="empty-state"><i class="fa-solid fa-handshake"></i><p>Sin patrocinadores. Agrega uno.</p></div>';
        return;
    }
    el.innerHTML = sponsorsData.map((s, i) => `
        <div class="sponsor-card">
            <img src="${escHtml(s.image)}" onerror="this.style.display='none'" alt="Sin imagen">
            <div class="sp-info">
                <div class="sp-name">${escHtml(s.name)}</div>
                <div class="sp-desc">${escHtml(s.description)}</div>
                <div class="ep-meta">${escHtml(s.url)} · ${escHtml(s.category)}</div>
            </div>
            <div style="display:flex;gap:0.25rem">
                <button class="btn btn-ghost btn-sm" onclick="editSponsor(${i})"><i class="fa-solid fa-pen"></i></button>
                <button class="btn btn-danger btn-sm" onclick="deleteSponsor(${i})"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>`).join('');
}

function addSponsor() {
    const name = prompt('Nombre del patrocinador:');
    if (!name) return;
    sponsorsData.push({ name, description: '', image: '', url: '', label: 'Ver más', category: '', active: true });
    renderSponsors();
}

function deleteSponsor(i) {
    if (!confirm('¿Eliminar?')) return;
    sponsorsData.splice(i, 1);
    renderSponsors();
}

function editSponsor(i) {
    const s = sponsorsData[i];
    const name = prompt('Nombre:', s.name); if (name === null) return;
    const desc = prompt('Descripción:', s.description); if (desc === null) return;
    const url = prompt('URL:', s.url); if (url === null) return;
    const image = prompt('URL imagen:', s.image); if (image === null) return;
    sponsorsData[i] = { ...s, name, description: desc, url, image };
    renderSponsors();
}

async function saveSponsors() {
    await apiPost('save_sponsors', { sponsors: JSON.stringify(sponsorsData) });
}

// ── Config save ───────────────────────────────────────────────
async function saveSection(section) {
    const fields = document.querySelectorAll(`#page-cfg-${section} .cfg-field`);
    const data = {};
    fields.forEach(f => {
        const key = f.dataset.key;
        if (!key) return;
        if (f.type === 'checkbox') data[key] = f.checked ? '1' : '0';
        else data[key] = f.value;
    });
    await apiPost('save_config', data);
}

// ── Users ─────────────────────────────────────────────────────
async function loadUsers() {
    const data = await apiGet({ check: 1 });
    // Fetch users via a simple trick — we need a users endpoint
    // We'll use the export endpoint
    const usData = await apiPost('export_data', { type: 'config' });
    // Actually let's get user list differently - we'll call a special view
    // For now, show current user
    const tbl = document.getElementById('users-table');
    tbl.innerHTML = `<div class="empty-state"><i class="fa-solid fa-users"></i><p>Gestión de usuarios disponible.<br>Usa el botón "Nuevo usuario" para agregar.</p></div>`;
}

function openUserModal(username = '') {
    document.getElementById('user-modal').style.display = 'flex';
    if (!username) {
        document.getElementById('um-username').value = '';
        document.getElementById('um-display_name').value = '';
        document.getElementById('um-email').value = '';
        document.getElementById('um-password').value = '';
        document.getElementById('um-role').value = 'editor';
        document.getElementById('um-active').checked = true;
        document.getElementById('um-username').disabled = false;
        document.getElementById('user-modal-title').textContent = 'Nuevo Usuario';
    }
}

function closeUserModal() {
    document.getElementById('user-modal').style.display = 'none';
}

async function saveUser(e) {
    e.preventDefault();
    const data = {
        username:     document.getElementById('um-username').value,
        display_name: document.getElementById('um-display_name').value,
        email:        document.getElementById('um-email').value,
        password:     document.getElementById('um-password').value,
        role:         document.getElementById('um-role').value,
        active:       document.getElementById('um-active').checked ? '1' : '0',
    };
    const res = await apiPost('save_user', data);
    if (res.ok) closeUserModal();
}

// ── Subscribers ───────────────────────────────────────────────
async function loadSubscribers() {
    const data = await apiPost('export_data', { type: 'subscribers' });
    const el = document.getElementById('subscribers-list');
    const subs = Object.values(data.data || {});
    if (!subs.length) { el.innerHTML = '<div class="empty-state"><i class="fa-solid fa-envelope"></i><p>Sin suscriptores aún</p></div>'; return; }
    el.innerHTML = `<table><thead><tr><th>Email</th><th>Fecha</th><th>Estado</th></tr></thead><tbody>
        ${subs.map(s => `<tr><td>${escHtml(s.email)}</td><td>${escHtml(s.date)}</td><td><span class="badge badge-success">Activo</span></td></tr>`).join('')}
    </tbody></table>`;
}

// ── Export ────────────────────────────────────────────────────
async function exportData(type) {
    const data = await apiPost('export_data', { type });
    if (!data.ok) return;
    const blob = new Blob([JSON.stringify(data.data, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = `terrorenrapel_${type}_${new Date().toISOString().split('T')[0]}.json`;
    a.click(); URL.revokeObjectURL(url);
}

// ── Maintenance ───────────────────────────────────────────────
async function toggleMaintenance(enabled) {
    const data = await apiPost('maintenance', { enabled: enabled ? '1' : '0' });
    if (data.ok) {
        const badge = document.getElementById('maintenance-badge');
        badge.style.display = enabled ? '' : 'none';
    }
}

// ── Init ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('app').style.display !== 'none') {
        loadUserInfo();
        loadDashboard();
    }
    <?php if ($cfg['maintenance_mode']): ?>
    document.getElementById('maintenance-badge').style.display = '';
    <?php endif; ?>
    // Responsive
    if (window.innerWidth <= 900) {
        document.getElementById('menu-toggle').style.display = '';
    }
});
</script>
</body>
</html>
