<?php

namespace WPWatchdog\Agent\Admin;

class IncidentsView
{
    private const CACHE_KEY = 'site_watchdog_cached_incidents';
    private const CACHE_TTL = 60;
    private const PAGE_SIZE = 20;
    private const REQUEST_TIMEOUT = 5;

    public function render(): void
    {
        $refresh = isset($_GET['refresh_incidents']) && '1' === $_GET['refresh_incidents'];
        $page = isset($_GET['incident_page']) ? max(1, absint($_GET['incident_page'])) : 1;

        if ($refresh) {
            delete_transient($this->cache_key($page));
        }

        $result = $this->get_incidents($page);
        $incidents = $result['incidents'];

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Incident History', 'site-watchdog') . '</h1> ';
        echo '<a href="' . esc_url(add_query_arg(['tab' => 'incidents', 'refresh_incidents' => '1'])) . '" class="page-title-action">' . esc_html__('Refresh', 'site-watchdog') . '</a>';
        echo '<hr class="wp-header-end" />';

        if ('' !== $result['error']) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($result['error']) . '</p></div>';
            echo '</div>';
            return;
        }

        if (empty($incidents)) {
            echo '<p>' . esc_html__('No incident history available yet.', 'site-watchdog') . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>' . esc_html__('Severity / Type', 'site-watchdog') . '</th><th>' . esc_html__('Error Message & Location', 'site-watchdog') . '</th><th>' . esc_html__('Occurrences', 'site-watchdog') . '</th><th>' . esc_html__('Last Seen', 'site-watchdog') . '</th><th>' . esc_html__('Status', 'site-watchdog') . '</th></tr></thead>';
        echo '<tbody>';

        foreach ($incidents as $incident) {
            $message = isset($incident['message']) ? (string) $incident['message'] : __('Unknown error', 'site-watchdog');
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

        $api_key = get_option('site_watchdog_api_key', '');
        if ('' === $api_key) {
            return $this->result([], ['total' => 0, 'page' => $page, 'pages' => 1], __('Configure an API key to view incident history.', 'site-watchdog'));
        }

        $url = add_query_arg(['limit' => self::PAGE_SIZE, 'page' => $page], SITE_WATCHDOG_API_URL . '/' . SITE_WATCHDOG_API_VERSION . '/sites/incidents');
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
            return $this->result([], ['total' => 0, 'page' => $page, 'pages' => 1], __('Could not load incident history. Please try again.', 'site-watchdog'));
        }

        if (200 !== (int) wp_remote_retrieve_response_code($response)) {
            return $this->result([], ['total' => 0, 'page' => $page, 'pages' => 1], __('The incident history service returned an error. Please try again.', 'site-watchdog'));
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if (!is_array($decoded) || !isset($decoded['data']['incidents']) || !is_array($decoded['data']['incidents'])) {
            return $this->result([], ['total' => 0, 'page' => $page, 'pages' => 1], __('The incident history response was invalid.', 'site-watchdog'));
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
        return [
            'message' => (string) ($incident['message'] ?? __('Unknown error', 'site-watchdog')),
            'location' => isset($incident['file'], $incident['line']) ? $incident['file'] . ':' . $incident['line'] : '',
            'type' => (string) ($incident['error_type'] ?? 'Unknown'),
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
        if ('E_ERROR' === $severity) {
            return 'E_ERROR';
        }

        if ('Exception' === $type) {
            return 'Exception';
        }

        return 'JS Error';
    }

    private function badge_class(string $severity, string $type): string
    {
        if ('E_ERROR' === $severity) {
            return 'error';
        }

        if ('Exception' === $type) {
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
            return __('Recently seen', 'site-watchdog');
        }

        $time = strtotime($timestamp);
        if (false === $time) {
            return $timestamp;
        }

        return sprintf(__('about %s ago', 'site-watchdog'), human_time_diff($time));
    }

    private function render_stack_trace($stack_trace): string
    {
        if (is_array($stack_trace)) {
            return implode("\n", array_filter(array_map('strval', $stack_trace)));
        }

        return (string) $stack_trace;
    }
}
