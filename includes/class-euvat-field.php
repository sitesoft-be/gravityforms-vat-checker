<?php

namespace Sitesoft\GravityForms\VATChecker;

if (!class_exists('GF_Fields')) {
    return;
}

class Field_EU_VAT extends \GF_Field_Text
{
    public $type = 'euvat';

    public function get_form_editor_field_settings(): array
    {
        return [
            'conditional_logic_field_setting',
            'prepopulate_field_setting',
            'error_message_setting',
            'label_setting',
            'admin_label_setting',
            'label_placement_setting',
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

	public function get_value_submission($field_values, $get_from_post_global_var = true) {
		$input_name = 'input_' . $this->id;
		return rgpost($input_name);
	}


	public function get_form_editor_field_title(): string
    {
        return esc_attr__('EU VAT Field', 'sitesoft-eu-vat');
    }

    public function is_conditional_logic_supported(): bool
    {
        return true;
    }

    public function get_field_input_admin($form, $value = '', $entry = null): string
    {
        return $this->get_field_input($form, $value, $entry);
    }

    public function get_field_input($form, $value = '', $entry = null): string
    {
        $id          = (int) $this->id;
        $form_id         = absint($form['id']);
        $is_entry_detail = $this->is_entry_detail();
        $is_form_editor  = $this->is_form_editor();

        $field_id    = $is_entry_detail || $is_form_editor || $form_id == 0 ? "input_$id" : 'input_' . $form_id . "_$id";
        $value        = esc_attr($value);
        $size         = $this->size;
        $class_suffix = $is_entry_detail ? '_admin' : '';
        $class = [
            "sitesoft-euvat-field",
            $size . $class_suffix,
        ];

        $max_length = is_numeric($this->maxLength) ? "maxlength='{$this->maxLength}'" : '';

        $tabindex              = $this->get_tabindex();
        $disabled_text         = $is_form_editor ? 'disabled="disabled"' : '';
        $placeholder_attribute = $this->get_field_placeholder_attribute();
        $required_attribute    = $this->isRequired ? 'aria-required="true"' : '';
        $invalid_attribute     = $this->failed_validation ? 'aria-invalid="true"' : 'aria-invalid="false"';
        $aria_describedby      = $this->get_aria_describedby();
        $autocomplete          = $this->enableAutocomplete ? $this->get_field_autocomplete_attribute() : '';

        // For Post Tags, Use the WordPress built-in class "howto" in the form editor.
        $text_hint = '';
        if ($this->type === 'post_tags') {
            $text_hint = '<p class="gfield_post_tags_hint gfield_description" id="' . $field_id . '_desc">' . gf_apply_filters([
                'gform_post_tags_hint',
                $form_id,
                $this->id,
            ], esc_html__('Separate tags with commas', 'gravityforms'), $form_id) . '</p>';
        }

        return "<div class='ginput_container ginput_container_email ginput_single_euvat'>
			    <input 
			        name='{$field_id}' 
			        id='{$field_id}' 
			        type='text' 
			        value='{$value}' 
			        class='" . esc_attr(implode(" ", $class)) . "' 
			        data-map-name='" . esc_attr($this->euvatNameField) . "'
			        data-map-street='" . esc_attr($this->euvatStreetField) . "'
			        data-map-zip='" . esc_attr($this->euvatZipField) . "'
			        data-map-city='" . esc_attr($this->euvatCityField) . "'
			        data-map-country='" . esc_attr($this->euvatCountryField) . "'
			        {$max_length} 
			        {$aria_describedby} 
			        {$tabindex} 
			        {$placeholder_attribute} 
			        {$required_attribute} 
			        {$invalid_attribute} 
			        {$disabled_text} 
			        {$autocomplete}
			    />
			    {$text_hint}
			</div>";

    }

    public function get_form_editor_field_input($form, $value = '', $entry = null)
    {
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
