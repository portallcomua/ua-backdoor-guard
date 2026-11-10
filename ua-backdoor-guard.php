
<?php
/**
 * Plugin Name: UA Backdoor Guard
 * Plugin URI: https://uaserver.pp.ua/ua-backdoor-guard/
 * Description: Сканер та активний захист сайту від бекдорів, шелів та зламів.
 * Version: 3.0.1
 * Author: UAServer
 * Author URI: https://uaserver.pp.ua
 * License: GPL-2.0-or-later
 * Text Domain: ua-backdoor-guard
 */

if (!defined('ABSPATH')) exit;

/* =========================================================
 * 1. UA PAY BOX — PRO
 * ======================================================= */

if (!function_exists('ua_backdoor_guard_is_pro')) {

    function ua_backdoor_guard_hub() {
        return 'https://uaserver.pp.ua/wp-json/upb/v1/';
    }

    function ua_backdoor_guard_domain() {
        return strtolower(
            preg_replace(
                '/^www\./',
                '',
                (string) wp_parse_url(home_url(), PHP_URL_HOST)
            )
        );
    }

    function ua_backdoor_guard_state($force = false) {

        $c = get_option('ua_backdoor_guard_pro');

        if (!is_array($c)) {
            $c = array(
                'state' => 'free',
                'expires' => 0,
                'checked' => 0
            );
        }

        if (
            $force ||
            time() - (int) $c['checked'] > 6 * HOUR_IN_SECONDS
        ) {

            $url = add_query_arg(
                array(
                    'service' => 'ua-backdoor-guard',
                    'domain' => ua_backdoor_guard_domain()
                ),
                ua_backdoor_guard_hub() . 'status'
            );

            $r = wp_remote_get($url, array(
                'timeout' => 12,
                'headers' => array(
                    'Accept' => 'application/json'
                )
            ));

            if (
                !is_wp_error($r) &&
                200 === (int) wp_remote_retrieve_response_code($r)
            ) {

                $j = json_decode(wp_remote_retrieve_body($r), true);

                if (is_array($j) && isset($j['state'])) {

                    $c = array(
                        'state' => sanitize_key($j['state']),
                        'expires' => isset($j['expires'])
                            ? (int) $j['expires']
                            : 0,
                        'checked' => time()
                    );

                    update_option('ua_backdoor_guard_pro', $c, false);
                }

            } elseif (time() - (int) $c['checked'] > 7 * DAY_IN_SECONDS) {

                $c['state'] = 'free';
            }
        }

        return $c;
    }

    function ua_backdoor_guard_is_pro() {

        $c = ua_backdoor_guard_state();

        return
            in_array(
                $c['state'],
                array('active', 'expiring', 'unlimited'),
                true
            ) &&
            (!$c['expires'] || $c['expires'] > time());
    }

    function ua_backdoor_guard_activate_pro() {

        $r = wp_remote_post(
            ua_backdoor_guard_hub() . 'activate',
            array(
                'timeout' => 20,
                'body' => array(
                    'service' => 'ua-backdoor-guard',
                    'domain' => ua_backdoor_guard_domain(),
                    'email' => get_option('admin_email'),
                    'site_url' => home_url()
                )
            )
        );

        if (is_wp_error($r)) {
            return $r;
        }

        $j = json_decode(wp_remote_retrieve_body($r), true);

        if (
            200 !== (int) wp_remote_retrieve_response_code($r) ||
            !is_array($j)
        ) {
            return new WP_Error(
                'pay',
                isset($j['message'])
                    ? sanitize_text_field($j['message'])
                    : 'Помилка сервера UA Pay Box'
            );
        }

        ua_backdoor_guard_state(true);

        return $j;
    }
}

/* =========================================================
 * 2. GITHUB AUTO UPDATE
 * ======================================================= */

add_filter(
    'pre_set_site_transient_update_plugins',
    'uabg_check_github_update'
);

function uabg_check_github_update($transient) {

    if (empty($transient->checked) || !is_object($transient)) {
        return $transient;
    }

    $repo = 'portallcomua/ua-backdoor-guard';
    $plugin_file = plugin_basename(__FILE__);
    $current_version = isset($transient->checked[$plugin_file])
        ? $transient->checked[$plugin_file]
        : '';

    if (!$current_version) {
        return $transient;
    }

    $release = get_transient('uabg_github_release');

    if (false === $release) {

        $response = wp_remote_get(
            'https://api.github.com/repos/' . $repo . '/releases/latest',
            array(
                'timeout' => 15,
                'headers' => array(
                    'User-Agent' => 'UA-Backdoor-Guard/' . $current_version,
                    'Accept' => 'application/vnd.github+json'
                )
            )
        );

        if (
            is_wp_error($response) ||
            200 !== (int) wp_remote_retrieve_response_code($response)
        ) {
            return $transient;
        }

        $release = json_decode(
            wp_remote_retrieve_body($response)
        );

        if (empty($release->tag_name)) {
            return $transient;
        }

        set_transient('uabg_github_release', $release, 6 * HOUR_IN_SECONDS);
    }

    $remote_version = ltrim($release->tag_name, 'vV');

    if (version_compare($current_version, $remote_version, '>=')) {
        return $transient;
    }

    $package = '';

    if (!empty($release->assets)) {

        foreach ($release->assets as $asset) {

            if (
                !empty($asset->browser_download_url) &&
                preg_match('/\.zip($|\?)/i', $asset->browser_download_url)
            ) {
                $package = $asset->browser_download_url;
                break;
            }
        }
    }

    if (!$package && !empty($release->zipball_url)) {
        $package = $release->zipball_url;
    }

    if (!$package) {
        return $transient;
    }

    $obj = new stdClass();

    $obj->slug = 'ua-backdoor-guard';
    $obj->plugin = $plugin_file;
    $obj->new_version = $remote_version;
    $obj->url = 'https://github.com/' . $repo;
    $obj->package = $package;
    $obj->tested = get_bloginfo('version');

    $transient->response[$plugin_file] = $obj;

    return $transient;
}

