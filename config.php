<?php
/**
 * Terror en Rapel — Configuración Central
 * Versión 2.0 | PHP 8.1+
 */

// ── Permisos ──────────────────────────────────────────────────
define('PERM_ALL',              'all');
define('PERM_SETTINGS_GENERAL', 'settings.general');
define('PERM_SETTINGS_DESIGN',  'settings.design');
define('PERM_SETTINGS_FEED',    'settings.feed');
define('PERM_SETTINGS_SEO',     'settings.seo');
define('PERM_SETTINGS_SOCIAL',  'settings.social');
define('PERM_SETTINGS_PLAYER',  'settings.player');
define('PERM_SETTINGS_NOTIF',   'settings.notif');
define('PERM_SETTINGS_ADS',     'settings.ads');
define('PERM_SPONSORS_VIEW',    'sponsors.view');
define('PERM_SPONSORS_EDIT',    'sponsors.edit');
define('PERM_USERS_VIEW',       'users.view');
define('PERM_USERS_EDIT',       'users.edit');
define('PERM_USERS_DELETE',     'users.delete');
define('PERM_CACHE_CLEAR',      'cache.clear');
define('PERM_MAINTENANCE',      'maintenance');
define('PERM_ANALYTICS',        'analytics');
define('PERM_PROFILE_EDIT',     'profile.edit');
define('PERM_EXPORT_DATA',      'export.data');
define('PERM_STORIES_VIEW',     'stories.view');
define('PERM_STORIES_EDIT',     'stories.edit');
define('PERM_STORIES_DELETE',   'stories.delete');

// ── Rutas ─────────────────────────────────────────────────────
define('BASE_PATH', __DIR__);
define('CACHE_PATH', BASE_PATH . '/cache');
define('DATA_PATH',  BASE_PATH . '/data');
define('UPLOADS_PATH', BASE_PATH . '/uploads');

// ── Seguridad ─────────────────────────────────────────────────
define('SESSION_TIMEOUT', 7200); // 2 horas
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 min
define('CSRF_TOKEN_LENGTH', 32);

// ── Versión ───────────────────────────────────────────────────
define('APP_VERSION', '2.0.0');
define('APP_NAME', 'Terror en Rapel CMS');

// ── Roles ─────────────────────────────────────────────────────
$ROLES = [
    'superadmin' => ['label'=>'Super Admin','color'=>'#f43f5e','icon'=>'fa-crown','permissions'=>[PERM_ALL],'description'=>'Acceso total al sistema.','protected'=>true],
    'admin'      => ['label'=>'Administrador','color'=>'#6366f1','icon'=>'fa-shield-halved','permissions'=>[PERM_SETTINGS_GENERAL,PERM_SETTINGS_DESIGN,PERM_SETTINGS_FEED,PERM_SETTINGS_SEO,PERM_SETTINGS_SOCIAL,PERM_SETTINGS_PLAYER,PERM_SETTINGS_NOTIF,PERM_SETTINGS_ADS,PERM_SPONSORS_VIEW,PERM_SPONSORS_EDIT,PERM_USERS_VIEW,PERM_CACHE_CLEAR,PERM_MAINTENANCE,PERM_ANALYTICS,PERM_PROFILE_EDIT,PERM_EXPORT_DATA,PERM_STORIES_VIEW,PERM_STORIES_EDIT,PERM_STORIES_DELETE],'description'=>'Gestión completa excepto crear admins.'],
    'editor'     => ['label'=>'Editor','color'=>'#10b981','icon'=>'fa-pen-to-square','permissions'=>[PERM_SETTINGS_GENERAL,PERM_SETTINGS_SOCIAL,PERM_SPONSORS_VIEW,PERM_SPONSORS_EDIT,PERM_PROFILE_EDIT,PERM_ANALYTICS,PERM_STORIES_VIEW,PERM_STORIES_EDIT],'description'=>'Edita contenido y patrocinadores.'],
    'viewer'     => ['label'=>'Observador','color'=>'#64748b','icon'=>'fa-eye','permissions'=>[PERM_ANALYTICS,PERM_SPONSORS_VIEW,PERM_STORIES_VIEW],'description'=>'Solo lectura y estadísticas.'],
    'custom'     => ['label'=>'Personalizado','color'=>'#f59e0b','icon'=>'fa-sliders','permissions'=>[],'description'=>'Permisos definidos manualmente.'],
];

