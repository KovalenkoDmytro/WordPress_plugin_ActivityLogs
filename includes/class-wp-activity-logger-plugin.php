<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class WPActivityLoggerPlugin
{
    public const VERSION = '2.6.1';
    public const VIEW_CAPABILITY = 'manage_options';
    public const NIGHTLY_MAINTENANCE_HOOK = 'wp_activity_logger_nightly_maintenance';
    public const LOG_RETENTION_DAYS = 30;
    public const OPTION_ACCESS_SLUG = 'wp_activity_logger_access_slug';
    public const OPTION_OWNER_USER_ID = 'wp_activity_logger_owner_user_id';
    public const OPTION_SHOW_ACCESS_NOTICE = 'wp_activity_logger_show_access_notice';
    public const OPTION_ACCESS_PASSWORD_HASH = 'wp_activity_logger_access_password_hash';
    public const OPTION_TIMEZONE = 'wp_activity_logger_timezone';
    public const USER_META_UNLOCKED_UNTIL = 'wp_activity_logger_unlocked_until';
    public const ACCESS_PASSWORD_CONST = 'WP_ACTIVITY_LOGGER_ACCESS_PASSWORD';

    private readonly WPActivityLoggerAdminService $admin_service;
    private readonly WPActivityLoggerEventLogger $event_logger;

    public function __construct(
        private readonly string $plugin_file
    ) {
        $this->admin_service = new WPActivityLoggerAdminService(
            plugin_file: $this->plugin_file,
            schedule_maintenance: $this->schedule_nightly_maintenance(...)
        );
        $this->event_logger = new WPActivityLoggerEventLogger();
    }

    public function register_hooks(): void
    {
        register_activation_hook($this->plugin_file, [$this, 'install']);
        register_deactivation_hook($this->plugin_file, [$this, 'deactivate']);

        add_action('init', [$this, 'schedule_nightly_maintenance']);
        add_action(self::NIGHTLY_MAINTENANCE_HOOK, [$this, 'run_nightly_maintenance']);

        $this->admin_service->register_hooks();
        $this->event_logger->register_hooks();
    }

    public function install(): void
    {
        wp_activity_logger_install();
        $this->admin_service->setup_on_install();
    }

    public function deactivate(): void
    {
        wp_clear_scheduled_hook(self::NIGHTLY_MAINTENANCE_HOOK);
        wp_clear_scheduled_hook('wp_activity_logger_nightly_update');
    }

    public function schedule_nightly_maintenance(): void
    {
        if (wp_next_scheduled(self::NIGHTLY_MAINTENANCE_HOOK) !== false) {
            return;
        }

        wp_schedule_event(
            wp_activity_logger_next_maintenance_timestamp(),
            'daily',
            self::NIGHTLY_MAINTENANCE_HOOK
        );
    }

    public function run_nightly_maintenance(): void
    {
        if (wp_installing()) {
            return;
        }

        wp_activity_logger_delete_expired_logs($this->get_log_retention_days());
    }

    public function can_view_logs(): bool
    {
        return $this->admin_service->can_view_logs();
    }

    public function is_screen_unlocked(): bool
    {
        return $this->admin_service->is_screen_unlocked();
    }

    public function get_admin_page_url(): string
    {
        return $this->admin_service->get_admin_page_url();
    }

    public function get_access_slug(): string
    {
        return $this->admin_service->get_access_slug();
    }

    public function is_password_required(): bool
    {
        return $this->admin_service->is_password_required();
    }

    public function has_saved_password(): bool
    {
        return $this->admin_service->has_saved_password();
    }

    public function get_timezone_name(): string
    {
        return $this->admin_service->get_timezone_name();
    }

    private function get_log_retention_days(): int
    {
        $days = (int) apply_filters('wp_activity_logger_retention_days', self::LOG_RETENTION_DAYS);

        return max(1, $days);
    }
}
