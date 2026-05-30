=== DK User Activity Logger ===
Contributors: dmytrokovalenko
Tags: activity log, audit log, security, logging, monitoring
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 2.6.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Records important WordPress activity and provides an owner-only log viewer for monitoring client changes.

== Description ==

DK User Activity Logger helps site owners keep an audit trail of important administrative activity in a dedicated owner-only log viewer.

Features include:

* Login and logout activity logging
* Post creation, updates, trash actions, and permanent deletions
* Plugin activation, deactivation, and deletion logging
* Owner-only log viewer under the Tools menu
* Live refresh, filters, sorting, and pagination
* Optional second password layer before logs are shown
* Configurable timezone with America/Edmonton as the default
* Automatic cleanup of expired logs

The plugin does not require an external account or third-party service to function.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it through the WordPress Plugins screen.
2. Activate **DK User Activity Logger**.
3. After activation, open **Tools > Activity Logs**.
4. Optionally set an extra access password inside the log viewer.

== Frequently Asked Questions ==

= Who can access the log viewer? =

Only the owner account stored by the plugin can open the log viewer screen.

= Is the viewer visible in the admin menu? =

The screen is available to the owner account from the Tools menu and from an admin bar shortcut.

= Does the plugin send data to third-party services? =

No. The release prepared for WordPress.org works fully inside WordPress and does not fetch plugin updates or operational data from an external service.

= What happens on scheduled maintenance? =

The plugin runs a daily cleanup task that removes log rows older than the configured retention period.

= Can I choose a different timezone? =

Yes. The plugin defaults to `America/Edmonton`, but the owner can choose a different timezone for displayed timestamps, date filters, and the nightly maintenance schedule.

== Screenshots ==

1. Owner-only activity log viewer with filters, metrics, and live refresh.
2. Security panel for protecting the hidden viewer with an extra password.

== Changelog ==

= 2.6.2 =

* Harden the activity log queries so every dynamic value uses a $wpdb->prepare() placeholder.
* Require WordPress 6.2+ for identifier (%i) placeholder support.

= 2.6.1 =

* Prepare the plugin for WordPress.org distribution.
* Remove the private auto-update flow from the public release build.
* Add WordPress.org-compatible readme and deployment packaging workflow.
* Add configurable timezone support with America/Edmonton as the default.

= 2.6 =

* Improve actor labels and log viewer clarity.
* Preserve Edmonton-based timestamps and filtering behavior.

== Upgrade Notice ==

= 2.6.2 =

This release hardens the database queries and now requires WordPress 6.2 or newer.

= 2.6.1 =

This release removes the private updater and aligns the public package with WordPress.org distribution requirements.
