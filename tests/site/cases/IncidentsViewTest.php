<?php

namespace WebsiteWatchdog\Agent\SelfTest\Cases;

use WebsiteWatchdog\Agent\Admin\IncidentsView;
use WebsiteWatchdog\Agent\SelfTest\TestCase;

class IncidentsViewTest extends TestCase
{
    private $view;

    public function setUp(): void
    {
        $this->view = new IncidentsView();
        $this->configure_verified_key();
        $this->set_request();
    }

    private function incidents_body(array $incidents, ?array $pagination = null): string
    {
        $data = ['incidents' => $incidents];
        if (null !== $pagination) {
            $data['pagination'] = $pagination;
        }

        return wp_json_encode(['data' => $data]);
    }

    private function render(): string
    {
        return $this->capture([$this->view, 'render']);
    }

    private function sample_incident(array $overrides = []): array
    {
        return array_merge([
            'message' => 'Call to undefined function foo()',
            'file' => '/var/www/html/x.php',
            'line' => 12,
            'error_type' => 'php_fatal',
            'severity' => 'critical',
            'occurrence_count' => 3,
            'last_seen_at' => gmdate('c', time() - 300),
            'status' => 'investigating',
        ], $overrides);
    }

    public function test_asks_for_an_api_key_when_none_is_configured(): void
    {
        delete_option('website_watchdog_api_key');

        $out = $this->render();

        $this->assert_contains('Configure an API key to view incident history.', $out);
        $this->assert_count(0, $this->http_calls());
    }

    public function test_requests_incidents_from_service_with_auth_and_paging(): void
    {
        $this->mock_http_response(200, $this->incidents_body([]));

        $this->render();

        $call = $this->last_http_call();
        $this->assert_contains('/' . WEBSITE_WATCHDOG_API_VERSION . '/sites/incidents', $call['url']);
        $this->assert_contains('limit=20', $call['url']);
        $this->assert_contains('page=1', $call['url']);
        $this->assert_same('Bearer selftest-key', $call['args']['headers']['Authorization']);
        $this->assert_same(15, $call['args']['timeout']);
    }

    public function test_renders_incident_rows(): void
    {
        $this->mock_http_response(200, $this->incidents_body([$this->sample_incident()]));

        $out = $this->render();

        $this->assert_contains('wp-list-table', $out);
        $this->assert_contains('Call to undefined function foo()', $out);
        $this->assert_contains('/var/www/html/x.php:12', $out);
        $this->assert_contains('>3x<', $out);
        $this->assert_contains('>CRITICAL<', $out);
        $this->assert_contains('wp-ui-note error', $out, 'critical severity uses the error badge');
        $this->assert_contains('wp-ui-note warning', $out, 'investigating status uses the warning badge');
        $this->assert_contains('>investigating<', $out);
        $this->assert_matches('/about .+ ago/', $out);
        $this->assert_contains('data-stack="[]"', $out);
        $this->assert_contains('name="website_watchdog_refresh_incidents"', str_replace('value="website_watchdog_refresh_incidents"', 'name="website_watchdog_refresh_incidents"', $out));
    }

    public function test_falls_back_for_sparse_incident_data(): void
    {
        $this->mock_http_response(200, $this->incidents_body([
            ['source' => 'cron', 'event_type' => 'exception', 'status' => 'resolved'],
            [],
        ]));

        $out = $this->render();

        $this->assert_contains('>cron<', str_replace('class="description">cron<', '>cron<', $out));
        $this->assert_contains('>exception<', $out, 'type is used as label when severity is missing');
        $this->assert_contains('wp-ui-note success', $out, 'resolved status uses the success badge');
        $this->assert_contains('Unknown error', $out);
        $this->assert_contains('Recently seen', $out);
        $this->assert_contains('>0x<', $out);
    }

    public function test_untrusted_incident_content_is_escaped(): void
    {
        $this->mock_http_response(200, $this->incidents_body([$this->sample_incident([
            'message' => '<script>alert(1)</script>',
            'file' => '"><img src=x onerror=alert(2)>',
            'status' => '<b>x</b>',
        ])]));

        $out = $this->render();

        $this->assert_not_contains('<script>alert(1)', $out);
        $this->assert_not_contains('<img src=x', $out);
        $this->assert_not_contains('<b>x</b>', $out);
        $this->assert_contains('&lt;script&gt;alert(1)', $out);
    }

    public function test_empty_history_message(): void
    {
        $this->mock_http_response(200, $this->incidents_body([]));

        $this->assert_contains('No incident history available yet.', $this->render());
    }

    public function test_successful_response_is_cached(): void
    {
        $this->mock_http_response(200, $this->incidents_body([$this->sample_incident()]));

        $this->render();
        $out = $this->render();

        $this->assert_count(1, $this->http_calls(), 'Second render should be served from the transient cache');
        $this->assert_contains('Call to undefined function foo()', $out);
        $this->assert_true(is_array(get_transient('website_watchdog_cached_incidents_1')));
    }

