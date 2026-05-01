<?php

declare(strict_types=1);

function wp_activity_logger_normalize_filters(array $source): array
{
    $allowed_order_columns = ['id', 'user', 'activity', 'ip_address', 'created_at'];
    $allowed_order = ['ASC', 'DESC'];

    $order_by = sanitize_key((string) ($source['order_by'] ?? 'created_at'));
    if (! in_array($order_by, $allowed_order_columns, true)) {
        $order_by = 'created_at';
    }

    $order = strtoupper(sanitize_text_field((string) ($source['order'] ?? 'DESC')));
    if (! in_array($order, $allowed_order, true)) {
        $order = 'DESC';
    }

    return [
        'start_date' => sanitize_text_field((string) ($source['start_date'] ?? '')),
        'end_date' => sanitize_text_field((string) ($source['end_date'] ?? '')),
        'username' => sanitize_text_field((string) ($source['username'] ?? '')),
        'search' => sanitize_text_field((string) ($source['search'] ?? '')),
        'ip_address' => sanitize_text_field((string) ($source['ip_address'] ?? '')),
        'order_by' => $order_by,
        'order' => $order,
        'paged' => max(1, absint($source['paged'] ?? 1)),
        'per_page' => 25,
    ];
}

function wp_activity_logger_admin_page(): void
{
    if (! wp_activity_logger()->can_view_logs()) {
        wp_die(esc_html__('You are not allowed to view these logs.', 'wp-activity-logger'));
    }

    $page_url = wp_activity_logger()->get_admin_page_url();
    $show_unlock = wp_activity_logger()->is_password_required() && ! wp_activity_logger()->is_screen_unlocked();

    echo '<div class="wrap wp-activity-logger-shell">';
    echo '<h1>' . esc_html__('Activity Logs', 'wp-activity-logger') . '</h1>';

    if ($show_unlock) {
        wp_activity_logger_render_unlock_screen($page_url);
        echo '</div>';

        return;
    }

    $filters = wp_activity_logger_normalize_filters($_GET);
    $payload = wp_activity_logger_get_logs_payload($filters);

    wp_activity_logger_render_dashboard($page_url, $filters, $payload);
    echo '</div>';
}

function wp_activity_logger_render_unlock_screen(string $page_url): void
{
    $has_error = isset($_GET['wp_activity_logger_access_error']) && sanitize_text_field(wp_unslash($_GET['wp_activity_logger_access_error'])) === '1';

    echo '<div class="wp-activity-logger-lock-card">';
    echo '<p class="wp-activity-logger-eyebrow">' . esc_html__('Private screen', 'wp-activity-logger') . '</p>';
    echo '<h2>' . esc_html__('Unlock the log viewer', 'wp-activity-logger') . '</h2>';
    echo '<p>' . esc_html__('This viewer is hidden from the standard admin navigation and protected with an access password.', 'wp-activity-logger') . '</p>';

    if ($has_error) {
        echo '<div class="notice notice-error inline"><p>' . esc_html__('The password did not match. Try again.', 'wp-activity-logger') . '</p></div>';
    }

    echo '<form method="post" action="' . esc_url($page_url) . '" class="wp-activity-logger-unlock-form">';
    wp_nonce_field('wp_activity_logger_unlock');
    echo '<input type="hidden" name="wp_activity_logger_unlock" value="1">';
    echo '<label for="wp-activity-logger-password">' . esc_html__('Access password', 'wp-activity-logger') . '</label>';
    echo '<input id="wp-activity-logger-password" name="wp_activity_logger_password" type="password" class="regular-text" autocomplete="current-password" required>';
    echo '<button type="submit" class="button button-primary">' . esc_html__('Unlock logs', 'wp-activity-logger') . '</button>';
    echo '</form>';
    echo '</div>';
}

