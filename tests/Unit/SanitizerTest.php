<?php

namespace WebsiteWatchdog\Agent\Tests\Unit;

use WebsiteWatchdog\Agent\Sanitizer;
use WebsiteWatchdog\Agent\Tests\TestCase;

class SanitizerTest extends TestCase
{
    public function test_redacts_sensitive_keys_case_insensitively(): void
    {
        $out = Sanitizer::clean_payload(['Password' => 'x', 'api_key' => 'y', 'Authorization' => 'Bearer z', 'safe' => 'ok']);

        $this->assertSame('[REDACTED]', $out['Password']);
        $this->assertSame('[REDACTED]', $out['api_key']);
        $this->assertSame('[REDACTED]', $out['Authorization']);
        $this->assertSame('ok', $out['safe']);
    }

    public function test_recurses_into_nested_arrays(): void
    {
        $out = Sanitizer::clean_payload(['a' => ['token' => 't', 'b' => ['cvv' => '123', 'n' => 1]]]);

        $this->assertSame('[REDACTED]', $out['a']['token']);
        $this->assertSame('[REDACTED]', $out['a']['b']['cvv']);
        $this->assertSame(1, $out['a']['b']['n']);
    }

    public function test_strips_query_strings_from_absolute_urls(): void
    {
        $out = Sanitizer::clean_payload(['message' => 'Failed at https://site.test/page?id=5&sid=abc today']);

        $this->assertSame('Failed at https://site.test/page today', $out['message']);
    }

    public function test_strips_query_strings_from_relative_paths(): void
    {
        $out = Sanitizer::clean_payload(['message' => 'Hit /checkout?coupon=SECRET now']);

        $this->assertSame('Hit /checkout now', $out['message']);
    }

    public function test_keeps_urls_without_query(): void
    {
        $out = Sanitizer::clean_payload(['message' => 'See https://site.test/a/b']);

        $this->assertSame('See https://site.test/a/b', $out['message']);
    }

    public function test_stack_trace_string_is_split_into_cleaned_lines(): void
    {
        $out = Sanitizer::clean_payload(['stack_trace' => "at a (https://s.test/x.js?v=1:1:1)\nat b"]);

        $this->assertSame(['at a (https://s.test/x.js', 'at b'], $out['stack_trace']);
    }

    public function test_non_string_values_and_integer_keys_are_preserved(): void
    {
        $out = Sanitizer::clean_payload([0 => 5, 'flag' => true, 'ratio' => 1.5, 'nothing' => null]);

        $this->assertSame(5, $out[0]);
        $this->assertTrue($out['flag']);
        $this->assertSame(1.5, $out['ratio']);
        $this->assertNull($out['nothing']);
    }

    public function test_empty_payload(): void
    {
        $this->assertSame([], Sanitizer::clean_payload([]));
    }
}
