<?php

namespace WebsiteWatchdog\Agent\Tests\Unit;

use WebsiteWatchdog\Agent\CircuitBreaker;
use WebsiteWatchdog\Agent\Tests\TestCase;
use WPState;

class CircuitBreakerTest extends TestCase
{
    public function test_allows_events_under_the_limit(): void
    {
        $breaker = new CircuitBreaker();

        for ($i = 0; $i < 50; $i++) {
            $this->assertTrue($breaker->is_allowed());
        }
    }

    public function test_trips_after_max_events_and_sets_cooldown(): void
    {
        $breaker = new CircuitBreaker();

        for ($i = 0; $i < 50; $i++) {
            $breaker->is_allowed();
        }

        $this->assertFalse($breaker->is_allowed());
        $this->assertTrue(WPState::$transients['website_watchdog_cooldown']);
        $this->assertFalse($breaker->is_allowed());
    }

    public function test_old_timestamps_are_discarded(): void
    {
        WPState::$transients['website_watchdog_rate_limit_count'] = array_fill(0, 50, time() - 3600);

        $this->assertTrue((new CircuitBreaker())->is_allowed());
        $this->assertCount(1, WPState::$transients['website_watchdog_rate_limit_count']);
    }

    public function test_active_cooldown_blocks_immediately(): void
    {
        WPState::$transients['website_watchdog_cooldown'] = true;

        $this->assertFalse((new CircuitBreaker())->is_allowed());
    }
}
