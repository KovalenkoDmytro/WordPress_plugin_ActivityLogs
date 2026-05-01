<?php
/**
 * Plugin Name: WP Activity Logger
 * Plugin URI: https://github.com/KovalenkoDmytro/wp_logs_plugin
 * Description: Records key site activity and provides a protected activity log screen for site owners.
 * Version: 2.0
 * Author: Dmytro Kovalenko
 * Author URI: https://dmytro-kovalenko.ca
 * License: GPL2
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Update URI: https://wp-plugins.dmytro-kovalenko.ca/
 */

declare(strict_types=1);

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if (! defined('ABSPATH')) {
    exit;
}

if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action(
        'admin_notices',
        static function (): void {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('WP Activity Logger requires PHP 8.1 or newer.', 'wp-activity-logger');
            echo '</p></div>';
        }
    );

    return;
}

require_once __DIR__ . '/plugin-update-checker-master/plugin-update-checker.php';

final class WPActivityLogger
{
    public const VERSION = '1.2.0';
    public const VIEW_CAPABILITY = 'manage_options';
    public const NIGHTLY_UPDATE_HOOK = 'wp_activity_logger_nightly_update';
    public const OPTION_ACCESS_SLUG = 'wp_activity_logger_access_slug';
    public const OPTION_OWNER_USER_ID = 'wp_activity_logger_owner_user_id';
    public const OPTION_SHOW_ACCESS_NOTICE = 'wp_activity_logger_show_access_notice';
    public const OPTION_ACCESS_PASSWORD_HASH = 'wp_activity_logger_access_password_hash';
    public const USER_META_UNLOCKED_UNTIL = 'wp_activity_logger_unlocked_until';
    public const ACCESS_PASSWORD_CONST = 'WP_ACTIVITY_LOGGER_ACCESS_PASSWORD';

    private \YahnisElsts\PluginUpdateChecker\v5p5\Plugin\UpdateChecker $update_checker;

