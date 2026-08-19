<?php

namespace WPWatchdog\Agent\Trappers;

use Throwable;
use WPWatchdog\Agent\Http\Client;

class ErrorTrapper
{
    protected $http_client;
    protected $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];

    public function __construct(?Client $http_client = null)
    {
        $this->http_client = $http_client ?: new Client();
    }

    public function register(): void
    {
        set_error_handler([$this, 'handle_error']);
        set_exception_handler([$this, 'handle_exception']);
        register_shutdown_function([$this, 'handle_shutdown']);
    }

    public function handle_error(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        if (!in_array($severity, $this->fatal_types, true)) {
            return false;
        }

        $this->dispatch($this->build_payload('php_error', [
            'severity' => $severity,
            'message' => $message,
            'file' => $file,
            'line' => $line,
        ]));

        return true;
    }

    public function handle_exception(Throwable $exception): void
    {
        $this->dispatch($this->build_payload('exception', [
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTrace(),
        ]));
    }

    public function handle_shutdown(): void
    {
        $error = error_get_last();

        if (!is_array($error)) {
            return;
        }

        if (!in_array($error['type'], $this->fatal_types, true)) {
            return;
        }

        $this->dispatch($this->build_payload('php_fatal', [
            'message' => $error['message'] ?? '',
            'file' => $error['file'] ?? '',
            'line' => $error['line'] ?? 0,
        ]));
    }

    protected function dispatch(array $payload): void
    {
        $this->http_client->dispatch($payload);
    }

    protected function build_payload(string $type, array $context): array
    {
        return [
            'event_type' => $type,
            'timestamp' => gmdate('c'),
            'environment' => get_option('site_watchdog_environment', 'production'),
            'payload' => $context,
        ];
    }
}
