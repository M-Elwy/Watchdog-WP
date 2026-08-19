<?php

namespace WPWatchdog\Agent\Admin;

class IncidentsView
{
    private const CACHE_KEY = 'site_watchdog_cached_incidents';
    private const CACHE_TTL = 60;

    public function render(): void
    {
        $refresh = isset($_GET['refresh_incidents']) && '1' === $_GET['refresh_incidents'];

        if ($refresh) {
            delete_transient(self::CACHE_KEY);
        }

        $incidents = $this->get_incidents();

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Incident History', 'wp-watchdog') . '</h1> ';
        echo '<a href="' . esc_url(add_query_arg('refresh_incidents', '1')) . '" class="page-title-action">' . esc_html__('Refresh', 'wp-watchdog') . '</a>';
        echo '<hr class="wp-header-end" />';

        if (empty($incidents)) {
            echo '<p>' . esc_html__('No incident history available yet.', 'wp-watchdog') . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>' . esc_html__('Severity / Type', 'wp-watchdog') . '</th><th>' . esc_html__('Error Message & Location', 'wp-watchdog') . '</th><th>' . esc_html__('Occurrences', 'wp-watchdog') . '</th><th>' . esc_html__('Last Seen', 'wp-watchdog') . '</th><th>' . esc_html__('Status', 'wp-watchdog') . '</th></tr></thead>';
        echo '<tbody>';

        foreach ($incidents as $incident) {
            $message = isset($incident['message']) ? (string) $incident['message'] : __('Unknown error', 'wp-watchdog');
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
        echo '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll(".incident-row").forEach(function(row){row.addEventListener("click",function(){var next=row.parentElement.rows[row.rowIndex];if(next&&next.classList.contains("incident-details")){next.style.display=next.style.display==="none"?"table-row":"none";}});});});</script>';
        echo '</div>';
    }

    private function get_incidents(): array
    {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $api_key = get_option('site_watchdog_api_key', '');
        if ('' === $api_key) {
            return [];
        }

        $response = wp_remote_get(
            SITE_WATCHDOG_API_URL . '/' . SITE_WATCHDOG_API_VERSION . '/sites/incidents',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 10,
            ]
        );

        if (is_wp_error($response)) {
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return [];
        }

        $incidents = isset($decoded['incidents']) && is_array($decoded['incidents']) ? $decoded['incidents'] : $decoded;
        set_transient(self::CACHE_KEY, $incidents, self::CACHE_TTL);

        return $incidents;
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
            return __('Recently seen', 'wp-watchdog');
        }

        $time = strtotime($timestamp);
        if (false === $time) {
            return $timestamp;
        }

        return sprintf(__('about %s ago', 'wp-watchdog'), human_time_diff($time));
    }

    private function render_stack_trace($stack_trace): string
    {
        if (is_array($stack_trace)) {
            return implode("\n", array_filter(array_map('strval', $stack_trace)));
        }

        return (string) $stack_trace;
    }
}
