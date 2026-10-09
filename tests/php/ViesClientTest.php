<?php

namespace Sitesoft\GravityForms\VATChecker\Tests;

use RuntimeException;
use Sitesoft\GravityForms\VATChecker\Tests\Support\FakeSoap;
use Sitesoft\GravityForms\VATChecker\Tests\Support\TestCase;
use Sitesoft\GravityForms\VATChecker\VAT_Result;
use Sitesoft\GravityForms\VATChecker\VIES_Client;

final class ViesClientTest extends TestCase
{
    public function test_vies_valid_true_is_valid_with_company_data(): void
    {
        $soap   = new FakeSoap([ FakeSoap::valid() ]);
        $result = $this->client($soap)->check('BE', '0123456749');

        $this->assertSame(VAT_Result::VALID, $result->status());
        $this->assertSame('BE', $result->country_code());
        $this->assertSame('0123456749', $result->vat_number());
        $this->assertSame('NV SITESOFT TEST', $result->name());
        $this->assertSame("Kerkstraat 1\n9000 Gent", $result->address());
        $this->assertSame([ 'countryCode' => 'BE', 'vatNumber' => '0123456749' ], $soap->calls[0]['params']);
    }

    public function test_vies_valid_false_is_invalid_and_never_retried(): void
    {
        $soap   = new FakeSoap([ FakeSoap::invalid() ]);
        $result = $this->client($soap)->check('BE', '0123456749');

        $this->assertSame(VAT_Result::INVALID, $result->status());
        $this->assertCount(1, $soap->calls);
        $this->assertSame([], $this->sleeps);
    }

    public function test_placeholder_name_and_address_are_dropped(): void
    {
        $soap   = new FakeSoap([ FakeSoap::valid('DE', '123456789', '---', '---') ]);
        $result = $this->client($soap)->check('DE', '123456789');

        $this->assertTrue($result->is_valid());
        $this->assertSame('', $result->name());
        $this->assertSame('', $result->address());
    }

    /**
     * @dataProvider temporary_faults
     */
    public function test_technical_faults_are_temporary_never_invalid(
        \Throwable $fault,
        string $expected_code,
        bool $retryable,
    ): void {
        // Same fault twice so a retry (when applicable) fails as well.
        $soap   = new FakeSoap([ $fault, $fault ]);
        $result = $this->client($soap)->check('BE', '0123456749');

        $this->assertSame(VAT_Result::TEMPORARY_ERROR, $result->status());
        $this->assertFalse($result->is_invalid());
        $this->assertSame($expected_code, $result->error_code());
        $this->assertCount($retryable ? 2 : 1, $soap->calls);
    }

    public static function temporary_faults(): array
    {
        return [
            'MS_MAX_CONCURRENT_REQ'     => [ FakeSoap::fault('MS_MAX_CONCURRENT_REQ'), 'MS_MAX_CONCURRENT_REQ', true ],
            'GLOBAL_MAX_CONCURRENT_REQ' => [ FakeSoap::fault('GLOBAL_MAX_CONCURRENT_REQ'), 'GLOBAL_MAX_CONCURRENT_REQ', true ],
            'MS_MAX_..._TIME'           => [ FakeSoap::fault('MS_MAX_CONCURRENT_REQ_TIME'), 'MS_MAX_CONCURRENT_REQ_TIME', true ],
            'MS_UNAVAILABLE'            => [ FakeSoap::fault('MS_UNAVAILABLE'), 'MS_UNAVAILABLE', true ],
            'SERVICE_UNAVAILABLE'       => [ FakeSoap::fault('SERVICE_UNAVAILABLE'), 'SERVICE_UNAVAILABLE', true ],
            'SERVER_BUSY'               => [ FakeSoap::fault('SERVER_BUSY'), 'SERVER_BUSY', true ],
            'VIES TIMEOUT'              => [ FakeSoap::fault('TIMEOUT'), 'TIMEOUT', true ],
            'HTTP connect failure'      => [ FakeSoap::fault('Could not connect to host', 'HTTP'), 'HTTP_CONNECT', true ],
            'HTTP read timeout'         => [ FakeSoap::fault('Error Fetching http headers', 'HTTP'), 'HTTP_READ', true ],
            'HTTP 503 page'             => [ FakeSoap::fault('Service Unavailable', 'HTTP'), 'HTTP_ERROR', true ],
            'WSDL unreachable'          => [
                FakeSoap::fault("SOAP-ERROR: Parsing WSDL: Couldn't load from 'https://ec.europa.eu/x.wsdl'", 'WSDL'),
                'WSDL_ERROR',
                true,
            ],
            'HTML instead of SOAP'      => [ FakeSoap::fault('looks like we got no XML document', 'Client'), 'BAD_RESPONSE', true ],
            'HTML page with doctype'    => [ FakeSoap::fault('DTD are not supported by SOAP', 'Client'), 'BAD_RESPONSE', true ],
            'unknown SoapFault'         => [ FakeSoap::fault('SOMETHING_NEW_AT_VIES'), 'UNKNOWN_FAULT', false ],
            'INVALID_INPUT'             => [ FakeSoap::fault('INVALID_INPUT'), 'INVALID_INPUT', false ],
            'IP_BLOCKED'                => [ FakeSoap::fault('IP_BLOCKED'), 'IP_BLOCKED', false ],
            'non-SOAP exception'        => [ new RuntimeException('boom'), 'INTERNAL_ERROR', false ],
        ];
    }

    public function test_first_temporary_error_then_successful_retry(): void
    {
        $soap   = new FakeSoap([ FakeSoap::fault('MS_MAX_CONCURRENT_REQ'), FakeSoap::valid() ]);
        $result = $this->client($soap)->check('BE', '0123456749');

        $this->assertTrue($result->is_valid());
        $this->assertCount(2, $soap->calls);
        $this->assertSame([ 400 ], $this->sleeps, 'one short backoff before the retry');
    }

