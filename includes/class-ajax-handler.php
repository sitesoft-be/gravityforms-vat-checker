<?php

namespace Sitesoft\GravityForms\VATChecker;

class AJAX_Handler {

	public function __construct() {
		add_action('wp_ajax_validate_vat_number', [$this, 'handle_ajax']);
		add_action('wp_ajax_nopriv_validate_vat_number', [$this, 'handle_ajax']);
	}

	public function handle_ajax(): void {
		check_ajax_referer('validate_vat_nonce', 'nonce');

		$vat = sanitize_text_field($_POST['vat'] ?? '');
		if (empty($vat)) {
			wp_send_json_error(['message' => 'BTW-nummer is leeg.']);
		}

		$url = "https://controleerbtwnummer.eu/api/validate/" . urlencode($vat) . ".json";
		$response = wp_remote_get($url);

		if (is_wp_error($response)) {
			wp_send_json_error(['message' => 'Fout bij verbinden met API.']);
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);

		if (!empty($body['valid'])) {
			wp_send_json_success(['message' => 'Geldig BTW-nummer.', ...$body]);
		} else {
			wp_send_json_error(['message' => 'Ongeldig BTW-nummer.']);
		}
	}
}
