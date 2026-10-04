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

        $client = new Client();

        // Verify once before retrying; keep the queue untouched if the key is invalid or the server is down.
        if (true !== $client->verify()) {
            return $queue;
        }

        $remaining = [];

        foreach ($queue as $item) {
            if (!is_array($item)) {
                continue;
            }

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
