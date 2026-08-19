<?php

namespace WPWatchdog\Agent;

use WPWatchdog\Agent\Admin\Settings;
use WPWatchdog\Agent\Cron\Heartbeat;
use WPWatchdog\Agent\Rest\JsErrorEndpoint;
use WPWatchdog\Agent\Trappers\ErrorTrapper;
use WPWatchdog\Agent\Trappers\StateChangeListener;

class Plugin
{
    protected $settings;
    protected $error_trapper;
    protected $state_change_listener;
    protected $js_error_endpoint;
    protected $heartbeat;

    public function __construct()
    {
        $this->settings = new Settings();
        $this->error_trapper = new ErrorTrapper();
        $this->state_change_listener = new StateChangeListener();
        $this->js_error_endpoint = new JsErrorEndpoint();
        $this->heartbeat = new Heartbeat();
    }

    public function register(): void
    {
        $this->settings->register();
        $this->error_trapper->register();
        $this->state_change_listener->register();
        $this->js_error_endpoint->register();
        $this->heartbeat->register();
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_script']);
    }

    public function enqueue_frontend_script(): void
    {
        wp_enqueue_script(
            'wp-watchdog-frontend',
            plugins_url('assets/js/watchdog-frontend.js', SITE_WATCHDOG_PATH . 'wp-watchdog.php'),
            [],
            SITE_WATCHDOG_VERSION,
            true
        );
    }
}
