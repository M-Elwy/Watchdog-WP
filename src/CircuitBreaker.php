<?php

namespace WebsiteWatchdog\Agent;

class CircuitBreaker
{
    private const MAX_EVENTS = 50;
    private const WINDOW_SECONDS = 60;
    private const COOLDOWN_SECONDS = 300;

    public function is_allowed(): bool
    {
        if (!empty(get_transient('website_watchdog_cooldown'))) {
            return false;
        }

        $now = time();
        $events = get_transient('website_watchdog_rate_limit_count');
        $events = is_array($events) ? $events : [];

        $events = array_values(array_filter($events, static function ($timestamp) use ($now): bool {
            return is_int($timestamp) && ($now - $timestamp) <= self::WINDOW_SECONDS;
        }));

        if (count($events) >= self::MAX_EVENTS) {
            set_transient('website_watchdog_cooldown', true, self::COOLDOWN_SECONDS);

            return false;
        }

        $events[] = $now;
        set_transient('website_watchdog_rate_limit_count', $events, self::WINDOW_SECONDS);

        return true;
    }
}
