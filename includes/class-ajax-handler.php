<?php

namespace Sitesoft\GravityForms\VATChecker;

class AJAX_Handler
{
    public function __construct()
    {
        add_action('wp_ajax_validate_vat_number', [ $this, 'handle_ajax' ]);
        add_action('wp_ajax_nopriv_validate_vat_number', [ $this, 'handle_ajax' ]);
    }

    public function handle_ajax(): void
    {
        check_ajax_referer('validate_vat_nonce', 'nonce');

        $vat         = sanitize_text_field($_POST['vat'] ?? '');
        $countryCode = sanitize_text_field($_POST['country_code'] ?? 'BE');
        if (empty($vat)) {
            wp_send_json_error([
                'message' => __('VAT number is empty', 'sitesoft-eu-vat'),
            ]);
        }

        $vat_checker = new EU_VAT_API(urlencode($vat), $countryCode);
        $results     = $vat_checker->get_results();

        if (! $results) {
            wp_send_json_error([
                'message' => __('An error has occurred, please try again', 'sitesoft-eu-vat'),
            ]);
        }

        if (! $results->valid) {
            wp_send_json_error([
                'message' => __('Invalid VAT number', 'sitesoft-eu-vat'),
            ]);
        }

        $parsed_address = $vat_checker->parse_address($results->address);

        wp_send_json_success([
            'message'     => __('Valid VAT number', 'sitesoft-eu-vat'),
            'vatNumber'   => $results->vatNumber,
            'countryCode' => $results->countryCode,
            'name'        => $results->name,
            'address'     => $parsed_address,
        ]);
    }
}
