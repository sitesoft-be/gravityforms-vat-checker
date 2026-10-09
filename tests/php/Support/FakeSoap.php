<?php

namespace Sitesoft\GravityForms\VATChecker\Tests\Support;

use LogicException;
use SoapFault;
use Throwable;

/**
 * Scripted stand-in for SoapClient. Each VIES call consumes the next step:
 * a response object, a Throwable (thrown), or a callable returning either.
 */
final class FakeSoap
{
    /** @var array<int, array{wsdl: string, options: array, params: array, socket_timeout: string|false}> */
    public array $calls = [];

    public function __construct(private array $script = [])
    {
    }

    public function push(...$steps): self
    {
        foreach ($steps as $step) {
            $this->script[] = $step;
        }

        return $this;
    }

    public function factory(): callable
    {
        $fake = $this;

        return static function (string $wsdl, array $options) use ($fake): object {
            return new class ($fake, $wsdl, $options) {
                public function __construct(private FakeSoap $fake, private string $wsdl, private array $options)
                {
                }

                public function checkVat(array $params)
                {
                    return $this->fake->respond($this->wsdl, $this->options, $params);
                }
            };
        };
    }

    public function respond(string $wsdl, array $options, array $params)
    {
        $this->calls[] = [
            'wsdl'           => $wsdl,
            'options'        => $options,
            'params'         => $params,
            'socket_timeout' => ini_get('default_socket_timeout'),
        ];

        if (! $this->script) {
            throw new LogicException('Unexpected VIES call #' . count($this->calls));
        }

        $step = array_shift($this->script);
        if (is_callable($step)) {
            $step = $step($params);
        }
        if ($step instanceof Throwable) {
            throw $step;
        }

        return $step;
    }

    public static function valid(
        string $country_code = 'BE',
        string $vat_number = '0123456749',
        string $name = 'NV SITESOFT TEST',
        string $address = "Kerkstraat 1\n9000 Gent",
    ): object {
        return (object) [
            'countryCode' => $country_code,
            'vatNumber'   => $vat_number,
            'requestDate' => '2026-10-09+02:00',
            'valid'       => true,
            'name'        => $name,
            'address'     => $address,
        ];
    }

    public static function invalid(string $country_code = 'BE', string $vat_number = '0123456749'): object
    {
        return (object) [
            'countryCode' => $country_code,
            'vatNumber'   => $vat_number,
            'requestDate' => '2026-10-09+02:00',
            'valid'       => false,
            'name'        => '---',
            'address'     => '---',
        ];
    }

    public static function fault(string $fault_string, string $fault_code = 'env:Server'): SoapFault
    {
        return new SoapFault($fault_code, $fault_string);
    }
}
