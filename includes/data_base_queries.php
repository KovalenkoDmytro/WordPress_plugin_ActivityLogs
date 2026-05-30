<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

function wp_activity_logger_install(): void
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'activity_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        activity TEXT NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY user_id (user_id),
        KEY created_at (created_at)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

function wp_activity_logger_record_activity(string $activity, ?int $user_id = null): void
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'activity_logs';
    $resolved_user_id = $user_id ?? get_current_user_id();
    if ($resolved_user_id < 0) {
        $resolved_user_id = 0;
    }

    $ip_address = isset($_SERVER['REMOTE_ADDR'])
        ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR']))
        : 'Unknown';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Writing to the plugin's custom activity log table; WordPress has no higher-level API for it.
    $wpdb->insert(
        $table_name,
        [
            'user_id' => $resolved_user_id,
            'activity' => $activity,
            'ip_address' => $ip_address,
            'created_at' => current_time('mysql', true),
        ],
        [
            '%d',
            '%s',
            '%s',
            '%s',
        ]
    );
}

function wp_activity_logger_get_logs_payload(array $filters): array
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'activity_logs';
    $users_table = $wpdb->users;
    $rows_per_page = max(10, min(100, (int) ($filters['per_page'] ?? 25)));
    $current_page = max(1, (int) ($filters['paged'] ?? 1));
    $offset = ($current_page - 1) * $rows_per_page;

    $where_params = wp_activity_logger_build_where_params($filters);

    // Map each sortable column to a (table alias, column) pair so the ORDER BY target can be
    // passed as %i.%i identifier placeholders instead of being interpolated into the query.
    $order_identifiers = [
        'id' => ['logs', 'id'],
        'user' => ['users', 'user_login'],
        'activity' => ['logs', 'activity'],
        'ip_address' => ['logs', 'ip_address'],
        'created_at' => ['logs', 'created_at'],
    ];

    $order_key = (string) ($filters['order_by'] ?? 'created_at');
    [$order_table, $order_field] = $order_identifiers[$order_key] ?? $order_identifiers['created_at'];
    $is_ascending = strtoupper((string) ($filters['order'] ?? 'DESC')) === 'ASC';

    // Every dynamic part of these queries is a real placeholder: table/column names use %i,
    // filter values use %s (built by wp_activity_logger_build_where_params()), LIMIT/OFFSET use %d.
    // The WHERE clause is a fixed literal: the created_at range always applies (with DATETIME
    // min/max bounds when unset) and each text filter self-disables via a `%s = ''` guard that
    // short-circuits to TRUE. No PHP variable is ever interpolated into the SQL string.
    // Direct, uncached reads are required for live activity-log data.

    // ReplacementsWrongNumber is a false positive: the spread (...$where_params) carries the
    // remaining placeholder values, which PHPCS cannot count statically. The count is correct.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
    $metrics_row = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS total_logs, COUNT(DISTINCT logs.user_id) AS unique_users, COUNT(DISTINCT logs.ip_address) AS unique_ips, MAX(logs.created_at) AS latest_activity FROM %i AS logs LEFT JOIN %i AS users ON users.ID = logs.user_id WHERE 1 = 1 AND logs.created_at >= %s AND logs.created_at <= %s AND (%s = '' OR users.user_login LIKE %s) AND (%s = '' OR logs.activity LIKE %s OR logs.ip_address LIKE %s OR users.user_login LIKE %s) AND (%s = '' OR logs.ip_address LIKE %s)", $table_name, $users_table, ...$where_params));

    $total_logs = (int) ($metrics_row->total_logs ?? 0);
    $total_pages = max(1, (int) ceil($total_logs / $rows_per_page));

    $rows_params = array_merge(
        [$table_name, $users_table],
        $where_params,
        [$order_table, $order_field, $rows_per_page, $offset]
    );

    // ReplacementsWrongNumber is a false positive here too: ...$rows_params holds every value.
    if ($is_ascending) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $wpdb->get_results($wpdb->prepare("SELECT logs.id, logs.user_id, logs.activity, logs.ip_address, logs.created_at, users.user_login FROM %i AS logs LEFT JOIN %i AS users ON users.ID = logs.user_id WHERE 1 = 1 AND logs.created_at >= %s AND logs.created_at <= %s AND (%s = '' OR users.user_login LIKE %s) AND (%s = '' OR logs.activity LIKE %s OR logs.ip_address LIKE %s OR users.user_login LIKE %s) AND (%s = '' OR logs.ip_address LIKE %s) ORDER BY %i.%i ASC LIMIT %d OFFSET %d", ...$rows_params));
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $wpdb->get_results($wpdb->prepare("SELECT logs.id, logs.user_id, logs.activity, logs.ip_address, logs.created_at, users.user_login FROM %i AS logs LEFT JOIN %i AS users ON users.ID = logs.user_id WHERE 1 = 1 AND logs.created_at >= %s AND logs.created_at <= %s AND (%s = '' OR users.user_login LIKE %s) AND (%s = '' OR logs.activity LIKE %s OR logs.ip_address LIKE %s OR users.user_login LIKE %s) AND (%s = '' OR logs.ip_address LIKE %s) ORDER BY %i.%i DESC LIMIT %d OFFSET %d", ...$rows_params));
    }

    return [
        'items' => array_map(
            static function (object $row): array {
                $user_id = (int) $row->user_id;
                $user_label = wp_activity_logger_resolve_user_label(
                    $user_id,
                    is_string($row->user_login) ? $row->user_login : ''
                );

                return [
                    'id' => (int) $row->id,
                    'user' => $user_label,
                    'activity' => wp_activity_logger_normalize_activity_text((string) $row->activity, $user_label),
                    'ipAddress' => (string) $row->ip_address,
                    'createdAt' => wp_activity_logger_format_timestamp((string) $row->created_at),
                ];
            },
            $rows
        ),
        'metrics' => [
            'totalLogs' => (int) ($metrics_row->total_logs ?? 0),
            'uniqueUsers' => (int) ($metrics_row->unique_users ?? 0),
            'uniqueIps' => (int) ($metrics_row->unique_ips ?? 0),
            'latestActivity' => ! empty($metrics_row->latest_activity)
                ? wp_activity_logger_format_timestamp((string) $metrics_row->latest_activity)
                : __('No activity yet', 'dk-user-activity-logger'),
        ],
        'pagination' => [
            'currentPage' => $current_page,
            'totalPages' => $total_pages,
            'totalLogs' => $total_logs,
            'perPage' => $rows_per_page,
        ],
    ];
}

