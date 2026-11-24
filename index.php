<?php
/**
 * Terror en Rapel — Página Principal
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

session_start_secure();

$cfg = config_load();

// ── Modo mantenimiento ────────────────────────────────────────
if ($cfg['maintenance_mode'] && !isset($_SESSION['admin_user'])) {
    http_response_code(503);
    header('Retry-After: 3600');
    ?><!DOCTYPE html><html lang="<?= h($cfg['language']) ?>"><head>
    <meta charset="UTF-8"><meta name="robots" content="noindex">
    <title><?= h($cfg['maintenance_title']) ?></title>
    <style>*{margin:0;padding:0;box-sizing:border-box}body{background:<?= h($cfg['maintenance_bg']) ?>;color:#fff;font-family:'DM Sans',sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center;padding:2rem}h1{font-size:2.5rem;margin-bottom:1rem}p{color:#94a3b8;font-size:1.1rem}.icon{font-size:4rem;margin-bottom:1.5rem;display:block}</style>
    </head><body>
    <div><span class="icon"><i class="fa-solid fa-tools"></i></span><h1><?= h($cfg['maintenance_title']) ?></h1><p><?= h($cfg['maintenance_message']) ?></p></div>
    </body></html><?php
    exit;
}

// ── API pública ───────────────────────────────────────────────
if (isset($_GET['api'])) {
    $api = sanitize_input($_GET['api'], 30);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');

    switch ($api) {
        case 'episodes':
            header('Cache-Control: public, max-age=60');
            echo json_encode(get_episodes(), JSON_UNESCAPED_UNICODE);
            break;

        case 'track_play':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                if (!rate_limit('play', 30, 60)) { http_response_code(429); echo '{"ok":false}'; break; }
                $guid = sanitize_input($_POST['guid'] ?? '', 64);
                if ($guid) track_play($guid);
            }
            echo '{"ok":true}';
            break;

        case 'track_view':
            track_pageview();
            echo '{"ok":true}';
            break;

        case 'subscribe':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{}'; break; }
            if (!rate_limit('subscribe', 3, 300)) { echo json_encode(['ok'=>false,'msg'=>'Demasiados intentos. Espera unos minutos.']); break; }
            $result = newsletter_subscribe($_POST['email'] ?? '');
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        case 'submit_story':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{}'; break; }
            if (!($cfg['stories_enabled'] ?? false)) { echo json_encode(['ok'=>false,'msg'=>'Función desactivada.']); break; }
            if (!rate_limit('story', 3, 3600)) { echo json_encode(['ok'=>false,'msg'=>'Demasiados intentos. Espera un momento.']); break; }
            echo json_encode(story_submit($_POST), JSON_UNESCAPED_UNICODE);
            break;

        default:
            http_response_code(404);
            echo '{"error":"Not found"}';
    }
    exit;
}

// ── Datos del podcast ─────────────────────────────────────────
$feed_data = get_episodes();
$podcast   = $feed_data['info']     ?? [];
$episodes  = $feed_data['episodes'] ?? [];

// ── SEO ───────────────────────────────────────────────────────
$meta_title = $cfg['meta_title'] ?: $cfg['site_title'];
$meta_desc  = $cfg['meta_description'] ?: ($podcast['description'] ?? $cfg['site_tagline']);
$meta_image = $cfg['og_image'] ?: ($podcast['image'] ?? '');
$canon_url  = $cfg['canonical_url'] ?: $cfg['site_web_url'];

// ── Plataformas sociales ──────────────────────────────────────
$platform_meta = [
    'spotify'         => ['Spotify',         'fa-brands fa-spotify',   '#1DB954'],
    'apple_podcasts'  => ['Apple Podcasts',   'fa-brands fa-apple',     '#FC3C44'],
    'deezer'          => ['Deezer',           'fa-solid fa-music',      '#EF5466'],
    'google_podcasts' => ['Google Podcasts',  'fa-brands fa-google',    '#4285F4'],
    'iheart'          => ['iHeart Radio',     'fa-solid fa-heart',      '#C6002B'],
    'podcast_addict'  => ['Podcast Addict',   'fa-solid fa-rss',        '#F74B00'],
    'podchaser'       => ['Podchaser',        'fa-solid fa-headphones', '#6761D7'],
    'amazon_music'    => ['Amazon Music',     'fa-brands fa-amazon',    '#00A8E0'],
    'pocket_casts'    => ['Pocket Casts',     'fa-solid fa-podcast',    '#F43E37'],
    'spreaker'        => ['Spreaker',         'fa-solid fa-microphone', '#8A2BE2'],
    'instagram'       => ['Instagram',        'fa-brands fa-instagram', '#E1306C'],
    'youtube'         => ['YouTube',          'fa-brands fa-youtube',   '#FF0000'],
    'twitter'         => ['Twitter/X',        'fa-brands fa-x-twitter', '#1DA1F2'],
    'tiktok'          => ['TikTok',           'fa-brands fa-tiktok',    '#69C9D0'],
    'facebook'        => ['Facebook',         'fa-brands fa-facebook',  '#1877F2'],
    'patreon'         => ['Patreon',          'fa-brands fa-patreon',   '#FF424D'],
    'discord'         => ['Discord',          'fa-brands fa-discord',   '#5865F2'],
    'rss'             => ['Feed RSS',         'fa-solid fa-rss',        '#FFA500'],
];

$active_socials = [];
foreach ($platform_meta as $key => [$label, $icon, $color]) {
    $url = $cfg['social_' . $key] ?? '';
    if ($url) $active_socials[$key] = ['label' => $label, 'icon' => $icon, 'color' => $color, 'url' => $url];
}

$support_platforms = [
    ['Patreon',         'fa-brands fa-patreon',  '#FF424D', 'support_patreon_url'],
    ['PayPal',          'fa-brands fa-paypal',   '#00457C', 'support_paypal_url'],
    ['Ko-fi',           'fa-solid fa-coffee',    '#29ABE0', 'support_ko_fi_url'],
    ['Buy Me a Coffee', 'fa-solid fa-mug-hot',   '#FFDD00', 'support_buy_me_coffee_url'],
    ['Mercado Pago',    'fa-solid fa-wallet',    '#009EE3', 'support_mercado_pago_url'],
];

$badge_new_days = (int)($cfg['badge_new_days'] ?? 7);
$now = time();
?>
<!DOCTYPE html>
<html lang="<?= h($cfg['language'] ?? 'es') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($cfg['robots_noindex'] ?? false): ?>
<meta name="robots" content="noindex,nofollow">
<?php endif; ?>
<title><?= h($meta_title) ?></title>
<meta name="description" content="<?= h(mb_substr(strip_tags($meta_desc), 0, 160)) ?>">
<meta name="keywords"    content="<?= h($cfg['meta_keywords'] ?? '') ?>">
<?php if ($canon_url): ?><link rel="canonical" href="<?= h($canon_url) ?>"><?php endif; ?>
<meta property="og:type"        content="website">
<meta property="og:title"       content="<?= h($meta_title) ?>">
<meta property="og:description" content="<?= h(mb_substr(strip_tags($meta_desc), 0, 200)) ?>">
<?php if ($meta_image): ?><meta property="og:image" content="<?= h($meta_image) ?>"><?php endif; ?>
<?php if ($canon_url): ?><meta property="og:url"   content="<?= h($canon_url) ?>"><?php endif; ?>
<meta name="twitter:card"        content="<?= h($cfg['twitter_card'] ?? 'summary_large_image') ?>">
<meta name="twitter:title"       content="<?= h($meta_title) ?>">
<meta name="twitter:description" content="<?= h(mb_substr(strip_tags($meta_desc), 0, 200)) ?>">
<?php if ($cfg['twitter_handle']): ?><meta name="twitter:site" content="@<?= h($cfg['twitter_handle']) ?>"><?php endif; ?>
<?php if ($meta_image): ?><meta name="twitter:image" content="<?= h($meta_image) ?>"><?php endif; ?>
<?php if ($cfg['favicon_url'] ?? ''): ?><link rel="icon" href="<?= h($cfg['favicon_url']) ?>"><?php endif; ?>
<?php if ($cfg['site_logo_url'] ?? ''): ?><link rel="apple-touch-icon" href="<?= h($cfg['site_logo_url']) ?>"><?php endif; ?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=<?= rawurlencode($cfg['font_heading'] ?? 'Creepster') ?>:wght@400;700&family=<?= rawurlencode($cfg['font_body'] ?? 'DM Sans') ?>:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<?php if (($cfg['structured_data'] ?? true) && $podcast): ?>
<script type="application/ld+json"><?= json_encode([
    '@context'    => 'https://schema.org',
    '@type'       => 'PodcastSeries',
    'name'        => $podcast['title'] ?? $cfg['site_title'],
    'description' => mb_substr(strip_tags($podcast['description'] ?? $cfg['site_tagline']), 0, 500),
    'image'       => $podcast['image'] ?? '',
    'url'         => $canon_url,
    'inLanguage'  => $cfg['language'] ?? 'es',
    'author'      => ['@type' => 'Person', 'name' => $cfg['host_name'] ?? ''],
    'numberOfEpisodes' => count($episodes),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php endif; ?>

<?= $cfg['custom_head'] ?? '' ?>

<style>
/* ── CSS Variables ── */
:root {
    --primary:    <?= h($cfg['primary_color']   ?? '#7c3aed') ?>;
    --secondary:  <?= h($cfg['secondary_color'] ?? '#06b6d4') ?>;
    --accent:     <?= h($cfg['accent_color']    ?? '#f59e0b') ?>;
    --success:    <?= h($cfg['success_color']   ?? '#22c55e') ?>;
    --danger:     <?= h($cfg['danger_color']    ?? '#ef4444') ?>;
    --body-bg:    <?= h($cfg['body_bg']         ?? '#050510') ?>;
    --surface:    <?= h($cfg['surface_color']   ?? '#0f0f22') ?>;
    --surface2:   <?= h($cfg['surface2_color']  ?? '#131330') ?>;
    --text:       <?= h($cfg['text_color']      ?? '#e2e8ff') ?>;
    --text-muted: <?= h($cfg['text_muted']      ?? '#6b7280') ?>;
    --text-muted2:<?= h($cfg['text_muted2']     ?? '#94a3b8') ?>;
    --border:     <?= h($cfg['border_color']    ?? 'rgba(255,255,255,0.07)') ?>;
    --sidebar-bg: <?= h($cfg['sidebar_bg']      ?? '#0a0a1a') ?>;
    --sidebar-w:  <?= h($cfg['sidebar_width']   ?? '270') ?>px;
    --content-max:<?= h($cfg['content_max_width']?? '1400') ?>px;
    --radius:     <?= h($cfg['border_radius_base']  ?? '14') ?>px;
    --radius-lg:  <?= h($cfg['border_radius_large'] ?? '20') ?>px;
    --font-heading:'<?= h($cfg['font_heading'] ?? 'Creepster') ?>', serif;
    --font-body:  '<?= h($cfg['font_body'] ?? 'DM Sans') ?>', sans-serif;
    --font-size:  <?= h($cfg['font_size_base'] ?? '15') ?>px;
    --shadow-lg:  0 20px 60px rgba(0,0,0,0.6);
    --glow:       0 0 40px rgba(124,58,237,0.3);
}

