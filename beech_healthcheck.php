<?php
/**
 * Plugin Name: Beech Healthcheck Collector
 * Description: Collects health data from remote WordPress sites and logs it for monitoring.
 * Version: 0.4
 * Author: Josh Wayman | Beech Agency
 * Author URI: https://beech.agency
 */

// ==== Constants ====
define('BHC_OPTION_SITES', 'bhc_sites');
define('BHC_OPTION_WEBHOOK', 'bhc_webhook_url');
define('BHC_TABLE_NAME', $GLOBALS['wpdb']->prefix . 'bhc_site_data');


if( ! class_exists( 'BEECH_Updater' ) ){
	include_once( plugin_dir_path( __FILE__ ) . 'updater.php' );
}

$updater = new BEECH_Updater( __FILE__ );
$updater->set_username( 'BeechAgency' );
$updater->set_repository( 'beech_healthcheck' );
/*
	$updater->authorize( 'abcdefghijk1234567890' ); // Your auth code goes here for private repos
*/
$updater->initialize();

require 'lumberjack.php';

class Beech_Healthcheck {
    public function init() {
        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'on_activation'));
        register_deactivation_hook(__FILE__, array($this, 'on_deactivation'));

        // Cron event
        add_action('bhc_hourly_event', array($this, 'fetch_all_sites'));

        // Admin menu
        add_action('admin_menu', array($this, 'admin_menu'));

        // AJAX
        add_action('wp_ajax_bhc_run_manual_check', array($this, 'run_manual_check'));

        $this->logger = new Beech_Lumberack();
    }

    public function on_activation() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE " . BHC_TABLE_NAME . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            site_url VARCHAR(255) NOT NULL,
            timestamp DATETIME NOT NULL,
            themes_installed INT,
            plugins_installed INT,
            plugins_active INT,
            users_total INT,
            users_admin INT,
            theme_active VARCHAR(255),
            error TEXT,
            raw JSON
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Create index on (site_url, timestamp) if not exists
        $index_name = 'idx_site_url_timestamp';
        $index_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1)
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE table_schema = DATABASE()
               AND table_name = %s
               AND index_name = %s",
            BHC_TABLE_NAME,
            $index_name
        ));

        if (!$index_exists) {
            $wpdb->query("CREATE INDEX {$index_name} ON " . BHC_TABLE_NAME . " (site_url, timestamp)");
        }

        if (!wp_next_scheduled('bhc_hourly_event')) {
            wp_schedule_event(time(), 'hourly', 'bhc_hourly_event');
        }
    }

    public function on_deactivation() {
        wp_clear_scheduled_hook('bhc_hourly_event');
    }

    public function admin_menu() {
        add_menu_page('Healthcheck Collector', 'Healthcheck', 'manage_options', 'bhc-dashboard', array($this, 'render_dashboard'));
        add_submenu_page('bhc-dashboard', 'Settings', 'Settings', 'manage_options', 'bhc-settings', array($this, 'render_settings'));
        add_submenu_page(null, 'Site History', 'Site History', 'manage_options', 'bhc-history', array($this, 'render_history'));
    }

    public function render_settings() {
        if ($_POST && current_user_can('manage_options')) {
            $sites = [];
            if (isset($_POST['bhc_site_url']) && is_array($_POST['bhc_site_url'])) {
                foreach ($_POST['bhc_site_url'] as $i => $url) {
                    if (!empty($url)) {
                        $sites[] = [
                            'url' => esc_url_raw($url),
                            'token' => sanitize_text_field($_POST['bhc_site_token'][$i] ?? ''),
                        ];
                    }
                }
            }

            update_option(BHC_OPTION_SITES, $sites);
            update_option(BHC_OPTION_WEBHOOK, sanitize_text_field($_POST['bhc_webhook_url']));
            echo '<div class="updated"><p>Settings saved.</p></div>';
        }

        $sites = get_option(BHC_OPTION_SITES, []);
        $webhook = get_option(BHC_OPTION_WEBHOOK, '');

        include plugin_dir_path(__FILE__) . 'templates/view_settings.php';
    }

    public function run_manual_check() {
        if (!current_user_can('manage_options') || !check_ajax_referer('bhc_run_manual_check', '', false)) {
            wp_send_json_error('Permission denied');
        }
        $this->fetch_all_sites();
        wp_send_json_success();
    }

    public function render_dashboard() {
        include plugin_dir_path(__FILE__) . 'templates/view_dashboard.php';
    }

    public function render_history() {
        if (!isset($_GET['site'])) {
            echo '<div class="wrap"><h1>Missing Site</h1></div>';
            return;
        }

        include plugin_dir_path(__FILE__) . 'templates/view_history.php';
    }

    public function fetch_all_sites() {
        global $wpdb;

        $sites = get_option(BHC_OPTION_SITES, []);
        $webhook = get_option(BHC_OPTION_WEBHOOK, '');
        $batch_alerts = [];

        foreach ($sites as $site) {
            $site_url = $site['url'];
            $token = $site['token'];
            $url = rtrim($site_url, '/') . '/wp-json/beech/v1/health?token=' . urlencode($token);

            $response = $this->fetch_with_retry($url, 30);

            if (is_wp_error($response)) {
                error_log("[BHC] Failed to fetch $site_url: " . $response->get_error_message());

                $this->handle_fetch_error($site_url, $response->get_error_message());

                $batch_alerts[] = [
                    'site_url' => $site_url,
                    'type' => 'error',
                    'message' => 'Request failed',
                    'error' =>  $response->get_error_message()
                ];
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                error_log("[BHC] Unexpected response code from $site_url: $code");
                
                $this->handle_fetch_error($site_url, $code);

                $batch_alerts[] = [
                    'site_url' => $site_url,
                    'type' => 'error',
                    'message' => 'Unexpected response code',
                    'error' => $code
                ];
                continue;
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!$data || !is_array($data)) {
                error_log("[BHC] Invalid JSON from $site_url");
                continue;
            }

            $themes_installed  = $data['themes']['installed'] ?? 0;
            $plugins_installed = $data['plugins']['installed'] ?? 0;
            $plugins_active    = $data['plugins']['active'] ?? 0;
            $users_total       = $data['users']['total'] ?? 0;
            $users_admin       = $data['users']['by_role']['administrator'] ?? 0;
            $theme_stylesheet  = $data['themes']['active']['stylesheet'] ?? '';

            $wpdb->insert(BHC_TABLE_NAME, [
                'site_url' => $site_url,
                'timestamp' => current_time('mysql'),
                'themes_installed' => $themes_installed,
                'plugins_installed' => $plugins_installed,
                'plugins_active' => $plugins_active,
                'users_total' => $users_total,
                'users_admin' => $users_admin,
                'theme_active' => $theme_stylesheet,
                'error' => null,
                'raw' => wp_json_encode($data)
            ]);

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM " . BHC_TABLE_NAME . " WHERE site_url = %s ORDER BY timestamp DESC LIMIT 2",
                $site_url
            ));

            if (count($rows) === 2) {
                $latest = $rows[0];
                $previous = $rows[1];
                $theme_diff = $latest->themes_installed - $previous->themes_installed;
                $plugin_diff = $latest->plugins_installed - $previous->plugins_installed;
                $admin_diff = $latest->users_admin - $previous->users_admin;
                if ($theme_diff > 0 || $plugin_diff > 0 || $admin_diff > 0) {
                    $batch_alerts[] = [
                        'site_url' => $site_url,
                        'type' => 'delta',
                        'message' => 'Changes detected',
                        'details' => [
                            'themes' => $theme_diff,
                            'plugins' => $plugin_diff,
                            'admins' => $admin_diff
                        ]
                    ];
                }
            }
        }

        if (!empty($batch_alerts)) {
            $this->notify_webhook($batch_alerts, 'Batch healthcheck summary');
        }
    }

    public function handle_fetch_error( $site_url, $error ) {
        global $wpdb;

        $wpdb->insert(BHC_TABLE_NAME, [
            'site_url' => $site_url,
            'timestamp' => current_time('mysql'),
            'themes_installed' => null,
            'plugins_installed' => null,
            'plugins_active' => null,
            'users_total' => null,
            'users_admin' => null,
            'theme_active' => null,
            'error' => $error,
            'raw' => null
        ]);
    }

    private function notify_webhook(array $alerts = [], string $summary_message = 'Healthcheck alerts') {
        $webhook = get_option(BHC_OPTION_WEBHOOK, '');
        if (!$webhook || empty($alerts)) return;

        $payload = [
            'timestamp' => current_time('mysql'),
            'message' => $summary_message,
            'alerts' => $alerts,
        ];

        wp_remote_post($webhook, [
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload),
        ]);
    }

    /**
     * Fetch a URL with retry on cURL error 28.
     *
     * @param string $url
     * @param int $timeout
     * @return array|WP_Error
     */
    private function fetch_with_retry($url, $timeout = 30) {
        $args = [
            'headers' => [
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
            ],
            'timeout' => $timeout
        ];

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response) && strpos($response->get_error_message(), 'cURL error 28') !== false) {
            error_log("[BHC] Failed to fetch, will retry, for $url: " . $response->get_error_message());
            // Retry once after a short delay
            sleep(60);
            $response = wp_remote_get($url, $args);
        }

        return $response;
    }
}

$GLOBALS['beech_healthcheck'] = new Beech_Healthcheck();
$GLOBALS['beech_healthcheck']->init();
    