// ── Usuarios por defecto ───────────────────────────────────────
$DEFAULT_ADMIN_USERS = [
    'admin' => [
        'username'=>'admin','password'=>password_hash('admin123',PASSWORD_BCRYPT),
        'display_name'=>'Administrador Principal','email'=>'admin@terrorenrapel.cl',
        'avatar'=>'','role'=>'superadmin','permissions'=>[],'active'=>true,
        'created_at'=>'2025-01-01','last_login'=>null,'can_delete'=>false,
    ],
];

// ── Config por defecto del podcast ────────────────────────────
$DEFAULT_CONFIG = [
    // Feed
    'feed_url'                  => 'https://www.spreaker.com/show/5842440/episodes/feed',
    'feed_cache_ttl'            => 900,
    'exclude_url_prefix'        => '',
    'episodes_per_page'         => 12,
    'default_sort'              => 'newest',
    'feed_backup_url'           => '',
    'feed_timeout'              => 15,
    'feed_provider'             => 'spreaker', // spreaker|anchor|spotify|rss|custom

    // Identidad
    'site_title'                => 'Terror en Rapel',
    'site_subtitle'             => 'Historias que te helarán la sangre',
    'site_tagline'              => 'Sumérgete en el terror con historias horripilantes y leyendas urbanas.',
    'welcome_message'           => '',
    'copyright_text'            => '© 2025 Terror en Rapel. Todos los derechos reservados.',
    'language'                  => 'es',
    'site_logo_url'             => '',
    'favicon_url'               => '',
    'site_web_url'              => '',

    // Diseño
    'theme_mode'                => 'dark',
    'primary_color'             => '#7c3aed',
    'secondary_color'           => '#06b6d4',
    'accent_color'              => '#f59e0b',
    'success_color'             => '#22c55e',
    'danger_color'              => '#ef4444',
    'sidebar_bg'                => '#0a0a1a',
    'body_bg'                   => '#050510',
    'surface_color'             => '#0f0f22',
    'surface2_color'            => '#131330',
    'text_color'                => '#e2e8ff',
    'text_muted'                => '#6b7280',
    'border_color'              => 'rgba(255,255,255,0.07)',
    'card_hover_glow'           => true,
    'border_radius_base'        => '14',
    'border_radius_large'       => '20',
    'background_noise'          => true,
    'animated_gradient'         => true,
    'font_heading'              => 'Creepster',
    'font_body'                 => 'DM Sans',
    'font_size_base'            => '15',

    // Layout
    'sidebar_width'             => '270',
    'content_max_width'         => '1400',
    'hero_style'                => 'full',
    'episode_layout'            => 'grid',
    'grid_columns_min'          => '290',
    'card_show_thumbnail'       => true,
    'card_show_desc'            => true,
    'card_desc_lines'           => 2,
    'card_show_date'            => true,
    'card_show_duration'        => true,
    'card_show_actions'         => true,
    'show_filters'              => true,
    'show_sort_options'         => true,

    // Player
    'player_autoplay'           => false,
    'default_volume'            => '80',
    'show_speed_control'        => true,
    'show_skip_buttons'         => true,
    'skip_back_seconds'         => 15,
    'skip_forward_seconds'      => 30,
    'history_max'               => 20,
    'remember_position'         => true,
    'player_show_cover'         => true,
    'player_show_next'          => true,
    'player_show_shuffle'       => true,
    'player_show_repeat'        => true,
    'player_show_queue'         => true,
    'player_show_download'      => true,
    'player_keyboard_shortcuts' => true,
    'player_continuous_play'    => true,
    'player_sleep_timer'        => true,
    'playlist_max'              => 10,

    // Hero
    'hero_show_cover'           => true,
    'hero_show_stats'           => true,
    'hero_show_listen_buttons'  => true,
    'hero_show_description'     => true,
    'hero_show_author'          => true,
    'hero_custom_banner_url'    => '',
    'badge_new_days'            => 7,
    'badge_trending_days'       => 30,
    'badge_trending_min_plays'  => 50,

    // Secciones
    'show_sponsors'             => true,
    'show_about'                => true,
    'show_profile'              => true,
    'show_newsletter'           => false,
    'show_support_section'      => true,
    'show_queue_section'        => true,
    'show_stories_section'      => true,

    // SEO
    'meta_title'                => '',
    'meta_description'          => '',
    'meta_keywords'             => 'podcast,terror,leyendas urbanas,horror,chile',
    'og_image'                  => '',
    'twitter_card'              => 'summary_large_image',
    'twitter_handle'            => '',
    'google_analytics_id'       => '',
    'structured_data'           => true,
    'canonical_url'             => '',
    'robots_noindex'            => false,

    // Perfil
    'host_name'                 => 'Cristóbal Páez',
    'host_email'                => '',
    'host_image'                => 'https://i.ibb.co/hFdKm16v/imagen-perfil.jpg',
    'host_bio'                  => 'Diseñador Web, Camarógrafo, Sonidista. Apasionado por crear experiencias digitales y audiovisuales.',
    'host_website'              => 'https://crispro941.vercel.app/',
    'host_location'             => 'Las Cabras, Chile',
    'host_title'                => 'Conductor y Productor',

    // Redes Sociales
    'social_website'            => '',
    'social_spotify'            => 'https://open.spotify.com/show/28jWRFwLIjkQiSvrYuFylE',
    'social_apple_podcasts'     => '',
    'social_spreaker'           => '',
    'social_deezer'             => 'https://deezer.com/show/5984517',
    'social_google_podcasts'    => '',
    'social_iheart'             => 'https://iheart.com/podcast/113628836',
    'social_podcast_addict'     => '',
    'social_podchaser'          => '',
    'social_amazon_music'       => '',
    'social_pocket_casts'       => '',
    'social_instagram'          => '',
    'social_youtube'            => '',
    'social_twitter'            => '',
    'social_tiktok'             => '',
    'social_facebook'           => '',
    'social_rss'                => '',
    'social_patreon'            => '',
    'social_discord'            => '',

    // Apoyo
    'funding_enabled'           => true,
    'funding_url'               => '',
    'funding_label'             => '¡Apoya el podcast!',
    'support_patreon_url'       => '',
    'support_paypal_url'        => '',
    'support_ko_fi_url'         => '',
    'support_buy_me_coffee_url' => '',
    'support_mercado_pago_url'  => '',
    'support_section_title'     => 'Apoya Terror en Rapel',
    'support_section_desc'      => 'Si disfrutas del podcast, considera apoyarnos. Cada aporte ayuda a crear más historias de terror.',

    // Historias de oyentes
    'stories_enabled'           => true,
    'stories_require_approval'  => true,
    'stories_allow_anonymous'   => true,
    'stories_max_length'        => 5000,
    'stories_title'             => 'Envía tu Historia de Terror',
    'stories_desc'              => '¿Viviste algo inexplicable? ¡Cuéntanos! Las mejores historias pueden aparecer en el podcast.',
    'stories_success_msg'       => '¡Historia recibida! La revisaremos y podría aparecer en el podcast.',

    // Newsletter
    'nl_provider'               => 'local',
    'nl_mailchimp_key'          => '',
    'nl_mailchimp_list'         => '',
    'newsletter_title'          => 'Suscríbete al newsletter',
    'newsletter_desc'           => 'Recibe los nuevos episodios de terror en tu correo.',
    'newsletter_btn_text'       => 'Suscribirme gratis',

    // Sponsors
    'sponsors'                  => [],

    // Supporters Club (Spreaker)
    'supporters_club_enabled'   => false,
    'supporters_club_url'       => '',
    'supporters_club_title'     => 'Únete al Supporters Club',
    'supporters_club_desc'      => 'Accede a contenido exclusivo y apoya directamente el podcast.',

    // Mantenimiento
    'maintenance_mode'          => false,
    'maintenance_title'         => 'Sitio en Mantenimiento',
    'maintenance_message'       => 'Estamos realizando mejoras. Vuelve pronto.',

    // Footer
    'footer_text'               => '',
    'custom_css'                => '',
    'custom_js'                 => '',
    'cookie_notice'             => false,
    'cookie_notice_text'        => 'Usamos cookies para mejorar tu experiencia.',

    // Analytics internos
    'analytics_enabled'         => true,
];
