<?php

namespace Sitesoft\GravityForms\VATChecker;

/**
 * Immutable outcome of a VAT number check.
 *
 * There are exactly three statuses:
 *
 * - VALID:           VIES answered valid=true.
 * - INVALID:         VIES answered valid=false, or the input can never be a
 *                    VAT number (fails the VIES input schema, so VIES is not called).
 * - TEMPORARY_ERROR: anything technical (SoapFault, timeout, connection failure,
 *                    unexpected response, ...). Never means "invalid".
 */
final class VAT_Result
{
    public const VALID           = 'valid';
    public const INVALID         = 'invalid';
    public const TEMPORARY_ERROR = 'temporary_error';

    public const SOURCE_VIES   = 'vies';
    public const SOURCE_CACHE  = 'cache';
    public const SOURCE_TOKEN  = 'token';
    public const SOURCE_FORMAT = 'format';

    private function __construct(
        private string $status,
        private string $country_code,
        private string $vat_number,
        private string $name = '',
        private string $address = '',
        private ?string $error_code = null,
        private bool $retryable = false,
        private string $source = self::SOURCE_VIES,
        private int $checked_at = 0,
    ) {}

    public static function valid(
        string $country_code,
        string $vat_number,
        string $name = '',
        string $address = '',
        string $source = self::SOURCE_VIES,
        ?int $checked_at = null,
    ): self {
        return new self(
            self::VALID,
            $country_code,
            $vat_number,
            self::clean_vies_text($name),
            self::clean_vies_text($address),
            null,
            false,
            $source,
            $checked_at ?? time(),
        );
    }

    public static function invalid(
        string $country_code,
        string $vat_number,
        string $source = self::SOURCE_VIES,
        ?string $error_code = null,
        ?int $checked_at = null,
    ): self {
        return new self(
            self::INVALID,
            $country_code,
            $vat_number,
            '',
            '',
            $error_code,
            false,
            $source,
            $checked_at ?? time(),
        );
    }

    public static function temporary_error(
        string $country_code,
        string $vat_number,
        string $error_code,
        bool $retryable = false,
        string $source = self::SOURCE_VIES,
        ?int $checked_at = null,
    ): self {
        return new self(
            self::TEMPORARY_ERROR,
            $country_code,
            $vat_number,
            '',
            '',
            $error_code,
            $retryable,
            $source,
            $checked_at ?? time(),
        );
    }

    public function status(): string
    {
        return $this->status;
    }

    public function is_valid(): bool
    {
        return $this->status === self::VALID;
    }

    public function is_invalid(): bool
    {
        return $this->status === self::INVALID;
    }

    public function is_temporary_error(): bool
    {
        return $this->status === self::TEMPORARY_ERROR;
    }

    public function country_code(): string
    {
        return $this->country_code;
    }

    public function vat_number(): string
    {
        return $this->vat_number;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function address(): string
    {
        return $this->address;
    }

    /**
     * Machine readable reason, e.g. MS_MAX_CONCURRENT_REQ, HTTP_CONNECT, FORMAT.
     * Internal only: never send this to the browser.
     */
    public function error_code(): ?string
    {
        return $this->error_code;
    }

    public function is_retryable(): bool
    {
        return $this->retryable;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function checked_at(): int
    {
        return $this->checked_at;
    }

    public function with_source(string $source): self
    {
        $clone         = clone $this;
        $clone->source = $source;

        return $clone;
    }

    /**
     * Plain array for transient/object cache storage (no objects in the cache).
     */
    public function to_array(): array
    {
        return [
            'status'       => $this->status,
            'country_code' => $this->country_code,
            'vat_number'   => $this->vat_number,
            'name'         => $this->name,
            'address'      => $this->address,
            'error_code'   => $this->error_code,
            'retryable'    => $this->retryable,
            'checked_at'   => $this->checked_at,
        ];
    }

    public static function from_array($data, string $source = self::SOURCE_CACHE): ?self
    {
        if (! is_array($data) || ! isset($data['status'], $data['country_code'], $data['vat_number'])) {
            return null;
        }

        if (! in_array($data['status'], [ self::VALID, self::INVALID, self::TEMPORARY_ERROR ], true)) {
            return null;
        }

        return new self(
            (string) $data['status'],
            (string) $data['country_code'],
            (string) $data['vat_number'],
            (string) ($data['name'] ?? ''),
            (string) ($data['address'] ?? ''),
            isset($data['error_code']) ? (string) $data['error_code'] : null,
            (bool) ($data['retryable'] ?? false),
            $source,
            (int) ($data['checked_at'] ?? 0),
        );
    }

    /**
     * VIES returns "---" when a member state does not share name/address (e.g. DE).
     */
    private static function clean_vies_text(string $value): string
    {
        $value = trim($value);

        return $value === '---' ? '' : $value;
    }
}
