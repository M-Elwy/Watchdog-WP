<?php

namespace WPWatchdog\Agent;

use WPWatchdog\Agent\Admin\Settings;
use WPWatchdog\Agent\Trappers\ErrorTrapper;

class Plugin
{
    protected $settings;
    protected $error_trapper;

    public function __construct()
    {
        $this->settings = new Settings();
        $this->error_trapper = new ErrorTrapper();
    }

    public function register(): void
    {
        $this->settings->register();
        $this->error_trapper->register();
    }
}
