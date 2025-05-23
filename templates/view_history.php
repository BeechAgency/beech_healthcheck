<?php

global $wpdb;
$site = sanitize_text_field($_GET['site']);
$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . BHC_TABLE_NAME . " WHERE site_url = %s ORDER BY timestamp DESC LIMIT 120", $site));

$is_error_active = !empty($rows[0]->error) ? true : false;

$card_message = 'Everything is looking a OK with this one.';

$card_message = $is_error_active ? '<b>WE GOT A PROBLEM!</b><br />This site is having issues. Please check the logs.' : $card_message;
?>
<style type="text/css">
    .card { margin-bottom : 2rem; margin-top: 2rem; }
    table.widefat thead th { font-weight: bold; }
</style>
<?

echo '<div class="wrap"><h1>History for ' . esc_html($site) . '</h1>';
echo '<div class="card"><p>'.$card_message.'</p></div>';
echo '<table class="widefat fixed striped"><thead><tr>
    <th>Timestamp</th>
    <th>Themes</th>
    <th>Plugins</th>
    <th>Admins</th>
    <th>Theme Name</th>
</tr></thead><tbody>';

$prev = null;
$prev_values = null;

$diff_badge = function ($diff) {
    if ($diff > 0) return '<span style="color:#00AF9B">↓' . $diff . '</span>';
    if ($diff < 0) return '<span style="color:red">↑' . abs($diff) . '</span>';
    return '';// '<span style="color:gray">–</span>';
};

foreach ($rows as $row) {
    if ($prev !== null) {
        $themes_diff = $row->themes_installed - $prev_values['themes_installed'];
        $plugins_diff = $row->plugins_installed - $prev_values['plugins_installed'];
        $admins_diff = $row->users_admin - $prev_values['users_admin'];

        echo '<tr>';
        echo '<td>' . esc_html($prev->timestamp) . '</td>';

        if(!empty($prev->error)) {
            $error_message = '';
            switch($prev->error) {
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

            echo '<td colspan="4"><b>ERROR: </b>' . esc_html($prev->error) . $error_message.'</td>';
        } else {
            echo '<td>' . esc_html($prev->themes_installed) . ' ' . $diff_badge($themes_diff) . '</td>';
            echo '<td>' . esc_html($prev->plugins_installed) . ' ' . $diff_badge($plugins_diff) . '</td>';
            echo '<td>' . esc_html($prev->users_admin) . ' ' . $diff_badge($admins_diff) . '</td>';
            echo '<td>' . esc_html($prev->theme_active) . '</td>';
        }

        echo '</tr>';
    }
    $prev = $row;
    $prev_values = ['themes_installed' => $row->themes_installed, 'plugins_installed' => $row->plugins_installed, 'users_admin' => $row->users_admin];
}

if ($prev !== null) {
    echo '<tr>';
    echo '<td>' . esc_html($prev->timestamp) . '</td>';

    if(!empty($prev->error)) {
        $error_message = '';
        switch($prev->error) {
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

        echo '<td colspan="4"><b>ERROR: </b>' . esc_html($prev->error) . $error_message.'</td>';
    } else {
        echo '<td>' . esc_html($prev->themes_installed) . '</td>';
        echo '<td>' . esc_html($prev->plugins_installed) . '</td>';
        echo '<td>' . esc_html($prev->users_admin) . '</td>';
        echo '<td>' . esc_html($prev->theme_active) . '</td>';
    }

    echo '</tr>';
}

echo '</tbody></table></div>';

?>