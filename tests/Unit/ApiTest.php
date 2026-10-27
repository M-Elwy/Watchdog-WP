<?php

namespace WebsiteWatchdog\Agent\Tests\Unit;

use WebsiteWatchdog\Agent\Api;
use WebsiteWatchdog\Agent\Tests\TestCase;
use WPState;

class ApiTest extends TestCase
{
    private function lastBody(): array
    {
        $call = end(WPState::$remote_calls);

        return json_decode($call['args']['body'], true);
    }

    public function test_send_plugin_state_event(): void
    {
        $this->configureVerifiedKey();

        Api::send_plugin_state_event('plugin_activated');

        $body = $this->lastBody();
        $this->assertSame('state_change', $body['event_type']);
        $this->assertSame('site_1', $body['site_id']);
        $this->assertSame('plugin_activated', $body['payload']['action']);
        $this->assertSame('Website Watchdog', $body['payload']['target']);
    }

    public function test_capture_event_merges_details_and_target(): void
    {
        $this->configureVerifiedKey();

        $this->assertTrue(Api::capture_event('deploy', ['version' => '2.0'], 'app'));

        $payload = $this->lastBody()['payload'];
        $this->assertSame('deploy', $payload['action']);
        $this->assertSame('2.0', $payload['version']);
        $this->assertSame('app', $payload['target']);
    }

    public function test_capture_event_without_target(): void
    {
        $this->configureVerifiedKey();

        Api::capture_event('deploy');

        $this->assertArrayNotHasKey('target', $this->lastBody()['payload']);
    }

    public function test_capture_event_returns_false_without_key(): void
    {
        $this->assertFalse(Api::capture_event('deploy'));
    }

    public function test_capture_incident_posts_to_on_demand_endpoint(): void
    {
        $this->configureVerifiedKey();

        $this->assertTrue(Api::capture_incident('boom', 'critical', 'cron'));

        $this->assertSame('https://example.test/api/v1/incidents/on-demand', end(WPState::$remote_calls)['url']);
        $body = $this->lastBody();
        $this->assertSame('critical', $body['severity']);
        $this->assertSame('cron', $body['source']);
        $this->assertSame('boom', $body['details']);
    }

    public function test_capture_incident_defaults_and_invalid_severity(): void
    {
        $this->configureVerifiedKey();

        Api::capture_incident('x');
        $this->assertSame('error', $this->lastBody()['severity']);
        $this->assertSame('on_demand', $this->lastBody()['source']);

        Api::capture_incident('x', 'bogus');
        $this->assertSame('info', $this->lastBody()['severity']);
    }
}
