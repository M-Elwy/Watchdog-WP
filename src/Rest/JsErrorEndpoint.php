<?php

namespace WPWatchdog\Agent\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WPWatchdog\Agent\Http\Client;
use WPWatchdog\Agent\Sanitizer;

class JsErrorEndpoint
{
    protected $http_client;

    public function __construct(?Client $http_client = null)
    {
        $this->http_client = $http_client ?: new Client();
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('wp-watchdog/' . WP_WATCHDOG_API_VERSION, '/js-error', [
                'methods' => 'POST',
                'callback' => [$this, 'handle_request'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public function handle_request(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $request->get_json_params();
        $payload = is_array($payload) ? $payload : [];
        $sanitized = Sanitizer::clean_payload($payload);

        $forward_payload = [
            'event_type' => 'js_error',
            'timestamp' => gmdate('c'),
            'environment' => get_option('wp_watchdog_environment', 'production'),
            'payload' => $sanitized,
        ];

        $this->http_client->dispatch($forward_payload);

        return new WP_REST_Response(['ok' => true], 200);
    }
}
