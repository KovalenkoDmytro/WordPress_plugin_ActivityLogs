# WP Activity Logger

WP Activity Logger records important WordPress activity and provides a private log viewer for the site owner. It is designed for situations where you need a clear audit trail of client actions without exposing the logging screen in the normal admin navigation.

## Features

- Records user login and logout activity.
- Records post creation, updates, trash actions, and permanent deletions.
- Records plugin activation, deactivation, and deletion events.
- Stores logs in a dedicated database table.
- Provides a hidden owner-only admin screen with:
  - live refresh
  - filtering by date, username, search text, and IP address
  - sorting and pagination
  - optional password protection
- Automatically checks for plugin updates every night from a private server.
- Automatically installs a newer plugin package when an update is available.

## Requirements

- WordPress 6.0+
- PHP 8.1+

## Plugin Structure

```text
wp-logs/
├── assets/
│   ├── admin.css
│   └── admin.js
├── includes/
│   ├── admin_show_page.php
│   └── data_base_queries.php
├── plugin-update-checker-master/
├── wp-logs-viewer-plugin.json
└── wp-logs-viewer.php
```

## Installation

1. Upload the plugin to `wp-content/plugins/wp-logs`.
2. Activate the plugin in WordPress.
3. On activation, the plugin:
   - creates the activity log table
   - assigns the current admin user as the plugin owner
   - generates a private access slug for the log screen
   - schedules the nightly update check

## Accessing the Log Viewer

The log viewer is intentionally hidden from the normal admin menu.

After activation, the plugin shows the owner a private access link in the admin notices area. Bookmark that link.

The owner can also access the screen from the admin bar while logged in.

Only the owner account can open the log viewer. Other admin users should not see the plugin in the plugins list and should not be able to access the private screen.

## Password Protection

The hidden URL is the first layer of protection. You can also enable a second layer by setting a password inside the log viewer screen.

When a password is configured:

- the owner must unlock the screen before viewing logs
- the unlocked session remains valid for a limited time

You can also define a password in code through `wp-config.php`:

```php
define('WP_ACTIVITY_LOGGER_ACCESS_PASSWORD', 'your-strong-password');
```

If this constant is present, it takes precedence over the password saved in the UI.

## Nightly Automatic Updates

This plugin uses the bundled `plugin-update-checker` library, but update metadata comes from a private server instead of GitHub.

### Update Flow

Every night at `02:00` in the WordPress site timezone, the plugin:

1. Requests plugin metadata from:

```text
https://wp-plugins.dmytro-kovalenko.ca/wp-logs/wp-logs-viewer-plugin.json
```

2. Checks whether the remote version is newer than the installed version.
3. If a newer version exists, WordPress downloads the package from:

```text
https://wp-plugins.dmytro-kovalenko.ca/wp-logs.zip
```

4. Runs an automatic plugin upgrade.

### Important Notes

- The metadata JSON must always contain the correct `version` and `download_url`.
- The ZIP package should unpack into the correct plugin directory structure.
- WordPress cron must run reliably on the site. If WP-Cron is disabled, a real server cron should trigger WordPress regularly.

## Metadata File Format

The private update server should host a JSON file like this:

```json
{
  "name": "WP Activity Logger",
  "version": "1.2.0",
  "download_url": "https://wp-plugins.dmytro-kovalenko.ca/wp-logs.zip",
  "homepage": "https://wp-plugins.dmytro-kovalenko.ca/",
  "details_url": "https://wp-plugins.dmytro-kovalenko.ca/",
  "requires": "6.0",
  "requires_php": "8.1",
  "tested": "6.6",
  "last_updated": "2026-05-01 12:00:00",
  "upgrade_notice": "Adds a hidden owner-only log viewer, auto-refresh, nightly private-server updates, and PHP 8.1 support.",
  "author": "Dmytro Kovalenko",
  "author_homepage": "https://dmytro-kovalenko.com/",
  "sections": {
    "description": "Protected WordPress activity logger with a hidden owner-only audit screen and nightly private-server update support.",
    "installation": "",
    "changelog": "",
    "custom_section": ""
  },
  "icons": {},
  "banners": {},
  "translations": [],
  "rating": 0,
  "num_ratings": 0,
  "downloaded": 0,
  "active_installs": 0
}
```

## Logged Events

The plugin currently logs:

- user login
- user logout
- post creation
- post update
- post moved to trash
- permanent post deletion
- plugin activation
- plugin deactivation
- plugin deletion

## Development Notes

- Main bootstrap: [wp-logs-viewer.php](/Users/dmytrokovalenko/Documents/Projects/WordpressStarter/app/plugins/wp-logs/wp-logs-viewer.php)
- Admin UI: [includes/admin_show_page.php](/Users/dmytrokovalenko/Documents/Projects/WordpressStarter/app/plugins/wp-logs/includes/admin_show_page.php)
- Database layer: [includes/data_base_queries.php](/Users/dmytrokovalenko/Documents/Projects/WordpressStarter/app/plugins/wp-logs/includes/data_base_queries.php)
- Update metadata: [wp-logs-viewer-plugin.json](/Users/dmytrokovalenko/Documents/Projects/WordpressStarter/app/plugins/wp-logs/wp-logs-viewer-plugin.json)

## Security Notes

- This plugin is meant for owner-only access.
- Do not expose the private log URL to clients.
- Use a strong password if password protection is enabled.
- Do not store secrets in the plugin repository.

## License

GPL2
