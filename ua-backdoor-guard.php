<?php
/**
 * Plugin Name: UA Backdoor Guard
 * Plugin URI:  https://uaserver.pp.ua/ua-backdoor-guard/
 * Description: Потужний сканер та активний захист вашого сайту від бекдорів, шелів та зламів. Локальна діагностика файлів, бази даних та активний Firewall.
 * Version:     3.0.0
 * Author:      UAServer
 * Author URI:  https://uaserver.pp.ua
 * License:     GPL-2.0-or-later
 * Text Domain: ua-backdoor-guard
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

// =========================================================================
// 1. СИСТЕМА PRO ТА ЛІЦЕНЗУВАННЯ (Pay Box)
// =========================================================================
if ( ! function_exists( 'ua_backdoor_guard_is_pro' ) ) {
    function ua_backdoor_guard_hub() { return 'https://uaserver.pp.ua/wp-json/upb/v1/'; }
    function ua_backdoor_guard_domain() { return strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ); }

    function ua_backdoor_guard_state( $force = false ) {
        $c = get_option( 'ua_backdoor_guard_pro' );
        $c = is_array( $c ) ? $c : array( 'state' => 'free', 'expires' => 0, 'checked' => 0 );
        if ( $force || time() - (int) $c['checked'] > 6 * HOUR_IN_SECONDS ) {
            $r = wp_remote_get( add_query_arg( array( 'service' => 'ua-backdoor-guard', 'domain' => ua_backdoor_guard_domain() ), ua_backdoor_guard_hub() . 'status' ), array( 'timeout' => 8 ) );
            if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
                $j = json_decode( wp_remote_retrieve_body( $r ), true );
                if ( is_array( $j ) && isset( $j['state'] ) ) {
                    $c = array( 'state' => $j['state'], 'expires' => (int) $j['expires'], 'checked' => time() );
                    update_option( 'ua_backdoor_guard_pro', $c, false );
                }
            } elseif ( time() - (int) $c['checked'] > 7 * DAY_IN_SECONDS ) {
                $c['state'] = 'free';
            }
        }
        return $c;
    }

    function ua_backdoor_guard_is_pro() {
        $c = ua_backdoor_guard_state();
        return in_array( $c['state'], array( 'active', 'expiring', 'unlimited' ), true ) && ( ! $c['expires'] || $c['expires'] > time() );
    }

    function ua_backdoor_guard_activate_pro() {
        $r = wp_remote_post( ua_backdoor_guard_hub() . 'activate', array( 'timeout' => 15, 'body' => array(
            'service' => 'ua-backdoor-guard', 'domain' => ua_backdoor_guard_domain(), 'email' => get_option( 'admin_email' ), 'site_url' => home_url(),
        ) ) );
        if ( is_wp_error( $r ) ) return $r;
        $j = json_decode( wp_remote_retrieve_body( $r ), true );
        if ( 200 !== (int) wp_remote_retrieve_response_code( $r ) || ! is_array( $j ) ) {
            return new WP_Error( 'pay', isset( $j['message'] ) ? $j['message'] : 'Помилка сервера оплати' );
        }
        ua_backdoor_guard_state( true );
        return $j;
    }
}

// =========================================================================
// 2. АВТО-ОНОВЛЕННЯ З GITHUB
// =========================================================================
add_filter('pre_set_site_transient_update_plugins', 'uabg_check_github_update');
function uabg_check_github_update($transient) {
    if (empty($transient->checked)) return $transient;
    
    $repo = 'portallcomua/ua-backdoor-guard';
    $plugin_slug = 'ua-backdoor-guard/ua-backdoor-guard.php';
    
    // Робимо запит до API GitHub (можна закешувати)
    $response = wp_remote_get("https://api.github.com/repos/{$repo}/releases/latest", array(
        'headers' => array('User-Agent' => 'WordPress/' . get_bloginfo('version'))
    ));
    
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return $transient;
    }
    
    $release = json_decode(wp_remote_retrieve_body($response));
    if (empty($release->tag_name)) return $transient;
    
    $remote_version = ltrim($release->tag_name, 'v');
    
    if (version_compare($transient->checked[$plugin_slug], $remote_version, '<')) {
        $obj = new stdClass();
        $obj->slug = 'ua-backdoor-guard';
        $obj->plugin = $plugin_slug;
        $obj->new_version = $remote_version;
        $obj->url = 'https://uaserver.pp.ua/ua-backdoor-guard/';
        // Шукаємо zip-файл у релізі
        if (!empty($release->assets)) {
            foreach ($release->assets as $asset) {
                if (strpos($asset->browser_download_url, '.zip') !== false) {
                    $obj->package = $asset->browser_download_url;
                    break;
                }
            }
        }
        $transient->response[$plugin_slug] = $obj;
    }
    
    return $transient;
}

// =========================================================================
// 3. ГОЛОВНИЙ КЛАС ПЛАГІНА
// =========================================================================
final class UA_Backdoor_Guard {
    const VERSION = '3.0.0';
    const OPTION_REPORT     = 'uabg_last_report';
    const OPTION_SETTINGS   = 'uabg_settings';
    const OPTION_SCAN_STATE = 'uabg_scan_state';
    const OPTION_FREE_SCANS = 'uabg_free_scans';
    const QUARANTINE_DIR_NAME = 'ua-bg-quarantine';

    private static $i18n = array();

    public static function init() {
        add_action('plugins_loaded', array(__CLASS__, 'load_textdomain'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        
        // AJAX та форми сканера
        add_action('admin_post_uabg_scan_start', array(__CLASS__, 'handle_scan_start'));
        add_action('wp_ajax_uabg_ajax_scan', array(__CLASS__, 'handle_ajax_scan'));
        add_action('admin_post_uabg_quarantine', array(__CLASS__, 'handle_quarantine'));
        add_action('admin_post_uabg_restore', array(__CLASS__, 'handle_restore'));
        add_action('admin_post_uabg_save_settings', array(__CLASS__, 'handle_settings'));
        add_action('admin_post_uabg_export', array(__CLASS__, 'handle_export'));
        
        // Активний захист
        add_action('init', array(__CLASS__, 'active_defense'), 0);
        add_action('admin_init', array(__CLASS__, 'block_uploads_php'), 0);
    }

    public static function load_textdomain() {
        $locale = get_locale();
        $lang = (strpos($locale, 'uk') === 0) ? 'uk' : 'en';
        
        if ($lang === 'uk') {
            self::$i18n = array(
                'title' => 'UA Backdoor Guard',
                'scan_btn' => 'Запустити сканування',
                'scans_left' => 'Залишилося безкоштовних сканувань:',
                'pro_btn' => 'Отримати PRO (Необмежено)',
                'pro_active' => 'PRO активовано',
                'quarantine' => 'Карантин',
                'settings' => 'Налаштування',
                'scanned' => 'Перевірено файлів',
                'findings' => 'Знайдено загроз',
                'defense_active' => 'Активний захист',
                'defense_on' => 'Увімкнено',
                'defense_off' => 'Вимкнено',
            );
        } else {
            self::$i18n = array(
                'title' => 'UA Backdoor Guard',
                'scan_btn' => 'Start Scan',
                'scans_left' => 'Free scans left:',
                'pro_btn' => 'Get PRO (Unlimited)',
                'pro_active' => 'PRO is Active',
                'quarantine' => 'Quarantine',
                'settings' => 'Settings',
                'scanned' => 'Files scanned',
                'findings' => 'Threats found',
                'defense_active' => 'Active Defense',
                'defense_on' => 'Enabled',
                'defense_off' => 'Disabled',
            );
        }
    }

    private static function t($key) {
        return isset(self::$i18n[$key]) ? self::$i18n[$key] : $key;
    }

    public static function activate() {
        if (!get_option(self::OPTION_SETTINGS)) {
            add_option(self::OPTION_SETTINGS, array('scan_limit' => 30000, 'batch_size' => 300));
        }
        if (get_option(self::OPTION_FREE_SCANS) === false) {
            update_option(self::OPTION_FREE_SCANS, 5);
        }
    }

    public static function admin_menu() {
        // Додаємо іконку
        $icon = 'dashicons-shield';
        add_menu_page(
            self::t('title'),
            self::t('title'),
            'manage_options',
            'ua-backdoor-guard',
            array(__CLASS__, 'render_page'),
            $icon,
            80
        );
    }

    // ==================== АКТИВНИЙ ЗАХИСТ (FIREWALL) ====================
    
    public static function active_defense() {
        if (!get_option('uabg_active_defense', 1)) return;
        
        // 1. Блокування підозрілих запитів (XSS, SQLi, LFI)
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if (preg_match('/(?:union.*select|base64_decode|eval\(|<\?php|wp-config\.php)/i', $uri)) {
            self::log_attack('Suspicious URI: ' . $uri);
            wp_die('Access Denied. Security block.', '403 Forbidden', array('response' => 403));
        }

        // 2. Заборона доступу до конфігу
        if (strpos($uri, 'wp-config.php') !== false) {
            wp_die('Access Denied.', '403 Forbidden', array('response' => 403));
        }
    }

    public static function block_uploads_php() {
        if (!get_option('uabg_active_defense', 1)) return;
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        // Якщо це прямий доступ до php-файлу в uploads
        if (strpos($uri, '/wp-content/uploads/') !== false && preg_match('/\.(php\d?|phtml)$/i', $uri)) {
            self::log_attack('PHP in uploads: ' . $uri);
            wp_die('Access Denied. PHP execution in uploads is prohibited.', '403 Forbidden', array('response' => 403));
        }
    }

    private static function log_attack($msg) {
        $log = get_option('uabg_attack_log', array());
        array_unshift($log, date('Y-m-d H:i:s') . ' | ' . $msg . ' | IP: ' . $_SERVER['REMOTE_ADDR']);
        $log = array_slice($log, 0, 50); // Зберігаємо 50 останніх
        update_option('uabg_active_defense_log', $log);
    }

    // ==================== ІНТЕРФЕЙС ====================

    public static function render_page() {
        if (!current_user_can('manage_options')) wp_die('Access Denied');

        $is_pro = ua_backdoor_guard_is_pro();
        $free_scans = (int) get_option(self::OPTION_FREE_SCANS, 5);
        $report = get_option(self::OPTION_REPORT, array());
        $state = get_option(self::OPTION_SCAN_STATE, array());
        $settings = wp_parse_args(get_option(self::OPTION_SETTINGS, array()), array('scan_limit' => 30000, 'batch_size' => 300));
        $is_running = !empty($state['running']);

        $defense_on = get_option('uabg_active_defense', 1);

        echo '<div class="wrap">';
        echo '<h1 style="display:flex; align-items:center; gap:10px;"><span class="dashicons dashicons-shield" style="font-size:32px; width:32px; height:32px;"></span> ' . self::t('title') . ' <small>v' . esc_html(self::VERSION) . '</small></h1>';
        
        // Панель статусу PRO
        echo '<div style="background:#fff; border:1px solid #ccd0d4; padding:15px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; box-shadow:0 1px 1px rgba(0,0,0,.04);">';
        if ($is_pro) {
            echo '<span style="color:#067647; font-weight:bold; font-size:16px;"><span class="dashicons dashicons-yes-alt"></span> ' . self::t('pro_active') . '</span>';
        } else {
            echo '<span>' . self::t('scans_left') . ' <strong>' . $free_scans . '</strong></span>';
            echo '<a href="https://uaserver.pp.ua/ua-backdoor-guard/" target="_blank" class="button button-primary" style="background:#b32d2e; border-color:#b32d2e;">' . self::t('pro_btn') . '</a>';
        }
        echo '</div>';

        // Панель активного захисту
        echo '<div style="background:#fff; border:1px solid #ccd0d4; padding:15px; margin-bottom:20px;">';
        echo '<h3 style="margin-top:0;"><span class="dashicons dashicons-lock"></span> ' . self::t('defense_active') . ': ';
        echo $defense_on ? '<span style="color:green;">' . self::t('defense_on') . '</span>' : '<span style="color:red;">' . self::t('defense_off') . '</span>';
        echo '</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;">';
        echo '<input type="hidden" name="action" value="uabg_toggle_defense">';
        wp_nonce_field('uabg_toggle_defense');
        submit_button($defense_on ? 'Вимкнути захист' : 'Увімкнути захист', 'secondary small', 'submit', false);
        echo '</form>';
        echo '</div>';

        // Кнопка сканування
        if (!$is_pro && $free_scans <= 0 && !$is_running) {
            echo '<div class="notice notice-error"><p>Ліміт безкоштовних сканувань вичерпано. Придбайте PRO для продовження.</p></div>';
        } else {
            if ($is_running) {
                echo '<div id="uabg-progress-wrap" style="margin:20px 0;">';
                echo '<div style="background:#f0f0f1; border:1px solid #c3c4c7; height:20px; border-radius:3px; overflow:hidden;"><div id="uabg-progress-bar" style="background:#2271b1; height:100%; width:0%; transition:width 0.3s;"></div></div>';
                echo '<p id="uabg-progress-text" style="margin-top:5px; font-weight:bold;">Обробка...</p></div>';
            } else {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="uabg_scan_start">';
                wp_nonce_field('uabg_scan_start');
                submit_button(self::t('scan_btn'), 'primary', 'submit', false);
                echo '</form>';
            }
        }

        // Вивід звіту
        if (!empty($report['created'])) {
            echo '<hr><h2>Результати сканування</h2>';
            echo '<p><strong>' . self::t('scanned') . ':</strong> ' . intval($report['files_scanned']) . ' | <strong>' . self::t('findings') . ':</strong> ' . intval(count($report['findings'])) . '</p>';
            self::render_findings($report['findings']);
            self::render_summary_section('Адміністратори', $report['admins']);
            self::render_summary_section('Підозрілі записи БД', $report['database']);
        }

        // JS для AJAX
        if ($is_running) {
            ?>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                var offset = <?php echo intval($state['offset']); ?>;
                var total = <?php echo intval($state['total']); ?>;
                var progressBar = document.getElementById('uabg-progress-bar');
                var progressText = document.getElementById('uabg-progress-text');

                function doBatch() {
                    var data = { action: 'uabg_ajax_scan', nonce: '<?php echo wp_create_nonce('uabg_ajax_scan'); ?>', batch_size: <?php echo intval($settings['batch_size']); ?> };
                    fetch(ajaxurl, { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: new URLSearchParams(data) })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            offset = res.data.offset; total = res.data.total;
                            var pct = Math.min(100, Math.round((offset / total) * 100));
                            progressBar.style.width = pct + '%';
                            progressText.innerText = 'Оброблено ' + offset + ' з ' + total + ' (' + pct + '%)';
                            if (res.data.running) setTimeout(doBatch, 100);
                            else { progressText.innerHTML = 'Сканування завершено! Оновіть сторінку.'; setTimeout(() => location.reload(), 2000); }
                        } else { progressText.innerText = 'Помилка: ' + res.data; }
                    }).catch(() => setTimeout(doBatch, 3000));
                }
                doBatch();
            });
            </script>
            <?php
        }
        echo '</div>';
    }

    private static function render_findings($findings) {
        if (empty($findings)) { echo '<p>Підозрілих файлів не знайдено.</p>'; return; }
        echo '<table class="widefat striped"><thead><tr><th>Рівень</th><th>Файл</th><th>Опис</th><th>Дія</th></tr></thead><tbody>';
        foreach ($findings as $f) {
            echo '<tr><td>' . self::severity_badge($f['severity']) . '</td>';
            echo '<td><code>' . esc_html($f['path']) . '</code></td>';
            echo '<td>' . esc_html($f['message']) . '</td>';
            echo '<td>';
            if (!empty($f['quarantinable']) && !empty($f['absolute']) && file_exists($f['absolute'])) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="uabg_quarantine">';
                echo '<input type="hidden" name="path" value="' . esc_attr($f['absolute']) . '">';
                wp_nonce_field('uabg_quarantine_' . hash('sha256', $f['absolute']));
                submit_button('Карантин', 'secondary small', 'submit', false);
                echo '</form>';
            } else { echo '—'; }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function severity_badge($sev) {
        $map = array('critical' => array('#b32d2e', 'Критично'), 'warning' => array('#996800', 'Підозріло'), 'info' => array('#2271b1', 'Інфо'));
        $m = isset($map[$sev]) ? $map[$sev] : array('#555', 'Невідомо');
        return '<span style="padding:3px 7px;background:' . esc_attr($m[0]) . ';color:#fff;border-radius:3px">' . esc_html($m[1]) . '</span>';
    }

    private static function render_summary_section($title, $items) {
        echo '<h3>' . esc_html($title) . '</h3>';
        if (empty($items)) { echo '<p>Нічого.</p>'; return; }
        echo '<ul style="list-style:disc;padding-left:22px">';
        foreach ($items as $item) echo '<li>' . esc_html(is_array($item) ? json_encode($item) : $item) . '</li>';
        echo '</ul>';
    }

    // ==================== ОБРОБКА СКАНУВАННЯ ====================

    public static function handle_scan_start() {
        if (!current_user_can('manage_options')) wp_die('Access Denied');
        check_admin_referer('uabg_scan_start');

        $is_pro = ua_backdoor_guard_is_pro();
        $free_scans = (int) get_option(self::OPTION_FREE_SCANS, 5);
        
        if (!$is_pro && $free_scans <= 0) {
            wp_die('Ліміт безкоштовних сканувань вичерпано.', 'Ліміт', array('back_link' => true));
        }

        if (!$is_pro) {
            update_option(self::OPTION_FREE_SCANS, $free_scans - 1);
        }

        @set_time_limit(120);
        $settings = wp_parse_args(get_option(self::OPTION_SETTINGS, array()), array('scan_limit' => 30000, 'batch_size' => 300));
        $limit = max(500, min(50000, intval($settings['scan_limit'])));

        $all = array();
        $roots = array(ABSPATH, WP_CONTENT_DIR);
        foreach ($roots as $root) {
            if (!is_dir($root) || !is_readable($root)) continue;
            try {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
                foreach ($it as $file) {
                    if (!$file->isFile() || $file->isLink()) continue;
                    $real = realpath($file->getPathname());
                    if (!$real || strpos($real, self::quarantine_dir()) === 0) continue;
                    $all[$real] = true;
                    if (count($all) >= $limit) break 2;
                }
            } catch (Exception $e) {}
        }
        $paths = array_keys($all);

        $report = array(
            'created' => current_time('mysql'), 'files_scanned' => 0, 'truncated' => (count($all) >= $limit),
            'findings' => array(), 'admins' => self::scan_admins(), 'cron' => self::scan_cron(),
            'database' => self::scan_database(), 'logs' => self::inspect_logs(), 'notes' => self::environment_notes(),
        );
        update_option(self::OPTION_REPORT, $report, false);
        update_option(self::OPTION_SCAN_STATE, array('running' => true, 'paths' => $paths, 'offset' => 0, 'total' => count($paths)), false);
        
        self::process_batch($settings['batch_size']);
        wp_safe_redirect(admin_url('admin.php?page=ua-backdoor-guard&scan=started'));
        exit;
    }

    public static function handle_ajax_scan() {
        if (!current_user_can('manage_options')) wp_send_json_error('Access Denied');
        check_ajax_referer('uabg_ajax_scan', 'nonce');
        @set_time_limit(120);
        self::process_batch(isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 300);
        
        $state = get_option(self::OPTION_SCAN_STATE, array());
        wp_send_json_success(array('running' => !empty($state['running']), 'offset' => $state['offset'], 'total' => $state['total']));
    }

    private static function process_batch($batch) {
        $state = get_option(self::OPTION_SCAN_STATE, array());
        if (empty($state['running'])) return;
        $report = get_option(self::OPTION_REPORT, array());
        
        $end = min($state['offset'] + $batch, $state['total']);
        for ($i = $state['offset']; $i < $end; $i++) {
            $real = $state['paths'][$i];
            if (!is_file($real) || !is_readable($real)) continue;
            $report['files_scanned']++;
            $res = self::inspect_file($real);
            if ($res) { foreach ($res as $f) $report['findings'][] = $f; }
        }
        $state['offset'] = $end;
        if ($end >= $state['total']) $state['running'] = false;

        usort($report['findings'], function($a,$b) {
            $r = array('critical'=>0,'warning'=>1,'info'=>2);
            return ($r[$a['severity']]??2) - ($r[$b['severity']]??2);
        });
        update_option(self::OPTION_REPORT, $report, false);
        update_option(self::OPTION_SCAN_STATE, $state, false);
    }

    // ==================== ПЕРЕВІРКА ФАЙЛІВ ====================

    private static function inspect_file($path) {
        $relative = self::relative_path($path);
        $name = basename($path);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $size = @filesize($path);
        $findings = array();

        // Невідомий PHP у корені
        if ($ext === 'php' && self::is_in_wp_root($path)) {
            $known = array('index.php','wp-config.php','wp-load.php','wp-settings.php','wp-blog-header.php','wp-cron.php','wp-signup.php','wp-activate.php','wp-comments-post.php','wp-links-opml.php','wp-login.php','wp-mail.php','wp-trackback.php','xmlrpc.php','wp-config-sample.php');
            if (!in_array($name, $known, true) && !preg_match('/^(wp-|index\.php|xmlrpc)/', $name)) {
                $findings[] = self::finding('critical', $relative, 'Невідомий PHP-файл у корені.', $path, true);
            }
        }

        // PHP в uploads
        if ($ext === 'php' && self::is_under_uploads($path)) {
            $findings[] = self::finding('critical', $relative, 'PHP-файл у uploads.', $path, true);
        }

        // Статичний аналіз
        if ($ext === 'php' && $size > 0 && $size <= 4 * 1024 * 1024 && is_readable($path)) {
            $content = @file_get_contents($path);
            if (is_string($content)) {
                foreach (self::php_signature_rules() as $label => $rule) {
                    if (preg_match($rule['pattern'], $content)) {
                        $findings[] = self::finding($rule['severity'], $relative, 'Сигнатура: ' . $label, $path, true);
                        break;
                    }
                }
            }
        }
        return empty($findings) ? null : $findings;
    }

    private static function php_signature_rules() {
        return array(
            'eval від змінної' => array('severity' => 'critical', 'pattern' => '/eval\s*\(\s*\$[a-zA-Z_]\w*\s*\)/i'),
            'hex2bin + eval' => array('severity' => 'critical', 'pattern' => '/hex2bin\s*\(.*?\).*?eval\s*\(/is'),
            'curl_exec + eval' => array('severity' => 'critical', 'pattern' => '/curl_exec\s*\(.*?eval\s*\(/is'),
            'create_function' => array('severity' => 'critical', 'pattern' => '/create_function\s*\(.*?gzuncompress/is'),
            'request → execution' => array('severity' => 'critical', 'pattern' => '/(eval|assert|system|shell_exec|passthru)\s*\(\s*\$_(?:GET|POST|REQUEST|COOKIE)/i'),
        );
    }

    private static function finding($sev, $path, $msg, $abs, $quar) {
        return array('severity'=>$sev, 'path'=>$path, 'message'=>$msg, 'absolute'=>$abs, 'quarantinable'=>$quar);
    }

    private static function relative_path($path) {
        $root = rtrim(ABSPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return strpos($path, $root) === 0 ? str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root))) : $path;
    }

    private static function is_under_uploads($path) {
        $u = wp_upload_dir();
        $base = realpath($u['basedir']); $real = realpath($path);
        return $base && $real && strpos($real, $base . DIRECTORY_SEPARATOR) === 0;
    }

    private static function is_in_wp_root($path) {
        $root = rtrim(realpath(ABSPATH), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $real = realpath($path);
        return $real && strpos(substr($real, strlen($root)), DIRECTORY_SEPARATOR) === false;
    }

    // ==================== СКАНУВАННЯ БД ТА КОРИСТУВАЧІВ ====================

    private static function scan_database() {
        global $wpdb;
        $items = array();
        $patterns = array(
            'iframe' => '/<iframe[^>]+src=[\'"]https?:\/\//i',
            'JS' => '/<script[^>]+src=[\'"]https?:\/\//i',
            'PHP eval' => '/<\?php\s*(?:eval|assert|base64_decode|system)/i',
        );
        $jobs = array(
            array($wpdb->options, 'option_name', 'option_value', 1000),
            array($wpdb->postmeta, 'meta_key', 'meta_value', 1000),
            array($wpdb->posts, 'ID', 'post_content', 1000),
        );
        foreach ($jobs as $job) {
            list($table, $c1, $c2, $limit) = $job;
            $rows = $wpdb->get_results("SELECT `$c1` AS k, `$c2` AS v FROM `$table` ORDER BY `$c1` DESC LIMIT $limit", ARRAY_A);
            if (!$rows) continue;
            foreach ($rows as $row) {
                foreach ($patterns as $label => $pat) {
                    if (preg_match($pat, $row['v'])) {
                        $items[] = $table . ' / ' . $row['k'] . ': ' . $label;
                        break;
                    }
                }
                if (count($items) >= 50) return $items;
            }
        }
        return $items;
    }

    private static function scan_admins() {
        $items = array();
        $users = get_users(array('role' => 'administrator', 'fields' => array('ID','user_login','user_email','user_registered')));
        $threshold = time() - 14 * DAY_IN_SECONDS;
        foreach ($users as $u) {
            $reg = strtotime($u->user_registered);
            $flag = ($reg && $reg > $threshold) ? ' [НОВИЙ]' : '';
            $items[] = sprintf('ID %d | %s | %s | %s%s', $u->ID, $u->user_login, $u->user_email, $u->user_registered, $flag);
        }
        return $items;
    }

    private static function scan_cron() {
        $items = array();
        $cron = _get_cron_array();
        if (!is_array($cron)) return $items;
        $susp = '/(eval|base64|shell|exec|curl|wget|file_put_contents|assert|system|hex2bin)/i';
        foreach ($cron as $timestamp => $hooks) {
            foreach ($hooks as $hook => $events) {
                if (preg_match($susp, $hook)) $items[] = 'Hook: ' . $hook;
            }
        }
        return $items;
    }

    private static function inspect_logs() {
        return array('Перевірте access.log на сервері.');
    }

    private static function environment_notes() {
        global $wp_version;
        return array('WordPress: ' . $wp_version, 'PHP: ' . PHP_VERSION);
    }

    // ==================== КАРАНТИН ТА ІНШЕ ====================

    public static function handle_quarantine() {
        if (!current_user_can('manage_options')) wp_die('Access Denied');
        $path = isset($_POST['path']) ? wp_unslash($_POST['path']) : '';
        check_admin_referer('uabg_quarantine_' . hash('sha256', $path));

        $real = realpath($path);
        $root = realpath(ABSPATH);
        if (!$real || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) wp_die('Invalid path');

        $dir = self::quarantine_dir();
        if (!is_dir($dir)) wp_mkdir_p($dir);
        $id = gmdate('Ymd-His') . '-' . substr(hash('sha256', $real), 0, 8);
        $dest = $dir . '/' . $id . '.quarantined';
        
        if (@rename($real, $dest)) {
            file_put_contents($dir . '/' . $id . '.json', json_encode(array('orig' => $real, 'time' => time())));
            wp_safe_redirect(admin_url('admin.php?page=ua-backdoor-guard&quarantined=1'));
        } else {
            wp_die('Rename failed');
        }
        exit;
    }

    public static function handle_restore() {
        if (!current_user_can('manage_options')) wp_die('Access Denied');
        $file = isset($_POST['file']) ? sanitize_file_name($_POST['file']) : '';
        check_admin_referer('uabg_restore_' . $file);
        $qdir = self::quarantine_dir();
        $meta = json_decode(@file_get_contents($qdir . '/' . $file . '.json'), true);
        if ($meta && rename($qdir . '/' . $file . '.quarantined', $meta['orig'])) {
            unlink($qdir . '/' . $file . '.json');
            wp_safe_redirect(admin_url('admin.php?page=ua-backdoor-guard&restored=1'));
        } else wp_die('Restore failed');
        exit;
    }

    private static function list_quarantine_files() {
        $res = array();
        foreach (glob(self::quarantine_dir() . '/*.json') as $f) {
            $meta = json_decode(file_get_contents($f), true);
            if ($meta) $res[] = array('name' => basename($f, '.json'), 'size' => filesize(self::quarantine_dir() . '/' . basename($f, '.json') . '.quarantined'));
        }
        return $res;
    }

    public static function handle_export() {
        if (!current_user_can('manage_options')) wp_die('Access Denied');
        check_admin_referer('uabg_export');
        $report = get_option(self::OPTION_REPORT, array());
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="uabg-report.json"');
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function handle_settings() {
        if (!current_user_can('manage_options')) wp_die('Access Denied');
        check_admin_referer('uabg_save_settings');
        update_option(self::OPTION_SETTINGS, array(
            'scan_limit' => isset($_POST['scan_limit']) ? intval($_POST['scan_limit']) : 30000,
            'batch_size' => isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 300
        ));
        wp_safe_redirect(admin_url('admin.php?page=ua-backdoor-guard&settings=saved'));
        exit;
    }
}

// Додаємо обробник для перемикання захисту
add_action('admin_post_uabg_toggle_defense', function() {
    if (!current_user_can('manage_options')) wp_die('Access Denied');
    check_admin_referer('uabg_toggle_defense');
    $current = get_option('uabg_active_defense', 1);
    update_option('uabg_active_defense', $current ? 0 : 1);
    wp_safe_redirect(admin_url('admin.php?page=ua-backdoor-guard&defense=toggled'));
    exit;
});

register_activation_hook(__FILE__, array('UA_Backdoor_Guard', 'activate'));
UA_Backdoor_Guard::init();
