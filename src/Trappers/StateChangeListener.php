<?php

namespace WPWatchdog\Agent\Trappers;

use WPWatchdog\Agent\Http\Client;

class StateChangeListener
{
    protected $http_client;

    public function __construct(?Client $http_client = null)
    {
        $this->http_client = $http_client ?: new Client();
    }

    public function register(): void
    {
        add_action('upgrader_process_complete', [$this, 'handle_upgrader_complete'], 10, 2);
        add_action('activated_plugin', [$this, 'handle_plugin_activation'], 10, 1);
        add_action('deactivated_plugin', [$this, 'handle_plugin_deactivation'], 10, 1);
        add_action('switch_theme', [$this, 'handle_theme_switch'], 10, 2);
    }

    public function handle_upgrader_complete($upgrader, $hook_extra): void
    {
        $payload = $this->build_payload([
            'action' => 'update_completed',
            'type' => $this->resolve_upgrader_type($upgrader, $hook_extra),
            'hook_extra' => $hook_extra,
        ]);

        $this->dispatch($payload);
    }

    public function handle_plugin_activation(string $plugin): void
    {
        $this->dispatch($this->build_payload([
            'action' => 'plugin_activated',
            'target' => $plugin,
        ]));
    }

    public function handle_plugin_deactivation(string $plugin): void
    {
        $this->dispatch($this->build_payload([
            'action' => 'plugin_deactivated',
            'target' => $plugin,
        ]));
    }

    public function handle_theme_switch(string $new_name, $new_theme): void
    {
        $this->dispatch($this->build_payload([
            'action' => 'theme_switched',
            'target' => $new_name,
        ]));
    }

    protected function build_payload(array $context): array
    {
        return [
            'event_type' => 'state_change',
            'timestamp' => gmdate('c'),
            'environment' => get_option('wp_watchdog_environment', 'production'),
            'payload' => $context,
        ];
    }

    protected function resolve_upgrader_type($upgrader, array $hook_extra): string
    {
        $type = 'unknown';

        if (!empty($hook_extra['plugin'])) {
            return 'plugin';
        }

        if (!empty($hook_extra['theme'])) {
            return 'theme';
        }

        if (!empty($hook_extra['bulk']) && true === $hook_extra['bulk']) {
            return 'core';
        }

        return $type;
    }

    protected function dispatch(array $payload): void
    {
        $this->http_client->dispatch($payload);
    }
}
