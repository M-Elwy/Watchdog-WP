<?php

namespace WebsiteWatchdog\Agent\SelfTest\Cases;

use WebsiteWatchdog\Agent\Rest\JsErrorEndpoint;
use WebsiteWatchdog\Agent\SelfTest\Sandbox;
use WebsiteWatchdog\Agent\SelfTest\TestCase;

class JsErrorEndpointTest extends TestCase
{
    private const ROUTE = '/website-watchdog/v1/js-error';

    public function setUp(): void
    {
        $_SERVER['REMOTE_ADDR'] = Sandbox::TEST_IP;
        $this->configure_verified_key();
    }

    private function post(array $json): \WP_REST_Response
    {
        return $this->rest('POST', self::ROUTE, ['json' => $json]);
    }

    public function test_route_is_public_and_forwards_error(): void
    {
        $this->as_visitor();

        $response = $this->post(['type' => 'window.onerror', 'message' => 'Boom']);

        $this->assert_status($response, 200);
        $this->assert_true($response->get_data()['ok']);
        $body = $this->last_http_json();
        $this->assert_same('js_error', $body['event_type']);
        $this->assert_same('Boom', $body['payload']['message']);
    }

    public function test_rejects_oversized_body(): void
    {
        $response = $this->post(['message' => str_repeat('a', 20000)]);

        $this->assert_status($response, 413);
        $this->assert_count(0, $this->http_calls());
    }

    public function test_rejects_invalid_json(): void
    {
        $response = $this->rest('POST', self::ROUTE, ['json' => 'not json']);

        $this->assert_status($response, 400);
        $this->assert_count(0, $this->http_calls());
    }

    public function test_rejects_non_object_json_payload(): void
    {
        $response = $this->rest('POST', self::ROUTE, ['json' => '"just a string"']);

        $this->assert_status($response, 400);
    }

    public function test_rate_limits_after_ten_requests_per_client(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->assert_status($this->post(['message' => 'x']), 200);
        }

        $this->assert_status($this->post(['message' => 'x']), 429);

        $_SERVER['REMOTE_ADDR'] = '192.0.2.78';
        $this->assert_status($this->post(['message' => 'x']), 200);
        delete_transient('website_watchdog_js_rate_' . md5('192.0.2.78'));
    }

    public function test_only_allowed_fields_are_forwarded_and_sanitized(): void
    {
        $this->post([
            'type' => 'error',
            'message' => '<b>Oops</b> at https://s.test/a?token=1',
            'line' => '-12',
            'column' => 4,
            'evil' => 'drop me',
            'source' => ['not', 'scalar'],
        ]);

        $payload = $this->last_http_json()['payload'];
        $this->assert_array_not_has_key('evil', $payload);
        $this->assert_same('Oops at https://s.test/a', $payload['message']);
        $this->assert_same(12, $payload['line']);
        $this->assert_same(4, $payload['column']);
        $this->assert_same('', $payload['source']);
    }

    public function test_long_fields_are_truncated(): void
    {
        $this->post(['message' => str_repeat('a', 5000)]);

        $this->assert_same(2000, strlen($this->last_http_json()['payload']['message']));
    }

    public function test_handler_works_with_injected_client(): void
    {
        $client = new \WebsiteWatchdog\Agent\SelfTest\RecordingClient();
        $endpoint = new JsErrorEndpoint($client);
        $request = new \WP_REST_Request('POST', self::ROUTE);
        $request->set_header('content-type', 'application/json');
        $request->set_body(wp_json_encode(['message' => 'injected']));

        $response = $endpoint->handle_request($request);

        $this->assert_status($response, 200);
        $this->assert_count(1, $client->dispatched);
        $this->assert_same('injected', $client->dispatched[0]['payload']['payload']['message']);
    }
}
