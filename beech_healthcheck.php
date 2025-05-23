<?php
/**
 * Plugin Name: Beech Healthcheck Collector
 * Description: Collects health data from remote WordPress sites and logs it for monitoring.
 * Version: 0.1
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

//$logger = new Beech_Lumberack();
//$logger->log('Lumberjack Activated!');

// ==== Plugin Activation: Create DB Table + Schedule Cron ====
register_activation_hook(__FILE__, function () {
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
        raw JSON
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    if (!wp_next_scheduled('bhc_hourly_event')) {
        wp_schedule_event(time(), 'hourly', 'bhc_hourly_event');
    }
});

// Unschedule on deactivation
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('bhc_hourly_event');
});

add_action('bhc_hourly_event', 'bhc_fetch_all_sites');

// ==== Admin Menu and Settings ====
add_action('admin_menu', function () {
    add_menu_page('Healthcheck Collector', 'Healthcheck', 'manage_options', 'bhc-dashboard', 'bhc_render_dashboard');
    add_submenu_page('bhc-dashboard', 'Settings', 'Settings', 'manage_options', 'bhc-settings', 'bhc_render_settings');
    add_submenu_page(null, 'Site History', 'Site History', 'manage_options', 'bhc-history', 'bhc_render_history');
});

function bhc_render_settings() {
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

    ?>
    <div class="wrap">
        <h1>Healthcheck Settings</h1>
        <form method="post">
            <h2>Sites</h2>
            <table class="widefat" id="bhc-sites-table">
                <thead><tr><th><b>URL</b></th><th><b>Token</b></th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($sites as $site): ?>
                        <tr>
                            <td><input type="url" name="bhc_site_url[]" value="<?php echo esc_attr($site['url']); ?>" class="regular-text" required></td>
                            <td><input type="text" name="bhc_site_token[]" value="<?php echo esc_attr($site['token']); ?>" class="regular-text"></td>
                            <td><button type="button" class="button remove-row">Remove</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p><button type="button" class="button" id="add-site">Add Site</button></p>

            <h2>Webhook URL</h2>
            <p><input type="url" name="bhc_webhook_url" value="<?php echo esc_attr($webhook); ?>" size="70" class="regular-text"></p>

            <p><input type="submit" value="Save Settings" class="button button-primary"></p>
        </form>

        <hr>

        <h2>Manual Check</h2>
        <p><button type="button" id="bhc-run-manual-check" class="button button-secondary">Run Check Now</button></p>
        <div id="bhc-manual-check-status"></div>
    </div>

    <script>
    document.getElementById('add-site').addEventListener('click', function () {
        const table = document.querySelector('#bhc-sites-table tbody');
        const row = document.createElement('tr');
        row.innerHTML = `
            <td><input type="url" name="bhc_site_url[]" class="regular-text" required></td>
            <td><input type="text" name="bhc_site_token[]" class="regular-text"></td>
            <td><button type="button" class="button remove-row">Remove</button></td>
        `;
        table.appendChild(row);
    });

    document.getElementById('bhc-sites-table').addEventListener('click', function (e) {
        if (e.target.classList.contains('remove-row')) {
            e.target.closest('tr').remove();
        }
    });

    document.getElementById('bhc-run-manual-check').addEventListener('click', function () {
        const status = document.getElementById('bhc-manual-check-status');
        status.textContent = 'Running...';
        fetch(ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'bhc_run_manual_check',
                _ajax_nonce: '<?php echo wp_create_nonce('bhc_run_manual_check'); ?>'
            })
        })
        .then(response => response.json())
        .then(data => {
            status.textContent = data.success ? 'Check completed successfully.' : 'Error: ' + (data.data || 'Unknown');
        });
    });
    </script>
    <?php
}


add_action('wp_ajax_bhc_run_manual_check', function () {
    if (!current_user_can('manage_options') || !check_ajax_referer('bhc_run_manual_check', '', false)) {
        wp_send_json_error('Permission denied');
    }

    bhc_fetch_all_sites(); // this should run your check
    wp_send_json_success();
});

// ==== Dashboard UI ====
function bhc_render_dashboard() {
    global $wpdb;

    $latest = $wpdb->get_results(
        "SELECT * FROM " . BHC_TABLE_NAME . " a
         INNER JOIN (
           SELECT site_url, MAX(timestamp) AS max_ts
           FROM " . BHC_TABLE_NAME . "
           GROUP BY site_url
         ) b ON a.site_url = b.site_url AND a.timestamp = b.max_ts
         ORDER BY a.site_url"
    );

    $previous = [];
    foreach ($latest as $row) {
        $previous[$row->site_url] = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . BHC_TABLE_NAME . " WHERE site_url = %s AND timestamp < %s ORDER BY timestamp DESC LIMIT 1",
            $row->site_url, date('Y-m-d H:i:s', strtotime('-24 hours'))
        ));
    }

    echo '<div class="wrap"><h1>Healthcheck Dashboard</h1><table class="widefat fixed striped"><thead><tr>
        <th>Site</th>
        <th>Themes</th>
        <th>Plugins</th>
        <th>Admins</th>
        <th>Theme Name</th>
        <th>Timestamp</th>
    </tr></thead><tbody>';

    foreach ($latest as $row) {
        $prev = $previous[$row->site_url];

        $themes_diff = $prev ? $row->themes_installed - $prev->themes_installed : 0;
        $plugins_diff = $prev ? $row->plugins_installed - $prev->plugins_installed : 0;
        $admins_diff = $prev ? $row->users_admin - $prev->users_admin : 0;

        $diff_badge = function ($diff) {
            if ($diff > 0) return '<span style="color:red">↑' . $diff . '</span>';
            if ($diff < 0) return '<span style="color:green">↓' . abs($diff) . '</span>';
            return '<span style="color:gray">–</span>';
        };

        $link = admin_url('admin.php?page=bhc-history&site=' . urlencode($row->site_url));

        echo '<tr>';
        echo '<td><a href="' . esc_url($link) . '">' . esc_html($row->site_url) . '</a></td>';
        echo '<td>' . esc_html($row->themes_installed) . ' ' . $diff_badge($themes_diff) . '</td>';
        echo '<td>' . esc_html($row->plugins_installed) . ' ' . $diff_badge($plugins_diff) . '</td>';
        echo '<td>' . esc_html($row->users_admin) . ' ' . $diff_badge($admins_diff) . '</td>';
        echo '<td>' . esc_html($row->theme_active) . '</td>';
        echo '<td>' . esc_html($row->timestamp) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

function bhc_render_history() {
    if (!isset($_GET['site'])) {
        echo '<div class="wrap"><h1>Missing Site</h1></div>';
        return;
    }

    global $wpdb;
    $site = sanitize_text_field($_GET['site']);
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . BHC_TABLE_NAME . " WHERE site_url = %s ORDER BY timestamp DESC LIMIT 30", $site));

    echo '<div class="wrap"><h1>History for ' . esc_html($site) . '</h1>';
    echo '<table class="widefat fixed striped"><thead><tr>
        <th>Timestamp</th>
        <th>Themes</th>
        <th>Plugins</th>
        <th>Admins</th>
        <th>Theme Name</th>
    </tr></thead><tbody>';

    $prev = null;
    foreach ($rows as $row) {
        $themes_diff = $prev ? $row->themes_installed - $prev->themes_installed : 0;
        $plugins_diff = $prev ? $row->plugins_installed - $prev->plugins_installed : 0;
        $admins_diff = $prev ? $row->users_admin - $prev->users_admin : 0;

        $diff_badge = function ($diff) {
            if ($diff > 0) return '<span style="color:red">↑' . $diff . '</span>';
            if ($diff < 0) return '<span style="color:green">↓' . abs($diff) . '</span>';
            return '<span style="color:gray">–</span>';
        };

        echo '<tr>';
        echo '<td>' . esc_html($row->timestamp) . '</td>';
        echo '<td>' . esc_html($row->themes_installed) . ' ' . $diff_badge($themes_diff) . '</td>';
        echo '<td>' . esc_html($row->plugins_installed) . ' ' . $diff_badge($plugins_diff) . '</td>';
        echo '<td>' . esc_html($row->users_admin) . ' ' . $diff_badge($admins_diff) . '</td>';
        echo '<td>' . esc_html($row->theme_active) . '</td>';
        echo '</tr>';

        $prev = $row;
    }

    echo '</tbody></table></div>';
}

function bhc_fetch_all_sites() {
    global $wpdb;

    //Beech_Lumberack::quick_log('Fetching healthcheck data for all sites');

    $sites = get_option(BHC_OPTION_SITES, []);
    $webhook = get_option(BHC_OPTION_WEBHOOK, '');

    $batch_alerts = [];

    foreach ($sites as $site) {
        $site_url = $site['url'];
        $token = $site['token'];

        //Beech_Lumberack::quick_log("Fetching healthcheck URL". print_r($site_url, true));

        $url = rtrim($site_url, '/') . '/wp-json/beech/v1/health?token=' . urlencode($token);

        //Beech_Lumberack::quick_log("Fetching healthcheck data from ". print_r($url, true));

        $response = wp_remote_get($url, ['timeout' => 15]);

        if (is_wp_error($response)) {
            Beech_Lumberack::quick_log("Response FAILED v1 ". print_r($response->get_error_message(), true)); 
            error_log("[BHC] Failed to fetch $site_url: " . $response->get_error_message());

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
            //Beech_Lumberack::quick_log("Response FAILED v2 ". print_r($code, true)); 
            error_log("[BHC] Unexpected response code from $site_url: $code");

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

        // Extract values safely
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
            'raw' => wp_json_encode($data)
        ]);

        // Check delta
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . BHC_TABLE_NAME . " WHERE site_url = %s ORDER BY timestamp DESC LIMIT 2",
            $site_url
        ));

        if (count($rows) === 2) {
            $latest = $rows[0];
            $previous = $rows[1];

            $theme_diff = 5; //$latest->themes_installed - $previous->themes_installed;
            $plugin_diff = 11;// $latest->plugins_installed - $previous->plugins_installed;
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

    // Send all alerts in a single webhook call
    if (!empty($batch_alerts)) {
        bhc_notify_webhook($batch_alerts, 'Batch healthcheck summary');
    }
}


function bhc_notify_webhook(array $alerts = [], string $summary_message = 'Healthcheck alerts') {
    $webhook = get_option(BHC_OPTION_WEBHOOK, '');
    if (!$webhook || empty($alerts)) return;

    $payload = [
        'timestamp' => current_time('mysql'),
        'message' => $summary_message,
        'alerts' => $alerts, // Always an array of alert items
    ];

    //Beech_Lumberack::quick_log("webhook payload ". print_r($payload, true)); 

    wp_remote_post($webhook, [
        'timeout' => 10,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => wp_json_encode($payload),
    ]);
}