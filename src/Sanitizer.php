<?php

namespace WPWatchdog\Agent;

class Sanitizer
{
    protected static $sensitive_pattern = '/(pass|password|pwd|secret|auth|token|key|stripe|card|bearer|ssn|cvv)/i';

    public static function clean_payload(array $data): array
    {
        $cleaned = [];

        foreach ($data as $key => $value) {
            $clean_key = is_string($key) ? $key : (string) $key;

            if (is_string($clean_key) && preg_match(self::$sensitive_pattern, $clean_key)) {
                $cleaned[$clean_key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $cleaned[$clean_key] = self::clean_payload($value);
                continue;
            }

            if (is_string($value) && 'stack_trace' === strtolower($clean_key)) {
                $cleaned[$clean_key] = array_map(static function ($entry): string {
                    return is_string($entry) ? $entry : '[REDACTED]';
                }, explode("\n", $value));
                continue;
            }

            $cleaned[$clean_key] = $value;
        }

        return $cleaned;
    }
}
