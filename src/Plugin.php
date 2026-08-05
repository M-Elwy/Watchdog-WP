<?php

namespace WPWatchdog\Agent;

use WPWatchdog\Agent\Admin\Settings;
use WPWatchdog\Agent\Trappers\ErrorTrapper;
use WPWatchdog\Agent\Trappers\StateChangeListener;

class Plugin
{
    protected $settings;
    protected $error_trapper;
    protected $state_change_listener;

    public function __construct()
    {
        $this->settings = new Settings();
        $this->error_trapper = new ErrorTrapper();
        $this->state_change_listener = new StateChangeListener();
    }

    public function register(): void
    {
        $this->settings->register();
        $this->error_trapper->register();
        $this->state_change_listener->register();
    }
}
