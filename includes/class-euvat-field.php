<?php

namespace Sitesoft\GravityForms\VATChecker;

if (!class_exists('GF_Fields')) {
	return;
}

class Field_EU_VAT extends \GF_Field {

	public $type = 'euvat';

	public function get_form_editor_field_settings(): array {
		return [
			'conditional_logic_field_setting',
			'prepopulate_field_setting',
			'error_message_setting',
			'label_setting',
			'admin_label_setting',
			'label_placement_setting',
			'sub_label_placement_setting',
			'default_input_values_setting',
			'input_placeholders_setting',
			'rules_setting',
			'copy_values_option',
			'description_setting',
			'visibility_setting',
			'css_class_setting',
			'euvat_mappings_setting',
		];
	}

	public function get_form_editor_field_title(): string {
		return esc_attr__('EU VAT Field', 'sitesoft-eu-vat');
	}

	public function is_conditional_logic_supported(): bool {
		return true;
	}

	public function get_field_input_admin($form, $value = '', $entry = null): string {
		return $this->get_field_input($form, $value, $entry);
	}

	public function get_field_input($form, $value = '', $entry = null): string {
		$input_id = $this->id;
		$field_id = 'input_' . $form['id'] . "_$input_id";
		$size          = $this->size;

		$class = [
			"sitesoft-euvat-field",
			$size
		];

		$required_attribute    = $this->isRequired ? 'aria-required="true"' : '';
		$single_placeholder_attribute        = $this->get_field_placeholder_attribute();

		return "<div class='ginput_container ginput_container_email ginput_single_euvat'>
			    <input 
			        name='{$field_id}' 
			        id='{$field_id}' 
			        type='text' 
			        class='" . esc_attr( implode(" ", $class) ) . "' 
			        {$single_placeholder_attribute} 
			        {$required_attribute} 
			        data-map-name='" . esc_attr($this->euvatNameField) . "'
			        data-map-street='" . esc_attr($this->euvatStreetField) . "'
			        data-map-zip='" . esc_attr($this->euvatZipField) . "'
			        data-map-city='" . esc_attr($this->euvatCityField) . "'
			        data-map-country='" . esc_attr($this->euvatCountryField) . "'
			    />
			</div>";

	}

	public function get_form_editor_field_input($form, $value = '', $entry = null) {
		return '
	    <li class="euvat_mappings_setting field_setting">
	        <label class="section_label">' . __('Mapping velden (optioneel)', 'sitesoft-eu-vat') . '</label>
	        <label>' . __('Naam veld ID', 'sitesoft-eu-vat') . '</label>
	        <input type="text" id="euvat_name_field" size="10">
	        <label>' . __('Straat veld ID', 'sitesoft-eu-vat') . '</label>
	        <input type="text" id="euvat_street_field" size="10">
	        <label>' . __('Postcode veld ID', 'sitesoft-eu-vat') . '</label>
	        <input type="text" id="euvat_zip_field" size="10">
	        <label>' . __('Gemeente veld ID', 'sitesoft-eu-vat') . '</label>
	        <input type="text" id="euvat_city_field" size="10">
	        <label>' . __('Land veld ID', 'sitesoft-eu-vat') . '</label>
	        <input type="text" id="euvat_country_field" size="10">
	    </li>';
	}

}

\GF_Fields::register(new Field_EU_VAT());
