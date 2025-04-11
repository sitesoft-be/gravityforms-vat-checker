<?php

namespace Sitesoft\GravityForms\VATChecker;

use SoapFault;

class EU_VAT_API
{
    public function __construct(
        protected string $vatNumber,
        protected string $countryCode = 'BE',
    ) {}

    public function get_results()
    {
        try {

            $stream_context = [];

            if (wp_get_environment_type() === "development") {
                $stream_context = [
                    'stream_context' => stream_context_create([
                        'ssl' => [
                            'allow_self_signed' => true,
                            'verify_peer'       => false,
                            'verify_peer_name'  => false,
                        ],
                    ]),
                ];
            }

            $client = new \SoapClient(
                "https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl",
                $stream_context,
            );

            $params = [
                'countryCode' => $this->countryCode,
                'vatNumber'   => $this->vatNumber,
            ];

            return $client->checkVat($params);

            // $result->countryCode;
            // $result->vatNumber;
            // $result->valid;
            // $result->name;
            // $result->address;

        } catch (SoapFault $e) {
            error_log($e);
        }

        return false;
    }

    public function parse_address(string $address): array
    {
        $lines      = explode("\n", trim($address));
        $streetLine = $lines[0] ?? '';
        $cityLine   = $lines[1] ?? '';

        preg_match('/^(.*?)(\d+\s?\w*)$/', $streetLine, $streetMatches);
        $street = trim($streetMatches[1] ?? '');
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
