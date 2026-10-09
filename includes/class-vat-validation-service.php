<?php

namespace Sitesoft\GravityForms\VATChecker;

use Throwable;

/**
 * Single entry point for VAT validation, used by BOTH the AJAX check and the
 * Gravity Forms submit validation, so they always agree.
 *
 * Order of resolution:
 *  1. Normalise input; input that can never be a VAT number => INVALID (no VIES call).
 *  2. Cached VALID/INVALID result for this country + number.
 *  3. (submission) Signed token proving an earlier VALID AJAX check of this number.
 *  4. (ajax) Very short cached TEMPORARY_ERROR, to stop request storms during an outage.
 *  5. Short per-number lock: a concurrent identical check waits for that result
 *     instead of firing a second VIES call (e.g. AJAX still running while submitting).
 *  6. Live VIES call (with timeouts + one retry), result cached.
 */
class VAT_Validation_Service
{
    public const CONTEXT_AJAX       = 'ajax';
    public const CONTEXT_SUBMISSION = 'submission';

    public const TTL_VALID     = 6 * HOUR_IN_SECONDS;
    public const TTL_INVALID   = 15 * MINUTE_IN_SECONDS;
    public const TTL_TEMPORARY = 10;

    /** Lock expiry: longer than the slowest possible check (2 attempts within the total budget). */
    public const LOCK_TTL = 20;

    /** How long a concurrent identical check waits for the running one. */
    public const LOCK_WAIT_SECONDS = 10;

    public const LOCK_POLL_MS = 250;

    private const CACHE_PREFIX = 'sseuvat_r_';
    private const LOCK_PREFIX  = 'sseuvat_l_';

    private static ?self $instance = null;

    /** @var callable(int): void */
    private $sleeper;

    /** @var callable(): float */
    private $clock;

    public function __construct(
        private VIES_Client $client,
        private VAT_Cache $cache,
        private ?VAT_Logger $logger = null,
        ?callable $sleeper = null,
        ?callable $clock = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
        $this->clock   = $clock ?? static function (): float {
            return microtime(true);
        };
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            $cache  = new VAT_Cache();
            $logger = new VAT_Logger($cache);

            $config = [
                'verify_ssl' => ! in_array(wp_get_environment_type(), [ 'local', 'development' ], true),
            ];

            self::$instance = new self(
                new VIES_Client((array) apply_filters('sitesoft_euvat_vies_client_config', $config), null, $logger),
                $cache,
                $logger,
            );
        }

