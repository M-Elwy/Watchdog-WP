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
        add_action('switch_theme', [$this, 'handle_theme_switch'], 10, 3);
    }

    public function handle_upgrader_complete($upgrader, $hook_extra): void
    {
        $target = $this->resolve_upgrader_type($upgrader, $hook_extra);

        $payload = $this->build_payload([
            'action' => $this->resolve_upgrader_action($target),
            'target' => $target,
            'details' => $hook_extra,
        ]);

        $this->dispatch($payload);
    }

    protected function resolve_upgrader_action(string $target): string
    {
        $actions = [
            'core' => 'core_updated',
            'plugin' => 'plugin_updated',
            'theme' => 'theme_updated',
        ];

        return $actions[$target] ?? 'update_completed';
    }

    public function handle_plugin_activation(string $plugin): void
    {
        $this->dispatch($this->build_payload([
            'action' => 'plugin_activated',
            'target' => $this->resolve_plugin_name($plugin),
            'details' => ['plugin_slug' => $this->resolve_plugin_slug($plugin)],
        ]));
    }

    public function handle_plugin_deactivation(string $plugin): void
    {
        $this->dispatch($this->build_payload([
            'action' => 'plugin_deactivated',
            'target' => $this->resolve_plugin_name($plugin),
            'details' => ['plugin_slug' => $this->resolve_plugin_slug($plugin)],
        ]));
    }

    public function handle_theme_switch(string $new_name, $new_theme, $old_theme): void
    {
        $this->dispatch($this->build_payload([
            'action' => 'theme_switched',
            'target' => $new_name,
            'details' => ['old_theme' => $old_theme->get('Name'), 'new_theme' => $new_name],
        ]));
    }

    protected function build_payload(array $context): array
    {
        return [
            'event_type' => 'state_change',
            'timestamp' => gmdate('c'),
            'payload' => $context,
        ];
    }

    protected function resolve_upgrader_type($upgrader, array $hook_extra): string
    {
        if (!empty($hook_extra['type'])) {
            return (string) $hook_extra['type'];
        }

        if (!empty($hook_extra['plugin']) || !empty($hook_extra['plugins'])) {
            return 'plugin';
        }

        if (!empty($hook_extra['theme']) || !empty($hook_extra['themes'])) {
            return 'theme';
        }

        return 'unknown';
    }

    protected function resolve_plugin_name(string $plugin): string
    {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_path = WP_PLUGIN_DIR . '/' . ltrim($plugin, '/');
        $plugin_data = is_readable($plugin_path) ? get_plugin_data($plugin_path, false, false) : [];

        return !empty($plugin_data['Name']) ? (string) $plugin_data['Name'] : $plugin;
    }

    // Stable identifier (unlike the display name) for matching incidents to the plugin that changed
    protected function resolve_plugin_slug(string $plugin): string
    {
        return str_contains($plugin, '/') ? dirname($plugin) : basename($plugin, '.php');
    }

    protected function dispatch(array $payload): void
    {
        $this->http_client->dispatch($payload);
    }
}