/* ── Reset & Base ── */
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
html { scroll-behavior:smooth; }
body {
    font-family: var(--font-body);
    background: var(--body-bg);
    color: var(--text);
    font-size: var(--font-size);
    line-height: 1.6;
    min-height: 100vh;
    overflow-x: hidden;
    <?php if ($cfg['background_noise'] ?? true): ?>
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='300' height='300' filter='url(%23n)' opacity='0.035'/%3E%3C/svg%3E");
    <?php endif; ?>
}
<?php if ($cfg['animated_gradient'] ?? true): ?>
body::before {
    content:''; position:fixed; inset:0; pointer-events:none; z-index:0;
    background:
        radial-gradient(ellipse at 10% 5%,  rgba(124,58,237,0.1) 0%, transparent 55%),
        radial-gradient(ellipse at 90% 95%, rgba(6,182,212,0.07)  0%, transparent 55%),
        radial-gradient(ellipse at 50% 50%, rgba(239,68,68,0.03)  0%, transparent 70%);
    animation: ambientAnim 15s ease-in-out infinite alternate;
}
@keyframes ambientAnim { from{opacity:.5} to{opacity:1} }
<?php endif; ?>

::-webkit-scrollbar       { width:6px; }
::-webkit-scrollbar-track { background:var(--surface); }
::-webkit-scrollbar-thumb { background:var(--primary); border-radius:3px; }

/* ── Layout ── */
.layout { display:flex; min-height:100vh; position:relative; z-index:1; }

/* ── Sidebar ── */
.sidebar {
    width: var(--sidebar-w);
    background: var(--sidebar-bg);
    border-right: 1px solid var(--border);
    position: sticky; top:0; height:100vh;
    overflow-y:auto; overflow-x:hidden;
    flex-shrink:0;
    display:flex; flex-direction:column;
}
.sidebar::-webkit-scrollbar { width:3px; }
.sidebar-inner { padding:1.5rem 1.1rem; flex:1; display:flex; flex-direction:column; gap:0; }

.cover-wrap { position:relative; margin-bottom:1.1rem; }
.podcast-cover {
    width:100%; aspect-ratio:1/1;
    border-radius:var(--radius);
    object-fit:cover;
    border:2px solid var(--border);
    display:block;
    transition:box-shadow 0.3s, transform 0.3s;
}
<?php if ($cfg['card_hover_glow'] ?? true): ?>
.podcast-cover:hover { box-shadow:var(--glow); transform:scale(1.02); }
<?php endif; ?>

.podcast-title {
    font-family:var(--font-heading);
    font-size:1.25rem; font-weight:700; line-height:1.2;
    margin-bottom:.35rem;
    background:linear-gradient(135deg,var(--text) 0%,var(--text-muted2) 100%);
    -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text;
}
.podcast-subtitle { font-size:.78rem; color:var(--text-muted); margin-bottom:.5rem; line-height:1.4; }
.podcast-author   { font-size:.78rem; color:var(--primary); font-weight:700; margin-bottom:1rem; }

.sidebar-stats { display:grid; grid-template-columns:1fr 1fr; gap:.5rem; margin-bottom:1rem; }
.stat-chip { background:rgba(255,255,255,0.04); border:1px solid var(--border); border-radius:var(--radius); padding:.5rem; text-align:center; }
.stat-chip .n { font-size:1.15rem; font-weight:700; color:var(--primary); display:block; }
.stat-chip .l { font-size:.65rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; }

/* Search */
.search-wrap { position:relative; margin-bottom:.9rem; }
.search-input {
    width:100%; background:rgba(255,255,255,0.05);
    border:1px solid var(--border); color:var(--text);
    padding:.55rem .75rem .55rem 2.2rem;
    border-radius:var(--radius);
    font-family:var(--font-body); font-size:.85rem;
    outline:none; transition:border-color .2s, background .2s;
}
.search-input:focus { border-color:var(--primary); background:rgba(124,58,237,0.07); }
.search-input::placeholder { color:var(--text-muted); }
.search-icon { position:absolute; left:.7rem; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:.8rem; pointer-events:none; }

/* Listen-on section */
.listen-label { font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--text-muted); margin-bottom:.5rem; display:block; }
.listen-grid  { display:flex; flex-direction:column; gap:.3rem; margin-bottom:.9rem; }
.listen-btn {
    display:flex; align-items:center; gap:.55rem;
    padding:.45rem .7rem; border-radius:var(--radius);
    border:1px solid var(--border); background:rgba(255,255,255,0.03);
    color:var(--text-muted2); text-decoration:none;
    font-size:.8rem; font-weight:600; line-height:1;
    transition:all .2s; white-space:nowrap; overflow:hidden;
}
.listen-btn:hover { background:rgba(255,255,255,0.07); border-color:rgba(255,255,255,0.15); transform:translateX(3px); }
.listen-btn i { font-size:.95rem; flex-shrink:0; width:18px; text-align:center; }

/* Social icons row */
.social-row { display:flex; flex-wrap:wrap; gap:.35rem; margin-bottom:.9rem; }
.social-icon {
    display:flex; align-items:center; justify-content:center;
    width:36px; height:36px; border-radius:var(--radius);
    border:1px solid var(--border); background:rgba(255,255,255,0.03);
    text-decoration:none; font-size:.95rem;
    transition:all .2s;
}
.social-icon:hover { transform:translateY(-2px) scale(1.1); filter:brightness(1.4); border-color:currentColor; }

/* Support pill */
.support-pill {
    display:flex; align-items:center; justify-content:center; gap:.5rem;
    padding:.6rem 1rem; border-radius:var(--radius);
    background:linear-gradient(135deg,rgba(124,58,237,.25),rgba(6,182,212,.15));
    border:1px solid rgba(124,58,237,.35);
    color:#fff; text-decoration:none; font-weight:700; font-size:.85rem;
    margin-top:auto; margin-bottom:.5rem;
    transition:opacity .2s, transform .2s;
}
.support-pill:hover { opacity:.85; transform:translateY(-2px); }

/* ── Main ── */
.main { flex:1; min-width:0; max-width:var(--content-max); }

/* ── Hero ── */
.hero {
    padding:3.5rem 2.5rem 2.5rem;
    background:linear-gradient(180deg,rgba(124,58,237,.1) 0%,transparent 100%);
    border-bottom:1px solid var(--border);
    position:relative; overflow:hidden;
}
.hero::before {
    content:''; position:absolute; top:-40%; left:-15%;
    width:55%; height:220%;
    background:radial-gradient(ellipse,rgba(124,58,237,.1) 0%,transparent 70%);
    pointer-events:none;
}
.hero::after {
    content:''; position:absolute; top:10%; right:-10%;
    width:40%; height:150%;
    background:radial-gradient(ellipse,rgba(6,182,212,.05) 0%,transparent 70%);
    pointer-events:none;
}
.hero-inner { position:relative; z-index:1; }
.hero-eyebrow { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.15em; color:var(--primary); margin-bottom:.6rem; display:flex; align-items:center; gap:.5rem; }
.hero-eyebrow::before { content:''; display:inline-block; width:24px; height:2px; background:var(--primary); border-radius:1px; }
.hero-title {
    font-family:var(--font-heading);
    font-size:clamp(2.2rem,5.5vw,3.8rem);
    font-weight:700; line-height:1.05;
    margin-bottom:1.1rem;
    background:linear-gradient(135deg,var(--text) 0%,rgba(124,58,237,.85) 100%);
    -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text;
}
.hero-desc { font-size:1.05rem; color:var(--text-muted2); max-width:580px; margin-bottom:2rem; line-height:1.75; }
.hero-actions { display:flex; gap:.75rem; flex-wrap:wrap; align-items:center; }

.btn-primary {
    display:inline-flex; align-items:center; gap:.5rem;
    background:var(--primary); color:#fff;
    padding:.75rem 1.6rem; border-radius:var(--radius);
    font-weight:700; font-size:.95rem; text-decoration:none;
    border:none; cursor:pointer; font-family:var(--font-body);
    transition:all .2s; white-space:nowrap;
}
.btn-primary:hover { transform:translateY(-2px); box-shadow:var(--glow); filter:brightness(1.1); }
.btn-ghost {
    display:inline-flex; align-items:center; gap:.5rem;
    background:transparent; color:var(--text);
    padding:.73rem 1.4rem; border-radius:var(--radius);
    font-weight:600; font-size:.9rem; text-decoration:none;
    border:1px solid var(--border); cursor:pointer; font-family:var(--font-body);
    transition:all .2s; white-space:nowrap;
}
.btn-ghost:hover { border-color:var(--primary); color:var(--primary); }

/* ── Filters bar ── */
.filters-bar {
    display:flex; align-items:center; gap:.65rem; flex-wrap:wrap;
    padding:1rem 2.5rem;
    border-bottom:1px solid var(--border);
    position:sticky; top:0; z-index:30;
    background:rgba(5,5,16,.85);
    backdrop-filter:blur(16px);
    -webkit-backdrop-filter:blur(16px);
}
.filter-pill {
    padding:.38rem .9rem; border-radius:999px;
    border:1px solid var(--border); background:transparent;
    color:var(--text-muted); font-family:var(--font-body);
    font-size:.8rem; font-weight:600; cursor:pointer; transition:all .2s;
}
.filter-pill:hover  { border-color:rgba(124,58,237,.5); color:var(--text); }
.filter-pill.active { background:var(--primary); border-color:var(--primary); color:#fff; }
.filter-right { margin-left:auto; display:flex; gap:.5rem; align-items:center; }
.sort-select {
    background:var(--surface); border:1px solid var(--border);
    color:var(--text); padding:.38rem .7rem;
    border-radius:var(--radius); font-family:var(--font-body);
    font-size:.8rem; cursor:pointer; outline:none;
    transition:border-color .2s;
}
.sort-select:focus { border-color:var(--primary); }

/* ── Episodes section ── */
.episodes-section { padding:2rem 2.5rem; }
.sec-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.5rem; flex-wrap:wrap; gap:.75rem; }
.sec-title { font-family:var(--font-heading); font-size:1.5rem; font-weight:700; }
.eps-count-badge {
    font-size:.78rem; color:var(--text-muted);
    background:rgba(255,255,255,0.05); border:1px solid var(--border);
    border-radius:999px; padding:.2rem .7rem;
}

