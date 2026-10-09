<?php

namespace Sitesoft\GravityForms\VATChecker\Tests;

use Sitesoft\GravityForms\VATChecker\Tests\Support\FakeSoap;
use Sitesoft\GravityForms\VATChecker\Tests\Support\TestCase;
use Sitesoft\GravityForms\VATChecker\VAT_Number;
use Sitesoft\GravityForms\VATChecker\VAT_Result;
use Sitesoft\GravityForms\VATChecker\VAT_Validation_Service as Service;
use WP_Test_State;

final class ValidationServiceTest extends TestCase
{
    public function test_valid_result_is_cached_for_six_hours_and_reused(): void
    {
        $soap    = new FakeSoap([ FakeSoap::valid() ]);
        $service = $this->service($soap);

        $first  = $service->validate('BE', '0123.456.749');
        $second = $service->validate('BE', 'BE 0123 456 749');

        $this->assertTrue($first->is_valid());
        $this->assertTrue($second->is_valid());
        $this->assertSame(VAT_Result::SOURCE_CACHE, $second->source());
        $this->assertSame('NV SITESOFT TEST', $second->name());
        $this->assertSame("Kerkstraat 1\n9000 Gent", $second->address());
        $this->assertCount(1, $soap->calls, 'cache hit must not call VIES');
        $this->assertSame([ 6 * HOUR_IN_SECONDS ], $this->stored_ttls());
    }

    public function test_invalid_result_is_cached_for_fifteen_minutes(): void
    {
        $soap    = new FakeSoap([ FakeSoap::invalid() ]);
        $service = $this->service($soap);

        $this->assertTrue($service->validate('BE', '0123456749')->is_invalid());
        $cached = $service->validate('BE', '0123456749', Service::CONTEXT_SUBMISSION);

        $this->assertTrue($cached->is_invalid());
        $this->assertSame(VAT_Result::SOURCE_CACHE, $cached->source());
        $this->assertCount(1, $soap->calls);
        $this->assertSame([ 15 * MINUTE_IN_SECONDS ], $this->stored_ttls());
    }

    public function test_temporary_error_is_cached_only_briefly_for_ajax(): void
    {
        $fault   = FakeSoap::fault('MS_MAX_CONCURRENT_REQ');
        $soap    = new FakeSoap([ $fault, $fault ]);
        $service = $this->service($soap);

        $this->assertTrue($service->validate('BE', '0123456749')->is_temporary_error());
        $again = $service->validate('BE', '0123456749');

        $this->assertTrue($again->is_temporary_error());
        $this->assertCount(2, $soap->calls, 'second AJAX call within 10s does not hit VIES again');
        $this->assertSame([ 10 ], $this->stored_ttls());
    }

    public function test_submission_ignores_a_cached_temporary_error(): void
    {
        $fault   = FakeSoap::fault('MS_MAX_CONCURRENT_REQ');
        $soap    = new FakeSoap([ $fault, $fault, FakeSoap::valid() ]);
        $service = $this->service($soap);

        $service->validate('BE', '0123456749');
        $result = $service->validate('BE', '0123456749', Service::CONTEXT_SUBMISSION);

        $this->assertTrue($result->is_valid());
        $this->assertCount(3, $soap->calls);
    }

    public function test_temporary_error_cache_can_be_disabled(): void
    {
        add_filter('sitesoft_euvat_cache_ttl_temporary', static fn () => 0);
        $fault   = FakeSoap::fault('MS_UNAVAILABLE');
        $soap    = new FakeSoap([ $fault, $fault ]);
        $service = $this->service($soap);

        $service->validate('BE', '0123456749');

        $this->assertSame([], $this->stored_ttls());
    }

    public function test_malformed_input_is_invalid_without_calling_vies(): void
    {
        $soap    = new FakeSoap();
        $service = $this->service($soap);

        foreach ([ '1', '0123456749012345', '0123456749!', '' ] as $input) {
            $result = $service->validate('BE', $input);
            $this->assertTrue($result->is_invalid(), $input);
            $this->assertSame(VAT_Result::SOURCE_FORMAT, $result->source());
        }
        $this->assertTrue($service->validate('US', '123456789')->is_invalid());
        $this->assertSame([], $soap->calls);
    }

