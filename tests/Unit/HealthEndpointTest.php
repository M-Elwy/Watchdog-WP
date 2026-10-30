<?php

namespace WebsiteWatchdog\Agent\Tests\Unit;

use WebsiteWatchdog\Agent\Rest\HealthEndpoint;
use WebsiteWatchdog\Agent\Tests\TestCase;
use WP_REST_Request;
use WPState;

class HealthEndpointTest extends TestCase
{
    public function test_register_adds_route_on_rest_api_init(): void
    {
        (new HealthEndpoint())->register();
        foreach (WPState::$actions['rest_api_init'] as $callback) {
            $callback();
        }

        $this->assertArrayHasKey('website-watchdog/v1/health', WPState::$routes);
        $this->assertSame('GET', WPState::$routes['website-watchdog/v1/health']['methods']);
    }

    public function test_authorize_accepts_matching_bearer_token(): void
    {
        WPState::$options['website_watchdog_api_key'] = 'secret';

        $this->assertTrue((new HealthEndpoint())->authorize(new WP_REST_Request('', ['Authorization' => 'Bearer secret'])));
    }

    public function test_authorize_rejects_wrong_missing_or_malformed_token(): void
    {
        WPState::$options['website_watchdog_api_key'] = 'secret';
        $endpoint = new HealthEndpoint();

        $this->assertFalse($endpoint->authorize(new WP_REST_Request('', ['Authorization' => 'Bearer nope'])));
        $this->assertFalse($endpoint->authorize(new WP_REST_Request('', ['Authorization' => 'secret'])));
        $this->assertFalse($endpoint->authorize(new WP_REST_Request()));
    }

    public function test_authorize_rejects_when_no_key_stored(): void
    {
        $this->assertFalse((new HealthEndpoint())->authorize(new WP_REST_Request('', ['Authorization' => 'Bearer '])));
    }

    public function test_handle_request_returns_health_payload(): void
    {
        WPState::$options['website_watchdog_site_id'] = 'site_1';

        $response = (new HealthEndpoint())->handle_request();

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($response->get_data()['ok']);
        $this->assertSame('site_1', $response->get_data()['site_id']);
        $this->assertSame(WEBSITE_WATCHDOG_VERSION, $response->get_data()['agent_version']);
        $this->assertSame(get_bloginfo('version'), $response->get_data()['wp_version']);
        $this->assertSame(PHP_VERSION, $response->get_data()['php_version']);
        $this->assertSame('no-store', $response->headers['Cache-Control']);
    }
}
