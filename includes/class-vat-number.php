<?php

namespace Sitesoft\GravityForms\VATChecker;

/**
 * Normalised (country code, VAT number) pair as sent to VIES.
 *
 * "be 0123.456.789", "BE0123 456 789" and "0123-456-789" (with BE selected)
 * all normalise to BE / 0123456789, so the AJAX check and the form submission
 * always hit the same cache entry.
 */
final class VAT_Number
{
    /**
     * Country codes accepted by VIES. Greece is "EL" in VIES, "XI" is Northern Ireland.
     */
    public const VIES_COUNTRY_CODES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI',
    ];

    /**
     * Whitespace (incl. non-breaking/zero-width), dots, dashes (incl. unicode dashes),
     * underscores, slashes and commas are formatting only.
     */
    private const STRIP_PATTERN = '/[\s\x{00A0}\x{2000}-\x{200B}\x{2010}-\x{2015}\x{2212}\x{202F}\x{205F}\x{3000}\x{FEFF}.\-_\/,]+/u';

    /**
     * VIES input schema for vatNumber is [0-9A-Za-z+*.]{2,12}; dots are already stripped.
     */
    private const VIES_NUMBER_PATTERN = '/^[0-9A-Z+*]{2,12}$/';

    private function __construct(
        private string $country_code,
        private string $number,
    ) {}

    public static function from_input(string $country_code, string $vat_number): self
    {
        $country_code = strtoupper(trim($country_code));
        if ($country_code === 'GR') {
            $country_code = 'EL';
        }

        $number = preg_replace(self::STRIP_PATTERN, '', strtoupper($vat_number));
        $number = is_string($number) ? $number : '';

        $prefixes = $country_code === 'EL' ? [ 'EL', 'GR' ] : [ $country_code ];
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && strlen($number) > 2 && str_starts_with($number, $prefix)) {
                $number = substr($number, 2);
                break;
            }
        }

        return new self($country_code, $number);
    }

    public function country_code(): string
    {
        return $this->country_code;
    }

    public function number(): string
    {
        return $this->number;
    }

    public function is_supported_country(): bool
    {
        return in_array($this->country_code, self::VIES_COUNTRY_CODES, true);
    }

    /**
     * True when VIES could possibly accept this input. Anything else can never be valid.
     */
    public function is_well_formed(): bool
    {
        return $this->is_supported_country() && preg_match(self::VIES_NUMBER_PATTERN, $this->number) === 1;
    }

    public function key(): string
    {
        return $this->country_code . $this->number;
    }
}