    public function test_ajax_valid_then_submit_while_vies_is_down_uses_cache(): void
    {
        $soap    = new FakeSoap([ FakeSoap::valid() ]);
        $service = $this->service($soap);

        $ajax = $service->validate('BE', '0123 456 749', Service::CONTEXT_AJAX);

        // VIES is now overloaded/unreachable; any call would fail.
        $soap->push(FakeSoap::fault('MS_MAX_CONCURRENT_REQ'), FakeSoap::fault('Could not connect to host', 'HTTP'));
        $submit = $service->validate('BE', 'BE0123456749', Service::CONTEXT_SUBMISSION, $service->create_token($ajax));

        $this->assertTrue($submit->is_valid());
        $this->assertCount(1, $soap->calls);
    }

    public function test_ajax_valid_then_submit_while_vies_is_down_and_cache_was_evicted_uses_token(): void
    {
        $soap    = new FakeSoap([ FakeSoap::valid() ]);
        $service = $this->service($soap);
        $token   = $service->create_token($service->validate('BE', '0123456749'));

        WP_Test_State::$transients = []; // e.g. Redis eviction / non-persistent cache
        $soap->push(FakeSoap::fault('MS_MAX_CONCURRENT_REQ'), FakeSoap::fault('MS_MAX_CONCURRENT_REQ'));

        $submit = $service->validate('BE', '0123.456.749', Service::CONTEXT_SUBMISSION, $token);

        $this->assertTrue($submit->is_valid());
        $this->assertSame(VAT_Result::SOURCE_TOKEN, $submit->source());
        $this->assertCount(1, $soap->calls, 'no VIES call needed at submit');
    }

    public function test_token_is_bound_to_the_number_and_expires(): void
    {
        $soap    = new FakeSoap([ FakeSoap::valid() ]);
        $service = $this->service($soap);
        $token   = $service->create_token($service->validate('BE', '0123456749'));

        $this->assertTrue($service->verify_token($token, VAT_Number::from_input('BE', '0123456749')));
        $this->assertFalse($service->verify_token($token, VAT_Number::from_input('BE', '0987654321')));
        $this->assertFalse($service->verify_token($token, VAT_Number::from_input('NL', '0123456749')));
        $this->assertFalse($service->verify_token('garbage', VAT_Number::from_input('BE', '0123456749')));

        [ , $signature ] = explode('.', $token);
        $expired         = (time() - 7 * HOUR_IN_SECONDS) . '.' . $signature;
        $this->assertFalse($service->verify_token($expired, VAT_Number::from_input('BE', '0123456749')));

        $this->assertSame('', $service->create_token(VAT_Result::invalid('BE', '0123456749')));
    }

    public function test_forged_token_does_not_bypass_vies_on_submit(): void
    {
        $soap    = new FakeSoap([ FakeSoap::invalid() ]);
        $service = $this->service($soap);
        $forged  = time() . '.' . str_repeat('a', 64);

        $this->assertTrue($service->validate('BE', '0123456749', Service::CONTEXT_SUBMISSION, $forged)->is_invalid());
    }

    public function test_submit_without_cache_or_token_while_vies_is_down_is_temporary_not_invalid(): void
    {
        $fault   = FakeSoap::fault('MS_MAX_CONCURRENT_REQ');
        $soap    = new FakeSoap([ $fault, $fault ]);
        $service = $this->service($soap);

        $result = $service->validate('BE', '0123456749', Service::CONTEXT_SUBMISSION);

        $this->assertTrue($result->is_temporary_error());
        $this->assertFalse($result->is_invalid());
    }

    public function test_concurrent_identical_check_waits_for_the_running_one(): void
    {
        $soap    = new FakeSoap(); // must not be called
        $lock    = $this->hold_lock_for('BE', '0123456749');
        $other   = new FakeSoap([ FakeSoap::valid() ]);
        $sleeps  = 0;
        $service = $this->service($soap, [], function (int $ms) use (&$sleeps, $other, $lock) {
            $this->now += $ms / 1000;
            // While we wait, "another PHP process" (the lock holder) finishes the same check.
            if (++$sleeps === 2) {
                $result = $this->client($other)->check('BE', '0123456749');
                $this->store_result($result);
                delete_transient($lock);
            }
        });

        $result = $service->validate('BE', '0123456749', Service::CONTEXT_SUBMISSION);

        $this->assertTrue($result->is_valid());
        $this->assertSame([], $soap->calls);
    }

