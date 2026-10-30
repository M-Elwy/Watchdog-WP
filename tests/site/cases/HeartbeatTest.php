<?php

namespace WebsiteWatchdog\Agent\SelfTest\Cases;

use WebsiteWatchdog\Agent\Cron\Heartbeat;
use WebsiteWatchdog\Agent\SelfTest\TestCase;

class HeartbeatTest extends TestCase
{
    public function test_register_schedule_adds_15_minute_interval(): void
    {
        $schedules = (new Heartbeat())->register_schedule([]);

        $this->assert_same(900, $schedules['15minutes']['interval']);
        $this->assert_not_empty($schedules['15minutes']['display']);
    }

    public function test_register_schedule_keeps_existing_definition(): void
    {
        $existing = ['15minutes' => ['interval' => 1, 'display' => 'custom']];

        $this->assert_same($existing, (new Heartbeat())->register_schedule($existing));
    }

    public function test_send_ping_posts_site_info_to_ping_endpoint(): void
    {
        $this->configure_verified_key();

        (new Heartbeat())->send_ping();

        $call = $this->last_http_call();
        $this->assert_same(WEBSITE_WATCHDOG_API_URL . '/' . WEBSITE_WATCHDOG_API_VERSION . '/sites/ping', $call['url']);
        $body = $this->last_http_json();
        $this->assert_same('site_ping', $body['event_type']);
        $this->assert_same(WEBSITE_WATCHDOG_VERSION, $body['payload']['agent_version']);
        $this->assert_same(PHP_VERSION, $body['payload']['php_version']);
        $this->assert_same(get_bloginfo('version'), $body['payload']['wp_version']);
        $this->assert_same(home_url(), $body['payload']['site_url']);
    }

    public function test_send_ping_without_key_sends_nothing(): void
    {
        delete_option('website_watchdog_api_key');

        (new Heartbeat())->send_ping();

        $this->assert_count(0, $this->http_calls());
    }

    public function test_run_queue_flush_delivers_queued_events(): void
    {
        $this->configure_verified_key();
        $this->set_option('website_watchdog_offline_queue', [['event_type' => 'queued']]);
        $this->mock_http_response(200, '{"data":{"site_id":"selftest-site"}}');

        (new Heartbeat())->run_queue_flush();

        $this->assert_same([], get_option('website_watchdog_offline_queue', []));
    }
}
