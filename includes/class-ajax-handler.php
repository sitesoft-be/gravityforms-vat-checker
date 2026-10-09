<?php

namespace Sitesoft\GravityForms\VATChecker;

class AJAX_Handler
{
    public function __construct()
    {
        add_action('wp_ajax_validate_vat_number', [ $this, 'handle_ajax' ]);
        add_action('wp_ajax_nopriv_validate_vat_number', [ $this, 'handle_ajax' ]);
    }

    public static function temporary_error_message(): string
    {
        return __(
            'VAT number verification is temporarily unavailable. Please try again in a few moments.',
            'sitesoft-eu-vat',
        );
    }

    public function handle_ajax(): void
    {
        $service = VAT_Validation_Service::instance();

        if (! check_ajax_referer('validate_vat_nonce', 'nonce', false)) {
            // Typically a cached page with an expired nonce. Technical, so never "invalid".
            if ($service->logger()) {
                $service->logger()->warning('ajax_nonce_failed', [ 'code' => 'NONCE' ]);
            }

            wp_send_json_error([
                'status'  => VAT_Result::TEMPORARY_ERROR,
                'message' => self::temporary_error_message(),
            ]);
        }

        $vat         = sanitize_text_field(wp_unslash($_POST['vat'] ?? ''));
        $countryCode = sanitize_text_field(wp_unslash($_POST['country_code'] ?? 'BE'));

        if ($vat === '') {
            wp_send_json_error([
                'status'  => VAT_Result::INVALID,
                'message' => __('VAT number is empty', 'sitesoft-eu-vat'),
            ]);
        }

        $result = $service->validate($countryCode, $vat, VAT_Validation_Service::CONTEXT_AJAX);

        if ($result->is_temporary_error()) {
            wp_send_json_error([
                'status'  => VAT_Result::TEMPORARY_ERROR,
                'message' => self::temporary_error_message(),
            ]);
        }

        if ($result->is_invalid()) {
            wp_send_json_error([
                'status'  => VAT_Result::INVALID,
                'message' => __('Invalid VAT number', 'sitesoft-eu-vat'),
            ]);
        }

        $vat_api        = new EU_VAT_API($result->vat_number(), $result->country_code());
        $parsed_address = $vat_api->parse_address($result->address());

        wp_send_json_success([
            'status'      => VAT_Result::VALID,
            'message'     => __('Valid VAT number', 'sitesoft-eu-vat'),
            'vatNumber'   => $result->vat_number(),
            'countryCode' => $result->country_code(),
            'name'        => $result->name(),
            'address'     => $parsed_address,
            'token'       => $service->create_token($result),
        ]);
    }
}
