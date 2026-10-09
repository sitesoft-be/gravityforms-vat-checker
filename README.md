# Gravity Forms EU VAT Checker

**Author:** [Sander Rebry](https://sitesoft.be)  
**Plugin URL:** [sitesoft.be](https://sitesoft.be)  
**Version:** 2026.10.09  
**Requires:** WordPress 6.0+, PHP 8.0+  
**Compatibility:** Gravity Forms

---

## Overview

**Gravity Forms EU VAT Checker** is a WordPress plugin that adds a field to Gravity Forms for validating
European VAT numbers against the official European Commission **VIES** SOAP service. The plugin supports country
selection and optional field mapping for name, address, zip code, city, and country.

---

## Features

- New field type in Gravity Forms: **EU VAT**
- Real-time validation of EU VAT numbers via the official VIES SOAP service (free)
- Clear distinction between **valid**, **invalid** and **temporarily unavailable** (VIES outage/overload)
- Short timeouts, one controlled retry, server-side caching and request deduplication
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

## Validation behaviour

Both the AJAX check and the final Gravity Forms submit use the same `VAT_Validation_Service`, so they always agree.

### Statuses

| Status            | When                                                                                     | Shown to the user                                                                                              |
|-------------------|------------------------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------|
| `valid`           | VIES answered `valid=true`                                                               | green checkmark, mapped fields filled                                                                          |
| `invalid`         | VIES answered `valid=false`, or the input can never be a VAT number (VIES input schema) | red cross + "Ongeldig btw-nummer"                                                                              |
| `temporary_error` | any technical problem: VIES faults, timeouts, connection errors, unexpected responses   | amber icon + "De controle van het btw-nummer is momenteel tijdelijk niet beschikbaar. Probeer het over enkele ogenblikken opnieuw." |

A technical problem is **never** reported as an invalid VAT number. Technical details are never sent to the browser.

### VIES fault classification

| Fault                                                                 | Result            | Retried once |
|-----------------------------------------------------------------------|-------------------|--------------|
| `MS_MAX_CONCURRENT_REQ`, `GLOBAL_MAX_CONCURRENT_REQ` (+ `_TIME`)       | temporary error   | yes          |
| `MS_UNAVAILABLE`, `SERVICE_UNAVAILABLE`, `SERVER_BUSY`, `TIMEOUT`      | temporary error   | yes          |
| HTTP errors (connect failure, read timeout, 5xx), WSDL load failure, HTML instead of SOAP | temporary error | yes |
| `INVALID_INPUT`, `INVALID_REQUESTER_INFO`, `VAT_BLOCKED`, `IP_BLOCKED` | temporary error   | no           |
| Unknown SoapFault / internal error                                    | temporary error   | no           |

### SOAP client

- Official WSDL `https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl`, endpoint forced to HTTPS.
- Connect timeout 5s, read timeout 8s (via `default_socket_timeout`, restored afterwards), total budget 12s.
- One retry after 400ms, only for retryable faults and only when the first attempt failed fast (< 4s).
- WSDL cached in memory and on disk (`WSDL_CACHE_BOTH`), `exceptions` on, `trace` off, SSL peer verification on
  (only disabled when `wp_get_environment_type()` is `local` or `development`).

### Caching and request-storm protection

- Input is normalised first: whitespace, dots, dashes removed, uppercase, country prefix stripped (`GR` → `EL`).
- Results are cached per country + number (keyed HMAC, no readable VAT numbers in cache keys):
  `valid` 6 hours (incl. name/address), `invalid` 15 minutes, `temporary_error` 10 seconds (AJAX only).
- A short lock per number makes concurrent identical checks wait for the running one instead of calling VIES again.
- A successful AJAX check returns a signed token (hidden field). On submit, a cached result or a valid token is
  accepted without calling VIES again, so a VIES hiccup between the AJAX check and the submit cannot block a
  registration. The submit never reuses a cached temporary error: it always gets its own live attempt.

### Frontend

- Typing is debounced (800ms) and only triggers a check once the number looks complete (BE: 10 digits, DE: 9 digits);
  otherwise the check runs on blur or country change.
- At most one request per country + number is in flight; blur does not start a duplicate request.
- An unchanged, already checked value is not checked again; changing the value clears the previous status immediately.
- Older AJAX responses can never overwrite newer input (requests are aborted and sequence-checked).
- Input classes: `vat-valid`, `vat-invalid`, `vat-temporary-error`, `vat-checking`.

### Logging

Technical VIES problems are written to the PHP error log, e.g.:

```
[sitesoft-euvat] 2026-10-09T10:15:02Z level=warning event=vies_temporary_error country=BE code=MS_MAX_CONCURRENT_REQ fault=env:Server attempt=1 retryable=yes duration_ms=184 message=MS_MAX_CONCURRENT_REQ
```

No names, addresses, form data or VAT numbers are logged. Identical lines are throttled to one per 60 seconds;
the next line reports `suppressed=N`. Hook `sitesoft_euvat_logged` to forward events to monitoring.

### Filters

| Filter                               | Default                       |
|--------------------------------------|-------------------------------|
| `sitesoft_euvat_cache_ttl_valid`     | `6 * HOUR_IN_SECONDS`         |
| `sitesoft_euvat_cache_ttl_invalid`   | `15 * MINUTE_IN_SECONDS`      |
| `sitesoft_euvat_cache_ttl_temporary` | `10` (0 disables)             |
| `sitesoft_euvat_vies_client_config`  | see `VIES_Client::DEFAULTS`   |
| `sitesoft_euvat_soap_options`        | SoapClient options            |
| `sitesoft_euvat_country_codes`       | `['BE', 'DE']` (dropdown)     |

---

## Development

```bash
composer install && vendor/bin/phpunit   # PHP (requires ext-soap); includes a real SoapClient against a local fake VIES
npm ci && npm test                       # JavaScript (node:test + jsdom)
```

Tests and dev tooling are excluded from the release ZIP.

---

## Translation & Localization

This plugin is translation-ready.  
Text domain: `sitesoft-eu-vat`  
Translation files can be added in the `/languages` folder as `.po/.mo` files.

---

## License

GPL v2 or later  
See [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)

---

## Support & Contact

For questions, issues or suggestions, please reach out via [https://sitesoft.be](https://sitesoft.be)
