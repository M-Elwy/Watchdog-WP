<?php

namespace WebsiteWatchdog\Agent\Rest;

use WP_REST_Request;
use WP_REST_Response;

class HealthEndpoint
{
    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('website-watchdog/' . WEBSITE_WATCHDOG_API_VERSION, '/health', [
                'methods' => 'GET',
                'callback' => [$this, 'handle_request'],
                'permission_callback' => [$this, 'authorize'],
            ]);
        });
    }

    /**
     * The Watchdog SaaS authenticates with the site's API key as a Bearer token.
     */
    public function authorize(WP_REST_Request $request): bool
    {
        $stored_key = (string) get_option('website_watchdog_api_key', '');
        if ('' === $stored_key) {
            return false;
        }

        $header = (string) $request->get_header('authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return false;
        }

        return hash_equals($stored_key, trim($matches[1]));
    }

    public function handle_request(): WP_REST_Response
    {
        $response = new WP_REST_Response([
            'ok' => true,
            'site_id' => (string) get_option('website_watchdog_site_id', ''),
            'timestamp' => gmdate('c'),
        ], 200);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }
}
