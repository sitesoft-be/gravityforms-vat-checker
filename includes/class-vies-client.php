<?php

namespace Sitesoft\GravityForms\VATChecker;

use SoapFault;
use Throwable;

/**
 * Thin, defensive client for the official VIES SOAP service (checkVat).
 *
 * - Short timeouts: connect 5s, read 8s (PHP's default would be 60s).
 * - Exactly one retry, only for fast-failing temporary infrastructure errors.
 * - Every technical problem becomes a TEMPORARY_ERROR result; only an actual
 *   VIES answer with valid=false becomes INVALID.
 */
class VIES_Client
{
    public const WSDL     = 'https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl';
    public const LOCATION = 'https://ec.europa.eu/taxation_customs/vies/services/checkVatService';

    public const DEFAULTS = [
        'wsdl'              => self::WSDL,
        // The WSDL's soap:address is plain http; force the TLS endpoint of the same service.
        'location'          => self::LOCATION,
        // TCP connect + TLS handshake, in seconds (SoapClient "connection_timeout").
        'connect_timeout'   => 5,
        // Max wait for response data, in seconds (applied via default_socket_timeout).
        'read_timeout'      => 8,
        // Upper bound for first attempt + retry together, in seconds.
        'total_budget'      => 12,
        'retry_delay_ms'    => 400,
        // Only retry when the first attempt failed fast (e.g. MS_MAX_CONCURRENT_REQ),
        // never after a slow timeout.
        'retry_max_elapsed' => 4.0,
        'verify_ssl'        => true,
        'user_agent'        => 'Sitesoft-GF-EUVAT (WordPress)',
    ];

    /**
     * Documented VIES fault strings => retryable.
     * Order matters for matching: the *_TIME variants come first.
     */
    public const VIES_FAULTS = [
        'MS_MAX_CONCURRENT_REQ_TIME'     => true,
        'GLOBAL_MAX_CONCURRENT_REQ_TIME' => true,
        'MS_MAX_CONCURRENT_REQ'          => true,
        'GLOBAL_MAX_CONCURRENT_REQ'      => true,
        'SERVICE_UNAVAILABLE'            => true,
        'MS_UNAVAILABLE'                 => true,
        'SERVER_BUSY'                    => true,
        'TIMEOUT'                        => true,
        // Not retryable, but still never "invalid": the request or requester was refused.
        'INVALID_INPUT'                  => false,
        'INVALID_REQUESTER_INFO'         => false,
        'VAT_BLOCKED'                    => false,
        'IP_BLOCKED'                     => false,
    ];

    private array $config;

    /** @var callable(string, array): object */
    private $soap_factory;

    /** @var callable(int): void */
    private $sleeper;

    /** @var callable(): float */
    private $clock;

    public function __construct(
        array $config = [],
        ?callable $soap_factory = null,
        private ?VAT_Logger $logger = null,
        ?callable $sleeper = null,
        ?callable $clock = null,
    ) {
        $this->config       = array_merge(self::DEFAULTS, $config);
        $this->soap_factory = $soap_factory ?? static function (string $wsdl, array $options): object {
            return new \SoapClient($wsdl, $options);
        };
        $this->sleeper      = $sleeper ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
        $this->clock        = $clock ?? static function (): float {
            return microtime(true);
        };
    }

    public function config(): array
    {
        return $this->config;
    }

    /**
     * @param string $country_code normalised VIES country code (see VAT_Number)
     * @param string $vat_number   normalised number without country prefix
     */
    public function check(string $country_code, string $vat_number): VAT_Result
    {
        $started = ($this->clock)();
        $result  = $this->attempt(
            $country_code,
            $vat_number,
            (int) $this->config['connect_timeout'],
            (int) $this->config['read_timeout'],
            1,
        );

        if (! $result->is_temporary_error() || ! $result->is_retryable()) {
            return $result;
        }

        if (($this->clock)() - $started > (float) $this->config['retry_max_elapsed']) {
            return $result;
        }

        ($this->sleeper)((int) $this->config['retry_delay_ms']);

        $remaining       = (float) $this->config['total_budget'] - (($this->clock)() - $started);
        $read_timeout    = (int) max(1, min((int) $this->config['read_timeout'], floor($remaining)));
        $connect_timeout = (int) max(1, min((int) $this->config['connect_timeout'], $read_timeout));

        return $this->attempt($country_code, $vat_number, $connect_timeout, $read_timeout, 2);
    }