    public function __construct()
    {
        register_activation_hook(__FILE__, [$this, 'install']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        $this->update_checker = $this->configure_updates();

        add_action('admin_init', [$this, 'ensure_owner_is_set']);
        add_action('admin_init', [$this, 'maybe_dismiss_access_notice']);
        add_action('admin_init', [$this, 'maybe_unlock_screen']);
        add_action('admin_init', [$this, 'maybe_save_security_settings']);
        add_action('init', [$this, 'schedule_nightly_update']);
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_bar_menu', [$this, 'register_admin_bar_link'], 90);
        add_action('admin_notices', [$this, 'render_access_notice']);
        add_action('wp_ajax_wp_activity_logger_fetch_logs', [$this, 'handle_fetch_logs']);
        add_action(self::NIGHTLY_UPDATE_HOOK, [$this, 'run_nightly_update']);

        add_filter('all_plugins', [$this, 'hide_plugin_from_non_owner']);

        $this->register_logging_hooks();
    }

    public function install(): void
    {
        wp_activity_logger_install();
        $this->ensure_access_slug();
        $this->persist_owner_user_id(get_current_user_id());
        update_option(self::OPTION_SHOW_ACCESS_NOTICE, 'yes', false);
        $this->schedule_nightly_update();
    }

    public function deactivate(): void
    {
        wp_clear_scheduled_hook(self::NIGHTLY_UPDATE_HOOK);
    }

    public function configure_updates(): \YahnisElsts\PluginUpdateChecker\v5p5\Plugin\UpdateChecker
    {
        $update_checker = PucFactory::buildUpdateChecker(
            $this->get_update_metadata_url(),
            __FILE__,
            'wp-logs',
            0
        );

        return $update_checker;
    }

    public function ensure_owner_is_set(): void
    {
        $this->ensure_access_slug();

        if ($this->get_owner_user_id() > 0 || ! current_user_can(self::VIEW_CAPABILITY)) {
            return;
        }

        $this->persist_owner_user_id(get_current_user_id());
    }

    public function maybe_dismiss_access_notice(): void
    {
        $dismiss = isset($_GET['wp_activity_logger_dismiss_notice']) ? sanitize_text_field(wp_unslash($_GET['wp_activity_logger_dismiss_notice'])) : '';
        if ($dismiss !== '1' || ! $this->is_owner_viewer()) {
            return;
        }

        check_admin_referer('wp_activity_logger_dismiss_notice');

        update_option(self::OPTION_SHOW_ACCESS_NOTICE, 'no', false);

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

        $provided_password = isset($_POST['wp_activity_logger_password']) ? (string) wp_unslash($_POST['wp_activity_logger_password']) : '';

        if ($this->password_matches($provided_password)) {
            update_user_meta(get_current_user_id(), self::USER_META_UNLOCKED_UNTIL, time() + (12 * HOUR_IN_SECONDS));

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
        add_submenu_page(
            null,
            __('Activity Logs', 'wp-activity-logger'),
            __('Activity Logs', 'wp-activity-logger'),
            self::VIEW_CAPABILITY,
            $this->get_access_slug(),
            'wp_activity_logger_admin_page'
        );
    }

    public function enqueue_admin_assets(string $hook_suffix): void
    {
        if (! $this->is_logs_screen($hook_suffix)) {
            return;
        }

        $style_path = __DIR__ . '/assets/admin.css';
        $script_path = __DIR__ . '/assets/admin.js';

        wp_enqueue_style(
            'wp-activity-logger-admin',
            plugins_url('assets/admin.css', __FILE__),
            [],
            (string) filemtime($style_path)
        );

        wp_enqueue_script(
            'wp-activity-logger-admin',
            plugins_url('assets/admin.js', __FILE__),
            [],
            (string) filemtime($script_path),
            true
        );

        wp_localize_script(
            'wp-activity-logger-admin',
            'wpActivityLoggerConfig',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wp_activity_logger_fetch_logs'),
                'defaultRefreshSeconds' => 30,
                'pageUrl' => $this->get_admin_page_url(),
                'siteDateFormat' => trim(get_option('date_format') . ' ' . get_option('time_format')),
                'strings' => [
                    'loading' => __('Refreshing logs...', 'wp-activity-logger'),
                    'ready' => __('Live refresh ready', 'wp-activity-logger'),
                    'updated' => __('Updated just now', 'wp-activity-logger'),
                    'empty' => __('No matching logs found.', 'wp-activity-logger'),
                    'error' => __('Could not refresh the logs right now.', 'wp-activity-logger'),
                    'locked' => __('This screen is locked.', 'wp-activity-logger'),
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
                'id' => 'wp-activity-logger',
                'title' => __('Activity Logs', 'wp-activity-logger'),
                'href' => $this->get_admin_page_url(),
                'meta' => [
                    'class' => 'wp-activity-logger-admin-bar-link',
                ],
            ]
        );
    }

    public function render_access_notice(): void
    {
        if (! $this->is_owner_viewer() || get_option(self::OPTION_SHOW_ACCESS_NOTICE, 'no') !== 'yes') {
            return;
        }

        $dismiss_url = wp_nonce_url(
            add_query_arg(['wp_activity_logger_dismiss_notice' => '1']),
            'wp_activity_logger_dismiss_notice'
        );

        echo '<div class="notice notice-success is-dismissible">';
        echo '<p><strong>' . esc_html__('WP Activity Logger is hidden from the regular admin menu.', 'wp-activity-logger') . '</strong></p>';
        echo '<p>' . esc_html__('Bookmark this private link to open the log viewer:', 'wp-activity-logger') . '</p>';
        echo '<p><a href="' . esc_url($this->get_admin_page_url()) . '">' . esc_html($this->get_admin_page_url()) . '</a></p>';

        if ($this->is_password_required()) {
            echo '<p>' . esc_html__('This screen also requires your access password.', 'wp-activity-logger') . '</p>';
        }

        echo '<p><a href="' . esc_url($dismiss_url) . '">' . esc_html__('Dismiss this notice', 'wp-activity-logger') . '</a></p>';
        echo '</div>';
    }

    public function handle_fetch_logs(): void
    {
        if (! $this->is_owner_viewer()) {
            wp_send_json_error(['message' => __('You are not allowed to view these logs.', 'wp-activity-logger')], 403);
        }

        if ($this->is_password_required() && ! $this->is_screen_unlocked()) {
            wp_send_json_error(['message' => __('The log screen is locked.', 'wp-activity-logger')], 423);
        }

        check_ajax_referer('wp_activity_logger_fetch_logs', 'nonce');

        $filters = wp_activity_logger_normalize_filters($_POST);
        wp_send_json_success(wp_activity_logger_get_logs_payload($filters));
    }

    public function hide_plugin_from_non_owner(array $plugins): array
    {
        if ($this->is_owner_viewer()) {
            return $plugins;
        }

        if (current_user_can('activate_plugins')) {
            unset($plugins[plugin_basename(__FILE__)]);
        }

        return $plugins;
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

        return (int) get_user_meta(get_current_user_id(), self::USER_META_UNLOCKED_UNTIL, true) > time();
    }

    public function get_admin_page_url(): string
    {
        return add_query_arg(
            ['page' => $this->get_access_slug()],
            admin_url('admin.php')
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

    public function schedule_nightly_update(): void
    {
        if (wp_next_scheduled(self::NIGHTLY_UPDATE_HOOK) !== false) {
            return;
        }

        wp_schedule_event($this->get_next_nightly_timestamp(), 'daily', self::NIGHTLY_UPDATE_HOOK);
    }

    public function run_nightly_update(): void
    {
        if (wp_installing()) {
            return;
        }

        $update = $this->update_checker->checkForUpdates();
        if ($update === null) {
            return;
        }

        $transient = get_site_transient('update_plugins');
        $transient = $this->update_checker->injectUpdate($transient);
        set_site_transient('update_plugins', $transient);

        if (! isset($transient->response[plugin_basename(__FILE__)])) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
        $upgrader->upgrade(plugin_basename(__FILE__));
    }

    private function register_logging_hooks(): void
    {
        add_action('wp_login', [$this, 'log_login'], 10, 2);
        add_action('wp_logout', [$this, 'log_logout']);
        add_action('post_updated', [$this, 'log_post_update'], 10, 3);
        add_action('wp_insert_post', [$this, 'log_post_creation'], 10, 3);
        add_action('wp_trash_post', [$this, 'log_post_trash']);
        add_action('before_delete_post', [$this, 'log_post_deletion']);
        add_action('activated_plugin', [$this, 'log_plugin_activation']);
        add_action('deactivated_plugin', [$this, 'log_plugin_deactivation']);
        add_action('upgrader_process_complete', [$this, 'log_plugin_deletion'], 10, 2);
    }

    public function log_login(string $user_login): void
    {
        $this->record_activity(sprintf("User '%s' logged in.", $user_login));
    }

    public function log_logout(): void
    {
        $current_user = wp_get_current_user();
        $login = $current_user->user_login ?: 'Unknown user';

        $this->record_activity(sprintf("User '%s' logged out.", $login));
    }

    public function log_post_update(int $post_id, object $post_after, object $post_before): void
    {
        if ($post_after->post_status === 'auto-draft') {
            return;
        }

        $current_user = wp_get_current_user();
        $changes = [];

        if ($post_before->post_title !== $post_after->post_title) {
            $changes[] = sprintf(
                "title changed from '%s' to '%s'",
                $post_before->post_title,
                $post_after->post_title
            );
        }

        if ($post_before->post_content !== $post_after->post_content) {
            $changes[] = 'content updated';
        }

        if ($changes === []) {
            return;
        }

        $this->record_activity(
            sprintf(
                "User '%s' updated post ID %d (%s): %s.",
                $current_user->user_login,
                $post_id,
                get_permalink($post_id) ?: home_url(sprintf('/?p=%d', $post_id)),
                implode(', ', $changes)
            )
        );
    }

    public function log_post_creation(int $post_id, \WP_Post $post, bool $update): void
    {
        if ($update || $post->post_status === 'auto-draft') {
            return;
        }

        $current_user = wp_get_current_user();
        $this->record_activity(
            sprintf(
                "User '%s' created post ID %d (%s) with title '%s'.",
                $current_user->user_login,
                $post_id,
                get_permalink($post_id) ?: home_url(sprintf('/?p=%d', $post_id)),
                $post->post_title
            )
        );
    }

    public function log_post_trash(int $post_id): void
    {
        $current_user = wp_get_current_user();
        $post = get_post($post_id);

        if (! $post instanceof \WP_Post) {
            return;
        }

        $this->record_activity(
            sprintf(
                "User '%s' moved post ID %d with title '%s' to the trash.",
                $current_user->user_login,
                $post_id,
                $post->post_title
            )
        );
    }

    public function log_post_deletion(int $post_id): void
    {
        $current_user = wp_get_current_user();
        $post = get_post($post_id);

        if (! $post instanceof \WP_Post || $post->post_status === 'trash') {
            return;
        }

        $this->record_activity(
            sprintf(
                "User '%s' permanently deleted post ID %d (%s) with title '%s'.",
                $current_user->user_login,
                $post_id,
                get_permalink($post_id) ?: home_url(sprintf('/?p=%d', $post_id)),
                $post->post_title
            )
        );
    }

    public function log_plugin_activation(string $plugin): void
    {
        $current_user = wp_get_current_user();
        $this->record_activity(
            sprintf(
                "User '%s' activated plugin '%s'.",
                $current_user->user_login,
                plugin_basename($plugin)
            )
        );
    }

    public function log_plugin_deactivation(string $plugin): void
    {
        $current_user = wp_get_current_user();
        $this->record_activity(
            sprintf(
                "User '%s' deactivated plugin '%s'.",
                $current_user->user_login,
                plugin_basename($plugin)
            )
        );
    }

    public function log_plugin_deletion(object $upgrader, array $options): void
    {
        if (($options['type'] ?? '') !== 'plugin' || ($options['action'] ?? '') !== 'delete') {
            return;
        }

        $current_user = wp_get_current_user();
        $deleted_plugins = isset($options['plugins']) && is_array($options['plugins'])
            ? implode(', ', array_map('plugin_basename', $options['plugins']))
            : 'Unknown plugins';

        $this->record_activity(
            sprintf(
                "User '%s' deleted plugin(s): %s.",
                $current_user->user_login,
                $deleted_plugins
            )
        );
    }

    private function record_activity(string $message): void
    {
        wp_activity_logger_record_activity($message);
    }

    private function is_page_request(): bool
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        return $page === $this->get_access_slug();
    }

    private function is_logs_screen(string $hook_suffix): bool
    {
        if ($hook_suffix === sprintf('admin_page_%s', $this->get_access_slug())) {
            return true;
        }

        return $this->is_page_request();
    }

    private function is_owner_viewer(): bool
    {
        if (! current_user_can(self::VIEW_CAPABILITY)) {
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
        return (int) get_option(self::OPTION_OWNER_USER_ID, 0);
    }

    private function persist_owner_user_id(int $user_id): void
    {
        if ($user_id <= 0) {
            return;
        }

        update_option(self::OPTION_OWNER_USER_ID, $user_id, false);
    }

    private function ensure_access_slug(): string
    {
        $slug = (string) get_option(self::OPTION_ACCESS_SLUG, '');
        if ($slug !== '') {
            return $slug;
        }

        $slug = sprintf('wp-activity-logs-%s', strtolower(wp_generate_password(10, false, false)));
        update_option(self::OPTION_ACCESS_SLUG, $slug, false);
        update_option(self::OPTION_SHOW_ACCESS_NOTICE, 'yes', false);

        return $slug;
    }

    private function get_access_password(): string
    {
        $password = defined(self::ACCESS_PASSWORD_CONST)
            ? trim((string) constant(self::ACCESS_PASSWORD_CONST))
            : '';

        return (string) apply_filters('wp_activity_logger_access_password', $password);
    }

    private function get_update_metadata_url(): string
    {
        return (string) apply_filters(
            'wp_activity_logger_update_metadata_url',
            'https://wp-plugins.dmytro-kovalenko.ca/WordPress_plugin_ActivityLogs/wp-logs-viewer-plugin.json'
        );
    }

    private function get_next_nightly_timestamp(): int
    {
        $timezone = wp_timezone();
        $next_run = new \DateTimeImmutable('now', $timezone);
        $next_run = $next_run->setTime(2, 0);

        if ($next_run->getTimestamp() <= time()) {
            $next_run = $next_run->modify('+1 day');
        }

        return $next_run->getTimestamp();
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
            delete_option(self::OPTION_ACCESS_PASSWORD_HASH);

            wp_safe_redirect(
                add_query_arg(
                    ['wp_activity_logger_security_saved' => 'removed'],
                    $this->get_admin_page_url()
                )
            );
            exit;
        }

        $password = isset($_POST['wp_activity_logger_new_password']) ? trim((string) wp_unslash($_POST['wp_activity_logger_new_password'])) : '';
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

        update_option(self::OPTION_ACCESS_PASSWORD_HASH, wp_hash_password($password), false);

        wp_safe_redirect(
            add_query_arg(
                ['wp_activity_logger_security_saved' => 'updated'],
                $this->get_admin_page_url()
            )
        );
        exit;
    }

    private function get_saved_password_hash(): string
    {
        return (string) get_option(self::OPTION_ACCESS_PASSWORD_HASH, '');
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

function wp_activity_logger(): WPActivityLogger
{
    static $plugin = null;

    if (! $plugin instanceof WPActivityLogger) {
        $plugin = new WPActivityLogger();
    }

    return $plugin;
}

require_once __DIR__ . '/includes/data_base_queries.php';
require_once __DIR__ . '/includes/admin_show_page.php';

wp_activity_logger();