    public function test_concurrent_check_gives_up_waiting_and_checks_itself(): void
    {
        $soap    = new FakeSoap([ FakeSoap::valid() ]);
        $service = $this->service($soap);

        $this->hold_lock_for('BE', '0123456749'); // holder never finishes
        $result = $service->validate('BE', '0123456749', Service::CONTEXT_SUBMISSION);

        $this->assertTrue($result->is_valid());
        $this->assertCount(1, $soap->calls);
        $this->assertLessThanOrEqual(Service::LOCK_WAIT_SECONDS * 1000 + 250, array_sum($this->sleeps));
    }

    public function test_lock_is_released_after_the_check(): void
    {
        $fault   = FakeSoap::fault('IP_BLOCKED');
        $soap    = new FakeSoap([ $fault, FakeSoap::valid() ]);
        $service = $this->service($soap);

        $service->validate('BE', '0123456749', Service::CONTEXT_SUBMISSION);
        $result = $service->validate('BE', '0123456749', Service::CONTEXT_SUBMISSION);

        $this->assertTrue($result->is_valid());
        $this->assertSame([], $this->sleeps, 'second check did not have to wait for a stale lock');
    }

    public function test_works_with_a_persistent_object_cache(): void
    {
        WP_Test_State::$ext_object_cache = true;
        $soap                            = new FakeSoap([ FakeSoap::valid() ]);
        $service                         = $this->service($soap);

        $service->validate('BE', '0123456749');
        $soap->push(FakeSoap::fault('MS_MAX_CONCURRENT_REQ'));

        $this->assertTrue($service->validate('BE', '0123456749', Service::CONTEXT_SUBMISSION)->is_valid());
        $this->assertCount(1, $soap->calls);
        $this->assertSame([], WP_Test_State::$transients);
    }

    public function test_cache_keys_do_not_contain_the_vat_number(): void
    {
        $service = $this->service(new FakeSoap([ FakeSoap::valid() ]));
        $service->validate('BE', '0123456749');

        foreach (array_keys(WP_Test_State::$transients) as $key) {
            $this->assertStringNotContainsString('0123456749', $key);
            $this->assertLessThanOrEqual(172, strlen('_transient_timeout_' . $key));
        }
    }

    public function test_internal_errors_become_temporary_errors(): void
    {
        add_filter('sitesoft_euvat_cache_ttl_valid', static function () {
            throw new \RuntimeException('broken filter');
        });
        $service = $this->service(new FakeSoap([ FakeSoap::valid() ]));

        $result = $service->validate('BE', '0123456749', Service::CONTEXT_SUBMISSION);

        $this->assertTrue($result->is_temporary_error());
        $this->assertSame('INTERNAL_ERROR', $result->error_code());
        $this->assertStringContainsString('event=validation_internal_error', implode("\n", $this->log_lines));
    }

    private function stored_ttls(): array
    {
        $ttls = [];
        foreach (WP_Test_State::$transients as $key => $entry) {
            if (str_starts_with($key, 'sseuvat_r_')) {
                $ttls[] = $entry['ttl'];
            }
        }

        return $ttls;
    }

    /**
     * Simulate another PHP process holding the lock for this number.
     */
    private function hold_lock_for(string $country, string $number): string
    {
        $key = 'sseuvat_l_' . $this->hash_of(VAT_Number::from_input($country, $number));
        set_transient($key, 1, Service::LOCK_TTL);

        return $key;
    }

    private function store_result(VAT_Result $result): void
    {
        $number = VAT_Number::from_input($result->country_code(), $result->vat_number());
        set_transient('sseuvat_r_' . $this->hash_of($number), $result->to_array(), Service::TTL_VALID);
    }

    private function hash_of(VAT_Number $number): string
    {
        $service    = new Service($this->client(new FakeSoap()), new \Sitesoft\GravityForms\VATChecker\VAT_Cache());
        $reflection = new \ReflectionMethod(Service::class, 'hash');
        $reflection->setAccessible(true);

        return $reflection->invoke($service, $number);
    }
}