/* =========================================================
 * 3. MAIN PLUGIN
 * ======================================================= */

final class UA_Backdoor_Guard {

    const VERSION = '3.0.1';

    const OPTION_REPORT = 'uabg_last_report';
    const OPTION_SETTINGS = 'uabg_settings';
    const OPTION_SCAN_STATE = 'uabg_scan_state';
    const OPTION_FREE_SCANS = 'uabg_free_scans';

    const QUARANTINE_DIR_NAME = 'ua-bg-quarantine';

    private static $i18n = array();

    public static function init() {

        add_action('plugins_loaded', array(__CLASS__, 'load_textdomain'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));

        add_action(
            'admin_post_uabg_scan_start',
            array(__CLASS__, 'handle_scan_start')
        );

        add_action(
            'wp_ajax_uabg_ajax_scan',
            array(__CLASS__, 'handle_ajax_scan')
        );

        add_action(
            'admin_post_uabg_quarantine',
            array(__CLASS__, 'handle_quarantine')
        );

        add_action(
            'admin_post_uabg_restore',
            array(__CLASS__, 'handle_restore')
        );

        add_action(
            'admin_post_uabg_save_settings',
            array(__CLASS__, 'handle_settings')
        );

        add_action(
            'admin_post_uabg_export',
            array(__CLASS__, 'handle_export')
        );

        add_action(
            'admin_post_uabg_clear',
            array(__CLASS__, 'handle_clear')
        );

        add_action(
            'admin_post_uabg_activate_pro',
            array(__CLASS__, 'handle_activate_pro')
        );

        add_action('init', array(__CLASS__, 'active_defense'), 0);
        add_action('admin_init', array(__CLASS__, 'block_uploads_php'), 0);
    }

    public static function load_textdomain() {

        $uk = strpos(get_locale(), 'uk') === 0;

        self::$i18n = $uk
            ? array(
                'title' => 'UA Backdoor Guard',
                'scan_btn' => 'Запустити сканування',
                'scans_left' => 'Безкоштовних сканувань залишилося:',
                'pro_btn' => 'Отримати PRO',
                'pro_active' => 'PRO активовано',
                'quarantine' => 'Карантин',
                'settings' => 'Налаштування',
                'scanned' => 'Перевірено файлів',
                'findings' => 'Знайдено загроз',
                'defense_active' => 'Активний захист',
                'defense_on' => 'Увімкнено',
                'defense_off' => 'Вимкнено'
            )
            : array(
                'title' => 'UA Backdoor Guard',
                'scan_btn' => 'Start Scan',
                'scans_left' => 'Free scans left:',
                'pro_btn' => 'Get PRO',
                'pro_active' => 'PRO is Active',
                'quarantine' => 'Quarantine',
                'settings' => 'Settings',
                'scanned' => 'Files scanned',
                'findings' => 'Threats found',
                'defense_active' => 'Active Defense',
                'defense_on' => 'Enabled',
                'defense_off' => 'Disabled'
            );
    }

    private static function t($key) {
        return isset(self::$i18n[$key]) ? self::$i18n[$key] : $key;
    }

    public static function activate() {

        if (!get_option(self::OPTION_SETTINGS)) {
            add_option(
                self::OPTION_SETTINGS,
                array(
                    'scan_limit' => 30000,
                    'batch_size' => 300
                )
            );
        }

        if (get_option(self::OPTION_FREE_SCANS) === false) {
            update_option(self::OPTION_FREE_SCANS, 5);
        }

        self::quarantine_dir();
    }

    public static function admin_menu() {

        add_menu_page(
            self::t('title'),
            self::t('title'),
            'manage_options',
            'ua-backdoor-guard',
            array(__CLASS__, 'render_page'),
            'dashicons-shield',
            80
        );
    }

    /* =====================================================
     * QUARANTINE DIRECTORY
     * =================================================== */

    private static function quarantine_dir() {

        $dir = WP_CONTENT_DIR . '/' . self::QUARANTINE_DIR_NAME;

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        if (is_dir($dir)) {

            if (!file_exists($dir . '/index.php')) {
                file_put_contents($dir . '/index.php', '<?php // Silence is golden.');
            }

            if (!file_exists($dir . '/.htaccess')) {
                file_put_contents(
                    $dir . '/.htaccess',
                    "Options -Indexes\nDeny from all\n"
                );
            }
        }

        return $dir;
    }

    /* =====================================================
     * ACTIVE DEFENSE
     * =================================================== */

    public static function active_defense() {

        if (!get_option('uabg_active_defense', 1)) {
            return;
        }

        $uri = isset($_SERVER['REQUEST_URI'])
            ? wp_unslash($_SERVER['REQUEST_URI'])
            : '';

        if (
            preg_match(
                '/(?:union.*select|base64_decode|eval\(|<\?php|wp-config\.php)/i',
                $uri
            )
        ) {
            self::log_attack('Suspicious URI: ' . $uri);

            wp_die(
                'Access Denied. Security block.',
                '403 Forbidden',
                array('response' => 403)
            );
        }

        if (strpos($uri, 'wp-config.php') !== false) {
            wp_die('Access Denied.', '403 Forbidden', array('response' => 403));
        }
    }

