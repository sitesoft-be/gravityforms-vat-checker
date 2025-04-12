# Gravity Forms EU VAT Checker

**Author:** [Sander Rebry](https://sitesoft.be)  
**Plugin URL:** [sitesoft.be](https://sitesoft.be)  
**Version:** 2025.04.01  
**Requires:** WordPress 6.0+, PHP 8.0+  
**Compatibility:** Gravity Forms

---

## Overview

**Gravity Forms EU VAT Checker** is a WordPress plugin that adds a custom field type to Gravity Forms for validating
European VAT numbers using an external API. The plugin supports country selection and optional field mapping for name,
address, zip code, city, and country.

---

## Features

- New field type in Gravity Forms: **EU VAT**
- Real-time validation of EU VAT numbers via an external API
- Country selection dropdown included in the field
- Optional field mapping for name, street, zip code, city, and country
- Inline visual feedback (checkmark or error icon)
- Supports conditional logic
- Built-in AJAX validation with JavaScript

---

## Installation

1. Upload the plugin to the `/wp-content/plugins/` directory or install it via the WordPress admin panel.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Make sure [Gravity Forms](https://www.gravityforms.com/) is installed and active.
4. Add a new field of type **EU VAT** in your form.
5. (Optional) Configure the mapping fields in the field settings panel.

---

## Field Settings

When using the **EU VAT** field in your Gravity Form, you can optionally map other fields:

- Name
- Street
- Zip Code
- City
- Country

These mappings allow the validation API to retrieve and cross-check additional company data.

---

## JavaScript & AJAX

The plugin automatically enqueues a JavaScript file for real-time validation using AJAX. Validation is triggered when
the field loses focus or when the form is submitted.

---

## Translation & Localization

This plugin is translation-ready.  
Text domain: `sitesoft-eu-vat`  
Translation files can be added in the `/languages` folder as `.po/.mo` files.

---

## Developer Info

- Namespace: `Sitesoft\GravityForms\VATChecker`
- Built with an object-oriented architecture
- Follows best practices like class encapsulation, modular design, and service separation (e.g. API, AJAX handlers)

---

## Possible Future Features

- Support for additional EU countries
- Auto-populate form fields with company data after VAT validation
- Integration with the official VIES API or other validation services

---

## License

GPL v2 or later  
See [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)

---

## Support & Contact

For questions, issues or suggestions, please reach out via [https://sitesoft.be](https://sitesoft.be)
