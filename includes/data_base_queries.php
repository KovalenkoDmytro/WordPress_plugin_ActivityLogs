<?php

declare(strict_types=1);

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

function wp_activity_logger_record_activity(string $activity): void
{
    if (! is_user_logged_in()) {
        return;
    }

    global $wpdb;

    $table_name = $wpdb->prefix . 'activity_logs';
    $user_id = get_current_user_id();
    $ip_address = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'Unknown');

    $wpdb->insert(
        $table_name,
        [
            'user_id' => $user_id,
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

    [$where_sql, $where_params] = wp_activity_logger_build_where_clause($filters);

    $orderable_columns = [
        'id' => 'logs.id',
        'user' => 'users.user_login',
        'activity' => 'logs.activity',
        'ip_address' => 'logs.ip_address',
        'created_at' => 'logs.created_at',
    ];

    $order_by = $filters['order_by'] ?? 'created_at';
    $order_column = $orderable_columns[$order_by] ?? $orderable_columns['created_at'];
    $order = strtoupper((string) ($filters['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

    $count_sql = "
        SELECT COUNT(*)
        FROM {$table_name} AS logs
        LEFT JOIN {$users_table} AS users ON users.ID = logs.user_id
        WHERE {$where_sql}
    ";

    $count_query = wp_activity_logger_prepare_sql($count_sql, $where_params);
    $total_logs = (int) $wpdb->get_var($count_query);

    $log_sql = "
        SELECT logs.id, logs.user_id, logs.activity, logs.ip_address, logs.created_at, users.user_login
        FROM {$table_name} AS logs
        LEFT JOIN {$users_table} AS users ON users.ID = logs.user_id
        WHERE {$where_sql}
        ORDER BY {$order_column} {$order}
        LIMIT %d OFFSET %d
    ";

    $log_query = $wpdb->prepare(
        $log_sql,
        array_merge($where_params, [$rows_per_page, $offset])
    );

    $rows = $wpdb->get_results($log_query);

    $metrics_sql = "
        SELECT
            COUNT(*) AS total_logs,
            COUNT(DISTINCT logs.user_id) AS unique_users,
            COUNT(DISTINCT logs.ip_address) AS unique_ips,
            MAX(logs.created_at) AS latest_activity
        FROM {$table_name} AS logs
        LEFT JOIN {$users_table} AS users ON users.ID = logs.user_id
        WHERE {$where_sql}
    ";

    $metrics_query = wp_activity_logger_prepare_sql($metrics_sql, $where_params);
    $metrics_row = $wpdb->get_row($metrics_query);
    $total_pages = max(1, (int) ceil($total_logs / $rows_per_page));

    return [
        'items' => array_map(
            static fn (object $row): array => [
                'id' => (int) $row->id,
                'user' => $row->user_login ?: __('Unknown user', 'wp-activity-logger'),
                'activity' => (string) $row->activity,
                'ipAddress' => (string) $row->ip_address,
                'createdAt' => wp_activity_logger_format_timestamp((string) $row->created_at),
            ],
            $rows
        ),
        'metrics' => [
            'totalLogs' => (int) ($metrics_row->total_logs ?? 0),
            'uniqueUsers' => (int) ($metrics_row->unique_users ?? 0),
            'uniqueIps' => (int) ($metrics_row->unique_ips ?? 0),
            'latestActivity' => ! empty($metrics_row->latest_activity)
                ? wp_activity_logger_format_timestamp((string) $metrics_row->latest_activity)
                : __('No activity yet', 'wp-activity-logger'),
        ],
        'pagination' => [
            'currentPage' => $current_page,
            'totalPages' => $total_pages,
            'totalLogs' => $total_logs,
            'perPage' => $rows_per_page,
        ],
    ];
}

function wp_activity_logger_build_where_clause(array $filters): array
{
    global $wpdb;

    $clauses = ['1=1'];
    $params = [];

    $start_date = (string) ($filters['start_date'] ?? '');
    if ($start_date !== '') {
        $start_boundary = wp_activity_logger_get_utc_date_boundary($start_date, false);
        if ($start_boundary !== null) {
            $clauses[] = 'logs.created_at >= %s';
            $params[] = $start_boundary;
        }
    }

    $end_date = (string) ($filters['end_date'] ?? '');
    if ($end_date !== '') {
        $end_boundary = wp_activity_logger_get_utc_date_boundary($end_date, true);
        if ($end_boundary !== null) {
            $clauses[] = 'logs.created_at <= %s';
            $params[] = $end_boundary;
        }
    }

    $username = (string) ($filters['username'] ?? '');
    if ($username !== '') {
        $clauses[] = 'users.user_login LIKE %s';
        $params[] = '%' . $wpdb->esc_like($username) . '%';
    }

    $search = (string) ($filters['search'] ?? '');
    if ($search !== '') {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $clauses[] = '(logs.activity LIKE %s OR logs.ip_address LIKE %s OR users.user_login LIKE %s)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $ip_address = (string) ($filters['ip_address'] ?? '');
    if ($ip_address !== '') {
        $clauses[] = 'logs.ip_address LIKE %s';
        $params[] = '%' . $wpdb->esc_like($ip_address) . '%';
    }

    return [implode(' AND ', $clauses), $params];
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

function wp_activity_logger_prepare_sql(string $sql, array $params): string
{
    global $wpdb;

    if ($params === []) {
        return $sql;
    }

    return $wpdb->prepare($sql, $params);
}

function wp_activity_logger_delete_expired_logs(int $retention_days): void
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'activity_logs';
    $cutoff = gmdate('Y-m-d H:i:s', time() - ($retention_days * DAY_IN_SECONDS));

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$table_name} WHERE created_at < %s",
            $cutoff
        )
    );
}

function wp_activity_logger_timezone(): DateTimeZone
{
    return new DateTimeZone('America/Edmonton');
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
