<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class WPActivityLoggerAdminService
{
    public function __construct(
        private readonly string $plugin_file,
        private readonly \Closure $schedule_maintenance
    ) {}

    public function register_hooks(): void
    {
        add_action('admin_init', [$this, 'ensure_owner_is_set']);
        add_action('admin_init', [$this, 'maybe_dismiss_access_notice']);
        add_action('admin_init', [$this, 'maybe_unlock_screen']);
        add_action('admin_init', [$this, 'maybe_save_security_settings']);
        add_action('admin_init', [$this, 'maybe_save_timezone_settings']);
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_bar_menu', [$this, 'register_admin_bar_link'], 90);
        add_action('admin_notices', [$this, 'render_access_notice']);
        add_action('wp_ajax_wp_activity_logger_fetch_logs', [$this, 'handle_fetch_logs']);

        add_filter('plugin_row_meta', [$this, 'add_plugin_row_meta_link'], 10, 4);
    }

    public function setup_on_install(): void
    {
        $this->ensure_access_slug();
        $this->persist_owner_user_id(get_current_user_id());
        update_option(WPActivityLoggerPlugin::OPTION_SHOW_ACCESS_NOTICE, 'yes', false);
        ($this->schedule_maintenance)();
    }

    public function ensure_owner_is_set(): void
    {
        $this->ensure_access_slug();

        if ($this->get_owner_user_id() > 0 || ! current_user_can(WPActivityLoggerPlugin::VIEW_CAPABILITY)) {
            return;
        }

        $this->persist_owner_user_id(get_current_user_id());
    }

    public function maybe_dismiss_access_notice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified immediately below before any state change.
        $dismiss = isset($_GET['wp_activity_logger_dismiss_notice']) ? sanitize_text_field(wp_unslash($_GET['wp_activity_logger_dismiss_notice'])) : '';
        if ($dismiss !== '1' || ! $this->is_owner_viewer()) {
            return;
        }

        check_admin_referer('wp_activity_logger_dismiss_notice');

        update_option(WPActivityLoggerPlugin::OPTION_SHOW_ACCESS_NOTICE, 'no', false);

        wp_safe_redirect(remove_query_arg(['wp_activity_logger_dismiss_notice', '_wpnonce']));
        exit;
    }

    public function maybe_unlock_screen(): void
    {
        if (! $this->is_page_request() || ! $this->is_owner_viewer()) {
            return;
        }

        $unlock_action = isset($_POST['wp_activity_logger_unlock']) ? sanitize_text_field(wp_unslash($_POST['wp_activity_logger_unlock'])) : '';
        if ($unlock_action !== '1') {
            return;
        }

        check_admin_referer('wp_activity_logger_unlock');

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Password input must remain raw for verification after unslashing.
        $provided_password = isset($_POST['wp_activity_logger_password']) ? (string) wp_unslash($_POST['wp_activity_logger_password']) : '';

        if ($this->password_matches($provided_password)) {
            update_user_meta(get_current_user_id(), WPActivityLoggerPlugin::USER_META_UNLOCKED_UNTIL, time() + (12 * HOUR_IN_SECONDS));

            wp_safe_redirect(
                add_query_arg(
                    ['wp_activity_logger_unlocked' => '1'],
                    $this->get_admin_page_url()
                )
            );
            exit;
        }

        wp_safe_redirect(
            add_query_arg(
                ['wp_activity_logger_access_error' => '1'],
                $this->get_admin_page_url()
            )
        );
        exit;
    }

    public function register_admin_page(): void
    {
        if (! $this->is_owner_viewer()) {
            return;
        }

        add_submenu_page(
            'tools.php',
            __('Activity Logs', 'wp-logs'),
            __('Activity Logs', 'wp-logs'),
            WPActivityLoggerPlugin::VIEW_CAPABILITY,
            $this->get_access_slug(),
            'wp_activity_logger_admin_page'
        );
    }

    public function enqueue_admin_assets(string $hook_suffix): void
    {
        if (! $this->is_logs_screen($hook_suffix)) {
            return;
        }

        $style_path = dirname(__DIR__) . '/assets/admin.css';
        $script_path = dirname(__DIR__) . '/assets/admin.js';

        wp_enqueue_style(
            'wp-logs-admin',
            plugins_url('assets/admin.css', $this->plugin_file),
            [],
            (string) filemtime($style_path)
        );

        wp_enqueue_script(
            'wp-logs-admin',
            plugins_url('assets/admin.js', $this->plugin_file),
            [],
            (string) filemtime($script_path),
            true
        );

        wp_localize_script(
            'wp-logs-admin',
            'wpActivityLoggerConfig',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wp_activity_logger_fetch_logs'),
                'defaultRefreshSeconds' => 30,
                'pageUrl' => $this->get_admin_page_url(),
                'siteDateFormat' => trim(get_option('date_format') . ' ' . get_option('time_format')),
                'timezone' => $this->get_timezone_name(),
                'strings' => [
                    'loading' => __('Refreshing logs...', 'wp-logs'),
                    'ready' => __('Live refresh ready', 'wp-logs'),
                    'updated' => __('Updated just now', 'wp-logs'),
                    'empty' => __('No matching logs found.', 'wp-logs'),
                    'error' => __('Could not refresh the logs right now.', 'wp-logs'),
                    'locked' => __('This screen is locked.', 'wp-logs'),
                ],
            ]
        );
    }

    public function register_admin_bar_link(\WP_Admin_Bar $admin_bar): void
    {
        if (! $this->is_owner_viewer()) {
            return;
        }

        $admin_bar->add_node(
            [
                'id' => 'wp-logs',
                'title' => __('Activity Logs', 'wp-logs'),
                'href' => $this->get_admin_page_url(),
                'meta' => [
                    'class' => 'wp-logs-admin-bar-link',
                ],
            ]
        );
    }

    public function render_access_notice(): void
    {
        if (! $this->is_owner_viewer() || get_option(WPActivityLoggerPlugin::OPTION_SHOW_ACCESS_NOTICE, 'no') !== 'yes') {
            return;
        }

        $dismiss_url = wp_nonce_url(
            add_query_arg(['wp_activity_logger_dismiss_notice' => '1']),
            'wp_activity_logger_dismiss_notice'
        );

        echo '<div class="notice notice-success is-dismissible">';
        echo '<p><strong>' . esc_html__('DK User Activity Logger is ready to use.', 'wp-logs') . '</strong></p>';
        echo '<p>' . esc_html__('Open Activity Logs from the Tools menu, or use this direct link:', 'wp-logs') . '</p>';
        echo '<p><a href="' . esc_url($this->get_admin_page_url()) . '">' . esc_html($this->get_admin_page_url()) . '</a></p>';

        if ($this->is_password_required()) {
            echo '<p>' . esc_html__('This screen also requires your access password.', 'wp-logs') . '</p>';
        }

        echo '<p><a href="' . esc_url($dismiss_url) . '">' . esc_html__('Dismiss this notice', 'wp-logs') . '</a></p>';
        echo '</div>';
    }

    public function handle_fetch_logs(): void
    {
        if (! $this->is_owner_viewer()) {
            wp_send_json_error(['message' => __('You are not allowed to view these logs.', 'wp-logs')], 403);
        }

        if ($this->is_password_required() && ! $this->is_screen_unlocked()) {
            wp_send_json_error(['message' => __('The log screen is locked.', 'wp-logs')], 423);
        }

        check_ajax_referer('wp_activity_logger_fetch_logs', 'nonce');

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- AJAX request is verified by check_ajax_referer() above.
        $filters = wp_activity_logger_normalize_filters(wp_unslash($_POST));
        wp_send_json_success(wp_activity_logger_get_logs_payload($filters));
    }

    public function add_plugin_row_meta_link(array $plugin_meta, string $plugin_file, array $plugin_data, string $status): array
    {
        unset($plugin_data, $status);

        if (! $this->is_owner_viewer() || $plugin_file !== plugin_basename($this->plugin_file)) {
            return $plugin_meta;
        }

        $plugin_meta[] = sprintf(
            '<a href="%s">%s</a>',
            esc_url($this->get_admin_page_url()),
            esc_html__('Logs', 'wp-logs')
        );

        return $plugin_meta;
    }

    public function can_view_logs(): bool
    {
        return $this->is_owner_viewer();
    }

    public function is_screen_unlocked(): bool
    {
        if (! $this->is_password_required()) {
            return true;
        }

        return (int) get_user_meta(get_current_user_id(), WPActivityLoggerPlugin::USER_META_UNLOCKED_UNTIL, true) > time();
    }

    public function get_admin_page_url(): string
    {
        return add_query_arg(
            ['page' => $this->get_access_slug()],
            admin_url('tools.php')
        );
    }

    public function get_access_slug(): string
    {
        return $this->ensure_access_slug();
    }

    public function is_password_required(): bool
    {
        return $this->get_access_password() !== '' || $this->get_saved_password_hash() !== '';
    }

    public function has_saved_password(): bool
    {
        return $this->get_saved_password_hash() !== '';
    }

    public function get_timezone_name(): string
    {
        return wp_activity_logger_timezone_name();
    }

    public function maybe_save_security_settings(): void
    {
        if (! $this->is_page_request() || ! $this->is_owner_viewer()) {
            return;
        }

        $action = isset($_POST['wp_activity_logger_save_security']) ? sanitize_text_field(wp_unslash($_POST['wp_activity_logger_save_security'])) : '';
        if ($action !== '1') {
            return;
        }

        check_admin_referer('wp_activity_logger_save_security');

        $remove_password = isset($_POST['wp_activity_logger_remove_password']) && sanitize_text_field(wp_unslash($_POST['wp_activity_logger_remove_password'])) === '1';
        if ($remove_password) {
            delete_option(WPActivityLoggerPlugin::OPTION_ACCESS_PASSWORD_HASH);

            wp_safe_redirect(
                add_query_arg(
                    ['wp_activity_logger_security_saved' => 'removed'],
                    $this->get_admin_page_url()
                )
            );
            exit;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Password input must remain raw for hashing after unslashing.
        $password = isset($_POST['wp_activity_logger_new_password']) ? trim((string) wp_unslash($_POST['wp_activity_logger_new_password'])) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Password input must remain raw for comparison after unslashing.
        $password_confirm = isset($_POST['wp_activity_logger_confirm_password']) ? trim((string) wp_unslash($_POST['wp_activity_logger_confirm_password'])) : '';

        if ($password === '' || $password_confirm === '' || $password !== $password_confirm) {
            wp_safe_redirect(
                add_query_arg(
                    ['wp_activity_logger_security_error' => 'mismatch'],
                    $this->get_admin_page_url()
                )
            );
            exit;
        }

        update_option(WPActivityLoggerPlugin::OPTION_ACCESS_PASSWORD_HASH, wp_hash_password($password), false);

        wp_safe_redirect(
            add_query_arg(
                ['wp_activity_logger_security_saved' => 'updated'],
                $this->get_admin_page_url()
            )
        );
        exit;
    }

    public function maybe_save_timezone_settings(): void
    {
        if (! $this->is_page_request() || ! $this->is_owner_viewer()) {
            return;
        }

        $action = isset($_POST['wp_activity_logger_save_timezone']) ? sanitize_text_field(wp_unslash($_POST['wp_activity_logger_save_timezone'])) : '';
        if ($action !== '1') {
            return;
        }

        check_admin_referer('wp_activity_logger_save_timezone');

        $timezone_name = isset($_POST['wp_activity_logger_timezone']) ? sanitize_text_field(wp_unslash($_POST['wp_activity_logger_timezone'])) : '';
        if (! wp_activity_logger_is_valid_timezone($timezone_name)) {
            wp_safe_redirect(
                add_query_arg(
                    ['wp_activity_logger_timezone_error' => 'invalid'],
                    $this->get_admin_page_url()
                )
            );
            exit;
        }

        update_option(WPActivityLoggerPlugin::OPTION_TIMEZONE, $timezone_name, false);
        wp_clear_scheduled_hook(WPActivityLoggerPlugin::NIGHTLY_MAINTENANCE_HOOK);
        wp_clear_scheduled_hook('wp_activity_logger_nightly_update');
        ($this->schedule_maintenance)();

        wp_safe_redirect(
            add_query_arg(
                ['wp_activity_logger_timezone_saved' => 'updated'],
                $this->get_admin_page_url()
            )
        );
        exit;
    }

    private function is_page_request(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection for the current admin page request.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        return $page === $this->get_access_slug();
    }

    private function is_logs_screen(string $hook_suffix): bool
    {
        if ($hook_suffix === sprintf('tools_page_%s', $this->get_access_slug())) {
            return true;
        }

        return $this->is_page_request();
    }

    private function is_owner_viewer(): bool
    {
        if (! current_user_can(WPActivityLoggerPlugin::VIEW_CAPABILITY)) {
            return false;
        }

        $owner_user_id = $this->get_owner_user_id();
        if ($owner_user_id <= 0) {
            return true;
        }

        return get_current_user_id() === $owner_user_id;
    }

    private function get_owner_user_id(): int
    {
        return (int) get_option(WPActivityLoggerPlugin::OPTION_OWNER_USER_ID, 0);
    }

    private function persist_owner_user_id(int $user_id): void
    {
        if ($user_id <= 0) {
            return;
        }

        update_option(WPActivityLoggerPlugin::OPTION_OWNER_USER_ID, $user_id, false);
    }

    private function ensure_access_slug(): string
    {
        $slug = (string) get_option(WPActivityLoggerPlugin::OPTION_ACCESS_SLUG, '');
        if ($slug !== '') {
            return $slug;
        }

        $slug = sprintf('wp-activity-logs-%s', strtolower(wp_generate_password(10, false, false)));
        update_option(WPActivityLoggerPlugin::OPTION_ACCESS_SLUG, $slug, false);
        update_option(WPActivityLoggerPlugin::OPTION_SHOW_ACCESS_NOTICE, 'yes', false);

        return $slug;
    }

    private function get_access_password(): string
    {
        $password = defined(WPActivityLoggerPlugin::ACCESS_PASSWORD_CONST)
            ? trim((string) constant(WPActivityLoggerPlugin::ACCESS_PASSWORD_CONST))
            : '';

        return (string) apply_filters('wp_activity_logger_access_password', $password);
    }

    private function get_saved_password_hash(): string
    {
        return (string) get_option(WPActivityLoggerPlugin::OPTION_ACCESS_PASSWORD_HASH, '');
    }

    private function password_matches(string $provided_password): bool
    {
        $constant_password = $this->get_access_password();
        if ($constant_password !== '') {
            return hash_equals($constant_password, $provided_password);
        }

        $saved_hash = $this->get_saved_password_hash();
        if ($saved_hash === '' || $provided_password === '') {
            return false;
        }

        return wp_check_password($provided_password, $saved_hash);
    }
}
