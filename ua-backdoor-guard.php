<?php
/**
 * Plugin Name: UA Backdoor Guard
 * Description: Локальна діагностика WordPress: пошук підозрілих файлів, змін, користувачів, cron-завдань і слідів можливого проникнення. Карантин виконується лише після підтвердження адміністратора.
 * Version: 1.0.0
 * Author: UA Tools
 * License: GPL-2.0-or-later
 * Text Domain: ua-backdoor-guard
 */

if (!defined('ABSPATH')) {
    exit;
}

final class UA_Backdoor_Guard {
    const VERSION = '1.0.0';
    const OPTION_REPORT = 'uabg_last_report';
    const OPTION_SETTINGS = 'uabg_settings';
    const QUARANTINE_DIR_NAME = 'ua-bg-quarantine';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_post_uabg_scan', array(__CLASS__, 'handle_scan'));
        add_action('admin_post_uabg_quarantine', array(__CLASS__, 'handle_quarantine'));
        add_action('admin_post_uabg_restore', array(__CLASS__, 'handle_restore'));
        add_action('admin_post_uabg_save_settings', array(__CLASS__, 'handle_settings'));
    }

    public static function activate() {
        if (!get_option(self::OPTION_SETTINGS)) {
            add_option(self::OPTION_SETTINGS, array(
                'scan_limit' => 5000,
                'auto_quarantine' => 0,
            ));
        }
    }

    public static function admin_menu() {
        add_management_page(
            'UA Backdoor Guard',
            'UA Backdoor Guard',
            'manage_options',
            'ua-backdoor-guard',
            array(__CLASS__, 'render_page')
        );
    }

    private static function settings() {
        $settings = get_option(self::OPTION_SETTINGS, array());
        return wp_parse_args($settings, array(
            'scan_limit' => 5000,
            'auto_quarantine' => 0,
        ));
    }

    private static function quarantine_dir() {
        return trailingslashit(WP_CONTENT_DIR) . self::QUARANTINE_DIR_NAME;
    }

    private static function ensure_quarantine_dir() {
        $dir = self::quarantine_dir();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if (is_dir($dir)) {
            if (!file_exists($dir . '/index.php')) {
                file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
            }
            if (!file_exists($dir . '/.htaccess')) {
                file_put_contents($dir . '/.htaccess', "Deny from all\n");
            }
            if (!file_exists($dir . '/web.config')) {
                file_put_contents($dir . '/web.config', "<?xml version=\"1.0\"?><configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>");
            }
        }
        return is_dir($dir) && is_writable($dir);
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостатньо прав.');
        }

        $report = get_option(self::OPTION_REPORT, array());
        $settings = self::settings();
        $quarantine_files = self::list_quarantine_files();

        echo '<div class="wrap">';
        echo '<h1>UA Backdoor Guard <small>v' . esc_html(self::VERSION) . '</small></h1>';
        echo '<p><strong>Режим:</strong> комбінований. Сканер шукає ознаки компрометації, але не видаляє файли автоматично. Підозрілі файли можна ізолювати вручну після перевірки.</p>';
        echo '<div class="notice notice-warning"><p><strong>Важливо:</strong> це діагностичний інструмент першої версії, а не гарантія виявлення всіх видів шкідливого ПЗ. Перед будь-якими діями зробіть резервну копію файлів і бази даних. Не запускайте очищення на єдиній копії сайту.</p></div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uabg_scan">';
        wp_nonce_field('uabg_scan');
        submit_button('Запустити повне сканування', 'primary', 'submit', false);
        echo '</form>';

        if (!empty($report['created'])) {
            echo '<hr><h2>Останній звіт</h2>';
            echo '<p><strong>Дата:</strong> ' . esc_html($report['created']) . ' | <strong>Перевірено файлів:</strong> ' . intval($report['files_scanned']) . ' | <strong>Знахідок:</strong> ' . intval(count($report['findings'])) . '</p>';
            if (!empty($report['truncated'])) {
                echo '<p class="notice notice-warning inline">Досягнуто ліміт сканування. Перевірено не всі файли.</p>';
            }
            self::render_findings($report['findings']);
            self::render_summary_section('Користувачі з правами адміністратора', $report['admins']);
            self::render_summary_section('Підозрілі заплановані завдання WP-Cron', $report['cron']);
            self::render_summary_section('Підозрілі записи бази даних', $report['database']);
            self::render_summary_section('Доступні журнали сервера', $report['logs']);
            self::render_summary_section('Інші сліди та обмеження', $report['notes']);
        }

        echo '<hr><h2>Карантин</h2>';
        echo '<p>Карантин зберігає файл поза його початковим шляхом. Не відновлюйте файл, якщо не впевнені, що він безпечний.</p>';
        if (empty($quarantine_files)) {
            echo '<p>Карантин порожній.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Файл</th><th>Розмір</th><th>Дія</th></tr></thead><tbody>';
            foreach ($quarantine_files as $qfile) {
                echo '<tr><td>' . esc_html($qfile['name']) . '</td><td>' . size_format($qfile['size']) . '</td><td>';
                echo '<form style="display:inline" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="uabg_restore"><input type="hidden" name="file" value="' . esc_attr($qfile['name']) . '">';
                wp_nonce_field('uabg_restore_' . $qfile['name']);
                submit_button('Відновити', 'secondary small', 'submit', false);
                echo '</form></td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<hr><h2>Налаштування сканування</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uabg_save_settings">';
        wp_nonce_field('uabg_save_settings');
        echo '<p><label>Максимум файлів за один запуск: <input type="number" min="500" max="30000" name="scan_limit" value="' . esc_attr($settings['scan_limit']) . '"></label></p>';
        echo '<p><label><input type="checkbox" name="auto_quarantine" value="1" ' . checked(1, intval($settings['auto_quarantine']), false) . '> Автоматично ізолювати файли з критичними ознаками</label></p>';
        echo '<p><em>Для першого тесту рекомендується залишити автоматичний карантин вимкненим. Сигнатури можуть збігатися з легальним кодом.</em></p>';
        submit_button('Зберегти налаштування');
        echo '</form>';
        echo '</div>';
    }

    private static function render_findings($findings) {
        if (empty($findings)) {
            echo '<p><strong>Підозрілих ознак не знайдено.</strong> Це не доводить, що сайт чистий.</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Рівень</th><th>Об’єкт</th><th>Опис</th><th>Дія</th></tr></thead><tbody>';
        foreach ($findings as $finding) {
            echo '<tr>';
            echo '<td>' . self::severity_badge($finding['severity']) . '</td>';
            echo '<td><code>' . esc_html($finding['path']) . '</code></td>';
            echo '<td>' . esc_html($finding['message']) . '</td><td>';
            if (!empty($finding['quarantinable']) && !empty($finding['absolute'])) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="uabg_quarantine">';
                echo '<input type="hidden" name="path" value="' . esc_attr($finding['absolute']) . '">';
                wp_nonce_field('uabg_quarantine_' . hash('sha256', $finding['absolute']));
                submit_button('У карантин', 'secondary small', 'submit', false);
                echo '</form>';
            } else {
                echo '—';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function severity_badge($severity) {
        $colors = array('critical' => '#b32d2e', 'warning' => '#996800', 'info' => '#2271b1');
        $labels = array('critical' => 'Критично', 'warning' => 'Підозріло', 'info' => 'Інформація');
        $color = isset($colors[$severity]) ? $colors[$severity] : '#555';
        $label = isset($labels[$severity]) ? $labels[$severity] : 'Невідомо';
        return '<span style="display:inline-block;padding:3px 7px;background:' . esc_attr($color) . ';color:#fff;border-radius:3px">' . esc_html($label) . '</span>';
    }

    private static function render_summary_section($title, $items) {
        echo '<h3>' . esc_html($title) . '</h3>';
        if (empty($items)) {
            echo '<p>Нічого підозрілого не виявлено або дані недоступні.</p>';
            return;
        }
        echo '<ul style="list-style:disc;padding-left:22px">';
        foreach ($items as $item) {
            echo '<li>' . esc_html(is_array($item) ? wp_json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $item) . '</li>';
        }
        echo '</ul>';
    }

    public static function handle_scan() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостатньо прав.');
        }
        check_admin_referer('uabg_scan');

        @set_time_limit(60);
        $settings = self::settings();
        $report = array(
            'created' => current_time('mysql'),
            'files_scanned' => 0,
            'truncated' => false,
            'findings' => array(),
            'admins' => self::scan_admins(),
            'cron' => self::scan_cron(),
            'database' => self::scan_database(),
            'logs' => self::inspect_logs(),
            'notes' => self::environment_notes(),
        );

        $roots = array(
            'WordPress root' => ABSPATH,
            'wp-content' => WP_CONTENT_DIR,
        );
        $seen = array();
        $limit = max(500, min(30000, intval($settings['scan_limit'])));
        foreach ($roots as $label => $root) {
            if (!is_dir($root) || !is_readable($root)) {
                $report['notes'][] = 'Каталог недоступний для читання: ' . $label;
                continue;
            }
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($iterator as $file) {
                    if (!$file->isFile() || $file->isLink()) {
                        continue;
                    }
                    $path = $file->getPathname();
                    $real = realpath($path);
                    if (!$real || isset($seen[$real])) {
                        continue;
                    }
                    $seen[$real] = true;
                    if (strpos($real, self::quarantine_dir()) === 0) {
                        continue;
                    }
                    if ($report['files_scanned'] >= $limit) {
                        $report['truncated'] = true;
                        break 2;
                    }
                    $report['files_scanned']++;
                    $finding = self::inspect_file($real);
                    if ($finding) {
                        $report['findings'][] = $finding;
                    }
                }
            } catch (Exception $e) {
                $report['notes'][] = 'Помилка читання каталогу ' . $label . ': ' . $e->getMessage();
            }
        }

        // Sort highest risk first.
        usort($report['findings'], function($a, $b) {
            $rank = array('critical' => 0, 'warning' => 1, 'info' => 2);
            return $rank[$a['severity']] - $rank[$b['severity']];
        });

        update_option(self::OPTION_REPORT, $report, false);
        wp_safe_redirect(admin_url('tools.php?page=ua-backdoor-guard&scan=done'));
        exit;
    }

    private static function inspect_file($path) {
        $relative = self::relative_path($path);
        $name = basename($path);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $size = @filesize($path);
        $mtime = @filemtime($path);
        $finding = null;

        if ($ext === 'php' && self::is_under_uploads($path)) {
            $finding = self::finding('critical', $relative, 'PHP-файл у каталозі uploads. Це часто небезпечно, якщо виконання PHP там не передбачене.', $path, true);
        }

        if (preg_match('/^\.(?!well-known)/', $name) && !in_array($name, array('.htaccess', '.user.ini'), true)) {
            $finding = self::finding('warning', $relative, 'Прихований файл. Перевірте його походження та вміст.', $path, $ext === 'php');
        }

        if ($ext === 'php' && $size > 0 && $size <= 2 * 1024 * 1024 && is_readable($path)) {
            $content = @file_get_contents($path, false, null, 0, 2 * 1024 * 1024);
            if (is_string($content)) {
                $checks = array(
                    'eval(base64_decode' => '/eval\s*\(\s*base64_decode\s*\(/i',
                    'eval(gzinflate' => '/eval\s*\(\s*gzinflate\s*\(/i',
                    'assert(base64_decode' => '/assert\s*\(\s*base64_decode\s*\(/i',
                    'variable function call' => '/\$[a-zA-Z_][a-zA-Z0-9_]*\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i',
                    'request-to-execution pattern' => '/(eval|assert|system|shell_exec|passthru|proc_open)\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i',
                    'long encoded payload' => '/base64_decode\s*\(\s*[\'"][A-Za-z0-9+\/=]{500,}[\'"]\s*\)/i',
                    'obfuscated variable variables' => '/\$\$[a-zA-Z_][a-zA-Z0-9_]*/',
                );
                foreach ($checks as $label => $pattern) {
                    if (preg_match($pattern, $content)) {
                        $severity = in_array($label, array('request-to-execution pattern', 'eval(base64_decode', 'eval(gzinflate'), true) ? 'critical' : 'warning';
                        $finding = self::finding($severity, $relative, 'Збіг сигнатури "' . $label . '". Це індикатор для ручної перевірки, не остаточний доказ вірусу.', $path, true);
                        break;
                    }
                }
                if (preg_match('/<\?php.{0,300}(?:FilesMan|WSO\s*Shell|c99shell|b374k)/is', $content)) {
                    $finding = self::finding('critical', $relative, 'Знайдені відомі маркери веб-шелу. Потрібне термінове підтвердження.', $path, true);
                }
            }
        }

        if (in_array($name, array('wp-config.php', '.htaccess', '.user.ini'), true) && $mtime && $mtime > (time() - 30 * DAY_IN_SECONDS)) {
            $note = self::finding('info', $relative, 'Важливий конфігураційний файл змінювався протягом останніх 30 днів. Перевірте історію змін.', $path, false);
            if (!$finding) {
                $finding = $note;
            }
        }

        if ($finding) {
            $finding['modified'] = $mtime ? date_i18n('Y-m-d H:i:s', $mtime) : 'невідомо';
            $finding['size'] = $size ? size_format($size) : 'невідомо';
            $finding['sha256'] = @hash_file('sha256', $path);
        }
        return $finding;
    }

    private static function finding($severity, $path, $message, $absolute, $quarantinable) {
        return array(
            'severity' => $severity,
            'path' => $path,
            'message' => $message,
            'absolute' => $absolute,
            'quarantinable' => (bool) $quarantinable,
        );
    }

    private static function relative_path($path) {
        $root = rtrim(ABSPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strpos($path, $root) === 0) {
            return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root)));
        }
        return $path;
    }

    private static function is_under_uploads($path) {
        $uploads = wp_upload_dir();
        $base = realpath($uploads['basedir']);
        $real = realpath($path);
        return $base && $real && strpos($real, $base . DIRECTORY_SEPARATOR) === 0;
    }

    private static function scan_admins() {
        $items = array();
        $users = get_users(array('role' => 'administrator', 'fields' => array('ID', 'user_login', 'user_email', 'user_registered')));
        foreach ($users as $user) {
            $items[] = sprintf(
                'ID %d | %s | %s | створено: %s',
                $user->ID,
                $user->user_login,
                $user->user_email,
                $user->user_registered
            );
        }
        if (count($users) > 1) {
            $items[] = 'Увага: адміністраторів більше одного. Це не обов’язково зловмисна дія — перевірте кожен обліковий запис.';
        }
        return $items;
    }

    private static function scan_cron() {
        $items = array();
        $cron = _get_cron_array();
        if (!is_array($cron)) {
            return $items;
        }
        $suspicious = '/(eval|base64|shell|exec|curl|wget|file_put_contents|assert|system)/i';
        foreach ($cron as $timestamp => $hooks) {
            foreach ($hooks as $hook => $events) {
                if (preg_match($suspicious, $hook)) {
                    $items[] = 'Підозріла назва hook: ' . $hook . ' | час: ' . date_i18n('Y-m-d H:i:s', $timestamp);
                }
                foreach ($events as $event) {
                    $args = isset($event['args']) ? wp_json_encode($event['args']) : '';
                    if ($args && preg_match($suspicious, $args)) {
                        $items[] = 'Підозрілі аргументи у hook: ' . $hook;
                    }
                }
            }
        }
        return $items;
    }

    private static function scan_database() {
        global $wpdb;
        $items = array();
        $patterns = array(
            'iframe з невідомим джерелом' => '/<iframe[^>]+src=[\'"]https?:\/\//i',
            'прихований javascript' => '/<script[^>]+src=[\'"]https?:\/\//i',
            'підозрілий PHP-код у тексті' => '/<\?php\s*(?:eval|assert|base64_decode)/i',
            'підозрілий redirect' => '/window\.location\s*=|document\.location\s*=/i',
        );

        $tables = array(
            $wpdb->options => array('option_name', 'option_value'),
            $wpdb->posts => array('ID', 'post_content'),
        );
        foreach ($tables as $table => $columns) {
            $col1 = esc_sql($columns[0]);
            $col2 = esc_sql($columns[1]);
            $rows = $wpdb->get_results("SELECT `$col1`, `$col2` FROM `$table` ORDER BY `$col1` DESC LIMIT 1500", ARRAY_A);
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                $value = isset($row[$columns[1]]) ? (string) $row[$columns[1]] : '';
                foreach ($patterns as $label => $pattern) {
                    if (preg_match($pattern, $value)) {
                        $items[] = $table . ' / ' . $row[$columns[0]] . ': ' . $label;
                        break;
                    }
                }
                if (count($items) >= 100) {
                    $items[] = 'Досягнуто ліміт відображення знахідок бази даних.';
                    return $items;
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
            $candidates[] = $base . '/logs';
            $candidates[] = $base . '/log';
        }
        $candidates[] = ABSPATH . 'error_log';
        $candidates[] = WP_CONTENT_DIR . '/debug.log';
        $candidates[] = dirname(ABSPATH) . '/error_log';

        $found = array();
        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                $found[] = $candidate . ' (доступний файл)';
            } elseif (is_dir($candidate) && is_readable($candidate)) {
                $found[] = $candidate . ' (доступний каталог; файли журналів не аналізувалися автоматично)';
            }
        }
        if (!$found) {
            $items[] = 'Журнали сервера не знайдені у стандартних доступних шляхах. Попросіть хостинг надати access/error logs.';
        } else {
            foreach ($found as $entry) {
                $items[] = $entry;
            }
            $items[] = 'Для встановлення IP, URL і часу першого проникнення потрібен окремий аналіз access/error logs від хостингу.';
        }
        return $items;
    }

    private static function environment_notes() {
        global $wp_version;
        $items = array();
        $items[] = 'WordPress: ' . (isset($wp_version) ? $wp_version : 'невідомо');
        $items[] = 'PHP: ' . PHP_VERSION;
        $items[] = 'Сканер не може достовірно визначити первинний вектор злому без серверних журналів і резервної копії до інциденту.';
        $items[] = 'Час зміни файлу не дорівнює гарантованому часу зараження: його можна змінити або підробити.';
        $items[] = 'Перевірка контрольних сум ядра WordPress і плагінів з офіційними пакетами буде розширена в наступній версії.';
        return $items;
    }

    public static function handle_quarantine() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостатньо прав.');
        }
        $path = isset($_POST['path']) ? wp_unslash($_POST['path']) : '';
        check_admin_referer('uabg_quarantine_' . hash('sha256', $path));

        $real = realpath($path);
        $root = realpath(ABSPATH);
        if (!$real || !$root || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($real) || is_link($path)) {
            wp_die('Недопустимий шлях до файлу.');
        }
        if (strpos($real, self::quarantine_dir()) === 0) {
            wp_die('Файл уже в карантині.');
        }
        if (!self::ensure_quarantine_dir()) {
            wp_die('Не вдалося створити захищений каталог карантину.');
        }

        $id = gmdate('Ymd-His') . '-' . substr(hash('sha256', $real), 0, 12);
        $destination = self::quarantine_dir() . '/' . $id . '.quarantined';
        $meta = array(
            'original' => $real,
            'quarantined' => $destination,
            'sha256' => @hash_file('sha256', $real),
            'time' => current_time('mysql'),
        );
        if (!@rename($real, $destination)) {
            wp_die('Не вдалося перемістити файл. Перевірте права доступу.');
        }
        file_put_contents(self::quarantine_dir() . '/' . $id . '.json', wp_json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        wp_safe_redirect(admin_url('tools.php?page=ua-backdoor-guard&quarantined=1'));
        exit;
    }

    public static function handle_restore() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостатньо прав.');
        }
        $file = isset($_POST['file']) ? sanitize_file_name(wp_unslash($_POST['file'])) : '';
        check_admin_referer('uabg_restore_' . $file);
        $qdir = self::quarantine_dir();
        $meta_file = $qdir . '/' . $file . '.json';
        if (!is_file($meta_file)) {
            wp_die('Метадані карантину не знайдені.');
        }
        $meta = json_decode(file_get_contents($meta_file), true);
        if (empty($meta['original']) || empty($meta['quarantined']) || strpos($meta['quarantined'], $qdir . '/') !== 0) {
            wp_die('Некоректні метадані карантину.');
        }
        $original = $meta['original'];
        $parent = dirname($original);
        if (!is_dir($parent) || !is_writable($parent) || file_exists($original)) {
            wp_die('Не можна відновити файл: каталог недоступний або файл уже існує.');
        }
        if (!@rename($meta['quarantined'], $original)) {
            wp_die('Не вдалося відновити файл.');
        }
        @unlink($meta_file);
        wp_safe_redirect(admin_url('tools.php?page=ua-backdoor-guard&restored=1'));
        exit;
    }

    private static function list_quarantine_files() {
        $dir = self::quarantine_dir();
        $result = array();
        if (!is_dir($dir)) {
            return $result;
        }
        foreach (glob($dir . '/*.json') as $meta_file) {
            $meta = json_decode(@file_get_contents($meta_file), true);
            if (!is_array($meta) || empty($meta['quarantined']) || !is_file($meta['quarantined'])) {
                continue;
            }
            $result[] = array(
                'name' => basename($meta_file, '.json'),
                'size' => filesize($meta['quarantined']),
            );
        }
        return $result;
    }

    public static function handle_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостатньо прав.');
        }
        check_admin_referer('uabg_save_settings');
        $settings = array(
            'scan_limit' => isset($_POST['scan_limit']) ? max(500, min(30000, intval($_POST['scan_limit']))) : 5000,
            'auto_quarantine' => 0, // Disabled in 1.0 until signature validation is stronger.
        );
        update_option(self::OPTION_SETTINGS, $settings, false);
        wp_safe_redirect(admin_url('tools.php?page=ua-backdoor-guard&settings=saved'));
        exit;
    }
}

register_activation_hook(__FILE__, array('UA_Backdoor_Guard', 'activate'));
UA_Backdoor_Guard::init();
