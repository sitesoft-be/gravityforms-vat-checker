<?php

namespace Sitesoft\GravityForms\VATChecker;

/**
 * Address helper for VIES data.
 *
 * The SOAP call itself lives in VIES_Client and is orchestrated by
 * VAT_Validation_Service. get_results() is kept for backwards compatibility only.
 */
class EU_VAT_API
{
    public function __construct(
        protected string $vatNumber,
        protected string $countryCode = 'BE',
    ) {}

    /**
     * @deprecated Use VAT_Validation_Service::instance()->validate(), which
     *             distinguishes invalid numbers from temporary VIES problems.
     *
     * @return object|false VIES-like object, or false on a temporary/technical error
     */
    public function get_results()
    {
        $result = VAT_Validation_Service::instance()->validate($this->countryCode, $this->vatNumber);

        if ($result->is_temporary_error()) {
            return false;
        }

        return (object) [
            'countryCode' => $result->country_code(),
            'vatNumber'   => $result->vat_number(),
            'valid'       => $result->is_valid(),
            'name'        => $result->name(),
            'address'     => $result->address(),
        ];
    }

    public function parse_address(string $address): array
    {
        $lines      = explode("\n", trim($address));
        $streetLine = $lines[0] ?? '';
        $cityLine   = $lines[1] ?? '';

        preg_match('/^(.*?)(\d+\s?\w*)$/', $streetLine, $streetMatches);
        $street = trim($streetMatches[1] ?? $streetLine);
        $number = trim($streetMatches[2] ?? '');

        preg_match('/^(\d{4,5})\s+(.*)$/', $cityLine, $cityMatches);
        $zip  = trim($cityMatches[1] ?? '');
        $city = trim($cityMatches[2] ?? '');

        return [
            'street'   => $street,
            'number'   => $number,
            'zip_code' => $zip,
            'city'     => $city,
            'country'  => $this->countryCode,
        ];
    }
}