    public function test_pagination_parameter_selects_the_page(): void
    {
        $this->mock_http_response(200, $this->incidents_body([]));

        $this->set_request(['incident_page' => '2']);
        $this->render();
        $this->assert_contains('page=2', $this->last_http_call()['url']);

        $this->set_request(['incident_page' => 'abc']);
        $this->render();
        $this->assert_contains('page=1', $this->last_http_call()['url']);

        $this->set_request(['incident_page' => '-3']);
        $this->render();
        $this->assert_contains('page=3', $this->last_http_call()['url'], 'negative values are made absolute');
    }

    public function test_pagination_links_are_rendered_for_multiple_pages(): void
    {
        $this->mock_http_response(200, $this->incidents_body([$this->sample_incident()], ['total' => 45, 'page' => 1, 'pages' => 3]));

        $out = $this->render();

        $this->assert_contains('tablenav-pages', $out);
        $this->assert_contains('incident_page=2', $out);
        $this->assert_contains('incident_page=3', $out);
        $this->assert_contains('paging-input">1<', $out);
    }

    public function test_no_pagination_for_a_single_page(): void
    {
        $this->mock_http_response(200, $this->incidents_body([$this->sample_incident()], ['total' => 1, 'page' => 1, 'pages' => 1]));

        $this->assert_not_contains('tablenav', $this->render());
    }

    public function test_http_error_is_reported_and_not_cached(): void
    {
        $this->mock_http_response(500, 'oops');

        $out = $this->render();

        $this->assert_contains('notice-error', $out);
        $this->assert_contains('HTTP 500', $out);
        $this->assert_false(get_transient('website_watchdog_cached_incidents_1'));
    }

    public function test_network_error_is_reported(): void
    {
        $this->mock_http_error('cURL error 28: timeout');

        $out = $this->render();

        $this->assert_contains('notice-error', $out);
        $this->assert_contains('cURL error 28: timeout', $out);
    }

    public function test_invalid_response_body_is_reported(): void
    {
        foreach (['not json', '{"data":{}}', '{"data":{"incidents":"nope"}}'] as $body) {
            delete_transient('website_watchdog_cached_incidents_1');
            $this->mock_http_response(200, $body);

            $this->assert_contains('response was invalid', $this->render(), 'body: ' . $body);
        }
    }

    public function test_refresh_button_form_carries_nonce_and_current_page(): void
    {
        $this->mock_http_response(200, $this->incidents_body([]));
        $this->set_request(['incident_page' => '2']);

        $out = $this->render();

        $this->assert_contains('value="website_watchdog_refresh_incidents"', $out);
        $this->assert_contains('name="incident_page" value="2"', $out);
        $this->assert_contains('name="_wpnonce"', $out);
        $this->assert_contains('admin-post.php', $out);
    }

    public function test_refresh_requires_manage_options(): void
    {
        $this->as_visitor();
        $this->post_with_nonce('website_watchdog_refresh_incidents', ['incident_page' => '1']);

        $this->expect_die([$this->view, 'handle_refresh']);
    }

    public function test_refresh_requires_valid_nonce(): void
    {
        $this->as_admin();
        set_transient('website_watchdog_cached_incidents_1', ['keep' => true], 60);
        $this->set_request([], ['incident_page' => '1']);

        $this->expect_die([$this->view, 'handle_refresh']);
        $this->assert_true(is_array(get_transient('website_watchdog_cached_incidents_1')), 'Cache must survive a rejected request');
    }

    public function test_refresh_clears_cache_for_the_page_and_redirects_back(): void
    {
        $this->as_admin();
        set_transient('website_watchdog_cached_incidents_2', ['stale' => true], 60);
        set_transient('website_watchdog_cached_incidents_1', ['other' => true], 60);
        $this->post_with_nonce('website_watchdog_refresh_incidents', ['incident_page' => '2']);

        $redirect = $this->expect_redirect([$this->view, 'handle_refresh']);

        $this->assert_false(get_transient('website_watchdog_cached_incidents_2'));
        $this->assert_true(is_array(get_transient('website_watchdog_cached_incidents_1')), 'Other pages stay cached');
        $this->assert_same('website-watchdog-settings', $redirect['query']['page']);
        $this->assert_same('incidents', $redirect['query']['tab']);
        $this->assert_same('2', $redirect['query']['incident_page']);
    }

    public function test_refresh_defaults_to_first_page_for_bad_input(): void
    {
        $this->as_admin();
        $this->post_with_nonce('website_watchdog_refresh_incidents', ['incident_page' => 'abc']);

        $redirect = $this->expect_redirect([$this->view, 'handle_refresh']);

        $this->assert_same('1', $redirect['query']['incident_page']);
    }
}
