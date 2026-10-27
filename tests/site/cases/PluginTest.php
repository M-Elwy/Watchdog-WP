<?php

namespace WebsiteWatchdog\Agent\SelfTest\Cases;

use WebsiteWatchdog\Agent\Plugin;
use WebsiteWatchdog\Agent\SelfTest\TestCase;

class PluginTest extends TestCase
{
    private const HANDLE = 'website-watchdog-frontend';

    public function tearDown(): void
    {
        wp_dequeue_script(self::HANDLE);
        wp_deregister_script(self::HANDLE);
    }

    public function test_plugin_can_be_instantiated(): void
    {
        $this->assert_instance_of(Plugin::class, new Plugin());
    }

    public function test_frontend_script_is_enqueued_in_footer_with_plugin_version(): void
    {
        (new Plugin())->enqueue_frontend_script();

        $this->assert_true(wp_script_is(self::HANDLE, 'enqueued'));
        $script = wp_scripts()->registered[self::HANDLE];
        $this->assert_contains('assets/js/website-watchdog-frontend.js', $script->src);
        $this->assert_same(WEBSITE_WATCHDOG_VERSION, $script->ver);
        $this->assert_same(1, wp_scripts()->get_data(self::HANDLE, 'group'), 'Script should load in the footer');
    }

    public function test_frontend_script_posts_to_the_registered_rest_route(): void
    {
        $js = (string) file_get_contents(WEBSITE_WATCHDOG_PATH . 'assets/js/website-watchdog-frontend.js');
        $this->assert_contains('/wp-json/website-watchdog/' . WEBSITE_WATCHDOG_API_VERSION . '/js-error', $js);
        $this->assert_array_has_key('/website-watchdog/' . WEBSITE_WATCHDOG_API_VERSION . '/js-error', rest_get_server()->get_routes());
    }
}