    public function test_two_temporary_errors_stop_after_exactly_one_retry(): void
    {
        $soap   = new FakeSoap([
            FakeSoap::fault('MS_MAX_CONCURRENT_REQ'),
            FakeSoap::fault('MS_MAX_CONCURRENT_REQ'),
        ]);
        $result = $this->client($soap)->check('BE', '0123456749');

        $this->assertTrue($result->is_temporary_error());
        $this->assertSame('MS_MAX_CONCURRENT_REQ', $result->error_code());
        $this->assertCount(2, $soap->calls);
        $this->assertSame([ 400 ], $this->sleeps);
    }

    public function test_temporary_error_then_invalid_on_retry_is_invalid(): void
    {
        $soap   = new FakeSoap([ FakeSoap::fault('MS_MAX_CONCURRENT_REQ'), FakeSoap::invalid() ]);
        $result = $this->client($soap)->check('BE', '0123456749');

        $this->assertTrue($result->is_invalid());
    }

    public function test_no_retry_after_a_slow_failure(): void
    {
        // A timeout that already took 8 seconds must not be followed by another 8 seconds.
        $soap   = new FakeSoap([ $this->slow(8.0, FakeSoap::fault('Error Fetching http headers', 'HTTP')) ]);
        $result = $this->client($soap)->check('BE', '0123456749');

        $this->assertTrue($result->is_temporary_error());
        $this->assertSame('HTTP_READ', $result->error_code());
        $this->assertCount(1, $soap->calls);
        $this->assertSame([], $this->sleeps);
    }

    public function test_retry_uses_remaining_time_budget(): void
    {
        $soap = new FakeSoap([
            $this->slow(3.5, FakeSoap::fault('Could not connect to host', 'HTTP')),
            FakeSoap::valid(),
        ]);
        $this->client($soap, [ 'total_budget' => 10 ])->check('BE', '0123456749');

        // 10s budget - 3.5s first attempt - 0.4s backoff => 6s left for the retry.
        $this->assertSame('8', $soap->calls[0]['socket_timeout']);
        $this->assertSame('6', $soap->calls[1]['socket_timeout']);
        $this->assertSame(5, $soap->calls[1]['options']['connection_timeout']);
    }

    public function test_soap_client_is_configured_for_production(): void
    {
        $soap = new FakeSoap([ FakeSoap::valid() ]);
        $this->client($soap)->check('BE', '0123456749');
        $options = $soap->calls[0]['options'];

        $this->assertSame(VIES_Client::WSDL, $soap->calls[0]['wsdl']);
        $this->assertSame('https://ec.europa.eu/taxation_customs/vies/services/checkVatService', $options['location']);
        $this->assertTrue($options['exceptions']);
        $this->assertFalse($options['trace']);
        $this->assertSame(WSDL_CACHE_BOTH, $options['cache_wsdl']);
        $this->assertSame(5, $options['connection_timeout']);
        $this->assertFalse($options['keep_alive']);
        $this->assertSame('8', $soap->calls[0]['socket_timeout'], 'read timeout during the call');

        $context = stream_context_get_options($options['stream_context']);
        $this->assertTrue($context['ssl']['verify_peer']);
        $this->assertTrue($context['ssl']['verify_peer_name']);
        $this->assertSame(8.0, $context['http']['timeout']);
    }

    public function test_default_socket_timeout_is_restored(): void
    {
        $before = ini_get('default_socket_timeout');
        $soap   = new FakeSoap([ FakeSoap::fault('MS_UNAVAILABLE'), FakeSoap::fault('MS_UNAVAILABLE') ]);
        $this->client($soap)->check('BE', '0123456749');

        $this->assertSame($before, ini_get('default_socket_timeout'));
    }

    public function test_ssl_verification_can_only_be_disabled_explicitly(): void
    {
        $soap = new FakeSoap([ FakeSoap::valid() ]);
        $this->client($soap, [ 'verify_ssl' => false ])->check('BE', '0123456749');
        $context = stream_context_get_options($soap->calls[0]['options']['stream_context']);

        $this->assertFalse($context['ssl']['verify_peer']);
    }

    public function test_unexpected_response_shape_is_temporary(): void
    {
        $soap   = new FakeSoap([ (object) [ 'foo' => 'bar' ], (object) [ 'foo' => 'bar' ] ]);
        $result = $this->client($soap)->check('BE', '0123456749');

        $this->assertTrue($result->is_temporary_error());
        $this->assertSame('BAD_RESPONSE', $result->error_code());
    }

    public function test_failures_are_logged_without_personal_data(): void
    {
        $soap = new FakeSoap([
            FakeSoap::fault('MS_MAX_CONCURRENT_REQ'),
            FakeSoap::fault('Could not connect to host', 'HTTP'),
        ]);
        $this->client($soap)->check('BE', '0123456749');

        $this->assertCount(2, $this->log_lines);
        $this->assertStringContainsString('event=vies_temporary_error', $this->log_lines[0]);
        $this->assertStringContainsString('country=BE', $this->log_lines[0]);
        $this->assertStringContainsString('code=MS_MAX_CONCURRENT_REQ', $this->log_lines[0]);
        $this->assertStringContainsString('fault=env:Server', $this->log_lines[0]);
        $this->assertStringContainsString('attempt=1', $this->log_lines[0]);
        $this->assertStringContainsString('code=HTTP_CONNECT', $this->log_lines[1]);
        $this->assertStringContainsString('attempt=2', $this->log_lines[1]);

        foreach ($this->log_lines as $line) {
            $this->assertStringNotContainsString('0123456749', $line);
        }
    }
}
