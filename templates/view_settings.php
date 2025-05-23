<?php
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