    public static function block_uploads_php() {

        if (!get_option('uabg_active_defense', 1)) {
            return;
        }

        $uri = isset($_SERVER['REQUEST_URI'])
            ? wp_unslash($_SERVER['REQUEST_URI'])
            : '';

        if (
            strpos($uri, '/wp-content/uploads/') !== false &&
            preg_match('/\.(php\d?|phtml)(?:$|\?)/i', $uri)
        ) {
            self::log_attack('PHP in uploads: ' . $uri);

            wp_die(
                'Access Denied. PHP execution in uploads is prohibited.',
                '403 Forbidden',
                array('response' => 403)
            );
        }
    }

    private static function log_attack($msg) {

        $log = get_option('uabg_active_defense_log', array());

        if (!is_array($log)) {
            $log = array();
        }

        array_unshift(
            $log,
            current_time('mysql') . ' | ' . $msg .
            ' | IP: ' . (
                isset($_SERVER['REMOTE_ADDR'])
                    ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
                    : 'unknown'
            )
        );

        update_option(
            'uabg_active_defense_log',
            array_slice($log, 0, 50)
        );
    }

    /* =====================================================
     * PRO ACTIVATION HANDLER
     * =================================================== */

    public static function handle_activate_pro() {

        if (!current_user_can('manage_options')) {
            wp_die('Access Denied');
        }

        check_admin_referer('uabg_activate_pro');

        $result = ua_backdoor_guard_activate_pro();

        if (is_wp_error($result)) {
            set_transient(
                'uabg_notice_' . get_current_user_id(),
                $result->get_error_message(),
                60
            );

            wp_safe_redirect(
                admin_url('admin.php?page=ua-backdoor-guard')
            );
            exit;
        }

        $payment_url = '';

        foreach (array('url', 'payment_url', 'checkout_url') as $key) {
            if (!empty($result[$key]) && is_string($result[$key])) {
                $payment_url = esc_url_raw($result[$key]);
                break;
            }
        }

        ua_backdoor_guard_state(true);

        if (
            $payment_url &&
            wp_http_validate_url($payment_url) &&
            strpos($payment_url, 'https://') === 0
        ) {
            wp_redirect($payment_url);
            exit;
        }

        if (ua_backdoor_guard_is_pro()) {

            set_transient(
                'uabg_notice_' . get_current_user_id(),
                'PRO успішно активовано!',
                60
            );

        } else {

            $message = !empty($result['message'])
                ? sanitize_text_field($result['message'])
                : 'Запит передано до UA Pay Box. Статус PRO буде перевірено автоматично.';

            set_transient(
                'uabg_notice_' . get_current_user_id(),
                $message,
                60
            );
        }

        wp_safe_redirect(admin_url('admin.php?page=ua-backdoor-guard'));
        exit;
    }

    /* =====================================================
     * ADMIN PAGE
     * =================================================== */

