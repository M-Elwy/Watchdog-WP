<?php

namespace WebsiteWatchdog\Agent\SelfTest;

require_once __DIR__ . '/Harness.php';

final class Runner
{
    const NAMESPACE_PREFIX = 'WebsiteWatchdog\\Agent\\SelfTest\\Cases\\';

    /**
     * @return array<int, array{test: string, status: string, message: string, ms: int}>
     */
    public static function run(string $filter = ''): array
    {
        $results = [];

        foreach (self::discover() as $class) {
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (0 !== strpos($method->getName(), 'test_')) {
                    continue;
                }

                $label = $reflection->getShortName() . '::' . $method->getName();
                if ('' !== $filter && false === stripos($label, $filter)) {
                    continue;
                }

                $results[] = self::run_one($class, $method->getName(), $label);
            }
        }

        return $results;
    }

    private static function discover(): array
    {
        $classes = [];
        $files = glob(__DIR__ . '/cases/*Test.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            require_once $file;
            $classes[] = self::NAMESPACE_PREFIX . basename($file, '.php');
        }

        return $classes;
    }

    private static function run_one(string $class, string $method, string $label): array
    {
        $status = 'pass';
        $message = '';
        $started = microtime(true);
        $level = ob_get_level();
        $case = new $class();

        Sandbox::begin();
        try {
            $case->setUp();
            $case->$method();
        } catch (SkipException $e) {
            $status = 'skip';
            $message = $e->getMessage();
        } catch (AssertionFailure $e) {
            $status = 'fail';
            $message = $e->getMessage();
        } catch (\Throwable $e) {
            $status = 'error';
            $message = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
        }

        try {
            $case->tearDown();
        } catch (\Throwable $e) {
            // Cleanup problems must not mask the test outcome.
        }

        $notices = Sandbox::$php_notices;
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
        Sandbox::end();

        if (!empty($notices) && 'pass' === $status) {
            $status = 'fail';
            $message = implode('; ', array_unique($notices));
        }

        return ['test' => $label, 'status' => $status, 'message' => $message, 'ms' => (int) round((microtime(true) - $started) * 1000)];
    }

    public static function summarize(array $results): array
    {
        $summary = ['pass' => 0, 'fail' => 0, 'error' => 0, 'skip' => 0, 'total' => count($results)];
        foreach ($results as $result) {
            $summary[$result['status']]++;
        }

        return $summary;
    }

    public static function environment(): array
    {
        global $wpdb;

        return [
            'PHP' => PHP_VERSION,
            'WordPress' => get_bloginfo('version'),
            'Plugin' => defined('WEBSITE_WATCHDOG_VERSION') ? WEBSITE_WATCHDOG_VERSION : 'n/a',
            'Multisite' => is_multisite() ? 'yes' : 'no',
            'DB' => isset($wpdb) && method_exists($wpdb, 'db_version') ? (string) $wpdb->db_version() : 'n/a',
            'Object cache' => wp_using_ext_object_cache() ? 'external' : 'none',
        ];
    }

    public static function text(array $results, bool $verbose = false): string
    {
        $lines = [];
        foreach (self::environment() as $key => $value) {
            $lines[] = $key . ': ' . $value;
        }
        $lines[] = '';

        foreach ($results as $result) {
            if ('pass' === $result['status'] && !$verbose) {
                continue;
            }
            $lines[] = sprintf('%-5s %s%s', strtoupper($result['status']), $result['test'], '' !== $result['message'] ? ' - ' . $result['message'] : '');
        }

        $summary = self::summarize($results);
        $lines[] = '';
        $lines[] = sprintf('%d tests: %d passed, %d failed, %d errors, %d skipped', $summary['total'], $summary['pass'], $summary['fail'], $summary['error'], $summary['skip']);

        return implode("\n", $lines) . "\n";
    }

    public static function html(array $results): string
    {
        $summary = self::summarize($results);
        $ok = 0 === $summary['fail'] + $summary['error'];
        $colors = ['pass' => '#1a7f37', 'fail' => '#d63638', 'error' => '#d63638', 'skip' => '#996800'];

        $html = '<p><strong>' . esc_html(implode(' | ', array_map(static function ($k, $v) {
            return $k . ' ' . $v;
        }, array_keys(self::environment()), self::environment()))) . '</strong></p>';
        $html .= '<div class="notice notice-' . ($ok ? 'success' : 'error') . ' inline"><p>' . esc_html(sprintf(
            '%d tests: %d passed, %d failed, %d errors, %d skipped',
            $summary['total'],
            $summary['pass'],
            $summary['fail'],
            $summary['error'],
            $summary['skip']
        )) . '</p></div>';
        $html .= '<table class="widefat striped"><thead><tr><th>Status</th><th>Test</th><th>Details</th><th>ms</th></tr></thead><tbody>';
        foreach ($results as $result) {
            $html .= '<tr><td style="color:' . esc_attr($colors[$result['status']]) . ';font-weight:600">' . esc_html(strtoupper($result['status']))
                . '</td><td>' . esc_html($result['test']) . '</td><td>' . esc_html($result['message']) . '</td><td>' . (int) $result['ms'] . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }
}
