<?php

namespace Sitesoft\GravityForms\VATChecker\Tests;

use Sitesoft\GravityForms\VATChecker\Tests\Support\TestCase;
use Sitesoft\GravityForms\VATChecker\VAT_Cache;
use Sitesoft\GravityForms\VATChecker\VAT_Logger;

final class LoggerTest extends TestCase
{
    public function test_identical_errors_are_throttled_and_counted(): void
    {
        $lines   = [];
        $context = [ 'country' => 'BE', 'code' => 'MS_MAX_CONCURRENT_REQ' ];

        for ($i = 0; $i < 50; $i++) {
            // New logger per iteration = new PHP request.
            (new VAT_Logger(new VAT_Cache(), function ($line) use (&$lines) {
                $lines[] = $line;
            }))->warning('vies_temporary_error', $context);
        }

        $this->assertCount(1, $lines);

        // Throttle window passes: next line reports what was suppressed.
        foreach (\WP_Test_State::$transients as $key => $entry) {
            if (str_starts_with($key, 'sseuvat_log_')) {
                unset(\WP_Test_State::$transients[ $key ]);
            }
        }
        (new VAT_Logger(new VAT_Cache(), function ($line) use (&$lines) {
            $lines[] = $line;
        }))->warning('vies_temporary_error', $context);

        $this->assertCount(2, $lines);
        $this->assertStringContainsString('suppressed=49', $lines[1]);
    }

    public function test_different_codes_are_logged_separately(): void
    {
        $lines  = [];
        $logger = new VAT_Logger(new VAT_Cache(), function ($line) use (&$lines) {
            $lines[] = $line;
        });

        $logger->warning('vies_temporary_error', [ 'country' => 'BE', 'code' => 'MS_UNAVAILABLE' ]);
        $logger->warning('vies_temporary_error', [ 'country' => 'BE', 'code' => 'HTTP_CONNECT' ]);
        $logger->warning('vies_temporary_error', [ 'country' => 'DE', 'code' => 'HTTP_CONNECT' ]);

        $this->assertCount(3, $lines);
    }

    public function test_only_whitelisted_context_and_sanitised_values(): void
    {
        $lines  = [];
        $logger = new VAT_Logger(new VAT_Cache(), function ($line) use (&$lines) {
            $lines[] = $line;
        });

        $logger->warning('vies_temporary_error', [
            'country' => 'BE',
            'code'    => 'UNKNOWN_FAULT',
            'message' => "Fault for BE0123456749\nline two \"quoted\"",
            'name'    => 'NV Secret Company',
            'address' => 'Kerkstraat 1',
        ]);

        $line = $lines[0];
        $this->assertMatchesRegularExpression('/^\[sitesoft-euvat\] \d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ level=warning event=vies_temporary_error /', $line);
        $this->assertStringNotContainsString('0123456749', $line);
        $this->assertStringNotContainsString('Secret', $line);
        $this->assertStringNotContainsString('Kerkstraat', $line);
        $this->assertStringNotContainsString("\n", $line);
        $this->assertStringContainsString("message=\"Fault for BE###### line two 'quoted'\"", $line);
    }
}
