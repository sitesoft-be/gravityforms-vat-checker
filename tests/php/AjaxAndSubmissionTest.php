<?php

namespace Sitesoft\GravityForms\VATChecker\Tests;

use Sitesoft\GravityForms\VATChecker\AJAX_Handler;
use Sitesoft\GravityForms\VATChecker\GF_Field_EU_VAT;
use Sitesoft\GravityForms\VATChecker\Tests\Support\FakeSoap;
use Sitesoft\GravityForms\VATChecker\Tests\Support\TestCase;
use WP_Test_Json_Response;
use WP_Test_State;

final class AjaxAndSubmissionTest extends TestCase
{
    private const TEMPORARY_MESSAGE = 'VAT number verification is temporarily unavailable. Please try again in a few moments.';

    public function test_ajax_valid_response(): void
    {
        $this->service(new FakeSoap([ FakeSoap::valid() ]));

        $response = $this->ajax('BE', 'BE 0123.456.749');

        $this->assertTrue($response['success']);
        $this->assertSame('valid', $response['data']['status']);
        $this->assertSame('0123456749', $response['data']['vatNumber']);
        $this->assertSame('BE', $response['data']['countryCode']);
        $this->assertSame('NV SITESOFT TEST', $response['data']['name']);
        $this->assertSame([
            'street'   => 'Kerkstraat',
            'number'   => '1',
            'zip_code' => '9000',
            'city'     => 'Gent',
            'country'  => 'BE',
        ], $response['data']['address']);
        $this->assertMatchesRegularExpression('/^\d+\.[a-f0-9]{64}$/', $response['data']['token']);
    }

    public function test_ajax_invalid_response(): void
    {
        $this->service(new FakeSoap([ FakeSoap::invalid() ]));

        $response = $this->ajax('BE', '0123456749');

        $this->assertFalse($response['success']);
        $this->assertSame([ 'status' => 'invalid', 'message' => 'Invalid VAT number' ], $response['data']);
    }

    public function test_ajax_temporary_error_response_hides_technical_details(): void
    {
        $fault = FakeSoap::fault('MS_MAX_CONCURRENT_REQ');
        $this->service(new FakeSoap([ $fault, $fault ]));

        $response = $this->ajax('BE', '0123456749');

        $this->assertFalse($response['success']);
        $this->assertSame([ 'status' => 'temporary_error', 'message' => self::TEMPORARY_MESSAGE ], $response['data']);
        $this->assertStringNotContainsString('MS_MAX', json_encode($response));
    }

    public function test_ajax_expired_nonce_is_temporary_not_invalid(): void
    {
        WP_Test_State::$nonce_valid = false;
        $soap                       = new FakeSoap();
        $this->service($soap);

        $response = $this->ajax('BE', '0123456749');

        $this->assertSame('temporary_error', $response['data']['status']);
        $this->assertSame([], $soap->calls);
        $this->assertStringContainsString('event=ajax_nonce_failed', implode("\n", $this->log_lines));
    }

    public function test_submit_valid(): void
    {
        $this->service(new FakeSoap([ FakeSoap::valid() ]));

        $field = $this->submit('BE', '0123.456.749');

        $this->assertFalse($field->failed_validation);
    }

    public function test_submit_invalid_shows_invalid_message(): void
    {
        $this->service(new FakeSoap([ FakeSoap::invalid() ]));

        $field = $this->submit('BE', '0123456749');

        $this->assertTrue($field->failed_validation);
        $this->assertSame('The EU VAT number is invalid.', $field->validation_message);
    }

    public function test_submit_invalid_uses_custom_error_message(): void
    {
        $this->service(new FakeSoap([ FakeSoap::invalid() ]));

        $field = $this->submit('BE', '0123456749', '', 'Custom: invalid!');

        $this->assertSame('Custom: invalid!', $field->validation_message);
    }

    public function test_submit_during_vies_outage_never_says_invalid(): void
    {
        $fault = FakeSoap::fault('Could not connect to host', 'HTTP');
        $this->service(new FakeSoap([ $fault, $fault ]));

        // Even with a custom "invalid" message configured on the field.
        $field = $this->submit('BE', '0123456749', '', 'Custom: invalid!');

        $this->assertTrue($field->failed_validation);
        $this->assertSame(esc_html(self::TEMPORARY_MESSAGE), $field->validation_message);
    }

    public function test_empty_optional_value_does_not_call_vies(): void
    {
        $soap = new FakeSoap();
        $this->service($soap);

        $field = $this->submit('BE', '   ');

        $this->assertFalse($field->failed_validation);
        $this->assertSame([], $soap->calls);
    }

    public function test_ajax_valid_followed_by_submit_while_vies_is_unreachable(): void
    {
        $soap = new FakeSoap([ FakeSoap::valid() ]);
        $this->service($soap);

        $ajax = $this->ajax('BE', '0123 456 749');
        $this->assertTrue($ajax['success']);

        $soap->push(
            FakeSoap::fault('MS_MAX_CONCURRENT_REQ'),
            FakeSoap::fault('Could not connect to host', 'HTTP'),
        );
        $field = $this->submit('BE', 'BE0123456749', $ajax['data']['token']);

        $this->assertFalse($field->failed_validation);
        $this->assertCount(1, $soap->calls, 'submit reused the AJAX result');
    }

    public function test_ajax_valid_followed_by_submit_with_evicted_cache_uses_token(): void
    {
        $soap = new FakeSoap([ FakeSoap::valid() ]);
        $this->service($soap);
        $ajax = $this->ajax('BE', '0123456749');

        WP_Test_State::$transients = [];
        $soap->push(FakeSoap::fault('MS_MAX_CONCURRENT_REQ'), FakeSoap::fault('MS_MAX_CONCURRENT_REQ'));
        $field = $this->submit('BE', '0123456749', $ajax['data']['token']);

        $this->assertFalse($field->failed_validation);
        $this->assertCount(1, $soap->calls);
    }

    public function test_valid_submit_renders_a_token_for_the_next_page_load(): void
    {
        $this->service(new FakeSoap([ FakeSoap::valid() ]));
        $field = $this->submit('BE', '0123456749');

        $html = $field->get_field_input([ 'id' => 3 ], '0123456749');

        $this->assertMatchesRegularExpression("/name='euvat_token_7' value='\\d+\\.[a-f0-9]{64}'/", $html);
        $this->assertStringContainsString("class='temporary'", $html);
    }

    private function ajax(string $country, string $vat): array
    {
        $_POST = [
            'action'       => 'validate_vat_number',
            'country_code' => $country,
            'vat'          => $vat,
            'nonce'        => 'nonce',
        ];

        try {
            (new AJAX_Handler())->handle_ajax();
        } catch (WP_Test_Json_Response $response) {
            return $response->payload;
        }

        $this->fail('AJAX handler did not send a JSON response');
    }

    private function submit(string $country, string $vat, string $token = '', string $error_message = ''): GF_Field_EU_VAT
    {
        $_POST = [
            'input_7'       => $vat,
            'country_code'  => $country,
            'euvat_token_7' => $token,
        ];

        $field               = new GF_Field_EU_VAT();
        $field->id           = 7;
        $field->errorMessage = $error_message;

        $value = $field->get_value_submission([]);
        $field->validate($value, [ 'id' => 3 ]);

        return $field;
    }
}
