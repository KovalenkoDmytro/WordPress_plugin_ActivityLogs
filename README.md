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
  - configurable timezone with `America/Edmonton` as the default
- Runs nightly maintenance to clean up expired log rows.

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
├── languages/
│   └── index.php
├── readme.txt
└── wp-logs-viewer.php
```

## Installation

1. Upload the plugin to `wp-content/plugins/wp-logs`.
2. Activate the plugin in WordPress.
3. On activation, the plugin:
   - creates the activity log table
   - assigns the current admin user as the plugin owner
   - generates a private access slug for the log screen
   - schedules the nightly maintenance task

## Accessing the Log Viewer

The log viewer is intentionally hidden from the normal admin menu.

After activation, the plugin shows the owner a private access link in the admin notices area. Bookmark that link.

The owner can also access the screen from the admin bar while logged in.

For convenience, the owner will also see a small `Logs` link in the plugin row on the WordPress Plugins screen.

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

## Nightly Maintenance

Every night at `02:00` in the WordPress site timezone, the plugin:

1. Deletes log rows older than the configured retention period.
2. Relies on WordPress cron to schedule the cleanup.

If WP-Cron is disabled, a real server cron should trigger WordPress regularly.

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

## Time Zone

- Log entries are stored in UTC for consistency.
- The plugin uses `America/Edmonton` by default.
- The owner can choose a different timezone in the hidden viewer settings.
- The selected timezone controls displayed timestamps, date filters, and the nightly maintenance schedule.

## Development Notes

- Main bootstrap: [wp-logs-viewer.php](/Users/dmytrokovalenko/Documents/Projects/WordpressStarter/app/plugins/wp-logs/wp-logs-viewer.php)
- Admin UI: [includes/admin_show_page.php](/Users/dmytrokovalenko/Documents/Projects/WordpressStarter/app/plugins/wp-logs/includes/admin_show_page.php)
- Database layer: [includes/data_base_queries.php](/Users/dmytrokovalenko/Documents/Projects/WordpressStarter/app/plugins/wp-logs/includes/data_base_queries.php)
- WordPress.org readme: [readme.txt](/Users/dmytrokovalenko/Documents/Projects/WordpressStarter/app/plugins/wp-logs/readme.txt)
- Deployment notes: [DEPLOYMENT.md](/Users/dmytrokovalenko/Documents/Projects/WordpressStarter/app/plugins/wp-logs/DEPLOYMENT.md)

## Security Notes

- This plugin is meant for owner-only access.
- Do not expose the private log URL to clients.
- Use a strong password if password protection is enabled.
- Do not store secrets in the plugin repository.

## License

GPL2
