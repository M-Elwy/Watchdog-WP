<?php

namespace WebsiteWatchdog\Agent\Admin;

class IncidentsView
{
    private const CACHE_KEY = 'website_watchdog_cached_incidents';
    private const CACHE_TTL = 60;
    private const PAGE_SIZE = 20;
    private const REQUEST_TIMEOUT = 15;

    public function handle_refresh(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'website-watchdog'));
        }

        check_admin_referer('website_watchdog_refresh_incidents');

        $page = isset($_POST['incident_page']) ? max(1, absint(wp_unslash($_POST['incident_page']))) : 1;
        delete_transient($this->cache_key($page));

        wp_safe_redirect(add_query_arg([
            'page' => 'website-watchdog-settings',
            'tab' => 'incidents',
            'incident_page' => $page,
        ], admin_url('admin.php')));
        exit;
    }

    public function render(): void
    {
        // Read-only pagination; no state is changed.
        $page = isset($_GET['incident_page']) ? max(1, absint($_GET['incident_page'])) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $result = $this->get_incidents($page);
        $incidents = $result['incidents'];

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Incident History', 'website-watchdog') . '</h1> ';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;">';
        echo '<input type="hidden" name="action" value="website_watchdog_refresh_incidents" />';
        echo '<input type="hidden" name="incident_page" value="' . esc_attr((string) $page) . '" />';
        wp_nonce_field('website_watchdog_refresh_incidents');
        submit_button(__('Refresh', 'website-watchdog'), 'secondary page-title-action', 'submit', false);
        echo '</form>';
        echo '<hr class="wp-header-end" />';

        if ('' !== $result['error']) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($result['error']) . '</p></div>';
            echo '</div>';
            return;
        }

        if (empty($incidents)) {
            echo '<p>' . esc_html__('No incident history available yet.', 'website-watchdog') . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>' . esc_html__('Severity / Type', 'website-watchdog') . '</th><th>' . esc_html__('Error Message & Location', 'website-watchdog') . '</th><th>' . esc_html__('Occurrences', 'website-watchdog') . '</th><th>' . esc_html__('Last Seen', 'website-watchdog') . '</th><th>' . esc_html__('Status', 'website-watchdog') . '</th></tr></thead>';
        echo '<tbody>';

        foreach ($incidents as $incident) {
            $message = isset($incident['message']) ? (string) $incident['message'] : __('Unknown error', 'website-watchdog');
            $location = isset($incident['location']) ? (string) $incident['location'] : '';
            $type = isset($incident['type']) ? (string) $incident['type'] : 'Unknown';
            $severity = isset($incident['severity']) ? (string) $incident['severity'] : '';
            $count = isset($incident['occurrences']) ? (int) $incident['occurrences'] : 0;
            $last_seen = isset($incident['last_seen']) ? (string) $incident['last_seen'] : '';
            $status = isset($incident['status']) ? (string) $incident['status'] : 'New';
            $stack_trace = isset($incident['stack_trace']) ? $incident['stack_trace'] : [];

            echo '<tr class="incident-row" data-stack="' . esc_attr(wp_json_encode($stack_trace)) . '">';
            echo '<td><span class="wp-ui-note ' . esc_attr($this->badge_class($severity, $type)) . '">' . esc_html($this->label_for($severity, $type)) . '</span></td>';
            echo '<td><strong>' . esc_html($message) . '</strong><br /><span class="description">' . esc_html($location) . '</span></td>';
            echo '<td><span class="badge">' . esc_html($count . 'x') . '</span></td>';
            echo '<td>' . esc_html($this->relative_time($last_seen)) . '</td>';
            echo '<td><span class="wp-ui-note ' . esc_attr($this->status_class($status)) . '">' . esc_html($status) . '</span></td>';
            echo '</tr>';
            echo '<tr class="incident-details" style="display:none;"><td colspan="5"><pre><code>' . esc_html($this->render_stack_trace($stack_trace)) . '</code></pre></td></tr>';
        }

        echo '</tbody></table>';
        $this->render_pagination($result['pagination']);
        echo '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll(".incident-row").forEach(function(row){row.addEventListener("click",function(){var next=row.parentElement.rows[row.rowIndex];if(next&&next.classList.contains("incident-details")){next.style.display=next.style.display==="none"?"table-row":"none";}});});});</script>';
        echo '</div>';
    }

    private function get_incidents(int $page): array
    {
        $cache_key = $this->cache_key($page);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $api_key = get_option('website_watchdog_api_key', '');
        if ('' === $api_key) {
            return $this->result([], ['total' => 0, 'page' => $page, 'pages' => 1], __('Configure an API key to view incident history.', 'website-watchdog'));
        }

        $url = add_query_arg(['limit' => self::PAGE_SIZE, 'page' => $page], WEBSITE_WATCHDOG_API_URL . '/' . WEBSITE_WATCHDOG_API_VERSION . '/sites/incidents');
        $response = wp_remote_get(
            $url,
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ],
                'timeout' => self::REQUEST_TIMEOUT,
            ]
        );

        if (is_wp_error($response)) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('Website Watchdog incident history request failed: ' . $response->get_error_message()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }

            /* translators: %s: technical error message from the HTTP request. */
            $message = sprintf(__('Could not load incident history (%s). Please try again.', 'website-watchdog'), $response->get_error_message());

            return $this->result([], ['total' => 0, 'page' => $page, 'pages' => 1], $message);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if (200 !== $code) {
            /* translators: %d: HTTP status code returned by the service. */
            $message = sprintf(__('The incident history service returned an error (HTTP %d). Please try again.', 'website-watchdog'), $code);

            return $this->result([], ['total' => 0, 'page' => $page, 'pages' => 1], $message);
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if (!is_array($decoded) || !isset($decoded['data']['incidents']) || !is_array($decoded['data']['incidents'])) {
            return $this->result([], ['total' => 0, 'page' => $page, 'pages' => 1], __('The incident history response was invalid.', 'website-watchdog'));
        }

        $incidents = array_map([$this, 'normalize_incident'], $decoded['data']['incidents']);
        $pagination = isset($decoded['data']['pagination']) && is_array($decoded['data']['pagination'])
            ? $decoded['data']['pagination']
            : ['total' => count($incidents), 'page' => $page, 'pages' => 1];
        $result = $this->result($incidents, $pagination, '');
        set_transient($cache_key, $result, self::CACHE_TTL);

        return $result;
    }

    private function cache_key(int $page): string
    {
        return self::CACHE_KEY . '_' . $page;
    }

    private function result(array $incidents, array $pagination, string $error): array
    {
        return ['incidents' => $incidents, 'pagination' => $pagination, 'error' => $error];
    }

    private function normalize_incident(array $incident): array
    {
        $location = isset($incident['file'], $incident['line']) ? $incident['file'] . ':' . $incident['line'] : '';
        if ('' === $location && !empty($incident['source'])) {
            $location = (string) $incident['source'];
        }

        $type = (string) ($incident['error_type'] ?? '');
        if ('' === $type) {
            $type = (string) ($incident['event_type'] ?? '');
        }

        return [
            'message' => (string) ($incident['message'] ?? __('Unknown error', 'website-watchdog')),
            'location' => $location,
            'type' => $type,
            'severity' => (string) ($incident['severity'] ?? ''),
            'occurrences' => (int) ($incident['occurrence_count'] ?? 0),
            'last_seen' => (string) ($incident['last_seen_at'] ?? ''),
            'status' => (string) ($incident['status'] ?? 'new'),
            'stack_trace' => [],
        ];
    }

    private function render_pagination(array $pagination): void
    {
        $pages = max(1, (int) ($pagination['pages'] ?? 1));
        $page = max(1, (int) ($pagination['page'] ?? 1));

        if ($pages <= 1) {
            return;
        }

        echo '<div class="tablenav"><div class="tablenav-pages">';
        for ($number = 1; $number <= $pages; $number++) {
            $url = add_query_arg(['tab' => 'incidents', 'incident_page' => $number], admin_url('admin.php'));
            if ($number === $page) {
                echo '<span class="paging-input">' . esc_html((string) $number) . '</span> ';
            } else {
                echo '<a class="button" href="' . esc_url($url) . '">' . esc_html((string) $number) . '</a> ';
            }
        }
        echo '</div></div>';
    }

    private function label_for(string $severity, string $type): string
    {
        if ('' !== $severity) {
            return strtoupper($severity);
        }

        return '' !== $type ? $type : __('Unknown', 'website-watchdog');
    }

    private function badge_class(string $severity, string $type): string
    {
        $level = strtolower($severity);

        if (in_array($level, ['critical', 'error'], true) || in_array($type, ['php_fatal', 'E_ERROR'], true)) {
            return 'error';
        }

        if ('warning' === $level || in_array($type, ['exception', 'php_error'], true)) {
            return 'warning';
        }

        return 'info';
    }

    private function status_class(string $status): string
    {
        switch (strtolower($status)) {
            case 'investigating':
                return 'warning';
            case 'resolved':
                return 'success';
            default:
                return 'error';
        }
    }

    private function relative_time(string $timestamp): string
    {
        if ('' === trim($timestamp)) {
            return __('Recently seen', 'website-watchdog');
        }

        $time = strtotime($timestamp);
        if (false === $time) {
            return $timestamp;
        }

        /* translators: %s: human-readable time difference, e.g. "5 minutes". */
        return sprintf(__('about %s ago', 'website-watchdog'), human_time_diff($time));
    }

    private function render_stack_trace($stack_trace): string
    {
        if (is_array($stack_trace)) {
            return implode("\n", array_filter(array_map('strval', $stack_trace)));
        }

        return (string) $stack_trace;
    }
}