/**
 * Build the ordered replacement values for the fixed WHERE clause used by the log queries.
 *
 * The clause keeps a constant shape so the SQL string never has to be assembled from
 * variables. The created_at range always applies, falling back to the DATETIME min/max
 * bounds when a date filter is absent (an empty string cannot be compared to a DATETIME
 * column under MySQL strict mode). Each text filter contributes a guard value — an empty
 * string short-circuits its condition to TRUE — followed by its comparison value(s).
 *
 * @param array<string, mixed> $filters
 *
 * @return list<string>
 */
function wp_activity_logger_build_where_params(array $filters): array
{
    global $wpdb;

    $start_boundary = wp_activity_logger_get_utc_date_boundary((string) ($filters['start_date'] ?? ''), false) ?? '1000-01-01 00:00:00';
    $end_boundary = wp_activity_logger_get_utc_date_boundary((string) ($filters['end_date'] ?? ''), true) ?? '9999-12-31 23:59:59';
    $username = (string) ($filters['username'] ?? '');
    $search = (string) ($filters['search'] ?? '');
    $ip_address = (string) ($filters['ip_address'] ?? '');

    $username_like = '%' . $wpdb->esc_like($username) . '%';
    $search_like = '%' . $wpdb->esc_like($search) . '%';
    $ip_like = '%' . $wpdb->esc_like($ip_address) . '%';

    return [
        $start_boundary,
        $end_boundary,
        $username,
        $username_like,
        $search,
        $search_like,
        $search_like,
        $search_like,
        $ip_address,
        $ip_like,
    ];
}

function wp_activity_logger_format_timestamp(string $timestamp): string
{
    $format = trim(get_option('date_format') . ' ' . get_option('time_format'));

    try {
        $date_time = new DateTimeImmutable($timestamp, new DateTimeZone('UTC'));
    } catch (Exception) {
        return $timestamp;
    }

    return $date_time
        ->setTimezone(wp_activity_logger_timezone())
        ->format($format . ' T');
}

function wp_activity_logger_resolve_user_label(int $user_id, string $user_login): string
{
    if ($user_login !== '') {
        return $user_login;
    }

    if ($user_id === 0) {
        return __('System', 'dk-user-activity-logger');
    }

    return sprintf(
        /* translators: %d: WordPress user ID */
        __('Deleted user #%d', 'dk-user-activity-logger'),
        $user_id
    );
}

function wp_activity_logger_normalize_activity_text(string $activity, string $user_label): string
{
    $normalized = preg_replace("/^User\\s+(?:''|\"\")\\s+/", $user_label . ' ', $activity, 1, $count);

    if (is_string($normalized) && $count === 1) {
        return $normalized;
    }

    return $activity;
}

function wp_activity_logger_delete_expired_logs(int $retention_days): void
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'activity_logs';
    $cutoff = gmdate('Y-m-d H:i:s', time() - ($retention_days * DAY_IN_SECONDS));

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Scheduled cleanup deletes expired rows from the custom activity log table.
    $wpdb->query(
        $wpdb->prepare(
            'DELETE FROM %i WHERE created_at < %s',
            $table_name,
            $cutoff
        )
    );
}

function wp_activity_logger_default_timezone_name(): string
{
    return 'America/Edmonton';
}

function wp_activity_logger_is_valid_timezone(string $timezone_name): bool
{
    return in_array($timezone_name, timezone_identifiers_list(), true);
}

function wp_activity_logger_timezone_name(): string
{
    $timezone_name = (string) get_option('wp_activity_logger_timezone', '');

    if (wp_activity_logger_is_valid_timezone($timezone_name)) {
        return $timezone_name;
    }

    return wp_activity_logger_default_timezone_name();
}

function wp_activity_logger_timezone(): DateTimeZone
{
    return new DateTimeZone(wp_activity_logger_timezone_name());
}

function wp_activity_logger_next_maintenance_timestamp(): int
{
    $next_run = new DateTimeImmutable('now', wp_activity_logger_timezone());
    $next_run = $next_run->setTime(2, 0);

    if ($next_run->getTimestamp() <= time()) {
        $next_run = $next_run->modify('+1 day');
    }

    return $next_run->getTimestamp();
}

function wp_activity_logger_get_utc_date_boundary(string $date, bool $end_of_day): ?string
{
    if ($date === '') {
        return null;
    }

    $time = $end_of_day ? '23:59:59' : '00:00:00';

    try {
        $date_time = new DateTimeImmutable($date . ' ' . $time, wp_activity_logger_timezone());
    } catch (Exception) {
        return null;
    }

    return $date_time
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');
}
