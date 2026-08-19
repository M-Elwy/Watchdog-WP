<?php

namespace WPWatchdog\Agent\Http;

class Queue
{
    private const TRANSIENT_KEY = 'site_watchdog_offline_queue';

    public function add(array $payload): void
    {
        $queue = get_option(self::TRANSIENT_KEY, []);
        $queue = is_array($queue) ? $queue : [];
        $queue[] = $payload;
        update_option(self::TRANSIENT_KEY, $queue, false);
    }

    public function flush(): array
    {
        $queue = get_option(self::TRANSIENT_KEY, []);
        $queue = is_array($queue) ? $queue : [];

        if (empty($queue)) {
            return [];
        }

        $remaining = [];

        foreach ($queue as $item) {
            if (!is_array($item)) {
                continue;
            }

            $client = new Client();
            $result = $client->dispatch($item, true);

            if ($result) {
                continue;
            }

            $remaining[] = $item;
        }

        update_option(self::TRANSIENT_KEY, $remaining, false);

        return $remaining;
    }
}
