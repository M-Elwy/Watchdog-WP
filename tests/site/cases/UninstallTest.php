<?php

namespace WebsiteWatchdog\Agent\SelfTest\Cases;

use WebsiteWatchdog\Agent\SelfTest\TestCase;

/**
 * uninstall.php deletes data and so is never executed here; instead we verify it cleans up everything the plugin stores.
 */
class UninstallTest extends TestCase
{
    private function source(): string
    {
        $combined = '';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(WEBSITE_WATCHDOG_PATH . 'src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ('php' === $file->getExtension()) {
                $combined .= file_get_contents($file->getPathname()) . "\n";
            }
        }

        return $combined;
    }

    private function uninstall_source(): string
    {
        return (string) file_get_contents(WEBSITE_WATCHDOG_PATH . 'uninstall.php');
    }

    public function test_uninstall_removes_every_option_the_plugin_stores(): void
    {
        preg_match_all("/(?:get|update|delete)_option\(\s*'(website_watchdog_[a-z_]+)'/", $this->source(), $matches);
        $options = array_unique($matches[1]);
        $this->assert_not_empty($options);

        foreach ($options as $option) {
            $this->assert_contains("'" . $option . "'", $this->uninstall_source(), 'uninstall.php does not delete option ' . $option);
        }
    }

    public function test_uninstall_clears_every_scheduled_hook(): void
    {
        preg_match_all("/wp_schedule_event\([^,]+,[^,]+,\s*'([a-z_]+)'/", $this->source(), $matches);
        $this->assert_not_empty($matches[1]);

        foreach (array_unique($matches[1]) as $hook) {
            $this->assert_contains("'" . $hook . "'", $this->uninstall_source(), 'uninstall.php does not clear cron hook ' . $hook);
        }
    }

    public function test_uninstall_is_guarded_against_direct_access(): void
    {
        $this->assert_contains("defined('WP_UNINSTALL_PLUGIN')", $this->uninstall_source());
    }
}
