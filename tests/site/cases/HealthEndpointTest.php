<?php

namespace WebsiteWatchdog\Agent\SelfTest\Cases;

use WebsiteWatchdog\Agent\SelfTest\TestCase;

class HealthEndpointTest extends TestCase
{
    private const ROUTE = '/website-watchdog/v1/health';

    private function get_health(array $headers = []): \WP_REST_Response
    {
        return $this->rest('GET', self::ROUTE, ['headers' => $headers]);
    }

    public function test_rejects_request_without_credentials(): void
    {
        $this->set_option('website_watchdog_api_key', 'secret');

        $status = $this->get_health()->get_status();

        $this->assert_true(in_array($status, [401, 403], true), 'Expected 401/403, got ' . $status);
    }

    public function test_rejects_wrong_or_malformed_token(): void
    {
        $this->set_option('website_watchdog_api_key', 'secret');

        foreach (['Bearer nope', 'secret', 'Basic secret'] as $header) {
            $status = $this->get_health(['authorization' => $header])->get_status();
            $this->assert_true(in_array($status, [401, 403], true), '"' . $header . '" should be rejected, got ' . $status);
        }
    }

    public function test_rejects_everything_when_no_key_is_stored(): void
    {
        delete_option('website_watchdog_api_key');

        $status = $this->get_health(['authorization' => 'Bearer '])->get_status();

        $this->assert_true(in_array($status, [401, 403], true), 'Expected 401/403, got ' . $status);
    }

    public function test_accepts_matching_bearer_token(): void
    {
        $this->set_option('website_watchdog_api_key', 'secret');
        $this->set_option('website_watchdog_site_id', 'site_1');

        $response = $this->get_health(['authorization' => 'Bearer secret']);

        $this->assert_status($response, 200);
        $data = $response->get_data();
        $this->assert_true($data['ok']);
        $this->assert_same('site_1', $data['site_id']);
        $this->assert_not_empty(strtotime($data['timestamp']), 'timestamp must be parseable');
        $this->assert_same('no-store', $response->get_headers()['Cache-Control']);
    }

    public function test_bearer_scheme_is_case_insensitive(): void
    {
        $this->set_option('website_watchdog_api_key', 'secret');

        $this->assert_status($this->get_health(['authorization' => 'bearer secret']), 200);
    }
}
