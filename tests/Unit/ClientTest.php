<?php

namespace WebsiteWatchdog\Agent\Tests\Unit;

use WebsiteWatchdog\Agent\Http\Client;
use WebsiteWatchdog\Agent\Tests\TestCase;
use WP_Error;
use WPState;

class ClientTest extends TestCase
{
    private function respond($response): void
    {
        WPState::$remote_handler = static function () use ($response) {
            return $response;
        };
    }

    public function test_check_key_rejects_empty_key_without_request(): void
    {
        $result = (new Client())->check_key('');

        $this->assertFalse($result['valid']);
        $this->assertSame([], WPState::$remote_calls);
    }

    public function test_check_key_valid(): void
    {
        $this->respond(['response' => ['code' => 200], 'body' => '{"data":{"site_id":"abc"}}']);

        $result = (new Client())->check_key('k');

        $this->assertTrue($result['valid']);
        $this->assertSame('abc', $result['site_id']);
        $this->assertSame('https://example.test/api/v1/sites/verify', WPState::$remote_calls[0]['url']);
        $this->assertSame('Bearer k', WPState::$remote_calls[0]['args']['headers']['Authorization']);
    }

    public function test_check_key_unauthorized(): void
    {
        foreach ([401, 403] as $code) {
            $this->respond(['response' => ['code' => $code], 'body' => '']);
            $this->assertFalse((new Client())->check_key('k')['valid']);
        }
    }

    public function test_check_key_server_error_is_unknown(): void
    {
        $this->respond(['response' => ['code' => 500], 'body' => '']);

        $this->assertNull((new Client())->check_key('k')['valid']);
    }

    public function test_check_key_network_error_is_unknown(): void
    {
        $this->respond(new WP_Error('http', 'timeout'));

        $result = (new Client())->check_key('k');

        $this->assertNull($result['valid']);
        $this->assertSame('timeout', $result['error']);
    }

    public function test_check_key_missing_site_id_is_invalid(): void
    {
        $this->respond(['response' => ['code' => 200], 'body' => '{"data":{}}']);

        $this->assertFalse((new Client())->check_key('k')['valid']);
    }

    public function test_verify_persists_valid_result(): void
    {
        WPState::$options['website_watchdog_api_key'] = 'k';
        $this->respond(['response' => ['code' => 200], 'body' => '{"data":{"site_id":"abc"}}']);

        $this->assertTrue((new Client())->verify());
        $this->assertTrue(WPState::$options['website_watchdog_api_key_verified']);
        $this->assertSame('abc', WPState::$options['website_watchdog_site_id']);
    }

    public function test_verify_persists_rejection(): void
    {
        WPState::$options['website_watchdog_api_key'] = 'k';
        WPState::$options['website_watchdog_api_key_verified'] = true;
        $this->respond(['response' => ['code' => 401], 'body' => '']);

        $this->assertFalse((new Client())->verify());
        $this->assertFalse(WPState::$options['website_watchdog_api_key_verified']);
    }

    public function test_verify_leaves_flag_unchanged_when_unreachable(): void
    {
        WPState::$options['website_watchdog_api_key'] = 'k';
        WPState::$options['website_watchdog_api_key_verified'] = true;
        $this->respond(new WP_Error('http', 'down'));

        $this->assertNull((new Client())->verify());
        $this->assertTrue(WPState::$options['website_watchdog_api_key_verified']);
    }

    public function test_dispatch_without_api_key_does_nothing(): void
    {
        $this->assertFalse((new Client())->dispatch(['a' => 1]));
        $this->assertSame([], WPState::$remote_calls);
        $this->assertArrayNotHasKey('website_watchdog_offline_queue', WPState::$options);
    }

    public function test_dispatch_with_unverified_key_queues_event(): void
    {
        WPState::$options['website_watchdog_api_key'] = 'k';

        $this->assertFalse((new Client())->dispatch(['a' => 1]));
        $this->assertCount(1, WPState::$options['website_watchdog_offline_queue']);
        $this->assertSame([], WPState::$remote_calls);
    }

    public function test_dispatch_unverified_without_queueing(): void
    {
        WPState::$options['website_watchdog_api_key'] = 'k';

        (new Client())->dispatch(['a' => 1], false);

        $this->assertArrayNotHasKey('website_watchdog_offline_queue', WPState::$options);
    }

    public function test_dispatch_sends_sanitized_authenticated_request(): void
    {
        $this->configureVerifiedKey('k');

        $this->assertTrue((new Client())->dispatch(['message' => 'x', 'password' => 'p']));

        $call = WPState::$remote_calls[0];
        $this->assertSame('https://example.test/api/v1/events/ingest', $call['url']);
        $this->assertSame('Bearer k', $call['args']['headers']['Authorization']);
        $this->assertSame(['message' => 'x', 'password' => '[REDACTED]'], json_decode($call['args']['body'], true));
    }

    public function test_dispatch_custom_endpoint(): void
    {
        $this->configureVerifiedKey();

        (new Client())->dispatch(['a' => 1], false, '/v1/sites/ping');

        $this->assertSame('https://example.test/api/v1/sites/ping', WPState::$remote_calls[0]['url']);
    }

    public function test_dispatch_queues_on_transport_error(): void
    {
        $this->configureVerifiedKey();
        $this->respond(new WP_Error('http', 'fail'));

        $this->assertFalse((new Client())->dispatch(['a' => 1]));
        $this->assertCount(1, WPState::$options['website_watchdog_offline_queue']);
    }

    public function test_dispatch_does_not_queue_when_disabled(): void
    {
        $this->configureVerifiedKey();
        $this->respond(new WP_Error('http', 'fail'));

        $this->assertFalse((new Client())->dispatch(['a' => 1], false));
        $this->assertArrayNotHasKey('website_watchdog_offline_queue', WPState::$options);
    }

    public function test_dispatch_blocked_by_circuit_breaker(): void
    {
        $this->configureVerifiedKey();
        WPState::$transients['website_watchdog_cooldown'] = true;

        $this->assertFalse((new Client())->dispatch(['a' => 1]));
        $this->assertSame([], WPState::$remote_calls);
    }
}
