<?php

namespace WebsiteWatchdog\Agent\SelfTest\Cases;

use WebsiteWatchdog\Agent\CircuitBreaker;
use WebsiteWatchdog\Agent\SelfTest\TestCase;

class CircuitBreakerTest extends TestCase
{
    public function test_allows_events_under_the_limit(): void
    {
        $breaker = new CircuitBreaker();

        for ($i = 0; $i < 50; $i++) {
            $this->assert_true($breaker->is_allowed(), 'Event ' . ($i + 1) . ' should be allowed');
        }
    }

    public function test_trips_after_max_events_and_sets_cooldown(): void
    {
        $breaker = new CircuitBreaker();
        for ($i = 0; $i < 50; $i++) {
            $breaker->is_allowed();
        }

        $this->assert_false($breaker->is_allowed());
        $this->assert_not_empty(get_transient('website_watchdog_cooldown'), 'Cooldown transient should be set');
        $this->assert_false($breaker->is_allowed(), 'Stays blocked during cooldown');
    }

    public function test_expired_timestamps_do_not_count(): void
    {
        set_transient('website_watchdog_rate_limit_count', array_fill(0, 50, time() - 3600), 60);

        $this->assert_true((new CircuitBreaker())->is_allowed());
        $this->assert_count(1, get_transient('website_watchdog_rate_limit_count'));
    }

    public function test_active_cooldown_blocks_immediately(): void
    {
        set_transient('website_watchdog_cooldown', true, 60);

        $this->assert_false((new CircuitBreaker())->is_allowed());
    }

    public function test_corrupt_stored_state_is_tolerated(): void
    {
        set_transient('website_watchdog_rate_limit_count', 'garbage', 60);

        $this->assert_true((new CircuitBreaker())->is_allowed());
    }
}
