<?php
/**
 * Plugin Name: UA Backdoor Guard
 * Description: Локальна діагностика WordPress з автоматичним скануванням.
 * Version: 2.1.0
 * Author: UA Tools
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit;

final class UA_Backdoor_Guard {
    const VERSION = '2.1.0';
    const OPTION_REPORT     = 'uabg_last_report';
    const OPTION_SETTINGS   = 'uabg_settings';
    const OPTION_SCAN_STATE = 'uabg_scan_state';
    const QUARANTINE_DIR_NAME = 'ua-bg-quarantine';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_post_uabg_scan_start', array(__CLASS__, 'handle_scan_start'));
        add_action('wp_ajax_uabg_ajax_scan', array(__CLASS__, 'handle_ajax_scan'));
        add_action('admin_post_uabg_quarantine', array(__CLASS__, 'handle_quarantine'));
        add_action('admin_post_uabg_restore', array(__CLASS__, 'handle_restore'));
        add_action('admin_post_uabg_save_settings', array(__CLASS__, 'handle_settings'));
        add_action('admin_post_uabg_export', array(__CLASS__, 'handle_export'));
    }

    public static function activate() {
        if (!get_option(self::OPTION_SETTINGS)) {
            add_option(self::OPTION_SETTINGS, array(
                'scan_limit' => 30000,
                'batch_size' => 300,
            ));
        }
    }

    public static function admin_menu() {
        add_management_page('UA Backdoor Guard', 'UA Backdoor Guard', 'manage_options',
            'ua-backdoor-guard', array(__CLASS__, 'render_page'));
    }

    private static function settings() {
        return wp_parse_args(get_option(self::OPTION_SETTINGS, array()), array(
            'scan_limit' => 30000,
            'batch_size' => 300,
        ));
    }

    private static function quarantine_dir() {
        return trailingslashit(WP_CONTENT_DIR) . self::QUARANTINE_DIR_NAME;
    }

    private static function ensure_quarantine_dir() {
        $dir = self::quarantine_dir();
        if (!is_dir($dir)) wp_mkdir_p($dir);
        if (is_dir($dir)) {
            if (!file_exists($dir . '/index.php')) file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
            if (!file_exists($dir . '/.htaccess')) file_put_contents($dir . '/.htaccess', "Deny from all\n");
            if (!file_exists($dir . '/web.config')) file_put_contents($dir . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>');
        }
        return is_dir($dir) && is_writable($dir);
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) wp_die('Недостатньо прав.');

        $report = get_option(self::OPTION_REPORT, array());
        $settings = self::settings();
        $quarantine_files = self::list_quarantine_files();

        echo '<div class="wrap">';
        echo '<h1>UA Backdoor Guard <small>v' . esc_html(self::VERSION) . '</small></h1>';
        echo '<div class="notice notice-error"><p><strong>Увага:</strong> якщо на сайті вже виявлено бекдори, спочатку виконайте аварійні дії: збережіть докази, змініть всі паролі, змініть солі, обмежте доступ.</p></div>';

        // Стан сканування
        $state = get_option(self::OPTION_SCAN_STATE, array());
        $is_running = !empty($state['running']);
        
        echo '<div id="uabg-progress-wrap" style="margin:20px 0; display:' . ($is_running ? 'block' : 'none') . ';">';
        echo '<div style="background:#f0f0f1; border:1px solid #c3c4c7; height:20px; width:100%; max-width:600px; border-radius:3px; overflow:hidden;">';
        echo '<div id="uabg-progress-bar" style="background:#2271b1; height:100%; width:0%; transition:width 0.3s;"></div>';
        echo '</div>';
        echo '<p id="uabg-progress-text" style="margin-top:5px; font-weight:bold;">Обробка файлів...</p>';
        echo '</div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uabg_scan_start">';
        wp_nonce_field('uabg_scan_start');
        submit_button('Запустити автоматичне сканування', 'primary', 'submit', false, array('id' => 'uabg-start-btn', 'style' => $is_running ? 'display:none;' : ''));
        echo '</form>';

        if (!empty($report['created'])) {
            echo '<hr><h2>Останній звіт (від ' . esc_html($report['created']) . ')</h2>';
            echo '<p><strong>Перевірено файлів:</strong> ' . intval($report['files_scanned']) . ' | <strong>Критичних/підозрілих:</strong> ' . intval(count($report['findings'])) . '</p>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:12px">';
            echo '<input type="hidden" name="action" value="uabg_export">';
            wp_nonce_field('uabg_export');
            submit_button('Експортувати повний звіт (JSON)', 'secondary', 'submit', false);
            echo '</form>';

            self::render_findings($report['findings']);
            self::render_summary_section('Користувачі з правами адміністратора', $report['admins']);
            self::render_summary_section('Підозрілі WP-Cron', $report['cron']);
            self::render_summary_section('Підозрілі записи бази даних', $report['database']);
            self::render_summary_section('Журнали', $report['logs']);
            self::render_summary_section('Примітки', $report['notes']);
        }

        echo '<hr><h2>Карантин</h2>';
        if (empty($quarantine_files)) {
            echo '<p>Порожньо.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Файл</th><th>Розмір</th><th>Дія</th></tr></thead><tbody>';
            foreach ($quarantine_files as $q) {
                echo '<tr><td>' . esc_html($q['name']) . '</td><td>' . size_format($q['size']) . '</td><td>';
                echo '<form style="display:inline" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="uabg_restore"><input type="hidden" name="file" value="' . esc_attr($q['name']) . '">';
                wp_nonce_field('uabg_restore_' . $q['name']);
                submit_button('Відновити', 'secondary small', 'submit', false);
                echo '</form></td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<hr><h2>Налаштування</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uabg_save_settings">';
        wp_nonce_field('uabg_save_settings');
        echo '<p><label>Максимум файлів: <input type="number" min="500" max="50000" name="scan_limit" value="' . esc_attr($settings['scan_limit']) . '"></label></p>';
        echo '<p><label>Розмір пакета (для AJAX): <input type="number" min="100" max="2000" name="batch_size" value="' . esc_attr($settings['batch_size']) . '"></label></p>';
        submit_button('Зберегти');
        echo '</form></div>';

        // AJAX JS
        if ($is_running) {
            ?>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                var offset = <?php echo intval($state['offset']); ?>;
                var total = <?php echo intval($state['total']); ?>;
                var batchSize = <?php echo intval($settings['batch_size']); ?>;
                var progressBar = document.getElementById('uabg-progress-bar');
                var progressText = document.getElementById('uabg-progress-text');

                function doBatch() {
                    var data = {
                        action: 'uabg_ajax_scan',
                        nonce: '<?php echo wp_create_nonce('uabg_ajax_scan'); ?>',
                        batch_size: batchSize
                    };
                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: new URLSearchParams(data)
                    })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            offset = res.data.offset;
                            total = res.data.total;
                            var pct = Math.min(100, Math.round((offset / total) * 100));
                            progressBar.style.width = pct + '%';
                            progressText.innerText = 'Оброблено ' + offset + ' з ' + total + ' файлів (' + pct + '%)';
                            if (res.data.running) {
                                setTimeout(doBatch, 100);
                            } else {
                                progressText.innerHTML = 'Сканування завершено! Оновіть сторінку.';
                                document.getElementById('uabg-start-btn').style.display = 'inline-block';
                            }
                        } else {
                            progressText.innerText = 'Помилка: ' + res.data;
                        }
                    })
                    .catch(err => {
                        progressText.innerText = 'Помилка мережі, повтор...';
                        setTimeout(doBatch, 3000);
                    });
                }
                doBatch();
            });
            </script>
            <?php
        }
    }

    private static function render_findings($findings) {
        if (empty($findings)) { echo '<p><strong>Підозрілих файлів не знайдено.</strong></p>'; return; }
        echo '<table class="widefat striped"><thead><tr><th>Рівень</th><th>Файл</th><th>Опис</th><th>Змінено</th><th>Дія</th></tr></thead><tbody>';
        foreach ($findings as $f) {
            echo '<tr>';
            echo '<td>' . self::severity_badge($f['severity']) . '</td>';
            echo '<td><code>' . esc_html($f['path']) . '</code></td>';
            echo '<td>' . esc_html($f['message']) . '</td>';
            echo '<td>' . esc_html(isset($f['modified']) ? $f['modified'] : '—') . '</td><td>';
            if (!empty($f['quarantinable']) && !empty($f['absolute']) && file_exists($f['absolute'])) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="uabg_quarantine">';
                echo '<input type="hidden" name="path" value="' . esc_attr($f['absolute']) . '">';
                wp_nonce_field('uabg_quarantine_' . hash('sha256', $f['absolute']));
                submit_button('Карантин', 'secondary small', 'submit', false);
                echo '</form>';
            } else {
                echo '—';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function severity_badge($sev) {
        $map = array(
            'critical' => array('#b32d2e', 'Критично'),
            'warning'  => array('#996800', 'Підозріло'),
            'info'     => array('#2271b1', 'Інформація'),
        );
        $m = isset($map[$sev]) ? $map[$sev] : array('#555', 'Невідомо');
        return '<span style="display:inline-block;padding:3px 7px;background:' . esc_attr($m[0]) . ';color:#fff;border-radius:3px">' . esc_html($m[1]) . '</span>';
    }

    private static function render_summary_section($title, $items) {
        echo '<h3>' . esc_html($title) . '</h3>';
        if (empty($items)) { echo '<p>Нічого підозрілого.</p>'; return; }
        echo '<ul style="list-style:disc;padding-left:22px">';
        foreach ($items as $item) {
            echo '<li>' . esc_html(is_array($item) ? wp_json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $item) . '</li>';
        }
        echo '</ul>';
    }

    public static function handle_scan_start() {
        if (!current_user_can('manage_options')) wp_die('Недостатньо прав.');
        check_admin_referer('uabg_scan_start');

        @set_time_limit(120);
        $settings = self::settings();
        $limit    = max(500, min(50000, intval($settings['scan_limit'])));

        // Швидкий збір файлів
        $all = array();
        $roots = array(ABSPATH, WP_CONTENT_DIR);
        foreach ($roots as $root) {
            if (!is_dir($root) || !is_readable($root)) continue;
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
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
            'created'       => current_time('mysql'),
            'files_scanned' => 0,
            'truncated'     => (count($all) >= $limit),
            'findings'      => array(),
            'admins'        => self::scan_admins(),
            'cron'          => self::scan_cron(),
            'database'      => self::scan_database(),
            'logs'          => self::inspect_logs(),
            'notes'         => self::environment_notes(),
        );
        update_option(self::OPTION_REPORT, $report, false);

        $state = array(
            'running' => true,
            'paths'   => $paths,
            'offset'  => 0,
            'total'   => count($paths),
        );
        update_option(self::OPTION_SCAN_STATE, $state, false);

        wp_safe_redirect(admin_url('tools.php?page=ua-backdoor-guard&scan=started'));
        exit;
    }

    public static function handle_ajax_scan() {
        if (!current_user_can('manage_options')) wp_send_json_error('Недостатньо прав.');
        check_ajax_referer('uabg_ajax_scan', 'nonce');

        @set_time_limit(120);
        $state = get_option(self::OPTION_SCAN_STATE, array());
        if (empty($state['running'])) {
            wp_send_json_success(array('running' => false, 'offset' => 0, 'total' => 0));
        }

        $report = get_option(self::OPTION_REPORT, array());
        $paths  = $state['paths'];
        $offset = intval($state['offset']);
        $batch  = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 300;
        $end    = min($offset + $batch, $state['total']);

        for ($i = $offset; $i < $end; $i++) {
            $real = $paths[$i];
            if (!is_file($real) || !is_readable($real)) continue;
            $report['files_scanned']++;
            $result = self::inspect_file($real);
            if ($result) {
                foreach ($result as $f) $report['findings'][] = $f;
            }
        }

        $state['offset'] = $end;
        if ($end >= $state['total']) $state['running'] = false;

        usort($report['findings'], function($a, $b) {
            $rank = array('critical' => 0, 'warning' => 1, 'info' => 2);
            return ($rank[$a['severity']] ?? 2) - ($rank[$b['severity']] ?? 2);
        });

        update_option(self::OPTION_REPORT, $report, false);
        update_option(self::OPTION_SCAN_STATE, $state, false);

        wp_send_json_success(array(
            'running' => $state['running'],
            'offset'  => $state['offset'],
            'total'   => $state['total'],
        ));
    }

    private static function inspect_file($path) {
        $relative = self::relative_path($path);
        $name     = basename($path);
        $ext      = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $size     = @filesize($path);
        $mtime    = @filemtime($path);
        $findings = array();

        if ($ext === 'php' && self::is_under_uploads($path)) {
            $findings[] = self::finding('critical', $relative, 'PHP-файл у uploads — типова ознака завантаженого бекдору.', $path, true);
        }

        if ($ext === 'php' && self::is_in_wp_root($path)) {
            $known = array('index.php','wp-config.php','wp-load.php','wp-settings.php','wp-blog-header.php','wp-cron.php','wp-signup.php','wp-activate.php','wp-comments-post.php','wp-links-opml.php','wp-login.php','wp-mail.php','wp-trackback.php','xmlrpc.php','wp-config-sample.php');
            if (!in_array($name, $known, true) && !preg_match('/^(wp-|index\.php|xmlrpc)/', $name)) {
                $findings[] = self::finding('critical', $relative, 'Невідомий PHP-файл у корені WordPress.', $path, true);
            }
        }

        if (preg_match('/^\.(?!well-known)/', $name) && !in_array($name, array('.htaccess','.user.ini'), true)) {
            $findings[] = self::finding('warning', $relative, 'Прихований файл.', $path, $ext === 'php');
        }

        if (in_array($ext, array('zip','phar','tar','gz','tgz','bz2','xz'), true) && $size > 0 && $size <= 20 * 1024 * 1024) {
            if (self::archive_contains_php($path, $ext)) {
                $findings[] = self::finding('critical', $relative, 'Архів містить PHP-файли.', $path, true);
            }
        }

        if ($ext === 'php' && $size > 0 && $size <= 4 * 1024 * 1024 && is_readable($path)) {
            $content = @file_get_contents($path);
            if (is_string($content)) {
                $is_core = preg_match('#^(wp-admin/|wp-includes/|wp-[a-z-]+\.php$|index\.php$|xmlrpc\.php$)#', $relative);

                foreach (self::php_signature_rules() as $label => $rule) {
                    if ($is_core && strpos($label, 'request') !== false) continue;
                    if (preg_match($rule['pattern'], $content)) {
                        $findings[] = self::finding($rule['severity'], $relative, 'Сигнатура: ' . $label . '. ' . $rule['hint'], $path, true);
                        break;
                    }
                }

                if (preg_match('/\$[^\x00-\x7F][\w\x{0400}-\x{04FF}\x{2018}-\x{201F}]*\s*=/u', $content)) {
                    $findings[] = self::finding('critical', $relative, 'Unicode-імена змінних.', $path, true);
                }

                $trim = ltrim($content);
                if (stripos($trim, '<?php') === 0 && preg_match('/\A<\?php\s*(?:\/\*.*?\*\/|\/\/[^\n]*\n)*\s*return\s*;/is', $trim)) {
                    $findings[] = self::finding('warning', $relative, 'Top-level return.', $path, true);
                }

                if (preg_match('/(FilesMan|WSO\s*Shell|c99shell|b374k|r57shell|php\s*spy)/i', $content)) {
                    $findings[] = self::finding('critical', $relative, 'Маркери веб-шелу.', $path, true);
                }

                if (preg_match('/[\'"]([A-Fa-f0-9]{800,}|[A-Za-z0-9+\/=]{800,})[\'"]/', $content)) {
                    $findings[] = self::finding('warning', $relative, 'Дуже довгий закодований рядок.', $path, true);
                }

                if (preg_match_all('/(?:https?:\/\/)([a-z0-9.-]+)/i', $content, $m)) {
                    $suspicious_tlds = '/\.(?:xyz|top|tk|ml|ga|cf|gq|pw|cc|ru|su)$/i';
                    foreach ($m[1] as $domain) {
                        if (preg_match($suspicious_tlds, $domain) && strpos($domain, 'wordpress.org') === false) {
                            $findings[] = self::finding('warning', $relative, 'Підозрілий зовнішній домен: ' . $domain, $path, true);
                            break;
                        }
                    }
                }
            }
        }

        if (in_array($name, array('wp-config.php','.htaccess','.user.ini','robots.txt'), true) && $mtime && $mtime > (time() - 30 * DAY_IN_SECONDS)) {
            $findings[] = self::finding('info', $relative, 'Конфіг змінювався <30 днів.', $path, false);
        }

        if (!$findings) return null;
        foreach ($findings as &$f) {
            $f['modified'] = $mtime ? date_i18n('Y-m-d H:i:s', $mtime) : 'невідомо';
            $f['size']     = $size ? size_format($size) : 'невідомо';
            $f['sha256']   = @hash_file('sha256', $path);
        }
        return $findings;
    }

    private static function php_signature_rules() {
        return array(
            'eval від змінної' => array(
                'severity' => 'critical',
                'pattern'  => <<<'REGEX'
/eval\s*\(\s*\$[a-zA-Z_]\w*\s*\)/i
REGEX
                ,
                'hint' => 'eval від проміжної змінної.',
            ),
            'hex2bin + eval' => array(
                'severity' => 'critical',
                'pattern'  => <<<'REGEX'
/hex2bin\s*\(.*?\).*?eval\s*\(/is
REGEX
                ,
                'hint' => 'Hex-обфускація + eval.',
            ),
            'curl_exec + eval' => array(
                'severity' => 'critical',
                'pattern'  => <<<'REGEX'
/curl_exec\s*\(.*?eval\s*\(/is
REGEX
                ,
                'hint' => 'Завантаження коду з мережі та виконання.',
            ),
            'file_get_contents + eval' => array(
                'severity' => 'critical',
                'pattern'  => <<<'REGEX'
/file_get_contents\s*\([^)]*https?:.*?eval\s*\(/is
REGEX
                ,
                'hint' => 'Виконання віддаленого коду.',
            ),
            'create_function + gzuncompress' => array(
                'severity' => 'critical',
                'pattern'  => <<<'REGEX'
/create_function\s*\(.*?gzuncompress/is
REGEX
                ,
                'hint' => 'Розшифрований payload.',
            ),
            'eval + base64/gzinflate/str_rot13' => array(
                'severity' => 'critical',
                'pattern'  => <<<'REGEX'
/eval\s*\(\s*(?:base64_decode|gzinflate|gzuncompress|str_rot13)\s*\(/i
REGEX
                ,
                'hint' => 'Класичний обфускований eval.',
            ),
            'assert + base64/gzinflate' => array(
                'severity' => 'critical',
                'pattern'  => <<<'REGEX'
/assert\s*\(\s*(?:base64_decode|gzinflate)\s*\(/i
REGEX
                ,
                'hint' => 'assert + decode.',
            ),
            'request → execution' => array(
                'severity' => 'critical',
                'pattern'  => <<<'REGEX'
/(eval|assert|system|shell_exec|passthru|proc_open|popen)\s*\(\s*\$_(?:GET|POST|REQUEST|COOKIE|SERVER)/i
REGEX
                ,
                'hint' => 'Пряме виконання даних запиту (RCE).',
            ),
            'variable function from request' => array(
                'severity' => 'critical',
                'pattern'  => <<<'REGEX'
/(?<![a-zA-Z0-9_\$>])\$[a-zA-Z_]\w*\s*\(\s*\$_(?:GET|POST|REQUEST|COOKIE)\s*\[/i
REGEX
                ,
                'hint' => 'Динамічний виклик функції з елемента суперглобальної.',
            ),
            'preg_replace /e' => array(
                'severity' => 'critical',
                'pattern'  => <<<'REGEX'
/preg_replace\s*\(\s*['"][^'"]*\/[a-z]*e[a-z]*['"]/i
REGEX
                ,
                'hint' => 'Застарілий /e модифікатор.',
            ),
            'CURLOPT_SSL_VERIFYPEER disabled' => array(
                'severity' => 'warning',
                'pattern'  => <<<'REGEX'
/CURLOPT_SSL_VERIFYPEER\s*=>\s*(?:0|false)/i
REGEX
                ,
                'hint' => 'Вимкнена перевірка SSL.',
            ),
            'obfuscated var-vars' => array(
                'severity' => 'warning',
                'pattern'  => <<<'REGEX'
/\$\$\w+|\$\{\s*\$\w+/
REGEX
                ,
                'hint' => 'Змінні змінних.',
            ),
            'shell execution' => array(
                'severity' => 'warning',
                'pattern'  => <<<'REGEX'
/\b(system|shell_exec|passthru|proc_open|popen|exec)\s*\(\s*['"][^'"]*['"]\s*\)/i
REGEX
                ,
                'hint' => 'Виконання shell-команд.',
            ),
        );
    }

    private static function archive_contains_php($path, $ext) {
        try {
            if ($ext === 'zip' && class_exists('ZipArchive')) {
                $z = new ZipArchive();
                if ($z->open($path) === true) {
                    for ($i = 0; $i < $z->numFiles; $i++) {
                        $n = $z->getNameIndex($i);
                        if ($n && preg_match('/\.(php\d?|phtml|phar)$/i', $n)) { $z->close(); return true; }
                    }
                    $z->close();
                }
            } elseif ($ext === 'phar') {
                return true;
            } elseif (in_array($ext, array('tar','gz','tgz','bz2','xz'), true)) {
                $fh = @fopen($path, 'rb');
                if ($fh) {
                    $chunk = fread($fh, 2 * 1024 * 1024);
                    fclose($fh);
                    if ($chunk !== false && strpos($chunk, '.php') !== false) return true;
                }
            }
        } catch (Exception $e) {}
        return false;
    }

    private static function finding($severity, $path, $message, $absolute, $quarantinable) {
        return array('severity' => $severity, 'path' => $path, 'message' => $message, 'absolute' => $absolute, 'quarantinable' => (bool) $quarantinable);
    }

    private static function relative_path($path) {
        $root = rtrim(ABSPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strpos($path, $root) === 0) return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root)));
        return $path;
    }

    private static function is_under_uploads($path) {
        $uploads = wp_upload_dir();
        $base = realpath($uploads['basedir']);
        $real = realpath($path);
        return $base && $real && strpos($real, $base . DIRECTORY_SEPARATOR) === 0;
    }

    private static function is_in_wp_root($path) {
        $root = rtrim(realpath(ABSPATH), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $real = realpath($path);
        if (!$real) return false;
        $rel = substr($real, strlen($root));
        return $rel !== false && strpos($rel, DIRECTORY_SEPARATOR) === false;
    }

    private static function scan_database() {
        global $wpdb;
        $items = array();
        $patterns = array(
            'iframe з зовнішнім джерелом' => '/<iframe[^>]+src=[\'"]https?:\/\//i',
            'зовнішній JavaScript'       => '/<script[^>]+src=[\'"]https?:\/\//i',
            'PHP-код у тексті'           => '/<\?php\s*(?:eval|assert|base64_decode|system)/i',
            'JavaScript redirect'        => '/(?:window|document)\.location\s*=|location\.href\s*=/i',
            'base64 eval у контенті'     => '/eval\s*\(\s*base64_decode/i',
            'curl + eval у контенті'     => '/curl_exec.*?eval\s*\(/is',
            'обфускований script'        => '/<script[^>]*>\s*eval\s*\(/i',
        );
        $jobs = array(
            array($wpdb->options,  'option_name', 'option_value', 2500),
            array($wpdb->postmeta, 'meta_key',    'meta_value',   2500),
            array($wpdb->posts,    'ID',          'post_content', 2500),
            array($wpdb->usermeta, 'meta_key',    'meta_value',   1000),
            array($wpdb->comments, 'comment_ID',  'comment_content', 1000),
        );
        foreach ($jobs as $job) {
            list($table, $col1, $col2, $limit) = $job;
            $c1 = esc_sql($col1); $c2 = esc_sql($col2); $lim = (int) $limit;
            $rows = $wpdb->get_results("SELECT `$c1` AS k, `$c2` AS v FROM `$table` ORDER BY `$c1` DESC LIMIT $lim", ARRAY_A);
            if (!is_array($rows)) continue;
            foreach ($rows as $row) {
                $value = (string) $row['v'];
                if ($value === '') continue;
                foreach ($patterns as $label => $pattern) {
                    if (preg_match($pattern, $value)) { $items[] = $table . ' / ' . $row['k'] . ': ' . $label; break; }
                }
                if (count($items) >= 200) { $items[] = 'Досягнуто ліміт.'; return $items; }
            }
        }
        return $items;
    }

    private static function scan_admins() {
        $items = array();
        $users = get_users(array('role' => 'administrator', 'fields' => array('ID','user_login','user_email','user_registered'), 'orderby' => 'user_registered', 'order' => 'DESC'));
        $threshold = time() - 14 * DAY_IN_SECONDS;
        foreach ($users as $u) {
            $reg = strtotime($u->user_registered);
            $flag = ($reg && $reg > $threshold) ? ' [НОВИЙ <14 днів]' : '';
            $items[] = sprintf('ID %d | %s | %s | створено: %s%s', $u->ID, $u->user_login, $u->user_email, $u->user_registered, $flag);
        }
        if (count($users) > 1) $items[] = 'Адміністраторів більше одного — перевірте кожен акаунт.';
        return $items;
    }

    private static function scan_cron() {
        $items = array();
        $cron = _get_cron_array();
        if (!is_array($cron)) return $items;
        $suspicious = '/(eval|base64|shell|exec|curl|wget|file_put_contents|assert|system|passthru|proc_open|hex2bin)/i';
        foreach ($cron as $timestamp => $hooks) {
            foreach ($hooks as $hook => $events) {
                if (preg_match($suspicious, $hook)) $items[] = 'Підозрілий hook: ' . $hook . ' | ' . date_i18n('Y-m-d H:i:s', $timestamp);
                foreach ($events as $event) {
                    if (!empty($event['args'])) {
                        $args = wp_json_encode($event['args']);
                        if ($args && preg_match($suspicious, $args)) $items[] = 'Підозрілі аргументи hook: ' . $hook;
                    }
                    if (isset($event['callback'])) {
                        $cb = $event['callback'];
                        $cb_str = is_array($cb) ? (is_object($cb[0]) ? get_class($cb[0]) : (string)$cb[0]) . '::' . $cb[1] : (string)$cb;
                        if (preg_match($suspicious, $cb_str)) $items[] = 'Підозрілий callback: ' . $cb_str;
                    }
                }
            }
        }
        return $items;
    }

    private static function inspect_logs() {
        $items = array();
        $candidates = array();
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $base = dirname(rtrim($_SERVER['DOCUMENT_ROOT'], '/\\'));
            $candidates[] = $base . '/logs/access.log';
            $candidates[] = $base . '/log/access.log';
            $candidates[] = $base . '/logs/error.log';
        }
        $candidates[] = ABSPATH . 'error_log';
        $candidates[] = WP_CONTENT_DIR . '/debug.log';
        $candidates[] = dirname(ABSPATH) . '/error_log';
        $found = false;
        $suspicious_php = '/\.(php\d?|phtml)\s+HTTP\/\d\.\d"\s+(?:200|302)/i';
        foreach (array_unique($candidates) as $c) {
            if (!is_file($c) || !is_readable($c)) continue;
            $found = true;
            $items[] = $c . ' (доступний)';
            $size = filesize($c);
            $fh = @fopen($c, 'rb');
            if (!$fh) continue;
            if ($size > 500000) fseek($fh, $size - 500000);
            $tail = stream_get_contents($fh);
            fclose($fh);
            if ($tail === false) continue;
            $hits = 0;
            foreach (preg_split('/\r?\n/', $tail) as $line) {
                if ($hits >= 15) break;
                if (preg_match($suspicious_php, $line) && preg_match('/\bPOST\b/i', $line)) { $items[] = 'POST до PHP: ' . substr($line, 0, 200); $hits++; }
            }
        }
        if (!$found) $items[] = 'Журнали не знайдені.';
        $items[] = 'Для точного часу та IP проникнення потрібні access/error logs від хостингу.';
        return $items;
    }

    private static function environment_notes() {
        global $wp_version;
        return array(
            'WordPress: ' . (isset($wp_version) ? $wp_version : 'невідомо'),
            'PHP: ' . PHP_VERSION,
            'Сканер не може достовірно визначити первинний вектор без журналів сервера.',
            'Час зміни файлу не дорівнює часу зараження.',
        );
    }

    public static function handle_quarantine() {
        if (!current_user_can('manage_options')) wp_die('Недостатньо прав.');
        $path = isset($_POST['path']) ? wp_unslash($_POST['path']) : '';
        check_admin_referer('uabg_quarantine_' . hash('sha256', $path));

        $real = realpath($path);
        $root = realpath(ABSPATH);
        if (!$real || !$root || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($real) || is_link($path)) wp_die('Недопустимий шлях.');
        if (strpos($real, self::quarantine_dir()) === 0) wp_die('Файл вже в карантині.');
        if (!self::ensure_quarantine_dir()) wp_die('Не вдалося створити каталог карантину.');

        $id = gmdate('Ymd-His') . '-' . substr(hash('sha256', $real), 0, 12);
        $dest = self::quarantine_dir() . '/' . $id . '.quarantined';
        $meta = array('original' => $real, 'quarantined' => $dest, 'sha256' => @hash_file('sha256', $real), 'time' => current_time('mysql'));
        if (!@rename($real, $dest)) wp_die('Не вдалося перемістити файл.');
        file_put_contents(self::quarantine_dir() . '/' . $id . '.json', wp_json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        wp_safe_redirect(admin_url('tools.php?page=ua-backdoor-guard&quarantined=1'));
        exit;
    }

    public static function handle_restore() {
        if (!current_user_can('manage_options')) wp_die('Недостатньо прав.');
        $file = isset($_POST['file']) ? sanitize_file_name(wp_unslash($_POST['file'])) : '';
        check_admin_referer('uabg_restore_' . $file);
        $qdir = self::quarantine_dir();
        $meta_file = $qdir . '/' . $file . '.json';
        if (!is_file($meta_file)) wp_die('Метадані не знайдені.');
        $meta = json_decode(file_get_contents($meta_file), true);
        if (empty($meta['original']) || empty($meta['quarantined']) || strpos($meta['quarantined'], $qdir . '/') !== 0) wp_die('Некоректні метадані.');
        $original = $meta['original'];
        $parent = dirname($original);
        if (!is_dir($parent) || !is_writable($parent) || file_exists($original)) wp_die('Не можна відновити.');
        if (!@rename($meta['quarantined'], $original)) wp_die('Не вдалося відновити.');
        @unlink($meta_file);
        wp_safe_redirect(admin_url('tools.php?page=ua-backdoor-guard&restored=1'));
        exit;
    }

    private static function list_quarantine_files() {
        $dir = self::quarantine_dir();
        $result = array();
        if (!is_dir($dir)) return $result;
        foreach (glob($dir . '/*.json') as $meta_file) {
            $meta = json_decode(@file_get_contents($meta_file), true);
            if (!is_array($meta) || empty($meta['quarantined']) || !is_file($meta['quarantined'])) continue;
            $result[] = array('name' => basename($meta_file, '.json'), 'size' => filesize($meta['quarantined']));
        }
        return $result;
    }

    public static function handle_export() {
        if (!current_user_can('manage_options')) wp_die('Недостатньо прав.');
        check_admin_referer('uabg_export');
        $report = get_option(self::OPTION_REPORT, array());
        if (empty($report)) wp_die('Звіт порожній.');
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="uabg-report-' . gmdate('Ymd-His') . '.json"');
        echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function handle_settings() {
        if (!current_user_can('manage_options')) wp_die('Недостатньо прав.');
        check_admin_referer('uabg_save_settings');
        $settings = array(
            'scan_limit' => isset($_POST['scan_limit']) ? max(500, min(50000, intval($_POST['scan_limit']))) : 30000,
            'batch_size' => isset($_POST['batch_size']) ? max(100, min(2000, intval($_POST['batch_size']))) : 300,
        );
        update_option(self::OPTION_SETTINGS, $settings, false);
        wp_safe_redirect(admin_url('tools.php?page=ua-backdoor-guard&settings=saved'));
        exit;
    }
}

register_activation_hook(__FILE__, array('UA_Backdoor_Guard', 'activate'));
UA_Backdoor_Guard::init();
