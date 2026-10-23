<?php

namespace WPWatchdog\Agent\Http;

class Queue
{
    private const TRANSIENT_KEY = 'site_watchdog_offline_queue';
    private const MAX_ITEMS = 50;

    public function add(array $payload): void
    {
        $queue = get_option(self::TRANSIENT_KEY, []);
        $queue = is_array($queue) ? $queue : [];
        $queue[] = $payload;
        $queue = $this->enforce_limits($queue);

        if (empty($queue)) {
            delete_option(self::TRANSIENT_KEY);
            return;
        }

        update_option(self::TRANSIENT_KEY, $queue, false);
    }

    public function flush(): array
    {
        $queue = get_option(self::TRANSIENT_KEY, []);
        $queue = is_array($queue) ? $queue : [];
        $queue = $this->enforce_limits($queue);

        if (empty($queue)) {
            delete_option(self::TRANSIENT_KEY);
            return [];
        }

        update_option(self::TRANSIENT_KEY, $queue, false);

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

    private function enforce_limits(array $queue): array
    {
        while (count($queue) > self::MAX_ITEMS) {
            array_shift($queue);
        }

        return $queue;
    }
}