    public static function render_page() {

        if (!current_user_can('manage_options')) {
            wp_die('Access Denied');
        }

        $is_pro = ua_backdoor_guard_is_pro();

        $free_scans = (int) get_option(self::OPTION_FREE_SCANS, 5);

        $report = get_option(self::OPTION_REPORT, array());
        $state = get_option(self::OPTION_SCAN_STATE, array());

        $settings = wp_parse_args(
            get_option(self::OPTION_SETTINGS, array()),
            array(
                'scan_limit' => 30000,
                'batch_size' => 300
            )
        );

        $is_running = !empty($state['running']);

        $defense_on = get_option('uabg_active_defense', 1);

        $notice = get_transient(
            'uabg_notice_' . get_current_user_id()
        );

        if ($notice) {
            delete_transient(
                'uabg_notice_' . get_current_user_id()
            );
        }

        ?>

        <div class="wrap uabg-wrap">

            <style>
                .uabg-wrap * {
                    box-sizing: border-box;
                }

                .uabg-panel {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    border-radius: 7px;
                    padding: 16px;
                    margin: 15px 0;
                }

                .uabg-pro {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    flex-wrap: wrap;
                    gap: 12px;
                }

                .uabg-actions {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 8px;
                    margin: 12px 0;
                }

                .uabg-wrap .widefat {
                    table-layout: fixed;
                    width: 100%;
                }

                .uabg-wrap .widefat td,
                .uabg-wrap .widefat th {
                    overflow-wrap: anywhere;
                    word-break: normal;
                    vertical-align: top;
                }

                .uabg-file {
                    white-space: normal !important;
                    overflow-wrap: anywhere;
                    word-break: break-word;
                    display: inline-block;
                    max-width: 100%;
                }

                .uabg-progress {
                    background: #f0f0f1;
                    border: 1px solid #c3c4c7;
                    height: 22px;
                    border-radius: 4px;
                    overflow: hidden;
                }

                .uabg-progress-bar {
                    background: #2271b1;
                    height: 100%;
                    width: 0;
                    transition: width .3s;
                }

                @media screen and (max-width: 782px) {

                    .uabg-wrap {
                        margin-right: 10px;
                    }

                    .uabg-pro {
                        display: block;
                    }

                    .uabg-pro > * {
                        display: block;
                        margin-bottom: 12px;
                    }

                    .uabg-actions {
                        display: flex;
                        flex-direction: column;
                    }

                    .uabg-actions .button,
                    .uabg-wrap form .button {
                        min-height: 40px;
                        line-height: 38px;
                        text-align: center;
                    }

                    .uabg-wrap table.widefat,
                    .uabg-wrap table.widefat thead,
                    .uabg-wrap table.widefat tbody,
                    .uabg-wrap table.widefat tr,
                    .uabg-wrap table.widefat td {
                        display: block;
                        width: 100%;
                    }

                    .uabg-wrap table.widefat thead {
                        display: none;
                    }

                    .uabg-wrap table.widefat tr {
                        background: #fff;
                        border: 1px solid #ccd0d4;
                        border-radius: 6px;
                        margin-bottom: 12px;
                        padding: 8px;
                    }

                    .uabg-wrap table.widefat td {
                        display: flex;
                        flex-wrap: wrap;
                        gap: 8px;
                        border: 0;
                        border-bottom: 1px solid #eee;
                        padding: 9px 5px;
                        min-width: 0;
                    }

                    .uabg-wrap table.widefat td:last-child {
                        border-bottom: 0;
                    }

                    .uabg-wrap table.widefat td:before {
                        content: attr(data-label);
                        font-weight: 700;
                        flex: 0 0 90px;
                        max-width: 90px;
                    }

                    .uabg-wrap table.widefat td > * {
                        max-width: 100%;
                    }

                    .uabg-file {
                        min-width: 0;
                        flex: 1 1 150px;
                    }
                }
            </style>

            <h1>
                <span class="dashicons dashicons-shield"></span>
                <?php echo esc_html(self::t('title')); ?>
                <small>v<?php echo esc_html(self::VERSION); ?></small>
            </h1>

            <?php if ($notice): ?>
                <div class="notice notice-info is-dismissible">
                    <p><?php echo esc_html($notice); ?></p>
                </div>
            <?php endif; ?>

            <div class="uabg-panel uabg-pro">

                <?php if ($is_pro): ?>

                    <strong style="color:#067647;font-size:17px;">
                        ✓ <?php echo esc_html(self::t('pro_active')); ?>
                    </strong>

                <?php else: ?>

                    <span>
                        <?php echo esc_html(self::t('scans_left')); ?>
                        <strong><?php echo esc_html($free_scans); ?></strong>
                    </span>

                    <form method="post"
                          action="<?php echo esc_url(admin_url('admin-post.php')); ?>">

                        <input type="hidden"
                               name="action"
                               value="uabg_activate_pro">

                        <?php wp_nonce_field('uabg_activate_pro'); ?>

                        <button type="submit"
                                class="button button-primary"
                                style="background:#b32d2e;border-color:#b32d2e;">
                            <?php echo esc_html(self::t('pro_btn')); ?>
                        </button>

                    </form>

                <?php endif; ?>

            </div>

            <div class="uabg-panel">

                <h3>
                    <?php echo esc_html(self::t('defense_active')); ?>:

                    <span style="color:<?php echo $defense_on ? 'green' : 'red'; ?>">
                        <?php echo esc_html(
                            $defense_on
                                ? self::t('defense_on')
                                : self::t('defense_off')
                        ); ?>
                    </span>
                </h3>

                <form method="post"
                      action="<?php echo esc_url(admin_url('admin-post.php')); ?>">

                    <input type="hidden"
                           name="action"
                           value="uabg_toggle_defense">

                    <?php wp_nonce_field('uabg_toggle_defense'); ?>

                    <button class="button">
                        <?php echo esc_html(
                            $defense_on
                                ? 'Вимкнути захист'
                                : 'Увімкнути захист'
                        ); ?>
                    </button>

                </form>

            </div>

            <?php if (!$is_pro && $free_scans <= 0 && !$is_running): ?>

                <div class="notice notice-error">
                    <p>Ліміт безкоштовних сканувань вичерпано. Придбайте PRO.</p>
                </div>

            <?php elseif ($is_running): ?>

                <div id="uabg-progress-wrap" class="uabg-panel">

                    <div class="uabg-progress">
                        <div id="uabg-progress-bar"
                             class="uabg-progress-bar"></div>
                    </div>

                    <p id="uabg-progress-text">Обробка...</p>

                </div>

            <?php else: ?>

                <div class="uabg-actions">

                    <form method="post"
                          action="<?php echo esc_url(admin_url('admin-post.php')); ?>">

                        <input type="hidden"
                               name="action"
                               value="uabg_scan_start">

                        <?php wp_nonce_field('uabg_scan_start'); ?>

                        <button class="button button-primary">
                            <?php echo esc_html(self::t('scan_btn')); ?>
                        </button>

                    </form>

                </div>

            <?php endif; ?>

            <?php if (!empty($report['created'])): ?>

                <hr>

                <h2>Результати сканування</h2>

                <div class="uabg-panel">

                    <p>
                        <strong><?php echo esc_html(self::t('scanned')); ?>:</strong>
                        <?php echo (int) ($report['files_scanned'] ?? 0); ?>

                        |

                        <strong><?php echo esc_html(self::t('findings')); ?>:</strong>
                        <?php echo count($report['findings'] ?? array()); ?>
                    </p>

                    <?php if (!empty($report['truncated'])): ?>
                        <p class="notice notice-warning">
                            Досягнуто встановленого ліміту файлів сканування.
                        </p>
                    <?php endif; ?>

                </div>

                <?php self::render_findings($report['findings'] ?? array()); ?>

                <?php
                self::render_summary_section(
                    'Адміністратори',
                    $report['admins'] ?? array()
                );

                self::render_summary_section(
                    'Підозрілі записи БД',
                    $report['database'] ?? array()
                );

                self::render_summary_section(
                    'WP-Cron',
                    $report['cron'] ?? array()
                );

                self::render_summary_section(
                    'Додаткові відомості',
                    $report['notes'] ?? array()
                );
                ?>

                <div class="uabg-panel">

                    <h3>Керування звітом</h3>

                    <div class="uabg-actions">

                        <form method="post"
                              action="<?php echo esc_url(admin_url('admin-post.php')); ?>">

                            <input type="hidden"
                                   name="action"
                                   value="uabg_export">

                            <?php wp_nonce_field('uabg_export'); ?>

                            <button class="button button-primary">
                                <span class="dashicons dashicons-download"></span>
                                Завантажити звіт JSON
                            </button>

                        </form>

                        <form method="post"
                              action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                              onsubmit="return confirm('Ви впевнені, що хочете очистити результати сканування?');">

                            <input type="hidden"
                                   name="action"
                                   value="uabg_clear">

                            <?php wp_nonce_field('uabg_clear'); ?>

                            <button class="button button-secondary">
                                Очистити результати сканування
                            </button>

                        </form>

                    </div>

                </div>

            <?php endif; ?>

            <?php if ($is_running): ?>

                <script>
                document.addEventListener('DOMContentLoaded', function() {

                    var offset = <?php echo (int) ($state['offset'] ?? 0); ?>;
                    var total = <?php echo (int) ($state['total'] ?? 0); ?>;

                    var progressBar = document.getElementById('uabg-progress-bar');
                    var progressText = document.getElementById('uabg-progress-text');

                    function doBatch() {

                        var data = {
                            action: 'uabg_ajax_scan',
                            nonce: '<?php echo esc_js(wp_create_nonce('uabg_ajax_scan')); ?>',
                            batch_size: <?php echo (int) $settings['batch_size']; ?>
                        };

                        fetch(ajaxurl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: new URLSearchParams(data)
                        })
                        .then(function(r) {
                            return r.json();
                        })
                        .then(function(res) {

                            if (res.success) {

                                offset = res.data.offset;
                                total = res.data.total;

                                var pct = total > 0
                                    ? Math.min(100, Math.round(offset / total * 100))
                                    : 100;

                                progressBar.style.width = pct + '%';

                                progressText.innerText =
                                    'Оброблено ' + offset + ' з ' + total +
                                    ' (' + pct + '%)';

                                if (res.data.running) {
                                    setTimeout(doBatch, 150);
                                } else {
                                    progressText.innerText =
                                        'Сканування завершено! Оновлюємо сторінку...';

                                    setTimeout(function() {
                                        location.reload();
                                    }, 1200);
                                }

                            } else {

                                progressText.innerText =
                                    'Помилка: ' + (res.data || 'Невідома помилка');
                            }

                        })
                        .catch(function() {
                            setTimeout(doBatch, 3000);
                        });
                    }

                    doBatch();
                });
                </script>