    /**
     * Map any exception thrown while talking to VIES to an internal error code.
     *
     * @return array{code: string, retryable: bool, fault: string, message: string}
     */
    public static function classify(Throwable $e): array
    {
        $message = (string) $e->getMessage();

        if (! $e instanceof SoapFault) {
            return [
                'code'      => 'INTERNAL_ERROR',
                'retryable' => false,
                'fault'     => get_class($e),
                'message'   => $message,
            ];
        }

        $fault_code   = isset($e->faultcode) ? (string) $e->faultcode : '';
        $fault_string = isset($e->faultstring) ? (string) $e->faultstring : $message;

        $pattern = '/\b(' . implode('|', array_keys(self::VIES_FAULTS)) . ')\b/';
        if (preg_match($pattern, $fault_string, $matches) === 1) {
            return [
                'code'      => $matches[1],
                'retryable' => self::VIES_FAULTS[ $matches[1] ],
                'fault'     => $fault_code,
                'message'   => $fault_string,
            ];
        }

        $code = 'UNKNOWN_FAULT';
        $retryable = false;

        if (strcasecmp($fault_code, 'HTTP') === 0) {
            $retryable = true;
            if (stripos($fault_string, 'could not connect') !== false) {
                $code = 'HTTP_CONNECT';
            } elseif (stripos($fault_string, 'error fetching http') !== false) {
                // Read timeout or connection reset while waiting for the response.
                $code = 'HTTP_READ';
            } elseif (stripos($fault_string, 'failed sending') !== false) {
                $code = 'HTTP_SEND';
            } else {
                // Non-200 answer without SOAP body, e.g. "Service Unavailable", "Bad Gateway".
                $code = 'HTTP_ERROR';
            }
        } elseif (strcasecmp($fault_code, 'WSDL') === 0) {
            $code      = 'WSDL_ERROR';
            $retryable = true;
        } elseif (preg_match('/no XML document|DTD are not supported|Bad Response/i', $fault_string) === 1) {
            // e.g. an HTML maintenance page instead of a SOAP envelope.
            $code      = 'BAD_RESPONSE';
            $retryable = true;
        }

        return [
            'code'      => $code,
            'retryable' => $retryable,
            'fault'     => $fault_code,
            'message'   => $fault_string,
        ];
    }

    private function attempt(
        string $country_code,
        string $vat_number,
        int $connect_timeout,
        int $read_timeout,
        int $attempt,
    ): VAT_Result {
        $started = ($this->clock)();

        $previous_socket_timeout = ini_get('default_socket_timeout');
        $this->set_socket_timeout((string) $read_timeout);

        // SoapClient may raise PHP warnings next to the SoapFault (libxml, SSL). With
        // display_errors on they would corrupt the AJAX JSON; the fault itself is logged.
        set_error_handler(static function (): bool {
            return true;
        }, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED);

        try {
            $client   = ($this->soap_factory)(
                (string) $this->config['wsdl'],
                $this->soap_options($connect_timeout, $read_timeout),
            );
            $response = $client->checkVat([
                'countryCode' => $country_code,
                'vatNumber'   => $vat_number,
            ]);

            $result = $this->parse_response($country_code, $vat_number, $response);
        } catch (Throwable $e) {
            $classification = self::classify($e);
            $result         = VAT_Result::temporary_error(
                $country_code,
                $vat_number,
                $classification['code'],
                $classification['retryable'],
            );

            $this->log_failure($result, $classification['fault'], $classification['message'], $attempt, $started);
        } finally {
            restore_error_handler();
            if ($previous_socket_timeout !== false) {
                $this->set_socket_timeout((string) $previous_socket_timeout);
            }
        }

        if ($result->is_temporary_error() && $result->error_code() === 'BAD_RESPONSE') {
            $this->log_failure($result, '', 'Unexpected checkVat response structure', $attempt, $started);
        }

        return $result;
    }

    private function parse_response(string $country_code, string $vat_number, $response): VAT_Result
    {
        $valid = is_object($response) && property_exists($response, 'valid') ? $response->valid : null;

        if (is_string($valid)) {
            $valid = filter_var($valid, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        if ($valid === true) {
            return VAT_Result::valid(
                $country_code,
                $vat_number,
                isset($response->name) ? (string) $response->name : '',
                isset($response->address) ? (string) $response->address : '',
            );
        }

        if ($valid === false) {
            return VAT_Result::invalid($country_code, $vat_number);
        }

        return VAT_Result::temporary_error($country_code, $vat_number, 'BAD_RESPONSE', true);
    }

    private function soap_options(int $connect_timeout, int $read_timeout): array
    {
        $ssl = $this->config['verify_ssl']
            ? [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ]
            : [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ];

        $options = [
            'exceptions'         => true,
            'trace'              => false,
            'cache_wsdl'         => defined('WSDL_CACHE_BOTH') ? WSDL_CACHE_BOTH : 3,
            'soap_version'       => defined('SOAP_1_1') ? SOAP_1_1 : 1,
            'connection_timeout' => $connect_timeout,
            'keep_alive'         => false,
            'user_agent'         => (string) $this->config['user_agent'],
            'stream_context'     => stream_context_create([
                'ssl'  => $ssl,
                // Used when the WSDL itself has to be downloaded (cold cache).
                'http' => [
                    'timeout'    => (float) $read_timeout,
                    'user_agent' => (string) $this->config['user_agent'],
                ],
            ]),
        ];

        if (! empty($this->config['location'])) {
            $options['location'] = (string) $this->config['location'];
        }

        return function_exists('apply_filters')
            ? (array) apply_filters('sitesoft_euvat_soap_options', $options)
            : $options;
    }

    private function set_socket_timeout(string $seconds): void
    {
        if (function_exists('ini_set')) {
            ini_set('default_socket_timeout', $seconds);
        }
    }

    private function log_failure(VAT_Result $result, string $fault, string $message, int $attempt, float $started): void
    {
        if (! $this->logger) {
            return;
        }

        $context = [
            'country'     => $result->country_code(),
            'code'        => $result->error_code(),
            'fault'       => $fault,
            'attempt'     => $attempt,
            'retryable'   => $result->is_retryable(),
            'duration_ms' => (int) round((($this->clock)() - $started) * 1000),
            'message'     => $message,
        ];

        if ($result->error_code() === 'INTERNAL_ERROR') {
            $this->logger->error('vies_internal_error', $context);

            return;
        }

        $this->logger->warning('vies_temporary_error', $context);
    }
}