function wp_activity_logger_render_dashboard(string $page_url, array $filters, array $payload): void
{
    $metrics = $payload['metrics'];
    $pagination = $payload['pagination'];
    $initial_payload = wp_json_encode($payload);
    $security_saved = isset($_GET['wp_activity_logger_security_saved']) ? sanitize_text_field(wp_unslash($_GET['wp_activity_logger_security_saved'])) : '';
    $security_error = isset($_GET['wp_activity_logger_security_error']) ? sanitize_text_field(wp_unslash($_GET['wp_activity_logger_security_error'])) : '';

    echo '<div class="wp-activity-logger-app" data-page-url="' . esc_url($page_url) . '">';

    echo '<section class="wp-activity-logger-hero">';
    echo '<div>';
    echo '<p class="wp-activity-logger-eyebrow">' . esc_html__('Owner-only activity monitor', 'wp-activity-logger') . '</p>';
    echo '<h2>' . esc_html__('A cleaner audit trail for client work', 'wp-activity-logger') . '</h2>';
    echo '<p>' . esc_html__('Track edits, plugin changes, and login activity without exposing the screen in the regular admin menu.', 'wp-activity-logger') . '</p>';
    echo '</div>';
    echo '<div class="wp-activity-logger-actions">';
    echo '<a class="button button-secondary" href="' . esc_url($page_url) . '">' . esc_html__('Reset view', 'wp-activity-logger') . '</a>';
    echo '<button type="button" class="button button-primary" data-refresh-now>' . esc_html__('Refresh now', 'wp-activity-logger') . '</button>';
    echo '</div>';
    echo '</section>';

    wp_activity_logger_render_security_panel($page_url, $security_saved, $security_error);

    echo '<section class="wp-activity-logger-stats">';
    wp_activity_logger_render_stat_card(__('Total logs', 'wp-activity-logger'), (string) $metrics['totalLogs'], 'total-logs');
    wp_activity_logger_render_stat_card(__('Users seen', 'wp-activity-logger'), (string) $metrics['uniqueUsers'], 'unique-users');
    wp_activity_logger_render_stat_card(__('IP addresses', 'wp-activity-logger'), (string) $metrics['uniqueIps'], 'unique-ips');
    wp_activity_logger_render_stat_card(__('Latest activity', 'wp-activity-logger'), (string) $metrics['latestActivity'], 'latest-activity');
    echo '</section>';

    echo '<section class="wp-activity-logger-panel">';
    echo '<form method="get" action="' . esc_url($page_url) . '" class="wp-activity-logger-filters" data-filter-form>';
    echo '<div class="wp-activity-logger-field">';
    echo '<label for="wpal-start-date">' . esc_html__('From', 'wp-activity-logger') . '</label>';
    echo '<input id="wpal-start-date" type="date" name="start_date" value="' . esc_attr($filters['start_date']) . '">';
    echo '</div>';
    echo '<div class="wp-activity-logger-field">';
    echo '<label for="wpal-end-date">' . esc_html__('To', 'wp-activity-logger') . '</label>';
    echo '<input id="wpal-end-date" type="date" name="end_date" value="' . esc_attr($filters['end_date']) . '">';
    echo '</div>';
    echo '<div class="wp-activity-logger-field">';
    echo '<label for="wpal-username">' . esc_html__('Username', 'wp-activity-logger') . '</label>';
    echo '<input id="wpal-username" type="text" name="username" value="' . esc_attr($filters['username']) . '" placeholder="' . esc_attr__('Filter by username', 'wp-activity-logger') . '">';
    echo '</div>';
    echo '<div class="wp-activity-logger-field wp-activity-logger-field-wide">';
    echo '<label for="wpal-search">' . esc_html__('Search', 'wp-activity-logger') . '</label>';
    echo '<input id="wpal-search" type="text" name="search" value="' . esc_attr($filters['search']) . '" placeholder="' . esc_attr__('Search activity text or IP address', 'wp-activity-logger') . '">';
    echo '</div>';
    echo '<div class="wp-activity-logger-field">';
    echo '<label for="wpal-ip-address">' . esc_html__('IP address', 'wp-activity-logger') . '</label>';
    echo '<input id="wpal-ip-address" type="text" name="ip_address" value="' . esc_attr($filters['ip_address']) . '" placeholder="' . esc_attr__('Contains...', 'wp-activity-logger') . '">';
    echo '</div>';
    echo '<div class="wp-activity-logger-field">';
    echo '<label for="wpal-order-by">' . esc_html__('Sort by', 'wp-activity-logger') . '</label>';
    echo '<select id="wpal-order-by" name="order_by">';
    wp_activity_logger_render_select_option('created_at', $filters['order_by'], __('Newest activity', 'wp-activity-logger'));
    wp_activity_logger_render_select_option('id', $filters['order_by'], __('Log ID', 'wp-activity-logger'));
    wp_activity_logger_render_select_option('user', $filters['order_by'], __('Username', 'wp-activity-logger'));
    wp_activity_logger_render_select_option('activity', $filters['order_by'], __('Activity text', 'wp-activity-logger'));
    wp_activity_logger_render_select_option('ip_address', $filters['order_by'], __('IP address', 'wp-activity-logger'));
    echo '</select>';
    echo '</div>';
    echo '<div class="wp-activity-logger-field">';
    echo '<label for="wpal-order">' . esc_html__('Direction', 'wp-activity-logger') . '</label>';
    echo '<select id="wpal-order" name="order">';
    wp_activity_logger_render_select_option('DESC', $filters['order'], __('Descending', 'wp-activity-logger'));
    wp_activity_logger_render_select_option('ASC', $filters['order'], __('Ascending', 'wp-activity-logger'));
    echo '</select>';
    echo '</div>';
    echo '<div class="wp-activity-logger-field">';
    echo '<label for="wpal-refresh-interval">' . esc_html__('Auto refresh', 'wp-activity-logger') . '</label>';
    echo '<select id="wpal-refresh-interval" name="refresh_interval" data-refresh-interval>';
    echo '<option value="0">' . esc_html__('Off', 'wp-activity-logger') . '</option>';
    echo '<option value="15">' . esc_html__('Every 15s', 'wp-activity-logger') . '</option>';
    echo '<option value="30" selected>' . esc_html__('Every 30s', 'wp-activity-logger') . '</option>';
    echo '<option value="60">' . esc_html__('Every 60s', 'wp-activity-logger') . '</option>';
    echo '</select>';
    echo '</div>';
    echo '<div class="wp-activity-logger-filter-actions">';
    echo '<button type="submit" class="button button-primary">' . esc_html__('Apply filters', 'wp-activity-logger') . '</button>';
    echo '<a class="button button-secondary" href="' . esc_url($page_url) . '">' . esc_html__('Clear filters', 'wp-activity-logger') . '</a>';
    echo '</div>';
    echo '</form>';

    echo '<div class="wp-activity-logger-meta-bar">';
    echo '<div class="wp-activity-logger-status"><span class="wp-activity-logger-status-dot"></span><span data-refresh-status>' . esc_html__('Live refresh ready', 'wp-activity-logger') . '</span></div>';
    echo '<div class="wp-activity-logger-meta-right">';
    echo '<span data-last-updated>' . esc_html__('Waiting for the next refresh...', 'wp-activity-logger') . '</span>';
    echo '<span class="wp-activity-logger-page-count" data-page-count>';
    echo esc_html(
        sprintf(
            __('Page %1$d of %2$d', 'wp-activity-logger'),
            $pagination['currentPage'],
            $pagination['totalPages']
        )
    );
    echo '</span>';
    echo '</div>';
    echo '</div>';

    echo '<div class="wp-activity-logger-table-wrap">';
    echo '<table class="wp-activity-logger-table widefat striped">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__('ID', 'wp-activity-logger') . '</th>';
    echo '<th>' . esc_html__('User', 'wp-activity-logger') . '</th>';
    echo '<th>' . esc_html__('Activity', 'wp-activity-logger') . '</th>';
    echo '<th>' . esc_html__('IP address', 'wp-activity-logger') . '</th>';
    echo '<th>' . esc_html__('Timestamp', 'wp-activity-logger') . '</th>';
    echo '</tr></thead>';
    echo '<tbody data-log-rows>';

    foreach ($payload['items'] as $item) {
        wp_activity_logger_render_table_row($item);
    }

    if ($payload['items'] === []) {
        echo '<tr data-empty-state><td colspan="5">' . esc_html__('No matching logs found.', 'wp-activity-logger') . '</td></tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';

    echo '<div class="wp-activity-logger-pagination">';
    echo '<button type="button" class="button" data-page-direction="prev">' . esc_html__('Previous', 'wp-activity-logger') . '</button>';
    echo '<span class="wp-activity-logger-pagination-summary" data-pagination-summary>';
    echo esc_html(
        sprintf(
            __('Showing %1$d logs', 'wp-activity-logger'),
            $pagination['totalLogs']
        )
    );
    echo '</span>';
    echo '<button type="button" class="button" data-page-direction="next">' . esc_html__('Next', 'wp-activity-logger') . '</button>';
    echo '</div>';
    echo '</section>';

    echo '<script type="application/json" id="wp-activity-logger-initial-state">' . esc_html($initial_payload ?: '{}') . '</script>';
    echo '</div>';
}

function wp_activity_logger_render_security_panel(string $page_url, string $security_saved, string $security_error): void
{
    echo '<section class="wp-activity-logger-security-card">';
    echo '<div class="wp-activity-logger-security-copy">';
    echo '<p class="wp-activity-logger-eyebrow">' . esc_html__('Protection', 'wp-activity-logger') . '</p>';
    echo '<h3>' . esc_html__('Hidden screen access', 'wp-activity-logger') . '</h3>';
    echo '<p>' . esc_html__('Only the plugin owner account can open this screen. You can add a second password here when you want an extra lock before the logs are shown.', 'wp-activity-logger') . '</p>';
    echo '<p><strong>' . esc_html__('Private URL:', 'wp-activity-logger') . '</strong> <a href="' . esc_url($page_url) . '">' . esc_html($page_url) . '</a></p>';

    if ($security_saved === 'updated') {
        echo '<div class="notice notice-success inline"><p>' . esc_html__('The access password was updated.', 'wp-activity-logger') . '</p></div>';
    } elseif ($security_saved === 'removed') {
        echo '<div class="notice notice-success inline"><p>' . esc_html__('The access password was removed. Hidden owner-only access is still active.', 'wp-activity-logger') . '</p></div>';
    } elseif ($security_error === 'mismatch') {
        echo '<div class="notice notice-error inline"><p>' . esc_html__('Password and confirmation must match.', 'wp-activity-logger') . '</p></div>';
    }

    $password_status = wp_activity_logger()->has_saved_password()
        ? __('Saved password active', 'wp-activity-logger')
        : __('No saved password yet', 'wp-activity-logger');

    echo '<p class="wp-activity-logger-password-status">' . esc_html($password_status) . '</p>';
    echo '</div>';

    echo '<form method="post" action="' . esc_url($page_url) . '" class="wp-activity-logger-security-form">';
    wp_nonce_field('wp_activity_logger_save_security');
    echo '<input type="hidden" name="wp_activity_logger_save_security" value="1">';
    echo '<label for="wpal-new-password">' . esc_html__('New access password', 'wp-activity-logger') . '</label>';
    echo '<input id="wpal-new-password" type="password" name="wp_activity_logger_new_password" class="regular-text" autocomplete="new-password">';
    echo '<label for="wpal-confirm-password">' . esc_html__('Confirm password', 'wp-activity-logger') . '</label>';
    echo '<input id="wpal-confirm-password" type="password" name="wp_activity_logger_confirm_password" class="regular-text" autocomplete="new-password">';
    echo '<div class="wp-activity-logger-security-actions">';
    echo '<button type="submit" class="button button-primary">' . esc_html__('Save password', 'wp-activity-logger') . '</button>';
    echo '<button type="submit" class="button button-secondary" name="wp_activity_logger_remove_password" value="1">' . esc_html__('Remove password', 'wp-activity-logger') . '</button>';
    echo '</div>';
    echo '</form>';
    echo '</section>';
}

function wp_activity_logger_render_stat_card(string $label, string $value, string $metric_key): void
{
    echo '<article class="wp-activity-logger-stat-card">';
    echo '<span class="wp-activity-logger-stat-label">' . esc_html($label) . '</span>';
    echo '<strong class="wp-activity-logger-stat-value" data-metric="' . esc_attr($metric_key) . '">' . esc_html($value) . '</strong>';
    echo '</article>';
}

function wp_activity_logger_render_select_option(string $value, string $current_value, string $label): void
{
    echo '<option value="' . esc_attr($value) . '"' . selected($current_value, $value, false) . '>' . esc_html($label) . '</option>';
}

function wp_activity_logger_render_table_row(array $item): void
{
    echo '<tr data-log-id="' . esc_attr((string) $item['id']) . '">';
    echo '<td>' . esc_html((string) $item['id']) . '</td>';
    echo '<td><span class="wp-activity-logger-user-pill">' . esc_html((string) $item['user']) . '</span></td>';
    echo '<td>' . esc_html((string) $item['activity']) . '</td>';
    echo '<td><code>' . esc_html((string) $item['ipAddress']) . '</code></td>';
    echo '<td>' . esc_html((string) $item['createdAt']) . '</td>';
    echo '</tr>';
}
