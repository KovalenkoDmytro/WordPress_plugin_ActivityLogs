<?php
/**
 * Plugin Name: DK User Activity Logger (Dev)
 * Plugin URI: https://github.com/KovalenkoDmytro/wp_logs_plugin
 * Description: Records key site activity and provides a protected activity log screen for site owners.
 * Version: 2.6.2
 * Author: Dmytro Kovalenko
 * Author URI: https://dmytro-kovalenko.ca
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * Text Domain: dk-user-activity-logger
 * Domain Path: /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action(
        'admin_notices',
        static function (): void {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('DK User Activity Logger requires PHP 8.1 or newer.', 'dk-user-activity-logger');
            echo '</p></div>';
        }
    );

    return;
}

require_once __DIR__ . '/includes/data_base_queries.php';
require_once __DIR__ . '/includes/class-wp-activity-logger-admin-service.php';
require_once __DIR__ . '/includes/class-wp-activity-logger-event-logger.php';
require_once __DIR__ . '/includes/class-wp-activity-logger-plugin.php';
require_once __DIR__ . '/includes/admin_show_page.php';

function wp_activity_logger(): WPActivityLoggerPlugin
{
    static $plugin = null;

    if (! $plugin instanceof WPActivityLoggerPlugin) {
        $plugin = new WPActivityLoggerPlugin(__FILE__);
        $plugin->register_hooks();
    }

    return $plugin;
}

wp_activity_logger();