            <?php endif; ?>

        </div>

        <?php
    }

    /* =====================================================
     * FINDINGS — RESPONSIVE TABLE
     * =================================================== */

    private static function render_findings($findings) {

        if (empty($findings)) {
            echo '<p>Підозрілих файлів не знайдено.</p>';
            return;
        }

        echo '<table class="widefat striped">';

        echo '<thead><tr>';
        echo '<th>Рівень</th>';
        echo '<th>Файл</th>';
        echo '<th>Опис</th>';
        echo '<th>Дія</th>';
        echo '</tr></thead><tbody>';

        foreach ($findings as $f) {

            echo '<tr>';

            echo '<td data-label="Рівень">';
            echo self::severity_badge($f['severity'] ?? '');
            echo '</td>';

            echo '<td data-label="Файл">';
            echo '<code class="uabg-file">';
            echo esc_html($f['path'] ?? '');
            echo '</code>';
            echo '</td>';

            echo '<td data-label="Опис">';
            echo esc_html($f['message'] ?? '');
            echo '</td>';

            echo '<td data-label="Дія">';

            if (
                !empty($f['quarantinable']) &&
                !empty($f['absolute']) &&
                file_exists($f['absolute'])
            ) {

                echo '<form method="post" action="' .
                    esc_url(admin_url('admin-post.php')) . '">';

                echo '<input type="hidden" name="action" value="uabg_quarantine">';

                echo '<input type="hidden" name="path" value="' .
                    esc_attr($f['absolute']) . '">';

                wp_nonce_field(
                    'uabg_quarantine_' . hash('sha256', $f['absolute'])
                );

                echo '<button class="button button-secondary">Карантин</button>';

                echo '</form>';

            } else {

                echo '—';
            }

            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private static function severity_badge($sev) {

        $map = array(
            'critical' => array('#b32d2e', 'Критично'),
            'warning' => array('#996800', 'Підозріло'),
            'info' => array('#2271b1', 'Інфо')
        );

        $m = isset($map[$sev])
            ? $map[$sev]
            : array('#555', 'Невідомо');

        return '<span style="padding:3px 7px;background:' .
            esc_attr($m[0]) .
            ';color:#fff;border-radius:3px">' .
            esc_html($m[1]) .
            '</span>';
    }

    private static function render_summary_section($title, $items) {

        echo '<h3>' . esc_html($title) . '</h3>';

        if (empty($items)) {
            echo '<p>Нічого не виявлено.</p>';
            return;
        }

        echo '<ul style="list-style:disc;padding-left:22px;overflow-wrap:anywhere;">';

        foreach ($items as $item) {

            echo '<li>';

            echo esc_html(
                is_array($item)
                    ? wp_json_encode($item, JSON_UNESCAPED_UNICODE)
                    : $item
            );

            echo '</li>';
        }

        echo '</ul>';
    }

    /* =====================================================
     * SCAN START
     * =================================================== */

    public static function handle_scan_start() {

        if (!current_user_can('manage_options')) {
            wp_die('Access Denied');
        }

        check_admin_referer('uabg_scan_start');

        $is_pro = ua_backdoor_guard_is_pro();

        $free_scans = (int) get_option(self::OPTION_FREE_SCANS, 5);

        if (!$is_pro && $free_scans <= 0) {
            wp_die('Ліміт безкоштовних сканувань вичерпано.');
        }

        if (!$is_pro) {
            update_option(self::OPTION_FREE_SCANS, $free_scans - 1);
        }

        @set_time_limit(120);

        $settings = wp_parse_args(
            get_option(self::OPTION_SETTINGS, array()),
            array(
                'scan_limit' => 30000,
                'batch_size' => 300
            )
        );

        $limit = max(500, min(50000, (int) $settings['scan_limit']));

        $all = array();

        $roots = array(ABSPATH, WP_CONTENT_DIR);

        foreach ($roots as $root) {

            if (!is_dir($root) || !is_readable($root)) {
                continue;
            }

            try {

                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(
                        $root,
                        FilesystemIterator::SKIP_DOTS
                    ),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );

                foreach ($it as $file) {

                    if (!$file->isFile() || $file->isLink()) {
                        continue;
                    }

                    $real = realpath($file->getPathname());

                    if (!$real) {
                        continue;
                    }

                    if (strpos($real, self::quarantine_dir()) === 0) {
                        continue;
                    }

                    $all[$real] = true;

                    if (count($all) >= $limit) {
                        break 2;
                    }
                }

            } catch (Exception $e) {
                // Недоступні каталоги пропускаються.
            }
        }

        $paths = array_keys($all);

        $report = array(
            'created' => current_time('mysql'),
            'files_scanned' => 0,
            'truncated' => count($all) >= $limit,
            'findings' => array(),
            'admins' => self::scan_admins(),
            'cron' => self::scan_cron(),
            'database' => self::scan_database(),
            'logs' => self::inspect_logs(),
            'notes' => self::environment_notes()
        );

        update_option(self::OPTION_REPORT, $report, false);

        update_option(
            self::OPTION_SCAN_STATE,
            array(
                'running' => true,
                'paths' => $paths,
                'offset' => 0,
                'total' => count($paths)
            ),
            false
        );

        self::process_batch($settings['batch_size']);

        wp_safe_redirect(
            admin_url('admin.php?page=ua-backdoor-guard&scan=started')
        );

        exit;
    }

    public static function handle_ajax_scan() {

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Access Denied');
        }

        check_ajax_referer('uabg_ajax_scan', 'nonce');

        @set_time_limit(120);

        self::process_batch(
            isset($_POST['batch_size'])
                ? max(1, min(1000, (int) $_POST['batch_size']))
                : 300
        );

        $state = get_option(self::OPTION_SCAN_STATE, array());

        wp_send_json_success(
            array(
                'running' => !empty($state['running']),
                'offset' => (int) ($state['offset'] ?? 0),
                'total' => (int) ($state['total'] ?? 0)
            )
        );
    }

    private static function process_batch($batch) {

        $state = get_option(self::OPTION_SCAN_STATE, array());

        if (empty($state['running'])) {
            return;
        }

        $report = get_option(self::OPTION_REPORT, array());

        $batch = max(1, min(1000, (int) $batch));

        $end = min(
            $state['offset'] + $batch,
            $state['total']
        );

        for ($i = $state['offset']; $i < $end; $i++) {

            $real = $state['paths'][$i];

            if (!is_file($real) || !is_readable($real)) {
                continue;
            }

            $report['files_scanned']++;

            $res = self::inspect_file($real);

            if ($res) {
                foreach ($res as $f) {
                    $report['findings'][] = $f;
                }
            }
        }

        $state['offset'] = $end;

        if ($end >= $state['total']) {
            $state['running'] = false;
        }

        usort(
            $report['findings'],
            function($a, $b) {

                $r = array(
                    'critical' => 0,
                    'warning' => 1,
                    'info' => 2
                );

                return
                    ($r[$a['severity']] ?? 2) -
                    ($r[$b['severity']] ?? 2);
            }
        );

        update_option(self::OPTION_REPORT, $report, false);
        update_option(self::OPTION_SCAN_STATE, $state, false);
    }

    /* =====================================================
     * FILE SCANNER
     * =================================================== */

    private static function inspect_file($path) {

        $relative = self::relative_path($path);

        $name = basename($path);

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        $size = @filesize($path);

        $findings = array();

        if ($ext === 'php' && self::is_in_wp_root($path)) {

            $known = array(
                'index.php',
                'wp-config.php',
                'wp-load.php',
                'wp-settings.php',
                'wp-blog-header.php',
                'wp-cron.php',
                'wp-signup.php',
                'wp-activate.php',
                'wp-comments-post.php',
                'wp-links-opml.php',
                'wp-login.php',
                'wp-mail.php',
                'wp-trackback.php',
                'xmlrpc.php',
                'wp-config-sample.php'
            );

            if (
                !in_array($name, $known, true) &&
                !preg_match('/^(wp-|index\.php|xmlrpc)/', $name)
            ) {

                $findings[] = self::finding(
                    'critical',
                    $relative,
                    'Невідомий PHP-файл у корені.',
                    $path,
                    true
                );
            }
        }

        if ($ext === 'php' && self::is_under_uploads($path)) {

            $findings[] = self::finding(
                'critical',
                $relative,
                'PHP-файл у uploads.',
                $path,
                true
            );
        }

        if (
            $ext === 'php' &&
            $size > 0 &&
            $size <= 4 * 1024 * 1024 &&
            is_readable($path)
        ) {

            $content = @file_get_contents($path);

            if (is_string($content)) {

                foreach (self::php_signature_rules() as $label => $rule) {

                    if (preg_match($rule['pattern'], $content)) {

                        $findings[] = self::finding(
                            $rule['severity'],
                            $relative,
                            'Сигнатура: ' . $label,
                            $path,
                            true
                        );

                        break;
                    }
                }
            }
        }

        return empty($findings) ? null : $findings;
    }

    private static function php_signature_rules() {

        return array(

            'eval від змінної' => array(
                'severity' => 'critical',
                'pattern' => '/eval\s*\(\s*\$[a-zA-Z_]\w*\s*\)/i'
            ),

            'hex2bin + eval' => array(
                'severity' => 'critical',
                'pattern' => '/hex2bin\s*\(.*?\).*?eval\s*\(/is'
            ),

            'curl_exec + eval' => array(
                'severity' => 'critical',
                'pattern' => '/curl_exec\s*\(.*?eval\s*\(/is'
            ),

            'create_function' => array(
                'severity' => 'critical',
                'pattern' => '/create_function\s*\(.*?gzuncompress/is'
            ),

            'request → execution' => array(
                'severity' => 'critical',
                'pattern' => '/(eval|assert|system|shell_exec|passthru)\s*\(\s*\$_(?:GET|POST|REQUEST|COOKIE)/i'
            )
        );
    }

    private static function finding($sev, $path, $msg, $abs, $quar) {

        return array(
            'severity' => $sev,
            'path' => $path,
            'message' => $msg,
            'absolute' => $abs,
            'quarantinable' => $quar
        );
    }

    private static function relative_path($path) {

        $root = rtrim(ABSPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return strpos($path, $root) === 0
            ? str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                substr($path, strlen($root))
            )
            : $path;
    }

    private static function is_under_uploads($path) {

        $u = wp_upload_dir();

        $base = realpath($u['basedir']);
        $real = realpath($path);

        return
            $base &&
            $real &&
            strpos($real, $base . DIRECTORY_SEPARATOR) === 0;
    }

    private static function is_in_wp_root($path) {

        $root = realpath(ABSPATH);

        $real = realpath($path);

        if (!$root || !$real) {
            return false;
        }

        $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return
            strpos($real, $root) === 0 &&
            strpos(
                substr($real, strlen($root)),
                DIRECTORY_SEPARATOR
            ) === false;
    }

    /* =====================================================
     * DATABASE / ADMINS / CRON
     * =================================================== */

    private static function scan_database() {

        global $wpdb;

        $items = array();

        $patterns = array(
            'iframe' => '/<iframe[^>]+src=[\'"]https?:\/\//i',
            'JS' => '/<script[^>]+src=[\'"]https?:\/\//i',
            'PHP eval' => '/<\?php\s*(?:eval|assert|base64_decode|system)/i'
        );

        $jobs = array(
            array($wpdb->options, 'option_name', 'option_value', 1000),
            array($wpdb->postmeta, 'meta_key', 'meta_value', 1000),
            array($wpdb->posts, 'ID', 'post_content', 1000)
        );

        foreach ($jobs as $job) {

            list($table, $c1, $c2, $limit) = $job;

            $rows = $wpdb->get_results(
                "SELECT `$c1` AS k, `$c2` AS v
                 FROM `$table`
                 ORDER BY `$c1` DESC
                 LIMIT " . (int) $limit,
                ARRAY_A
            );

            if (!$rows) {
                continue;
            }

            foreach ($rows as $row) {

                foreach ($patterns as $label => $pat) {

                    if (preg_match($pat, (string) $row['v'])) {

                        $items[] =
                            $table . ' / ' . $row['k'] . ': ' . $label;

                        break;
                    }
                }

                if (count($items) >= 50) {
                    return $items;
                }
            }
        }

        return $items;
    }

    private static function scan_admins() {

        $items = array();

        $users = get_users(
            array(
                'role' => 'administrator',
                'fields' => array(
                    'ID',
                    'user_login',
                    'user_email',
                    'user_registered'
                )
            )
        );

        $threshold = time() - 14 * DAY_IN_SECONDS;

        foreach ($users as $u) {

            $reg = strtotime($u->user_registered);

            $flag = ($reg && $reg > $threshold)
                ? ' [НОВИЙ]'
                : '';

            $items[] = sprintf(
                'ID %d | %s | %s | %s%s',
                $u->ID,
                $u->user_login,
                $u->user_email,
                $u->user_registered,
                $flag
            );
        }

        return $items;
    }

    private static function scan_cron() {

        $items = array();

        $cron = _get_cron_array();

        if (!is_array($cron)) {
            return $items;
        }

        $susp = '/(eval|base64|shell|exec|curl|wget|file_put_contents|assert|system|hex2bin)/i';

        foreach ($cron as $timestamp => $hooks) {

            foreach ($hooks as $hook => $events) {

                if (preg_match($susp, $hook)) {
                    $items[] = 'Hook: ' . $hook;
                }
            }
        }

        return $items;
    }

    private static function inspect_logs() {
        return array('Перевірте access.log на сервері.');
    }

    private static function environment_notes() {

        global $wp_version;

        return array(
            'WordPress: ' . $wp_version,
            'PHP: ' . PHP_VERSION
        );
    }

    /* =====================================================
     * QUARANTINE
     * =================================================== */

    public static function handle_quarantine() {

        if (!current_user_can('manage_options')) {
            wp_die('Access Denied');
        }

        $path = isset($_POST['path'])
            ? wp_unslash($_POST['path'])
            : '';

        check_admin_referer(
            'uabg_quarantine_' . hash('sha256', $path)
        );

        $real = realpath($path);
        $root = realpath(ABSPATH);

        if (
            !$real ||
            !$root ||
            strpos($real, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0 ||
            !is_file($real)
        ) {
            wp_die('Invalid path');
        }

        $dir = self::quarantine_dir();

        $id = gmdate('Ymd-His') . '-' .
            substr(hash('sha256', $real), 0, 8);

        $dest = $dir . '/' . $id . '.quarantined';

        if (@rename($real, $dest)) {

            file_put_contents(
                $dir . '/' . $id . '.json',
                wp_json_encode(
                    array(
                        'orig' => $real,
                        'time' => time()
                    )
                )
            );

            wp_safe_redirect(
                admin_url('admin.php?page=ua-backdoor-guard&quarantined=1')
            );

        } else {

            wp_die('Не вдалося перемістити файл у карантин.');
        }

        exit;
    }

    public static function handle_restore() {

        if (!current_user_can('manage_options')) {
            wp_die('Access Denied');
        }

        $file = isset($_POST['file'])
            ? sanitize_file_name(wp_unslash($_POST['file']))
            : '';

        check_admin_referer('uabg_restore_' . $file);

        $qdir = self::quarantine_dir();

        $meta_file = $qdir . '/' . $file . '.json';

        $meta = json_decode(
            @file_get_contents($meta_file),
            true
        );

        if (
            $meta &&
            !empty($meta['orig']) &&
            strpos(
                $meta['orig'],
                rtrim(realpath(ABSPATH), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            ) === 0 &&
            !file_exists($meta['orig']) &&
            @rename(
                $qdir . '/' . $file . '.quarantined',
                $meta['orig']
            )
        ) {

            unlink($meta_file);

            wp_safe_redirect(
                admin_url('admin.php?page=ua-backdoor-guard&restored=1')
            );

        } else {

            wp_die('Не вдалося відновити файл.');
        }

        exit;
    }

    private static function list_quarantine_files() {

        $res = array();

        foreach (glob(self::quarantine_dir() . '/*.json') as $f) {

            $meta = json_decode(file_get_contents($f), true);

            if ($meta) {

                $name = basename($f, '.json');

                $quarantined = self::quarantine_dir() .
                    '/' . $name . '.quarantined';

                $res[] = array(
                    'name' => $name,
                    'size' => file_exists($quarantined)
                        ? filesize($quarantined)
                        : 0
                );
            }
        }

        return $res;
    }

    /* =====================================================
     * EXPORT / CLEAR / SETTINGS
     * =================================================== */

    public static function handle_export() {

        if (!current_user_can('manage_options')) {
            wp_die('Access Denied');
        }

        check_admin_referer('uabg_export');

        $report = get_option(self::OPTION_REPORT, array());

        nocache_headers();

        header('Content-Type: application/json; charset=utf-8');

        header(
            'Content-Disposition: attachment; filename="uabg-report.json"'
        );

        echo wp_json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    public static function handle_clear() {

        if (!current_user_can('manage_options')) {
            wp_die('Access Denied');
        }

        check_admin_referer('uabg_clear');

        delete_option(self::OPTION_REPORT);

        delete_option(self::OPTION_SCAN_STATE);

        wp_safe_redirect(
            admin_url('admin.php?page=ua-backdoor-guard&cleared=1')
        );

        exit;
    }

    public static function handle_settings() {

        if (!current_user_can('manage_options')) {
            wp_die('Access Denied');
        }

        check_admin_referer('uabg_save_settings');

        update_option(
            self::OPTION_SETTINGS,
            array(
                'scan_limit' => isset($_POST['scan_limit'])
                    ? max(500, min(50000, (int) $_POST['scan_limit']))
                    : 30000,

                'batch_size' => isset($_POST['batch_size'])
                    ? max(1, min(1000, (int) $_POST['batch_size']))
                    : 300
            )
        );

        wp_safe_redirect(
            admin_url('admin.php?page=ua-backdoor-guard&settings=saved')
        );

        exit;
    }
}

/* =========================================================
 * DEFENSE TOGGLE
 * ======================================================= */

add_action('admin_post_uabg_toggle_defense', function() {

    if (!current_user_can('manage_options')) {
        wp_die('Access Denied');
    }

    check_admin_referer('uabg_toggle_defense');

    $current = get_option('uabg_active_defense', 1);

    update_option('uabg_active_defense', $current ? 0 : 1);

    wp_safe_redirect(
        admin_url('admin.php?page=ua-backdoor-guard&defense=toggled')
    );

    exit;
});

/* =========================================================
 * START
 * ======================================================= */

register_activation_hook(
    __FILE__,
    array('UA_Backdoor_Guard', 'activate')
);

UA_Backdoor_Guard::init();