        return self::$instance;
    }

    public function logger(): ?VAT_Logger
    {
        return $this->logger;
    }

    /**
     * Replace the shared instance (tests / custom wiring).
     */
    public static function set_instance(?self $instance): void
    {
        self::$instance = $instance;
    }

    public function validate(
        string $country_code,
        string $vat_number,
        string $context = self::CONTEXT_AJAX,
        string $token = '',
    ): VAT_Result {
        $number = VAT_Number::from_input($country_code, $vat_number);

        try {
            return $this->resolve($number, $context, $token);
        } catch (Throwable $e) {
            // Whatever goes wrong in our own code: never "invalid", never a fatal on submit.
            if ($this->logger) {
                $this->logger->error('validation_internal_error', [
                    'country' => $number->country_code(),
                    'code'    => 'INTERNAL_ERROR',
                    'fault'   => get_class($e),
                    'context' => $context,
                    'message' => $e->getMessage(),
                ]);
            }

            return VAT_Result::temporary_error($number->country_code(), $number->number(), 'INTERNAL_ERROR');
        }
    }

    /**
     * Stateless proof that this server received VALID from VIES for this number.
     * Format: "<unix timestamp>.<hmac>"; the VAT number itself is not in the token.
     */
    public function create_token(VAT_Result $result): string
    {
        if (! $result->is_valid()) {
            return '';
        }

        $issued_at = time();

        return $issued_at . '.' . $this->sign_token($result->country_code(), $result->vat_number(), $issued_at);
    }

    public function verify_token(string $token, VAT_Number $number): bool
    {
        if (! preg_match('/^(\d{9,11})\.([a-f0-9]{64})$/', $token, $matches)) {
            return false;
        }

        $issued_at = (int) $matches[1];
        $age       = time() - $issued_at;

        if ($age < -60 || $age > $this->ttl(VAT_Result::VALID)) {
            return false;
        }

        $expected = $this->sign_token($number->country_code(), $number->number(), $issued_at);

        return hash_equals($expected, $matches[2]);
    }

    private function resolve(VAT_Number $number, string $context, string $token): VAT_Result
    {
        if (! $number->is_well_formed()) {
            return VAT_Result::invalid(
                $number->country_code(),
                $number->number(),
                VAT_Result::SOURCE_FORMAT,
                $number->is_supported_country() ? 'FORMAT' : 'UNSUPPORTED_COUNTRY',
            );
        }

        $cached = $this->get_cached($number);
        if ($cached && ! $cached->is_temporary_error()) {
            return $cached;
        }

        if ($context === self::CONTEXT_SUBMISSION && $token !== '' && $this->verify_token($token, $number)) {
            return VAT_Result::valid(
                $number->country_code(),
                $number->number(),
                '',
                '',
                VAT_Result::SOURCE_TOKEN,
            );
        }

        // A submission always gets its own live attempt; only AJAX reuses a recent temporary error.
        if ($cached && $context === self::CONTEXT_AJAX) {
            return $cached;
        }

        $lock_key = self::LOCK_PREFIX . $this->hash($number);

        if (! $this->cache->add($lock_key, 1, self::LOCK_TTL)) {
            $waited = $this->wait_for_concurrent_check($number, $lock_key, $context);
            if ($waited) {
                return $waited;
            }

            // Lock holder died or took too long: do our own check (without the lock).
            return $this->check_and_store($number);
        }

        try {
            return $this->check_and_store($number);
        } finally {
            $this->cache->delete($lock_key);
        }
    }

    private function check_and_store(VAT_Number $number): VAT_Result
    {
        $result = $this->client->check($number->country_code(), $number->number());

        $ttl = $this->ttl($result->status());
        if ($ttl > 0) {
            $this->cache->set(self::CACHE_PREFIX . $this->hash($number), $result->to_array(), $ttl);
        }

        return $result;
    }

    private function wait_for_concurrent_check(VAT_Number $number, string $lock_key, string $context): ?VAT_Result
    {
        $deadline = ($this->clock)() + self::LOCK_WAIT_SECONDS;

        while (($this->clock)() < $deadline) {
            ($this->sleeper)(self::LOCK_POLL_MS);

            $cached = $this->get_cached($number, true);
            if ($cached && ! $cached->is_temporary_error()) {
                return $cached;
            }

            if ($this->cache->get($lock_key, true) === false) {
                // The other check finished without a VALID/INVALID answer.
                if ($cached && $context === self::CONTEXT_AJAX) {
                    return $cached;
                }

                return null;
            }
        }

        return null;
    }

    private function get_cached(VAT_Number $number, bool $fresh = false): ?VAT_Result
    {
        $result = VAT_Result::from_array($this->cache->get(self::CACHE_PREFIX . $this->hash($number), $fresh));

        if (! $result) {
            return null;
        }

        // Guard against hash collisions or stale formats.
        if ($result->country_code() !== $number->country_code() || $result->vat_number() !== $number->number()) {
            return null;
        }

        return $result;
    }

    private function ttl(string $status): int
    {
        switch ($status) {
            case VAT_Result::VALID:
                return (int) apply_filters('sitesoft_euvat_cache_ttl_valid', self::TTL_VALID);
            case VAT_Result::INVALID:
                return (int) apply_filters('sitesoft_euvat_cache_ttl_invalid', self::TTL_INVALID);
            default:
                return (int) apply_filters('sitesoft_euvat_cache_ttl_temporary', self::TTL_TEMPORARY);
        }
    }

    /**
     * Keyed hash so cache keys and tokens never contain a readable or
     * brute-forceable VAT number.
     */
    private function hash(VAT_Number $number): string
    {
        return substr(hash_hmac('sha256', 'euvat-cache|' . $number->key(), $this->secret()), 0, 40);
    }

    private function sign_token(string $country_code, string $vat_number, int $issued_at): string
    {
        return hash_hmac('sha256', 'euvat-token|' . $country_code . '|' . $vat_number . '|' . $issued_at, $this->secret());
    }

    private function secret(): string
    {
        return wp_salt('auth');
    }
}
