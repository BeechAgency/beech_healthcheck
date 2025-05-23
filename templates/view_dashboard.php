<?php 
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

$previous_data = $wpdb->get_results(
    "SELECT site_url,
            MAX(themes_installed) AS max_themes_installed,
            MAX(plugins_installed) AS max_plugins_installed,
            MAX(users_admin) AS max_users_admin
     FROM " . BHC_TABLE_NAME . "
     WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
       AND (site_url, timestamp) NOT IN (
           SELECT site_url, MAX(timestamp) 
           FROM " . BHC_TABLE_NAME . "
           GROUP BY site_url
       )
     GROUP BY site_url"
);

$previous = [];
foreach ($previous_data as $row) {
    $previous[$row->site_url] = $row;
}

?>
<style type="text/css">
    .card { margin-bottom : 2rem; margin-top: 2rem; }
    table.widefat thead th { font-weight: bold; }

    .circle {
        width: 1rem;
        height: 1rem;
        border-radius: 50%;
        display: block;
        margin: 0 auto;
        background: blue;
        animation: pulse 4s infinite ease;
        animation-direction: alternate;
    }
    .circle.ok {
        background: #00AF9B;

    }
    .circle.error {
        background: #FF5D36;
        animation-duration: 0.5s;
    }
    .circle.warning {
        background: #FFD140;
        animation-duration: 2s;
    }
    .status-column {
        width: 4.5rem;
        text-align: center;
    }

    @keyframes pulse {
        0% {
            transform: scale(0.90);
        }
        70% {
            transform: scale(1.1);
        }
        100% {
            transform: scale(0.90);
        }
    }
</style>
<?php
echo '<div class="wrap">
		<h1 class="wp-heading-inline">Beech Healthcheck Dashboard</h1>
		<div class="card">
			<p>View the healthcheck history for the last 24 hours. Click an item to view the full history for that site.</p>
		</div>
    <table class="widefat fixed striped"><thead><tr>
    <th>Site</th>
    <th class="status-column"></th>
    <th>Themes</th>
    <th>Plugins</th>
    <th>Admins</th>
    <th>Theme Name</th>
    <th>Timestamp</th>
</tr></thead><tbody>';

foreach ($latest as $row) {
    $prev = isset($previous[$row->site_url]) ? $previous[$row->site_url] : null;

    $themes_diff = $prev ? $row->themes_installed - $prev->max_themes_installed : 0;
    $plugins_diff = $prev ? $row->plugins_installed - $prev->max_plugins_installed : 0;
    $admins_diff = $prev ? $row->users_admin - $prev->max_users_admin : 0;

    $diff_badge = function ($diff) {
        if ($diff > 0) return '<span style="color:#FF5D36">↑' . $diff . '</span>';
        if ($diff < 0) return '<span style="color:#00AF9B;">↓' . abs($diff) . '</span>';
        return '<span style="color:gray; opacity: 0.5;">–</span>';
    };

    $link = admin_url('admin.php?page=bhc-history&site=' . urlencode($row->site_url));

    echo '<tr>';
    echo '<td><a href="' . esc_url($link) . '">' . esc_html($row->site_url) . '</a></td>';

    if( !empty($row->error) ) {

        $error_message = '';
        switch($row->error) {
            case '401': 
                $error_message = ' - Most likely a configuration error. Check the token.';
                break;
            case '403':
                $error_message = ' - Site is private. Is beech_login installed?';
                break;
            case '404':
                $error_message = ' - Not found. Weird. Is beech_login installed?';
                break;
            case '500':
                $error_message = ' - Most likely a server error. Investigate.';
                break;
        }
        echo '<td class="status"><span class="circle error"></span></td>';
        echo '<td colspan="4"><b>ERROR: </b>' . esc_html($row->error) . $error_message. '</td>';
    } else {
        $status = 'ok';

        if ($themes_diff > 0) $status = 'error';
        if ($plugins_diff > 0) $status = 'warning';
        if ($admins_diff > 0) $status = 'warning';

        echo '<td class="status"><span class="circle '.$status.'"></span></td>';
        echo '<td>' . esc_html($row->themes_installed) . ' ' . $diff_badge($themes_diff) . '</td>';
        echo '<td>' . esc_html($row->plugins_installed) . ' ' . $diff_badge($plugins_diff) . '</td>';
        echo '<td>' . esc_html($row->users_admin) . ' ' . $diff_badge($admins_diff) . '</td>';
        echo '<td>' . esc_html($row->theme_active) . '</td>';
    }
    
    echo '<td>' . esc_html($row->timestamp) . '</td>';
    echo '</tr>';
}

echo '</tbody></table></div>';
?>