<?php

namespace WPWatchdog\Agent;

use WPWatchdog\Agent\Admin\Settings;

class Plugin
{
    protected $settings;

    public function __construct()
    {
        $this->settings = new Settings();
    }

    public function register(): void
    {
        $this->settings->register();
    }
}
