<?php

/*
 * Plugin Name:       Gravity Forms EU VAT Checker
 * Plugin URI:        https://sitesoft.be
 * Description:       Validates EU VAT in Gravity Forms field
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Version:           2025.04.01
 * Author:            Sander Rebry
 * Author URI:        https://sitesoft.be
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sitesoft-eu-vat
 * Domain Path:       /languages
 * Requires Plugins:  gravityforms
 */

namespace Sitesoft\GravityForms\VATChecker;

defined('ABSPATH') || exit;

define('SITESOFT_GF_URL', plugin_dir_url(__FILE__));
define('SITESOFT_GF_DIR', plugin_dir_path(__FILE__));

require_once SITESOFT_GF_DIR . 'update-checker.php';

add_action('gform_loaded', function () {
    require_once SITESOFT_GF_DIR . 'includes/class-euvat-field.php';
    require_once SITESOFT_GF_DIR . 'includes/class-ajax-handler.php';

    new Field_EU_VAT();
    new AJAX_Handler();
}, 5);

add_action('gform_enqueue_scripts', function ($form, $is_ajax) {
    foreach ($form['fields'] as $field) {
        if (isset($field->type) && $field->type === 'euvat') {
            wp_enqueue_script(
                'eu-vat-validator',
                SITESOFT_GF_URL . 'assets/js/vat-validator.js',
                [ 'jquery' ],
                null,
                true,
            );
            wp_localize_script('eu-vat-validator', 'vatChecker', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('validate_vat_nonce'),
            ]);
            break;
        }
    }
}, 10, 2);

add_action('gform_field_standard_settings', function ($position, $form_id) {
    if ($position == 50) {
        ?>
        <li class="euvat_mappings_setting field_setting">
            <label class="section_label"><?php esc_html_e('Mapping velden (optioneel)', 'sitesoft-eu-vat'); ?></label>

            <label><?php esc_html_e('Naam veld (input_x)', 'sitesoft-eu-vat'); ?></label>
            <input type="text" id="euvat_name_field" size="25">

            <label><?php esc_html_e('Straat veld', 'sitesoft-eu-vat'); ?></label>
            <input type="text" id="euvat_street_field" size="25">

            <label><?php esc_html_e('Postcode veld', 'sitesoft-eu-vat'); ?></label>
            <input type="text" id="euvat_zip_field" size="25">

            <label><?php esc_html_e('Gemeente veld', 'sitesoft-eu-vat'); ?></label>
            <input type="text" id="euvat_city_field" size="25">

            <label><?php esc_html_e('Land veld', 'sitesoft-eu-vat'); ?></label>
            <input type="text" id="euvat_country_field" size="25">
        </li>
		<?php
    }
}, 10, 2);

add_action('admin_footer', function () {
    $screen = get_current_screen();
    if ($screen && $screen->id !== 'toplevel_page_gf_edit_forms') {
        return;
    }
    ?>
    <script type="text/javascript">
        jQuery(document).on('gform_load_field_settings', function (event, field, form) {
            jQuery('#euvat_name_field').val(field.euvatNameField || '');
            jQuery('#euvat_street_field').val(field.euvatStreetField || '');
            jQuery('#euvat_zip_field').val(field.euvatZipField || '');
            jQuery('#euvat_city_field').val(field.euvatCityField || '');
            jQuery('#euvat_country_field').val(field.euvatCountryField || '');
        });

        jQuery('#euvat_name_field').on('change', function () {
            SetFieldProperty('euvatNameField', jQuery(this).val());
        });
        jQuery('#euvat_street_field').on('change', function () {
            SetFieldProperty('euvatStreetField', jQuery(this).val());
        });
        jQuery('#euvat_zip_field').on('change', function () {
            SetFieldProperty('euvatZipField', jQuery(this).val());
        });
        jQuery('#euvat_city_field').on('change', function () {
            SetFieldProperty('euvatCityField', jQuery(this).val());
        });
        jQuery('#euvat_country_field').on('change', function () {
            SetFieldProperty('euvatCountryField', jQuery(this).val());
        });
    </script>
	<?php
});