/* Episodes grid */
.episodes-grid {
    display:grid;
    grid-template-columns:repeat(auto-fill, minmax(<?= h($cfg['grid_columns_min'] ?? '290') ?>px, 1fr));
    gap:1.25rem;
}
<?php if (($cfg['episode_layout'] ?? 'grid') === 'list'): ?>
.episodes-grid { grid-template-columns:1fr; }
<?php endif; ?>

/* Episode card */
.ep-card {
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:var(--radius);
    overflow:hidden; cursor:pointer;
    transition:all .25s cubic-bezier(.4,0,.2,1);
    position:relative; display:flex; flex-direction:column;
    animation:cardIn .4s ease both;
}
@keyframes cardIn { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
<?php if ($cfg['card_hover_glow'] ?? true): ?>
.ep-card:hover {
    border-color:rgba(124,58,237,.45);
    transform:translateY(-4px);
    box-shadow:0 16px 48px rgba(0,0,0,.5), 0 0 24px rgba(124,58,237,.2);
}
<?php else: ?>
.ep-card:hover { border-color:rgba(255,255,255,.15); transform:translateY(-2px); box-shadow:0 8px 32px rgba(0,0,0,.4); }
<?php endif; ?>
.ep-card.is-playing { border-color:var(--primary); box-shadow:var(--glow); }
.ep-card.hidden { display:none; }

/* Thumbnail */
.ep-thumb-wrap { position:relative; aspect-ratio:<?= h($cfg['card_thumbnail_ratio'] ?? '16/9') ?>; overflow:hidden; background:var(--surface2); }
.ep-thumb-wrap img { width:100%; height:100%; object-fit:cover; transition:transform .4s; display:block; }
.ep-card:hover .ep-thumb-wrap img { transform:scale(1.06); }
.thumb-overlay {
    position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
    background:rgba(0,0,0,.45); opacity:0; transition:opacity .25s;
}
.ep-card:hover .thumb-overlay { opacity:1; }
.thumb-play-icon {
    width:54px; height:54px; background:var(--primary); border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    font-size:1.3rem; color:#fff;
    transform:scale(.8); transition:transform .25s;
    box-shadow:0 4px 16px rgba(124,58,237,.5);
}
.ep-card:hover .thumb-play-icon { transform:scale(1); }

/* Badges over thumbnail */
.ep-badges { position:absolute; top:.6rem; left:.6rem; display:flex; gap:.3rem; z-index:2; }
.ep-badge {
    padding:.18rem .55rem; border-radius:999px;
    font-size:.65rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em;
}
.badge-new { background:var(--success); color:#fff; }
.badge-ep  { background:rgba(0,0,0,.65); color:var(--text-muted2); backdrop-filter:blur(4px); }
.badge-playing { background:var(--primary); color:#fff; }

/* Card body */
.ep-body { padding:1rem 1.1rem; flex:1; display:flex; flex-direction:column; }
.ep-title {
    font-size:.95rem; font-weight:700; line-height:1.35; margin-bottom:.5rem;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
}
.ep-desc {
    font-size:.8rem; color:var(--text-muted2); line-height:1.55; flex:1; margin-bottom:.75rem;
    display:-webkit-box; -webkit-line-clamp:<?= (int)($cfg['card_desc_lines'] ?? 2) ?>; -webkit-box-orient:vertical; overflow:hidden;
}
.ep-meta { display:flex; gap:.65rem; font-size:.73rem; color:var(--text-muted); flex-wrap:wrap; }
.ep-meta span { display:flex; align-items:center; gap:.28rem; }

/* Card actions bar */
.ep-actions {
    display:flex; align-items:center; gap:.4rem;
    padding:.65rem 1.1rem; border-top:1px solid var(--border);
    background:rgba(0,0,0,.25);
}
.act-btn {
    display:flex; align-items:center; justify-content:center;
    width:30px; height:30px; border-radius:50%;
    border:1px solid var(--border); background:transparent;
    color:var(--text-muted2); cursor:pointer; font-size:.78rem;
    transition:all .2s;
}
.act-btn:hover, .act-btn.active { background:var(--primary); border-color:var(--primary); color:#fff; }
.act-btn a { color:inherit; text-decoration:none; display:flex; align-items:center; justify-content:center; width:100%; height:100%; }
.play-btn {
    margin-left:auto; display:flex; align-items:center; gap:.4rem;
    background:var(--primary); color:#fff; border:none;
    border-radius:var(--radius); padding:.35rem .85rem;
    font-size:.8rem; font-weight:700; cursor:pointer; font-family:var(--font-body);
    transition:all .2s;
}
.play-btn:hover { filter:brightness(1.15); transform:translateX(2px); }

/* No results */
.no-results { grid-column:1/-1; text-align:center; padding:4rem 2rem; color:var(--text-muted); }
.no-results i { font-size:3rem; display:block; margin-bottom:1rem; opacity:.4; color:var(--primary); }

/* Pagination */
.pagination { display:flex; gap:.5rem; justify-content:center; padding:2rem 2.5rem; flex-wrap:wrap; }
.page-btn {
    padding:.45rem .9rem; border-radius:var(--radius);
    border:1px solid var(--border); background:var(--surface);
    color:var(--text-muted2); cursor:pointer; font-size:.85rem; font-weight:600;
    transition:all .2s; font-family:var(--font-body);
}
.page-btn:hover, .page-btn.active { background:var(--primary); border-color:var(--primary); color:#fff; }

/* ── Player ── */
#player {
    position:fixed; bottom:0; left:0; right:0;
    background:rgba(10,10,26,.95);
    border-top:1px solid var(--border);
    backdrop-filter:blur(24px); -webkit-backdrop-filter:blur(24px);
    z-index:200;
    transform:translateY(105%);
    transition:transform .35s cubic-bezier(.4,0,.2,1);
}
#player.open { transform:translateY(0); }
.player-row {
    display:flex; align-items:center; gap:1rem;
    padding:.7rem 1.75rem; max-width:100%;
}
.p-cover { width:50px; height:50px; border-radius:8px; object-fit:cover; flex-shrink:0; background:var(--surface2); }
.p-info  { min-width:0; flex:0 0 200px; }
.p-title  { font-weight:700; font-size:.85rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.p-author { font-size:.72rem; color:var(--text-muted); margin-top:2px; }
.p-controls { display:flex; align-items:center; gap:.45rem; }
.c-btn {
    width:34px; height:34px; border-radius:50%;
    background:transparent; border:1px solid var(--border);
    color:var(--text-muted2); cursor:pointer; font-size:.85rem;
    display:flex; align-items:center; justify-content:center;
    transition:all .2s; flex-shrink:0;
}
.c-btn:hover { background:rgba(255,255,255,.08); color:var(--text); }
.c-btn.on    { color:var(--primary); border-color:var(--primary); }
.c-btn-play  {
    width:42px; height:42px; border-radius:50%;
    background:var(--primary); border:none; color:#fff;
    cursor:pointer; font-size:1rem;
    display:flex; align-items:center; justify-content:center;
    transition:all .2s; flex-shrink:0;
}
.c-btn-play:hover { transform:scale(1.08); box-shadow:var(--glow); }
.p-progress { flex:1; min-width:0; }
.progress-track {
    position:relative; height:4px; background:rgba(255,255,255,.1);
    border-radius:2px; cursor:pointer; margin-bottom:.3rem;
    transition:height .15s;
}
.progress-track:hover { height:6px; }
.progress-fill { position:absolute; left:0; top:0; bottom:0; background:var(--primary); border-radius:2px; }
.progress-thumb {
    position:absolute; top:50%; right:-5px; transform:translateY(-50%);
    width:12px; height:12px; background:#fff; border-radius:50%;
    box-shadow:0 1px 4px rgba(0,0,0,.5); pointer-events:none;
}
.p-times { display:flex; justify-content:space-between; font-size:.68rem; color:var(--text-muted); }
.p-volume { display:flex; align-items:center; gap:.5rem; flex:0 0 110px; }
.p-volume i { font-size:.75rem; color:var(--text-muted); flex-shrink:0; }
.p-volume input { flex:1; accent-color:var(--primary); cursor:pointer; height:3px; }
.speed-btn {
    background:var(--surface2); border:1px solid var(--border);
    color:var(--text); padding:.22rem .55rem; border-radius:6px;
    font-size:.72rem; font-weight:700; cursor:pointer; font-family:var(--font-body);
    transition:all .15s;
}
.speed-btn:hover { border-color:var(--primary); }
.p-close { background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:.95rem; padding:.25rem; }

/* Sleep timer */
.sleep-menu {
    display:none; position:absolute; bottom:100%; right:0;
    background:var(--surface2); border:1px solid var(--border);
    border-radius:var(--radius); padding:.5rem; min-width:140px;
    z-index:10;
}
.sleep-menu.open { display:block; }
.sleep-opt {
    display:block; width:100%; padding:.35rem .65rem;
    background:none; border:none; color:var(--text-muted2);
    text-align:left; cursor:pointer; font-size:.82rem; font-family:var(--font-body);
    border-radius:6px; transition:background .15s;
}
.sleep-opt:hover { background:rgba(255,255,255,.07); color:var(--text); }

/* ── Section blocks ── */
.section-block {
    padding:3rem 2.5rem; border-top:1px solid var(--border);
}
.eyebrow { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.12em; color:var(--primary); margin-bottom:.5rem; }
.block-title { font-family:var(--font-heading); font-size:1.8rem; font-weight:700; margin-bottom:.9rem; line-height:1.15; }
.block-desc  { color:var(--text-muted2); max-width:580px; margin-bottom:2rem; line-height:1.75; }

/* Supporters Club */
.supporters-box {
    background:linear-gradient(135deg,rgba(124,58,237,.18) 0%,rgba(239,68,68,.1) 100%);
    border:1px solid rgba(124,58,237,.4); border-radius:var(--radius-lg);
    padding:2.5rem; text-align:center; position:relative; overflow:hidden;
}
.supporters-box::before {
    content:''; position:absolute; top:-40%; right:-20%;
    width:60%; height:180%;
    background:radial-gradient(ellipse, rgba(245,158,11,.12) 0%, transparent 70%);
    pointer-events:none;
}
.supporters-box h3 { font-family:var(--font-heading); font-size:1.6rem; margin-bottom:.65rem; }
.supporters-link {
    display:inline-flex; align-items:center; gap:.5rem; margin-top:1.25rem;
    background:var(--primary); color:#fff; padding:.8rem 2.2rem;
    border-radius:var(--radius); font-weight:700; text-decoration:none;
    transition:all .2s;
}
.supporters-link:hover { transform:translateY(-2px); box-shadow:var(--glow); }

/* Support grid */
.support-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(170px,1fr)); gap:.75rem; }
.sup-btn {
    display:flex; align-items:center; gap:.75rem;
    padding:.9rem 1rem; border-radius:var(--radius);
    border:1px solid var(--border); background:var(--surface);
    color:var(--text); text-decoration:none; font-weight:700; font-size:.875rem;
    transition:all .2s;
}
.sup-btn:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(0,0,0,.3); }
.sup-btn i { font-size:1.15rem; flex-shrink:0; }

/* Sponsors */
.sponsors-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:1.2rem; }
.sp-card {
    background:var(--surface); border:1px solid var(--border);
    border-radius:var(--radius); padding:1.5rem 1.25rem;
    text-align:center; text-decoration:none; color:var(--text);
    transition:all .25s; display:block;
}
.sp-card:hover { border-color:rgba(124,58,237,.45); transform:translateY(-4px); box-shadow:var(--glow); }
.sp-img {
    width:72px; height:72px; border-radius:50%; object-fit:cover;
    margin:0 auto .9rem; display:block;
    background:var(--surface2); border:2px solid var(--border);
}
.sp-name { font-weight:700; margin-bottom:.3rem; }
.sp-desc { font-size:.8rem; color:var(--text-muted2); line-height:1.5; }
.sp-lnk  { display:inline-flex; align-items:center; gap:.3rem; margin-top:.75rem; font-size:.8rem; color:var(--primary); font-weight:700; }

/* Profile */
.profile-card {
    display:flex; gap:2.5rem; align-items:flex-start;
    background:var(--surface); border:1px solid var(--border);
    border-radius:var(--radius-lg); padding:2.5rem;
}
.profile-img {
    width:130px; height:130px; border-radius:50%; object-fit:cover;
    border:3px solid var(--primary); flex-shrink:0;
    box-shadow:var(--glow);
}
.profile-name  { font-family:var(--font-heading); font-size:1.6rem; font-weight:700; margin-bottom:.25rem; }
.profile-role  { color:var(--primary); font-size:.85rem; font-weight:700; margin-bottom:.25rem; }
.profile-loc   { color:var(--text-muted); font-size:.8rem; margin-bottom:1rem; display:flex; align-items:center; gap:.35rem; }
.profile-bio   { color:var(--text-muted2); font-size:.9rem; line-height:1.75; }
.profile-link  {
    display:inline-flex; align-items:center; gap:.4rem;
    color:var(--primary); font-weight:700; text-decoration:none; font-size:.85rem;
    margin-top:1rem; transition:opacity .2s;
}
.profile-link:hover { opacity:.8; }

/* Story form */
.story-wrap { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); padding:2rem; }
.form-row    { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
.form-grp    { margin-bottom:1rem; }
.form-grp label { display:block; font-size:.78rem; font-weight:700; color:var(--text-muted2); text-transform:uppercase; letter-spacing:.05em; margin-bottom:.4rem; }
.form-grp label .opt { font-weight:400; opacity:.6; text-transform:none; }
.f-ctrl {
    width:100%; background:var(--surface2); border:1px solid var(--border);
    color:var(--text); padding:.65rem 1rem;
    border-radius:var(--radius); font-family:var(--font-body);
    font-size:.9rem; outline:none; transition:border-color .2s, box-shadow .2s;
}
.f-ctrl:focus { border-color:var(--primary); box-shadow:0 0 0 3px rgba(124,58,237,.15); }
.f-ctrl::placeholder { color:var(--text-muted); }
textarea.f-ctrl { resize:vertical; min-height:170px; }
.char-count { font-size:.72rem; color:var(--text-muted); text-align:right; margin-top:.25rem; }
.char-count.warn { color:var(--accent); }
.char-count.over { color:var(--danger); }
.submit-btn {
    background:var(--primary); color:#fff; border:none;
    padding:.8rem 2.2rem; border-radius:var(--radius);
    font-family:var(--font-body); font-size:.95rem; font-weight:700;
    cursor:pointer; display:inline-flex; align-items:center; gap:.5rem;
    transition:all .2s;
}
.submit-btn:hover:not(:disabled) { transform:translateY(-2px); box-shadow:var(--glow); }
.submit-btn:disabled { opacity:.5; cursor:not-allowed; }
.form-feedback {
    margin-top:1rem; padding:.8rem 1.1rem; border-radius:var(--radius);
    font-size:.875rem; display:none;
}
.form-feedback.ok  { background:rgba(34,197,94,.1); border:1px solid var(--success); color:#4ade80; }
.form-feedback.err { background:rgba(239,68,68,.1);  border:1px solid var(--danger);  color:#fca5a5; }

/* Newsletter */
.nl-box {
    background:linear-gradient(135deg,rgba(124,58,237,.15) 0%,rgba(6,182,212,.06) 100%);
    border:1px solid var(--border); border-radius:var(--radius-lg);
    padding:3rem 2rem; text-align:center;
}
.nl-form { display:flex; gap:.75rem; max-width:420px; margin:1.5rem auto 0; }
.nl-input {
    flex:1; background:var(--surface); border:1px solid var(--border);
    color:var(--text); padding:.72rem 1rem; border-radius:var(--radius);
    font-family:var(--font-body); font-size:.9rem; outline:none;
    transition:border-color .2s;
}
.nl-input:focus { border-color:var(--primary); }
.nl-btn {
    background:var(--primary); color:#fff; border:none;
    padding:.72rem 1.3rem; border-radius:var(--radius);
    font-weight:700; cursor:pointer; font-family:var(--font-body);
    white-space:nowrap; transition:opacity .2s;
}
.nl-btn:hover { opacity:.85; }

/* Cookie notice */
#cookie-bar {
    position:fixed; bottom:0; left:0; right:0;
    background:var(--surface2); border-top:1px solid var(--border);
    padding:.9rem 1.5rem;
    display:flex; align-items:center; justify-content:space-between; gap:1rem;
    z-index:150; flex-wrap:wrap;
    animation:slideUp .3s ease;
}
@keyframes slideUp { from{transform:translateY(100%)} to{transform:translateY(0)} }
.cookie-accept {
    background:var(--primary); color:#fff; border:none;
    padding:.45rem 1.2rem; border-radius:var(--radius);
    font-weight:700; cursor:pointer; font-family:var(--font-body);
    flex-shrink:0;
}

/* Footer */
footer { padding:2rem 2.5rem; border-top:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; }
.footer-copy { font-size:.8rem; color:var(--text-muted); }
.footer-links { display:flex; gap:1rem; }
.footer-links a { font-size:.8rem; color:var(--text-muted); text-decoration:none; transition:color .2s; }
.footer-links a:hover { color:var(--primary); }

/* ── Responsive ── */
@media (max-width:1000px) {
    :root { --sidebar-w:240px; }
    .hero, .episodes-section, .section-block, .filters-bar, footer { padding-left:1.5rem; padding-right:1.5rem; }
}
@media (max-width:780px) {
    .layout { flex-direction:column; }
    .sidebar { width:100%; height:auto; position:static; border-right:none; border-bottom:1px solid var(--border); }
    .sidebar-inner { flex-direction:row; flex-wrap:wrap; align-items:flex-start; gap:1rem; }
    .sidebar-inner > * { flex:1 1 160px; }
    .cover-wrap { flex:0 0 120px; }
    .podcast-cover { max-width:120px; }
    .listen-grid { display:grid; grid-template-columns:1fr 1fr; }
    .hero, .episodes-section, .section-block { padding-left:1.1rem; padding-right:1.1rem; }
    .hero { padding-top:2rem; padding-bottom:1.5rem; }
    .filters-bar { padding-left:1.1rem; overflow-x:auto; flex-nowrap:nowrap; flex-wrap:nowrap; scrollbar-width:none; }
    .filters-bar::-webkit-scrollbar { display:none; }
    .player-row { padding:.65rem 1rem; flex-wrap:wrap; gap:.5rem; }
    .p-info  { flex:0 0 calc(100% - 55px); }
    .p-progress { order:10; width:100%; }
    .p-volume, .speed-btn { display:none; }
    .profile-card { flex-direction:column; }
    .form-row { grid-template-columns:1fr; }
    .nl-form { flex-direction:column; }
    footer { flex-direction:column; text-align:center; }
}
@media (max-width:480px) {
    .sidebar-inner { flex-direction:column; }
    .cover-wrap { flex:unset; }
    .podcast-cover { max-width:100%; }
    .hero-title { font-size:2rem; }
    .episodes-grid { grid-template-columns:1fr; }
}
<?= $cfg['custom_css'] ?? '' ?>
</style>
</head>
<body>

<?php if (($cfg['cookie_notice'] ?? false) && !isset($_COOKIE['cookie_ok'])): ?>
<div id="cookie-bar">
    <span style="font-size:.85rem;color:var(--text-muted2)"><?= h($cfg['cookie_notice_text']) ?></span>
    <button class="cookie-accept" onclick="acceptCookie()">Aceptar</button>
</div>
<?php endif; ?>

<div class="layout">

    <!-- ══ SIDEBAR ══ -->
    <aside class="sidebar">
        <div class="sidebar-inner">
            <!-- Cover -->
            <?php if ($podcast['image'] ?? ''): ?>
            <div class="cover-wrap">
                <img src="<?= h($podcast['image']) ?>"
                     alt="<?= h($podcast['title'] ?? $cfg['site_title']) ?>"
                     class="podcast-cover" loading="lazy">
            </div>
            <?php endif; ?>

            <!-- Info -->
            <div>
                <div class="podcast-title"><?= h($cfg['site_title']) ?></div>
                <?php if ($cfg['site_subtitle']): ?>
                <div class="podcast-subtitle"><?= h($cfg['site_subtitle']) ?></div>
                <?php endif; ?>
                <?php if ($cfg['host_name']): ?>
                <div class="podcast-author"><i class="fa-solid fa-microphone" style="font-size:.7rem"></i> <?= h($cfg['host_name']) ?></div>
                <?php endif; ?>

                <?php if (!empty($episodes)): ?>
                <div class="sidebar-stats">
                    <div class="stat-chip"><span class="n"><?= count($episodes) ?></span><span class="l">Episodios</span></div>
                    <div class="stat-chip"><span class="n"><?= $podcast['total'] ?? count($episodes) ?></span><span class="l">Total</span></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Search -->
            <div class="search-wrap">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="search" id="search-input" class="search-input"
                       placeholder="Buscar episodios..." oninput="doFilter()" autocomplete="off">
            </div>

            <!-- Listen on -->
            <?php
            $podcast_keys = ['spotify','apple_podcasts','deezer','google_podcasts','iheart','podcast_addict','podchaser','amazon_music','pocket_casts','spreaker'];
            $listen_socials = array_filter($active_socials, fn($k) => in_array($k, $podcast_keys), ARRAY_FILTER_USE_KEY);
            if (!empty($listen_socials)):
            ?>
            <div>
                <span class="listen-label">Escúchanos en</span>
                <div class="listen-grid">
                    <?php foreach (array_slice($listen_socials, 0, 8, true) as $key => $s): ?>
                    <a href="<?= h($s['url']) ?>" target="_blank" rel="noopener noreferrer"
                       class="listen-btn" style="color:<?= h($s['color']) ?>">
                        <i class="<?= h($s['icon']) ?>"></i><?= h($s['label']) ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Social icons -->
            <?php if (!empty($active_socials)): ?>
            <div class="social-row">
                <?php foreach ($active_socials as $key => $s): ?>
                <a href="<?= h($s['url']) ?>" target="_blank" rel="noopener noreferrer"
                   class="social-icon" title="<?= h($s['label']) ?>" style="color:<?= h($s['color']) ?>">
                    <i class="<?= h($s['icon']) ?>"></i>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Support pill -->
            <?php if (($cfg['funding_enabled'] ?? true) && ($cfg['funding_url'] ?? '')): ?>
            <a href="<?= h($cfg['funding_url']) ?>" target="_blank" rel="noopener noreferrer" class="support-pill">
                <i class="fa-solid fa-heart"></i> <?= h($cfg['funding_label'] ?? '¡Apóyanos!') ?>
            </a>
            <?php endif; ?>

        </div><!-- /sidebar-inner -->
    </aside><!-- /sidebar -->

    <!-- ══ MAIN ══ -->
    <main class="main" id="top">

        <!-- Hero -->
        <section class="hero">
            <div class="hero-inner">
                <div class="hero-eyebrow">Podcast de Terror</div>
                <h1 class="hero-title"><?= h($cfg['site_title']) ?></h1>
                <?php if ($cfg['hero_show_description'] ?? true): ?>
                <p class="hero-desc"><?= h($cfg['site_tagline']) ?></p>
                <?php endif; ?>
                <div class="hero-actions">
                    <?php if (!empty($episodes)): ?>
                    <button class="btn-primary" onclick="playEp(0)">
                        <i class="fa-solid fa-play"></i> Escuchar ahora
                    </button>
                    <?php endif; ?>
                    <?php if ($cfg['supporters_club_enabled'] && $cfg['supporters_club_url']): ?>
                    <a href="<?= h($cfg['supporters_club_url']) ?>" target="_blank" rel="noopener" class="btn-ghost">
                        <i class="fa-solid fa-crown"></i> Supporters Club
                    </a>
                    <?php endif; ?>
                    <?php if ($cfg['stories_enabled'] ?? true): ?>
                    <a href="#send-story" class="btn-ghost">
                        <i class="fa-solid fa-ghost"></i> Enviar historia
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- Filters -->
        <?php if (($cfg['show_filters'] ?? true) || ($cfg['show_sort_options'] ?? true)): ?>
        <div class="filters-bar" id="filters-bar">
            <?php if ($cfg['show_filters'] ?? true): ?>
            <button class="filter-pill active" data-filter="all"   onclick="setFilter('all',this)">Todos</button>
            <button class="filter-pill"        data-filter="new"   onclick="setFilter('new',this)">Nuevos</button>
            <button class="filter-pill"        data-filter="long"  onclick="setFilter('long',this)">&gt;30 min</button>
            <button class="filter-pill"        data-filter="short" onclick="setFilter('short',this)">Cortos</button>
            <?php endif; ?>
            <?php if ($cfg['show_sort_options'] ?? true): ?>
            <div class="filter-right">
                <select class="sort-select" id="sort-sel" onchange="doFilter()">
                    <option value="newest" <?= ($cfg['default_sort'] ?? 'newest') === 'newest' ? 'selected' : '' ?>>Más recientes</option>
                    <option value="oldest" <?= ($cfg['default_sort'] ?? '') === 'oldest' ? 'selected' : '' ?>>Más antiguos</option>
                    <option value="longest">Más largos</option>
                    <option value="shortest">Más cortos</option>
                </select>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Episodes -->
        <section class="episodes-section">
            <div class="sec-header">
                <h2 class="sec-title">Episodios</h2>
                <span class="eps-count-badge" id="eps-count"><?= count($episodes) ?> episodios</span>
            </div>

            <div class="episodes-grid" id="eps-grid">
                <?php foreach ($episodes as $i => $ep):
                    $is_new = $ep['timestamp'] && ($now - $ep['timestamp']) < ($badge_new_days * 86400);
                    $thumb_fallback = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 400 225'%3E%3Crect fill='%230f0f22' width='400' height='225'/%3E%3Ccircle cx='200' cy='112' r='44' fill='none' stroke='%236b7280' stroke-width='6'/%3E%3Cpath d='M188 88 L218 112 L188 136 Z' fill='%236b7280'/%3E%3C/svg%3E";
                ?>
                <article class="ep-card"
                         id="epc-<?= h($ep['guid']) ?>"
                         data-guid="<?= h($ep['guid']) ?>"
                         data-ts="<?= (int)$ep['timestamp'] ?>"
                         data-dur="<?= (int)$ep['duration'] ?>"
                         data-keywords="<?= h(strtolower($ep['title'] . ' ' . mb_substr($ep['description'],0,200))) ?>"
                         data-index="<?= $i ?>"
                         onclick="playEp(<?= $i ?>)">

                    <?php if ($cfg['card_show_thumbnail'] ?? true): ?>
                    <div class="ep-thumb-wrap">
                        <img src="<?= h($ep['image'] ?: $podcast['image'] ?? '') ?>"
                             alt="<?= h($ep['title']) ?>"
                             loading="lazy"
                             onerror="this.src='<?= $thumb_fallback ?>'">
                        <div class="thumb-overlay">
                            <div class="thumb-play-icon"><i class="fa-solid fa-play"></i></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="ep-badges">
                        <?php if ($ep['episode_num']): ?>
                        <span class="ep-badge badge-ep">Ep <?= (int)$ep['episode_num'] ?></span>
                        <?php endif; ?>
                        <?php if ($is_new): ?>
                        <span class="ep-badge badge-new">Nuevo</span>
                        <?php endif; ?>
                    </div>

                    <div class="ep-body">
                        <h3 class="ep-title"><?= h($ep['title']) ?></h3>
                        <?php if ($cfg['card_show_desc'] ?? true): ?>
                        <p class="ep-desc"><?= h($ep['description']) ?></p>
                        <?php endif; ?>
                        <div class="ep-meta">
                            <?php if (($cfg['card_show_date'] ?? true) && $ep['timestamp']): ?>
                            <span><i class="fa-regular fa-calendar"></i><?= date('d/m/Y', $ep['timestamp']) ?></span>
                            <?php endif; ?>
                            <?php if (($cfg['card_show_duration'] ?? true) && $ep['duration_fmt']): ?>
                            <span><i class="fa-regular fa-clock"></i><?= h($ep['duration_fmt']) ?></span>
                            <?php endif; ?>
                            <?php if ($ep['season']): ?>
                            <span><i class="fa-solid fa-layer-group"></i>T<?= (int)$ep['season'] ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($cfg['card_show_actions'] ?? true): ?>
                    <div class="ep-actions" onclick="event.stopPropagation()">
                        <button class="act-btn fav-btn" title="Favorito"
                                data-guid="<?= h($ep['guid']) ?>"
                                onclick="toggleFav('<?= h($ep['guid']) ?>',this)">
                            <i class="fa-regular fa-heart"></i>
                        </button>
                        <button class="act-btn" title="Agregar a cola"
                                onclick="addQueue(<?= $i ?>,this)">
                            <i class="fa-solid fa-list-ul"></i>
                        </button>
                        <?php if ($cfg['player_show_download'] ?? true): ?>
                        <button class="act-btn" title="Descargar"
                                onclick="dlEp('<?= h($ep['audio_url']) ?>')">
                            <i class="fa-solid fa-download"></i>
                        </button>
                        <?php endif; ?>
                        <button class="play-btn" onclick="playEp(<?= $i ?>)">
                            <i class="fa-solid fa-play"></i> Reproducir
                        </button>
                    </div>
                    <?php endif; ?>

                </article>
                <?php endforeach; ?>

                <?php if (empty($episodes)): ?>
                <div class="no-results">
                    <i class="fa-solid fa-podcast"></i>
                    <h3>No se pudieron cargar los episodios</h3>
                    <p style="margin-top:.5rem">Verifica la URL del feed en el <a href="admin.php" style="color:var(--primary)">panel de control</a>.</p>
                </div>
                <?php endif; ?>
            </div>

            <div id="pagination" class="pagination"></div>
        </section>

        <!-- ── Supporters Club ── -->
        <?php if (($cfg['supporters_club_enabled'] ?? false) && ($cfg['supporters_club_url'] ?? '')): ?>
        <section class="section-block">
            <div class="supporters-box">
                <i class="fa-solid fa-crown" style="font-size:2.5rem;color:var(--accent);display:block;margin-bottom:1rem"></i>
                <h3><?= h($cfg['supporters_club_title']) ?></h3>
                <p style="color:var(--text-muted2);margin-top:.5rem;max-width:500px;margin-left:auto;margin-right:auto"><?= h($cfg['supporters_club_desc']) ?></p>
                <a href="<?= h($cfg['supporters_club_url']) ?>" target="_blank" rel="noopener" class="supporters-link">
                    <i class="fa-solid fa-crown"></i> Unirme al Supporters Club
                </a>
            </div>
        </section>
        <?php endif; ?>

        <!-- ── Apoyo ── -->
        <?php if ($cfg['show_support_section'] ?? true): ?>
        <section class="section-block">
            <div class="eyebrow">Apoya el podcast</div>
            <h2 class="block-title"><i class="fa-solid fa-coffee" style="color:var(--accent);margin-right:.35rem"></i><?= h($cfg['support_section_title'] ?? 'Apóyanos') ?></h2>
            <p class="block-desc"><?= h($cfg['support_section_desc'] ?? '') ?></p>
            <div class="support-grid">
                <?php if (($cfg['funding_enabled'] ?? true) && ($cfg['funding_url'] ?? '')): ?>
                <a href="<?= h($cfg['funding_url']) ?>" target="_blank" rel="noopener" class="sup-btn"
                   style="background:linear-gradient(135deg,rgba(124,58,237,.2),rgba(6,182,212,.1));border-color:rgba(124,58,237,.4)">
                    <i class="fa-solid fa-heart" style="color:var(--primary)"></i><?= h($cfg['funding_label'] ?? 'Apoyar') ?>
                </a>
                <?php endif; ?>
                <?php foreach ($support_platforms as [$plbl, $pico, $pcol, $pcfgk]):
                    $purl = $cfg[$pcfgk] ?? '';
                    if (!$purl) continue;
                ?>
                <a href="<?= h($purl) ?>" target="_blank" rel="noopener" class="sup-btn">
                    <i class="<?= h($pico) ?>" style="color:<?= h($pcol) ?>"></i><?= h($plbl) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- ── Patrocinadores ── -->
        <?php if (($cfg['show_sponsors'] ?? true) && !empty($cfg['sponsors'])): ?>
        <section class="section-block">
            <div class="eyebrow">Patrocinadores</div>
            <h2 class="block-title">Quienes hacen posible el podcast</h2>
            <div class="sponsors-grid">
                <?php foreach ($cfg['sponsors'] as $sp):
                    if (!($sp['active'] ?? true)) continue;
                ?>
                <a href="<?= h($sp['url'] ?? '#') ?>" target="_blank" rel="noopener noreferrer" class="sp-card">
                    <?php if ($sp['image'] ?? ''): ?>
                    <img src="<?= h($sp['image']) ?>" alt="<?= h($sp['name']) ?>" class="sp-img" loading="lazy">
                    <?php else: ?>
                    <div class="sp-img" style="display:flex;align-items:center;justify-content:center;font-size:2rem"><i class="fa-solid fa-handshake"></i></div>
                    <?php endif; ?>
                    <div class="sp-name"><?= h($sp['name']) ?></div>
                    <div class="sp-desc"><?= h($sp['description'] ?? '') ?></div>
                    <?php if ($sp['label'] ?? ''): ?>
                    <span class="sp-lnk"><i class="fa-solid fa-arrow-up-right-from-square"></i><?= h($sp['label']) ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- ── Perfil ── -->
        <?php if (($cfg['show_profile'] ?? true) && ($cfg['host_name'] ?? '')): ?>
        <section class="section-block">
            <div class="eyebrow">El host</div>
            <div class="profile-card">
                <?php if ($cfg['host_image'] ?? ''): ?>
                <img src="<?= h($cfg['host_image']) ?>" alt="<?= h($cfg['host_name']) ?>" class="profile-img" loading="lazy">
                <?php endif; ?>
                <div style="flex:1;min-width:0">
                    <div class="profile-name"><?= h($cfg['host_name']) ?></div>
                    <?php if ($cfg['host_title']): ?>
                    <div class="profile-role"><?= h($cfg['host_title']) ?></div>
                    <?php endif; ?>
                    <?php if ($cfg['host_location']): ?>
                    <div class="profile-loc"><i class="fa-solid fa-location-dot"></i><?= h($cfg['host_location']) ?></div>
                    <?php endif; ?>
                    <?php if ($cfg['host_bio']): ?>
                    <div class="profile-bio"><?= h($cfg['host_bio']) ?></div>
                    <?php endif; ?>
                    <?php if ($cfg['host_website']): ?>
                    <a href="<?= h($cfg['host_website']) ?>" target="_blank" rel="noopener" class="profile-link">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i> Ver portafolio
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- ── Historias de oyentes ── -->
        <?php if (($cfg['show_stories_section'] ?? true) && ($cfg['stories_enabled'] ?? true)): ?>
        <section class="section-block" id="send-story">
            <div class="eyebrow"><i class="fa-solid fa-ghost"></i> Oyentes</div>
            <h2 class="block-title"><?= h($cfg['stories_title'] ?? 'Envía tu Historia') ?></h2>
            <p class="block-desc"><?= h($cfg['stories_desc'] ?? '') ?></p>
            <div class="story-wrap">
                <form id="story-form" onsubmit="submitStory(event)" novalidate>
                    <div class="form-row">
                        <div class="form-grp">
                            <label>Tu nombre <span class="opt">(o anónimo)</span></label>
                            <input type="text" class="f-ctrl" name="name" maxlength="100"
                                   placeholder="¿Cómo te llamamos?" autocomplete="name">
                        </div>
                        <div class="form-grp">
                            <label>Email <span class="opt">(opcional)</span></label>
                            <input type="email" class="f-ctrl" name="email" maxlength="200"
                                   placeholder="Para avisarte si usamos tu historia">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-grp">
                            <label>Título <span style="color:var(--danger)">*</span></label>
                            <input type="text" class="f-ctrl" name="title" required maxlength="200"
                                   placeholder="Dale un título impactante">
                        </div>
                        <div class="form-grp">
                            <label>Lugar donde ocurrió</label>
                            <input type="text" class="f-ctrl" name="location" maxlength="100"
                                   placeholder="Ciudad, región, país...">
                        </div>
                    </div>
                    <div class="form-grp">
                        <label>Tu historia <span style="color:var(--danger)">*</span></label>
                        <textarea class="f-ctrl" name="story" required
                                  maxlength="<?= (int)($cfg['stories_max_length'] ?? 5000) ?>"
                                  placeholder="Cuéntanos qué pasó... (mínimo 50 caracteres)"
                                  oninput="countChars(this)"></textarea>
                        <div class="char-count">
                            <span id="story-chars">0</span> / <?= (int)($cfg['stories_max_length'] ?? 5000) ?> caracteres
                        </div>
                    </div>
                    <button type="submit" class="submit-btn" id="story-btn">
                        <i class="fa-solid fa-paper-plane"></i> Enviar mi historia
                    </button>
                </form>
                <div class="form-feedback" id="story-fb"></div>
            </div>
        </section>
        <?php endif; ?>

        <!-- ── Newsletter ── -->
        <?php if ($cfg['show_newsletter'] ?? false): ?>
        <section class="section-block">
            <div class="nl-box">
                <i class="fa-solid fa-envelope" style="font-size:2.5rem;color:var(--primary);display:block;margin-bottom:1rem"></i>
                <h2 style="font-family:var(--font-heading);font-size:1.7rem;margin-bottom:.5rem"><?= h($cfg['newsletter_title']) ?></h2>
                <p style="color:var(--text-muted2)"><?= h($cfg['newsletter_desc']) ?></p>
                <form class="nl-form" onsubmit="doSubscribe(event)">
                    <input type="email" class="nl-input" id="nl-email" placeholder="tu@email.com" required>
                    <button type="submit" class="nl-btn"><?= h($cfg['newsletter_btn_text']) ?></button>
                </form>
                <div class="form-feedback" id="nl-fb" style="max-width:420px;margin:.75rem auto 0"></div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Footer -->
        <footer>
            <div class="footer-copy"><?= h($cfg['copyright_text']) ?><?= $cfg['footer_text'] ? ' · ' . h($cfg['footer_text']) : '' ?></div>
            <div class="footer-links">
                <?php if ($cfg['social_rss'] ?? ''): ?>
                <a href="<?= h($cfg['social_rss']) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-rss"></i> RSS</a>
                <?php elseif ($cfg['feed_url']): ?>
                <a href="<?= h($cfg['feed_url']) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-rss"></i> RSS</a>
                <?php endif; ?>
                <?php if ($cfg['stories_enabled'] ?? true): ?>
                <a href="#send-story">Enviar historia</a>
                <?php endif; ?>
                <a href="admin.php" title="Panel"><i class="fa-solid fa-gear"></i> Admin</a>
            </div>
        </footer>

    </main><!-- /main -->
</div><!-- /layout -->

<!-- ══ PLAYER ══ -->
<div id="player" role="region" aria-label="Reproductor de audio">
    <div class="player-row">
        <?php if ($cfg['player_show_cover'] ?? true): ?>
        <img id="p-cover" class="p-cover" src="" alt="Portada" onerror="this.style.display='none'">
        <?php endif; ?>
        <div class="p-info">
            <div class="p-title" id="p-title">Selecciona un episodio</div>
            <div class="p-author"><?= h($cfg['site_title']) ?></div>
        </div>
        <div class="p-controls">
            <?php if ($cfg['player_show_shuffle'] ?? true): ?>
            <button class="c-btn" id="btn-shuf" title="Aleatorio (S)" onclick="toggleShuffle()" aria-label="Aleatorio"><i class="fa-solid fa-shuffle"></i></button>
            <?php endif; ?>
            <?php if ($cfg['player_show_repeat'] ?? true): ?>
            <button class="c-btn" id="btn-rep"  title="Repetir (R)"   onclick="toggleRepeat()"  aria-label="Repetir"><i class="fa-solid fa-repeat"></i></button>
            <?php endif; ?>
            <?php if ($cfg['player_show_next'] ?? true): ?>
            <button class="c-btn" title="Anterior (P)" onclick="prevEp()" aria-label="Anterior"><i class="fa-solid fa-backward-step"></i></button>
            <?php endif; ?>
            <?php if ($cfg['show_skip_buttons'] ?? true): ?>
            <button class="c-btn" title="Retroceder <?= (int)($cfg['skip_back_seconds']??15) ?>s" onclick="seekRel(-<?= (int)($cfg['skip_back_seconds']??15) ?>)" aria-label="Retroceder"><i class="fa-solid fa-rotate-left"></i></button>
            <?php endif; ?>
            <button class="c-btn-play" id="main-play" onclick="togglePlay()" aria-label="Play/Pausa">
                <i class="fa-solid fa-play" id="play-ico"></i>
            </button>
            <?php if ($cfg['show_skip_buttons'] ?? true): ?>
            <button class="c-btn" title="Adelantar <?= (int)($cfg['skip_forward_seconds']??30) ?>s" onclick="seekRel(<?= (int)($cfg['skip_forward_seconds']??30) ?>)" aria-label="Adelantar"><i class="fa-solid fa-rotate-right"></i></button>
            <?php endif; ?>
            <?php if ($cfg['player_show_next'] ?? true): ?>
            <button class="c-btn" title="Siguiente (N)" onclick="nextEp()" aria-label="Siguiente"><i class="fa-solid fa-forward-step"></i></button>
            <?php endif; ?>
        </div>
        <div class="p-progress">
            <div class="progress-track" id="prog-track" onclick="seekClick(event)" role="slider" aria-label="Progreso">
                <div class="progress-fill" id="prog-fill" style="width:0%">
                    <div class="progress-thumb"></div>
                </div>
            </div>
            <div class="p-times">
                <span id="t-cur">0:00</span>
                <span id="t-tot">0:00</span>
            </div>
        </div>
        <div class="p-volume">
            <i class="fa-solid fa-volume-low"></i>
            <input type="range" min="0" max="1" step="0.02"
                   value="<?= h(($cfg['default_volume'] ?? 80) / 100) ?>"
                   id="vol-slider" oninput="setVol(this.value)" aria-label="Volumen">
        </div>
        <?php if ($cfg['show_speed_control'] ?? true): ?>
        <button class="speed-btn" id="spd-btn" onclick="cycleSpeed()" title="Velocidad">1×</button>
        <?php endif; ?>
        <?php if ($cfg['player_sleep_timer'] ?? true): ?>
        <div style="position:relative">
            <button class="c-btn" id="sleep-btn" title="Temporizador de sueño" onclick="toggleSleepMenu()"><i class="fa-solid fa-moon"></i></button>
            <div class="sleep-menu" id="sleep-menu">
                <button class="sleep-opt" onclick="setSleep(0)">Desactivar</button>
                <button class="sleep-opt" onclick="setSleep(15)">15 minutos</button>
                <button class="sleep-opt" onclick="setSleep(30)">30 minutos</button>
                <button class="sleep-opt" onclick="setSleep(45)">45 minutos</button>
                <button class="sleep-opt" onclick="setSleep(60)">1 hora</button>
                <button class="sleep-opt" onclick="setSleep(-1)">Al fin del episodio</button>
            </div>
        </div>
        <?php endif; ?>
        <button class="p-close" onclick="closePlayer()" title="Cerrar reproductor" aria-label="Cerrar"><i class="fa-solid fa-chevron-down"></i></button>
    </div>
    <audio id="aud" preload="metadata"></audio>
</div>

<script>
// ── Data ──────────────────────────────────────────────────────
const EPS = <?= json_encode($episodes, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE) ?>;
const CFG = {
    skipBack:    <?= (int)($cfg['skip_back_seconds']    ?? 15) ?>,
    skipFwd:     <?= (int)($cfg['skip_forward_seconds'] ?? 30) ?>,
    continuous:  <?= ($cfg['player_continuous_play'] ?? true)  ? 'true' : 'false' ?>,
    histMax:     <?= (int)($cfg['history_max']          ?? 20) ?>,
    perPage:     <?= (int)($cfg['episodes_per_page']    ?? 0)  ?>,
    remPos:      <?= ($cfg['remember_position']         ?? true) ? 'true' : 'false' ?>,
    newDays:     <?= $badge_new_days ?>,
    defVol:      <?= ($cfg['default_volume'] ?? 80) / 100 ?>,
};

// ── State ─────────────────────────────────────────────────────
const aud   = document.getElementById('aud');
let curIdx  = -1;
let playing = false;
let shuffle = false;
let repeat  = false;
let speeds  = [1, 1.25, 1.5, 1.75, 2, 0.75];
let spdIdx  = 0;
let queue   = [];
let filtered= [...EPS];
let curFilter='all';
let curPage = 1;
let sleepTimer = null;
let sleepEndEp = false;
let favs = JSON.parse(localStorage.getItem('ter_favs')||'[]');
let positions = JSON.parse(localStorage.getItem('ter_pos')||'{}');

// ── Audio events ──────────────────────────────────────────────
aud.addEventListener('timeupdate', () => {
    if (!aud.duration) return;
    const pct = (aud.currentTime / aud.duration) * 100;
    document.getElementById('prog-fill').style.width = pct + '%';
    document.getElementById('t-cur').textContent = fmt(aud.currentTime);
    if (curIdx >= 0 && EPS[curIdx]) {
        positions[EPS[curIdx].guid] = aud.currentTime;
        try { localStorage.setItem('ter_pos', JSON.stringify(positions)); } catch(e){}
    }
});
aud.addEventListener('loadedmetadata', () => {
    document.getElementById('t-tot').textContent = fmt(aud.duration);
    if (CFG.remPos && curIdx >= 0 && EPS[curIdx]) {
        const pos = positions[EPS[curIdx].guid] || 0;
        if (pos > 10 && pos < aud.duration - 10) aud.currentTime = pos;
    }
});
aud.addEventListener('ended', () => {
    if (sleepEndEp) { setSleep(0); return; }
    if (repeat) { aud.currentTime = 0; aud.play(); }
    else if (queue.length) { playEp(queue.shift()); }
    else if (CFG.continuous) { nextEp(); }
    else { setPlayIcon(false); playing = false; }
});
aud.addEventListener('play',  () => { playing=true;  setPlayIcon(true);  });
aud.addEventListener('pause', () => { playing=false; setPlayIcon(false); });
aud.addEventListener('error', () => { showNotice('No se pudo cargar el audio.','err'); });

// ── Player functions ──────────────────────────────────────────
function playEp(idxInFiltered) {
    const ep = filtered[idxInFiltered];
    if (!ep) return;
    const globalIdx = EPS.findIndex(e => e.guid === ep.guid);
    curIdx = globalIdx >= 0 ? globalIdx : idxInFiltered;

    aud.src = ep.audio_url;
    aud.volume = parseFloat(document.getElementById('vol-slider')?.value ?? CFG.defVol);
    aud.play().catch(err => console.warn('Autoplay blocked:', err));

    document.getElementById('p-title').textContent = ep.title;
    const cover = document.getElementById('p-cover');
    if (cover) { cover.src = ep.image || ''; cover.style.display = ep.image ? '' : 'none'; }

    const player = document.getElementById('player');
    player.classList.add('open');
    document.body.style.paddingBottom = '90px';

    // Highlight
    document.querySelectorAll('.ep-card').forEach(c => {
        c.classList.remove('is-playing');
        c.querySelector('.badge-playing')?.remove();
    });
    const card = document.getElementById('epc-' + ep.guid);
    if (card) {
        card.classList.add('is-playing');
        const badges = card.querySelector('.ep-badges');
        if (badges && !badges.querySelector('.badge-playing')) {
            const b = document.createElement('span');
            b.className = 'ep-badge badge-playing';
            b.innerHTML = '<i class="fa-solid fa-volume-high"></i> Reproduciendo';
            badges.prepend(b);
        }
        card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // Track + history
    fetch('index.php?api=track_play', { method:'POST', body: new URLSearchParams({guid: ep.guid}) }).catch(()=>{});
    saveHistory(ep.guid);

    // Media session API
    if ('mediaSession' in navigator) {
        navigator.mediaSession.metadata = new MediaMetadata({
            title: ep.title,
            artist: document.querySelector('.podcast-title')?.textContent || '',
            artwork: ep.image ? [{src: ep.image}] : [],
        });
        navigator.mediaSession.setActionHandler('play',          () => aud.play());
        navigator.mediaSession.setActionHandler('pause',         () => aud.pause());
        navigator.mediaSession.setActionHandler('nexttrack',     () => nextEp());
        navigator.mediaSession.setActionHandler('previoustrack', () => prevEp());
        navigator.mediaSession.setActionHandler('seekbackward',  () => seekRel(-CFG.skipBack));
        navigator.mediaSession.setActionHandler('seekforward',   () => seekRel(CFG.skipFwd));
    }
}

function togglePlay() {
    if (curIdx < 0 && filtered.length) { playEp(0); return; }
    if (playing) aud.pause(); else aud.play().catch(()=>{});
}
function setPlayIcon(p) {
    const ico = document.getElementById('play-ico');
    if (ico) ico.className = p ? 'fa-solid fa-pause' : 'fa-solid fa-play';
}
function nextEp() {
    if (!filtered.length) return;
    const cur = curIdx >= 0 ? EPS[curIdx] : null;
    const fi  = cur ? filtered.findIndex(e => e.guid === cur.guid) : -1;
    const next = shuffle ? Math.floor(Math.random() * filtered.length) : (fi + 1) % filtered.length;
    playEp(next);
}
function prevEp() {
    if (!filtered.length) return;
    if (aud.currentTime > 5) { aud.currentTime = 0; return; }
    const cur = curIdx >= 0 ? EPS[curIdx] : null;
    const fi  = cur ? filtered.findIndex(e => e.guid === cur.guid) : 0;
    playEp((fi - 1 + filtered.length) % filtered.length);
}
function seekRel(sec)    { aud.currentTime = Math.max(0, Math.min(aud.duration||0, aud.currentTime+sec)); }
function seekClick(e) {
    const rect = document.getElementById('prog-track').getBoundingClientRect();
    const pct  = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
    if (aud.duration) aud.currentTime = pct * aud.duration;
}
function setVol(v) { aud.volume = parseFloat(v); }
function toggleShuffle() {
    shuffle = !shuffle;
    document.getElementById('btn-shuf')?.classList.toggle('on', shuffle);
}
function toggleRepeat() {
    repeat = !repeat;
    document.getElementById('btn-rep')?.classList.toggle('on', repeat);
}
function cycleSpeed() {
    spdIdx = (spdIdx + 1) % speeds.length;
    aud.playbackRate = speeds[spdIdx];
    const btn = document.getElementById('spd-btn');
    if (btn) btn.textContent = speeds[spdIdx] + '×';
}
function closePlayer() {
    aud.pause();
    document.getElementById('player').classList.remove('open');
    document.body.style.paddingBottom = '';
    document.querySelectorAll('.ep-card').forEach(c => {
        c.classList.remove('is-playing');
        c.querySelector('.badge-playing')?.remove();
    });
}
function dlEp(url) {
    const a = document.createElement('a');
    a.href = url; a.download = ''; a.target = '_blank';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
}
function fmt(s) {
    if (!s || isNaN(s)) return '0:00';
    const h=Math.floor(s/3600), m=Math.floor((s%3600)/60), sec=Math.floor(s%60);
    if (h>0) return `${h}:${String(m).padStart(2,'0')}:${String(sec).padStart(2,'0')}`;
    return `${m}:${String(sec).padStart(2,'0')}`;
}

// Sleep timer
function toggleSleepMenu() {
    document.getElementById('sleep-menu').classList.toggle('open');
}
function setSleep(mins) {
    clearTimeout(sleepTimer); sleepEndEp = false;
    document.getElementById('sleep-menu').classList.remove('open');
    const btn = document.getElementById('sleep-btn');
    if (mins === 0) { if (btn) btn.classList.remove('on'); return; }
    if (mins === -1) { sleepEndEp = true; if (btn) btn.classList.add('on'); return; }
    if (btn) btn.classList.add('on');
    sleepTimer = setTimeout(() => { aud.pause(); if (btn) btn.classList.remove('on'); }, mins * 60000);
}

// ── Queue ─────────────────────────────────────────────────────
function addQueue(idx, btn) {
    const ep = filtered[idx];
    if (!ep) return;
    if (queue.includes(idx)) return;
    queue.push(idx);
    if (btn) { btn.innerHTML = '<i class="fa-solid fa-check"></i>'; setTimeout(() => btn.innerHTML='<i class="fa-solid fa-list-ul"></i>', 1200); }
}

// ── Favorites ─────────────────────────────────────────────────
function toggleFav(guid, btn) {
    const i = favs.indexOf(guid);
    if (i < 0) { favs.push(guid); btn.classList.add('active'); btn.innerHTML='<i class="fa-solid fa-heart"></i>'; }
    else        { favs.splice(i,1); btn.classList.remove('active'); btn.innerHTML='<i class="fa-regular fa-heart"></i>'; }
    try { localStorage.setItem('ter_favs', JSON.stringify(favs)); } catch(e){}
}
function restoreFavs() {
    favs.forEach(guid => {
        const btn = document.querySelector(`.fav-btn[data-guid="${CSS.escape(guid)}"]`);
        if (btn) { btn.classList.add('active'); btn.innerHTML='<i class="fa-solid fa-heart"></i>'; }
    });
}

// ── History ───────────────────────────────────────────────────
function saveHistory(guid) {
    let h = JSON.parse(localStorage.getItem('ter_hist')||'[]');
    h = h.filter(g => g !== guid);
    h.unshift(guid);
    try { localStorage.setItem('ter_hist', JSON.stringify(h.slice(0, CFG.histMax))); } catch(e){}
}

// ── Filter / Sort / Search ────────────────────────────────────
let filterTimeout;
function doFilter() {
    clearTimeout(filterTimeout);
    filterTimeout = setTimeout(applyFilter, 120);
}
function setFilter(f, btn) {
    curFilter = f;
    document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    doFilter();
}
function applyFilter() {
    const q    = (document.getElementById('search-input')?.value || '').toLowerCase().trim();
    const sort = document.getElementById('sort-sel')?.value || 'newest';
    const now  = Date.now() / 1000;
    let eps = [...EPS];
    if (q) eps = eps.filter(e => e.data_keywords
        ? e.data_keywords.includes(q)
        : (e.title.toLowerCase().includes(q) || e.description.toLowerCase().includes(q)));
    if (curFilter === 'new')   eps = eps.filter(e => e.timestamp && (now - e.timestamp) < CFG.newDays * 86400);
    if (curFilter === 'long')  eps = eps.filter(e => e.duration >= 1800);
    if (curFilter === 'short') eps = eps.filter(e => e.duration > 0 && e.duration < 600);
    if (sort === 'newest')   eps.sort((a,b) => b.timestamp - a.timestamp);
    if (sort === 'oldest')   eps.sort((a,b) => a.timestamp - b.timestamp);
    if (sort === 'longest')  eps.sort((a,b) => b.duration  - a.duration);
    if (sort === 'shortest') eps.sort((a,b) => a.duration  - b.duration);
    filtered = eps;
    curPage  = 1;
    renderGrid();
}

function renderGrid() {
    const perPage = CFG.perPage > 0 ? CFG.perPage : filtered.length;
    const start   = (curPage - 1) * perPage;
    const visible = new Set(filtered.slice(start, start + perPage).map(e => e.guid));

    document.getElementById('eps-count').textContent = filtered.length + ' episodios';

    // Update card visibility & onclick index
    document.querySelectorAll('.ep-card').forEach(card => {
        const guid = card.dataset.guid;
        if (!visible.has(guid)) { card.classList.add('hidden'); return; }
        card.classList.remove('hidden');
        const fi = filtered.slice(start, start+perPage).findIndex(e => e.guid === guid);
        card.dataset.index = fi;
        card.onclick = () => playEp(fi + (curPage-1)*perPage);
        // Update play btn
        const pBtn = card.querySelector('.play-btn');
        if (pBtn) pBtn.onclick = (ev) => { ev.stopPropagation(); playEp(fi + (curPage-1)*perPage); };
    });

    // Empty state
    const grid = document.getElementById('eps-grid');
    let noRes = grid.querySelector('.no-results');
    if (!visible.size) {
        if (!noRes) {
            noRes = document.createElement('div');
            noRes.className = 'no-results';
            noRes.innerHTML = '<i class="fa-solid fa-ghost"></i><h3>Sin resultados</h3><p style="margin-top:.5rem">Intenta con otros términos.</p>';
            grid.appendChild(noRes);
        }
    } else {
        noRes?.remove();
    }

    renderPagination(Math.ceil(filtered.length / (perPage || 1)));
    restoreFavs();
}

function renderPagination(total) {
    const pag = document.getElementById('pagination');
    if (!pag) return;
    if (total <= 1) { pag.innerHTML = ''; return; }
    let html = '';
    if (curPage > 1) html += `<button class="page-btn" onclick="goPage(${curPage-1})"><i class="fa-solid fa-chevron-left"></i></button>`;
    for (let i = Math.max(1,curPage-2); i <= Math.min(total,curPage+2); i++) {
        html += `<button class="page-btn${i===curPage?' active':''}" onclick="goPage(${i})">${i}</button>`;
    }
    if (curPage < total) html += `<button class="page-btn" onclick="goPage(${curPage+1})"><i class="fa-solid fa-chevron-right"></i></button>`;
    pag.innerHTML = html;
}
function goPage(p) { curPage = p; renderGrid(); window.scrollTo({top:0,behavior:'smooth'}); }

// ── Forms ─────────────────────────────────────────────────────
function countChars(el) {
    const max = parseInt(el.maxLength);
    const n   = el.value.length;
    const cc  = document.getElementById('story-chars');
    if (cc) cc.textContent = n;
    const wrap = el.closest('.form-grp')?.querySelector('.char-count');
    if (wrap) {
        wrap.className = 'char-count' + (n > max*0.9 ? ' warn' : '') + (n >= max ? ' over' : '');
    }
}

async function submitStory(e) {
    e.preventDefault();
    const btn = document.getElementById('story-btn');
    const fb  = document.getElementById('story-fb');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enviando...';
    try {
        const res  = await fetch('index.php?api=submit_story', { method:'POST', body: new FormData(e.target) });
        const json = await res.json();
        fb.className = 'form-feedback ' + (json.ok ? 'ok' : 'err');
        fb.textContent = json.msg;
        fb.style.display = '';
        if (json.ok) e.target.reset();
    } catch {
        fb.className = 'form-feedback err';
        fb.textContent = 'Error al enviar. Intenta de nuevo.';
        fb.style.display = '';
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Enviar mi historia';
}

async function doSubscribe(e) {
    e.preventDefault();
    const email = document.getElementById('nl-email').value;
    const fb    = document.getElementById('nl-fb');
    const fd    = new FormData(); fd.append('email', email);
    try {
        const res  = await fetch('index.php?api=subscribe', { method:'POST', body: fd });
        const json = await res.json();
        fb.className = 'form-feedback ' + (json.ok ? 'ok' : 'err');
        fb.textContent = json.msg;
        fb.style.display = '';
    } catch {
        fb.className = 'form-feedback err';
        fb.textContent = 'Error. Intenta de nuevo.';
        fb.style.display = '';
    }
}

// ── Cookie ────────────────────────────────────────────────────
function acceptCookie() {
    document.cookie = 'cookie_ok=1;path=/;max-age=31536000;SameSite=Lax';
    const el = document.getElementById('cookie-bar');
    if (el) { el.style.transition='opacity .3s'; el.style.opacity=0; setTimeout(()=>el.remove(),300); }
}

// ── Keyboard shortcuts ────────────────────────────────────────
<?php if ($cfg['player_keyboard_shortcuts'] ?? true): ?>
document.addEventListener('keydown', e => {
    if (['INPUT','TEXTAREA','SELECT'].includes(e.target.tagName)) return;
    switch(e.key) {
        case ' ': e.preventDefault(); togglePlay();           break;
        case 'ArrowRight': seekRel(CFG.skipFwd);              break;
        case 'ArrowLeft':  seekRel(-CFG.skipBack);            break;
        case 'ArrowUp':    aud.volume=Math.min(1,aud.volume+.05); break;
        case 'ArrowDown':  aud.volume=Math.max(0,aud.volume-.05); break;
        case 'n': case 'N': nextEp();                         break;
        case 'p': case 'P': prevEp();                         break;
        case 'm': case 'M': aud.muted=!aud.muted;             break;
        case 's': case 'S': toggleShuffle();                  break;
        case 'r': case 'R': toggleRepeat();                   break;
    }
});
<?php endif; ?>

// ── Analytics ─────────────────────────────────────────────────
fetch('index.php?api=track_view', {method:'POST'}).catch(()=>{});

// ── Notice helper ─────────────────────────────────────────────
function showNotice(msg, type='ok') {
    const n = document.createElement('div');
    n.style.cssText = `position:fixed;bottom:100px;left:50%;transform:translateX(-50%);background:var(--surface2);border:1px solid var(--${type==='ok'?'success':'danger'});border-radius:var(--radius);padding:.7rem 1.2rem;color:var(--text);z-index:999;font-size:.85rem;box-shadow:var(--shadow-lg)`;
    n.textContent = msg;
    document.body.appendChild(n);
    setTimeout(() => n.remove(), 3000);
}

// ── Init ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    aud.volume = CFG.defVol;
    applyFilter();
    restoreFavs();
    <?php if (($cfg['player_autoplay'] ?? false) && !empty($episodes)): ?>
    setTimeout(() => playEp(0), 800);
    <?php endif; ?>
});

// Close sleep menu on outside click
document.addEventListener('click', e => {
    const menu = document.getElementById('sleep-menu');
    if (menu?.classList.contains('open') && !e.target.closest('#sleep-btn') && !e.target.closest('#sleep-menu')) {
        menu.classList.remove('open');
    }
});
</script>

<?php if ($cfg['google_analytics_id'] ?? ''): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= h($cfg['google_analytics_id']) ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','<?= h($cfg['google_analytics_id']) ?>');</script>
<?php endif; ?>

<?php if ($cfg['custom_js'] ?? ''): ?>
<script><?= $cfg['custom_js'] ?></script>
<?php endif; ?>
</body>
</html>
