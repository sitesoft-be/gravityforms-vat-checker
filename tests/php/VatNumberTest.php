<?php

namespace Sitesoft\GravityForms\VATChecker\Tests;

use PHPUnit\Framework\TestCase;
use Sitesoft\GravityForms\VATChecker\VAT_Number;

final class VatNumberTest extends TestCase
{
    /**
     * @dataProvider inputs
     */
    public function test_normalisation(string $country, string $input, string $expected_country, string $expected_number): void
    {
        $number = VAT_Number::from_input($country, $input);

        $this->assertSame($expected_country, $number->country_code());
        $this->assertSame($expected_number, $number->number());
    }

    public static function inputs(): array
    {
        return [
            'plain'                    => [ 'BE', '0123456749', 'BE', '0123456749' ],
            'dots and spaces'          => [ 'BE', ' 0123.456.749 ', 'BE', '0123456749' ],
            'dashes'                   => [ 'BE', '0123-456-749', 'BE', '0123456749' ],
            'prefix'                   => [ 'BE', 'BE0123456749', 'BE', '0123456749' ],
            'lowercase prefix + space' => [ 'be', 'be 0123 456 749', 'BE', '0123456749' ],
            'nbsp and en dash'         => [ 'BE', "0123\u{00A0}456\u{2013}749", 'BE', '0123456749' ],
            'DE'                       => [ 'DE', 'DE 123 456 789', 'DE', '123456789' ],
            'other prefix is kept'     => [ 'BE', 'DE123456789', 'BE', 'DE123456789' ],
            'greece GR -> EL'          => [ 'GR', 'GR 094014201', 'EL', '094014201' ],
            'greece EL prefix'         => [ 'EL', 'EL094014201', 'EL', '094014201' ],
            'letters uppercased'       => [ 'NL', 'nl123456789b01', 'NL', '123456789B01' ],
        ];
    }

    public function test_well_formed_rules_follow_the_vies_schema(): void
    {
        $this->assertTrue(VAT_Number::from_input('BE', '0123456749')->is_well_formed());
        $this->assertTrue(VAT_Number::from_input('IE', '1234567WA')->is_well_formed());
        $this->assertTrue(VAT_Number::from_input('IE', '1+23456A')->is_well_formed());

        $this->assertFalse(VAT_Number::from_input('BE', '')->is_well_formed());
        $this->assertFalse(VAT_Number::from_input('BE', '1')->is_well_formed());
        $this->assertFalse(VAT_Number::from_input('BE', '0123456749012345')->is_well_formed());
        $this->assertFalse(VAT_Number::from_input('BE', '0123456749!')->is_well_formed());
        $this->assertFalse(VAT_Number::from_input('US', '123456789')->is_well_formed());
    }

    public function test_same_number_in_different_notations_has_one_key(): void
    {
        $this->assertSame(
            VAT_Number::from_input('BE', 'BE 0123.456.749')->key(),
            VAT_Number::from_input('be', '0123456749')->key(),
        );
    }
}
