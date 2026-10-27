<?php

namespace WebsiteWatchdog\Agent\SelfTest\Cases;

use WebsiteWatchdog\Agent\Sanitizer;
use WebsiteWatchdog\Agent\SelfTest\TestCase;

class SanitizerTest extends TestCase
{
    public function test_redacts_sensitive_keys_case_insensitively(): void
    {
        $out = Sanitizer::clean_payload(['Password' => 'x', 'api_key' => 'y', 'Authorization' => 'Bearer z', 'card_number' => '4111', 'safe' => 'ok']);

        $this->assert_same('[REDACTED]', $out['Password']);
        $this->assert_same('[REDACTED]', $out['api_key']);
        $this->assert_same('[REDACTED]', $out['Authorization']);
        $this->assert_same('[REDACTED]', $out['card_number']);
        $this->assert_same('ok', $out['safe']);
    }

    public function test_redacts_sensitive_keys_inside_nested_arrays(): void
    {
        $out = Sanitizer::clean_payload(['a' => ['token' => 't', 'b' => ['cvv' => '123', 'n' => 1]]]);

        $this->assert_same('[REDACTED]', $out['a']['token']);
        $this->assert_same('[REDACTED]', $out['a']['b']['cvv']);
        $this->assert_same(1, $out['a']['b']['n']);
    }

    public function test_strips_query_strings_from_absolute_urls(): void
    {
        $out = Sanitizer::clean_payload(['message' => 'Failed at https://site.test/page?id=5&sid=abc today']);

        $this->assert_same('Failed at https://site.test/page today', $out['message']);
    }

    public function test_strips_query_strings_from_relative_paths(): void
    {
        $out = Sanitizer::clean_payload(['message' => 'Hit /checkout?coupon=SECRET now']);

        $this->assert_same('Hit /checkout now', $out['message']);
    }

    public function test_keeps_urls_without_query(): void
    {
        $out = Sanitizer::clean_payload(['message' => 'See https://site.test/a/b']);

        $this->assert_same('See https://site.test/a/b', $out['message']);
    }

    public function test_stack_trace_string_becomes_cleaned_lines(): void
    {
        $out = Sanitizer::clean_payload(['stack_trace' => "at a (https://s.test/x.js?v=1:1:1)\nat b"]);

        $this->assert_same(['at a (https://s.test/x.js', 'at b'], $out['stack_trace']);
    }

    public function test_preserves_non_string_values_and_integer_keys(): void
    {
        $out = Sanitizer::clean_payload([0 => 5, 'flag' => true, 'ratio' => 1.5, 'nothing' => null]);

        $this->assert_same(5, $out[0]);
        $this->assert_same(true, $out['flag']);
        $this->assert_same(1.5, $out['ratio']);
        $this->assert_null($out['nothing']);
    }

    public function test_empty_payload(): void
    {
        $this->assert_same([], Sanitizer::clean_payload([]));
    }
}
