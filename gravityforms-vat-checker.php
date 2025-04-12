<?php

/*
 * Plugin Name:       Gravity Forms EU VAT Checker
 * Plugin URI:        https://sitesoft.be
 * Description:       Validates EU VAT in Gravity Forms field
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Version:           2025.04.12
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
    require_once SITESOFT_GF_DIR . 'includes/class-eu-vat-api.php';
    require_once SITESOFT_GF_DIR . 'includes/class-gf-field-euvat.php';
    require_once SITESOFT_GF_DIR . 'includes/class-ajax-handler.php';

    new GF_Field_EU_VAT();
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

    if ($position !== 50) {
        return;
    }

    $formOb = \GFAPI::get_form($form_id);

    $mappingFields = [
        'name'    => __('Name', 'sitesoft-eu-vat'),
        'street'  => __('Street', 'sitesoft-eu-vat'),
        'zip'     => __('Zip code', 'sitesoft-eu-vat'),
        'city'    => __('City', 'sitesoft-eu-vat'),
        'country' => __('Country', 'sitesoft-eu-vat'),
    ];

    $fields = \GFAPI::get_fields_by_type($formOb, [ 'text', 'address' ], true);
    ?>
    <label class="section_label"><?php esc_html_e('Mapping fields (optional)', 'sitesoft-eu-vat'); ?></label>
	<?php foreach ($mappingFields as $mappingField => $label): ?>
        <li class="euvat_mappings_setting field_setting">
            <label class="field_placeholder"
                   for="euvat_<?php echo $mappingField; ?>_field"
            >
				<?php echo $label; ?>
            </label>
            <select id="euvat_<?php echo $mappingField; ?>_field">
                <option selected><?php _e('Select a field', 'sitesoft-eu-vat'); ?></option>
				<?php foreach ($fields as $field):

				    if ($field->inputType === 'address') :
				        $address_types = $field->inputs;
				        foreach ($address_types as $address_type) :
				            ?>
                            <option value="<?php echo esc_attr($address_type['id']); ?>"><?php echo esc_html($address_type['label']); ?></option>
						<?php endforeach;
				    else:
				        ?>
                        <option value="<?php echo esc_attr($field->id); ?>"><?php echo esc_html($field->label); ?></option>
					<?php endif; endforeach; ?>
            </select>
        </li>
	<?php endforeach; ?>
	<?php
}, 10, 2);

add_action('gform_editor_js', function () {
    ?>
    <script type="text/javascript">
        (function ($) {
            $(document).on('gform_load_field_settings', function (event, field, form) {
                $('#euvat_name_field').val(field.euvatNameField || '');
                $('#euvat_street_field').val(field.euvatStreetField || '');
                $('#euvat_zip_field').val(field.euvatZipField || '');
                $('#euvat_city_field').val(field.euvatCityField || '');
                $('#euvat_country_field').val(field.euvatCountryField || '');
            });

            $('#euvat_name_field').on('change', function () {
                SetFieldProperty('euvatNameField', $(this).val());
            });
            $('#euvat_street_field').on('change', function () {
                SetFieldProperty('euvatStreetField', $(this).val());
            });
            $('#euvat_zip_field').on('change', function () {
                SetFieldProperty('euvatZipField', $(this).val());
            });
            $('#euvat_city_field').on('change', function () {
                SetFieldProperty('euvatCityField', $(this).val());
            });
            $('#euvat_country_field').on('change', function () {
                SetFieldProperty('euvatCountryField', $(this).val());
            });
        }(jQuery))

    </script>
	<?php
});

function load_textdomain(): void
{
    get_plugin_data(__FILE__);
}
add_action('init', __NAMESPACE__ . '\\load_textdomain');
