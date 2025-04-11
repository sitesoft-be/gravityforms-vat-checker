<?php

namespace Sitesoft\GravityForms\VATChecker;

if (! class_exists('GF_Fields')) {
    return;
}

class GF_Field_EU_VAT extends \GF_Field_Text
{
    public $type = 'euvat';

    public function get_form_editor_field_settings(): array
    {
        return [
            ...parent::get_form_editor_field_settings(),
            'euvat_mappings_setting',
        ];
    }

    public function validate($value, $form): void
    {
        $country_code = $this->countryCodeSubmitted ?? 'BE';
        $vat_checker  = new EU_VAT_API(urlencode($value), $country_code);
        $results      = $vat_checker->get_results();

        if (! $results) {
            $this->failed_validation  = true;
            $this->validation_message = empty($this->errorMessage) ? esc_html__(
                'There is something wrong with the request.',
                'sitesoft-eu-vat',
            ) : $this->errorMessage;
        }

        if (! $results->valid) {
            $this->failed_validation  = true;
            $this->validation_message = empty($this->errorMessage) ? esc_html__(
                'The EU VAT number is invalid.',
                'sitesoft-eu-vat',
            ) : $this->errorMessage;
        }
    }

    public function get_value_submission($field_values, $get_from_post_global_var = true): array|string
    {
        $input_name = 'input_' . $this->id;

        $this->countryCodeSubmitted = rgpost('country_code') ?: 'BE';

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
        $form_id         = absint($form['id']);
        $is_entry_detail = $this->is_entry_detail();
        $is_form_editor  = $this->is_form_editor();
        $id              = (int) $this->id;
        $country_codes   = [
            'BE',
            'DE',
        ];

        $field_id = $is_entry_detail || $is_form_editor || $form_id == 0 ? "input_$id" : 'input_' . $form_id . "_$id";

        $value        = esc_attr($value);
        $size         = $this->size;
        $class_suffix = $is_entry_detail ? '_admin' : '';
        $class        = [
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

        $selected_code = $this->countryCodeSubmitted ?? 'BE';

        $html = "<div class='ginput_container ginput_container_textginput_container_email ginput_single_euvat' style='position: relative;display: flex; flex-wrap:wrap; gap: .5rem;'>
				<select name='country_code' style='min-width:60px;flex-shrink:1;width:auto;'>";

        foreach ($country_codes as $country_code) {
            $selected = selected($selected_code, $country_code, false);
            $html     .= "<option value='{$country_code}'{$selected}>{$country_code}</option>";
        }
        $html .= "</select>
			    <input
			        name='input_{$id}'
			        id='{$field_id}'
			        type='text'
			        value='{$value}'
			        class='" . esc_attr(implode(" ", $class)) . "'
			        data-map-name='" . esc_attr($this->euvatNameField) . "'
			        data-map-street='" . esc_attr($this->euvatStreetField) . "'
			        data-map-zip='" . esc_attr($this->euvatZipField) . "'
			        data-map-city='" . esc_attr($this->euvatCityField) . "'
			        data-map-country='" . esc_attr($this->euvatCountryField) . "'
			        style='flex:1;'
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
			     <div class='icon-wrapper' style='width: 15px;height: 15px; position: absolute; top: 50%; transform: translateY(-50%); right: 1rem; display:none'>
				    <div class='checkmark' style='display:none; color:green;'>
				    <svg xmlns='http://www.w3.org/2000/svg' fill='#008000' viewBox='0 0 448 512'><path d='M438.6 105.4c12.5 12.5 12.5 32.8 0 45.3l-256 256c-12.5 12.5-32.8 12.5-45.3 0l-128-128c-12.5-12.5-12.5-32.8 0-45.3s32.8-12.5 45.3 0L160 338.7 393.4 105.4c12.5-12.5 32.8-12.5 45.3 0z'/></svg>
					</div>
					<div class='invalid' style='display: none; color: #ff0000;'>
					<svg xmlns='http://www.w3.org/2000/svg' fill='#ff0000' viewBox='0 0 384 512'><path d='M342.6 150.6c12.5-12.5 12.5-32.8 0-45.3s-32.8-12.5-45.3 0L192 210.7 86.6 105.4c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3L146.7 256 41.4 361.4c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0L192 301.3 297.4 406.6c12.5 12.5 32.8 12.5 45.3 0s12.5-32.8 0-45.3L237.3 256 342.6 150.6z'/></svg>
					</div>
				</div>
			</div>";

        return $html;
    }
}

\GF_Fields::register(new GF_Field_EU_VAT());
