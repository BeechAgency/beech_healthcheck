# Beech Healthcheck Collector

A WordPress plugin that collects and monitors health data from remote WordPress sites in real-time.

## Overview

The Beech Healthcheck Collector is a centralized monitoring solution designed to collect health data from remote WordPress installations. It periodically fetches information about installed themes, plugins, active plugins, user counts, and admin users from monitored sites and stores this data in a local database for historical tracking and analysis.

### Key Features

- **Automated Health Monitoring**: Hourly scheduled collection of site health data via WordPress cron
- **Historical Data Logging**: Stores all collected data with timestamps for trend analysis
- **Dashboard Overview**: Visual interface to view current status of all monitored sites
- **Site History**: Detailed historical records for individual sites
- **Manual Checks**: Ability to run manual health checks on demand via AJAX
- **Error Tracking**: Captures and logs errors for troubleshooting
- **GitHub-based Updates**: Automatic plugin updates via GitHub releases

## Requirements

### On the Main/Collector Site
- WordPress 5.0+
- PHP 7.2+

### On Remote/Monitored Sites
- **Beech Login Plugin**: The `beech_login` plugin must be installed and activated on any remote site you want to monitor. This plugin provides the API endpoints that the Healthcheck Collector uses to gather health data.

## Installation

1. Download the plugin or clone this repository into your WordPress plugins directory
2. Activate the plugin through the WordPress admin panel
3. Configure remote sites in the Healthcheck settings

## Configuration

### Adding Sites to Monitor

1. Navigate to **Healthcheck > Settings** in the WordPress admin
2. Add the URL of each remote site you want to monitor
3. Ensure the `beech_login` plugin is active on each remote site
4. Test the connection

## Database Structure

The plugin creates a custom WordPress table `wp_bhc_site_data` that stores the following information for each health check:

| Column | Type | Description |
|--------|------|-------------|
| id | BIGINT | Auto-incrementing primary key |
| site_url | VARCHAR(255) | URL of the monitored site |
| timestamp | DATETIME | When the data was collected |
| themes_installed | INT | Total number of installed themes |
| plugins_installed | INT | Total number of installed plugins |
| plugins_active | INT | Number of currently active plugins |
| users_total | INT | Total number of user accounts |
| users_admin | INT | Number of admin-level users |
| theme_active | VARCHAR(255) | Name of the currently active theme |
| error | TEXT | Any error messages encountered |
| raw | JSON | Raw response data for debugging |

## Monitoring & Data Collection

### Automatic Collection
- Health checks run automatically every hour via WordPress scheduled events
- Data is collected from all configured remote sites
- Results are stored in the database with full timestamp records

### Manual Collection
- Click "Run Manual Check" in the dashboard to trigger an immediate health check
- Useful for testing configurations or immediate monitoring needs

## Dashboard

The main dashboard displays:
- Overview of all monitored sites
- Current health status
- Last check timestamp
- Quick links to detailed site history

## Updater & GitHub Releases

### Automatic Updates

This plugin includes a custom GitHub-based updater that automatically checks for new releases and prompts administrators to update when a new version is available.

### How the Updater Works

1. The updater checks the BeechAgency/beech_healthcheck GitHub repository for releases
2. Compares the tagged release version with the currently installed version
3. If a newer version exists, displays an update notification in the WordPress admin
4. Administrators can update directly from WordPress without manual downloads

### Releasing a New Update

To release a new version of the plugin:

#### Step 1: Update Version Number
Edit `beech_healthcheck.php` and update the version number in the plugin header:

```php
/**
 * Plugin Name: Beech Healthcheck Collector
 * Description: Collects health data from remote WordPress sites and logs it for monitoring.
 * Version: 0.7  // <- Change this
 * Author: Josh Wayman | Beech Agency
 * Author URI: https://beech.agency
 */
```

#### Step 2: Commit Changes
```bash
git add .
git commit -m "Release version 0.7"
```

#### Step 3: Create GitHub Release Tag
Create a tag matching the version number:

```bash
git tag v0.7
git push origin v0.7
```

#### Step 4: Create GitHub Release
1. Go to https://github.com/BeechAgency/beech_healthcheck
2. Navigate to **Releases**
3. Click **Draft a new release**
4. Select the tag you just created (e.g., `v0.7`)
5. Add release notes describing the changes
6. (Optional) Attach a `.zip` file of the plugin if desired
7. Click **Publish release**

#### Step 5: Verify Update Detection
1. Go to the WordPress Plugins page
2. The update notification should appear within a few minutes
3. Administrators can then update from the WordPress admin interface

### Authentication (Optional)

For private repositories, you can add a GitHub personal access token to the updater in `beech_healthcheck.php`:

```php
$updater->authorize( 'your_github_token_here' );
```

This allows the updater to access private repositories and bypass GitHub API rate limits.

## Logging

The plugin uses the Beech Lumberack logging system for debugging and error tracking. Logs are stored in the WordPress uploads directory (`wp-content/uploads/beech-lumberack-log.txt`).

### Log Levels
- `INFO`: General information about data collection
- `DEBUG`: Detailed debugging information
- `ERROR`: Error messages and exceptions

## Troubleshooting

### Sites Not Appearing in Data
1. Verify the `beech_login` plugin is installed and activated on remote sites
2. Check that the remote site URL is correct in settings
3. Review logs for connection errors
4. Test the health check URL manually in a browser

### Scheduled Events Not Running
1. Verify WordPress cron is enabled: `define( 'DISABLE_WP_CRON', false );`
2. Check that site has regular traffic (which triggers WP-Cron)
3. Consider a loopback request: Add to crontab `*/15 * * * * curl http://yoursite.com/?doing_wp_cron`

### Updates Not Appearing
1. Check that the repository is public or the authorization token is correct
2. Verify GitHub releases are created with proper version tags (e.g., `v0.7`)
3. WordPress may cache update checks; wait up to an hour or manually refresh

## Development

### Project Structure
- `beech_healthcheck.php` - Main plugin file
- `updater.php` - GitHub-based update checker and installer
- `lumberjack.php` - Logging utility
- `templates/` - Admin interface templates
  - `view_dashboard.php` - Main dashboard view
  - `view_settings.php` - Settings configuration
  - `view_history.php` - Site history details

## Support & Issues

For issues, feature requests, or bug reports, please create an issue on the [GitHub repository](https://github.com/BeechAgency/beech_healthcheck).

## License

Developed by Beech Agency - [https://beech.agency](https://beech.agency)

## Author

Josh Wayman | Beech Agency
