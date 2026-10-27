<?php

namespace WebsiteWatchdog\Agent\SelfTest\Cases;

use WebsiteWatchdog\Agent\Http\Client;
use WebsiteWatchdog\Agent\SelfTest\TestCase;

class ClientTest extends TestCase
{
    private function expected_api(string $path): string
    {
        return WEBSITE_WATCHDOG_API_URL . '/' . WEBSITE_WATCHDOG_API_VERSION . $path;
    }

    public function test_check_key_rejects_empty_key_without_request(): void
    {
        $result = (new Client())->check_key('');

        $this->assert_false($result['valid']);
        $this->assert_count(0, $this->http_calls());
    }

    public function test_check_key_accepts_valid_key(): void
    {
        $this->mock_http_response(200, '{"data":{"site_id":"abc"}}');

        $result = (new Client())->check_key('my-key');

        $this->assert_true($result['valid']);
        $this->assert_same('abc', $result['site_id']);
        $call = $this->last_http_call();
        $this->assert_same($this->expected_api('/sites/verify'), $call['url']);
        $this->assert_same('Bearer my-key', $call['args']['headers']['Authorization']);
        $this->assert_same(home_url(), json_decode($call['args']['body'], true)['site_url']);
    }

    public function test_check_key_rejects_unauthorized(): void
    {
        foreach ([401, 403] as $code) {
            $this->mock_http_response($code, '');
            $result = (new Client())->check_key('k');
            $this->assert_false($result['valid'], 'HTTP ' . $code);
            $this->assert_same('HTTP ' . $code, $result['error']);
        }
    }

    public function test_check_key_is_unknown_on_server_error(): void
    {
        $this->mock_http_response(500, '');

        $this->assert_null((new Client())->check_key('k')['valid']);
    }

    public function test_check_key_is_unknown_on_network_error(): void
    {
        $this->mock_http_error('timeout');

        $result = (new Client())->check_key('k');

        $this->assert_null($result['valid']);
        $this->assert_same('timeout', $result['error']);
    }

    public function test_check_key_requires_site_id_in_response(): void
    {
        $this->mock_http_response(200, '{"data":{}}');

        $this->assert_false((new Client())->check_key('k')['valid']);
    }

    public function test_verify_persists_valid_result(): void
    {
        $this->set_option('website_watchdog_api_key', 'k');
        $this->mock_http_response(200, '{"data":{"site_id":"abc"}}');

        $this->assert_true((new Client())->verify());
        $this->assert_true((bool) get_option('website_watchdog_api_key_verified'));
        $this->assert_same('abc', get_option('website_watchdog_site_id'));
    }

    public function test_verify_persists_rejection(): void
    {
        $this->configure_verified_key('k');
        $this->mock_http_response(401, '');

        $this->assert_false((new Client())->verify());
        $this->assert_false((bool) get_option('website_watchdog_api_key_verified'));
    }

    public function test_verify_keeps_flag_when_server_unreachable(): void
    {
        $this->configure_verified_key('k');
        $this->mock_http_error();

        $this->assert_null((new Client())->verify());
        $this->assert_true((bool) get_option('website_watchdog_api_key_verified'));
    }

    public function test_dispatch_without_api_key_does_nothing(): void
    {
        delete_option('website_watchdog_api_key');

        $this->assert_false((new Client())->dispatch(['a' => 1]));
        $this->assert_count(0, $this->http_calls());
        $this->assert_same([], get_option('website_watchdog_offline_queue', []));
    }

    public function test_dispatch_queues_event_when_key_not_verified(): void
    {
        $this->set_option('website_watchdog_api_key', 'k');
        $this->set_option('website_watchdog_api_key_verified', false);

        $this->assert_false((new Client())->dispatch(['a' => 1]));
        $this->assert_count(1, get_option('website_watchdog_offline_queue'));
        $this->assert_count(0, $this->http_calls());
    }

    public function test_dispatch_can_skip_queueing(): void
    {
        $this->set_option('website_watchdog_api_key', 'k');
        $this->set_option('website_watchdog_api_key_verified', false);

        (new Client())->dispatch(['a' => 1], false);

        $this->assert_same([], get_option('website_watchdog_offline_queue', []));
    }

    public function test_dispatch_sends_sanitized_authenticated_request(): void
    {
        $this->configure_verified_key('k');

        $this->assert_true((new Client())->dispatch(['message' => 'x', 'password' => 'p']));

        $call = $this->last_http_call();
        $this->assert_same($this->expected_api('/events/ingest'), $call['url']);
        $this->assert_same('Bearer k', $call['args']['headers']['Authorization']);
        $this->assert_same('application/json', $call['args']['headers']['Content-Type']);
        $this->assert_same(['message' => 'x', 'password' => '[REDACTED]'], json_decode($call['args']['body'], true));
    }

    public function test_dispatch_supports_custom_endpoint(): void
    {
        $this->configure_verified_key();

        (new Client())->dispatch(['a' => 1], false, '/' . WEBSITE_WATCHDOG_API_VERSION . '/sites/ping');

        $this->assert_same($this->expected_api('/sites/ping'), $this->last_http_call()['url']);
    }

    public function test_dispatch_queues_on_transport_error(): void
    {
        $this->configure_verified_key();
        $this->mock_http_error();

        $this->assert_false((new Client())->dispatch(['a' => 1]));
        $this->assert_count(1, get_option('website_watchdog_offline_queue'));
    }

    public function test_dispatch_does_not_queue_when_disabled(): void
    {
        $this->configure_verified_key();
        $this->mock_http_error();

        $this->assert_false((new Client())->dispatch(['a' => 1], false));
        $this->assert_same([], get_option('website_watchdog_offline_queue', []));
    }

    public function test_dispatch_is_blocked_by_circuit_breaker(): void
    {
        $this->configure_verified_key();
        set_transient('website_watchdog_cooldown', true, 60);

        $this->assert_false((new Client())->dispatch(['a' => 1]));
        $this->assert_count(0, $this->http_calls());
    }
}
