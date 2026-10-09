<?php

namespace Sitesoft\GravityForms\VATChecker;

/**
 * Server-side diagnostics for VIES problems.
 *
 * - One line per event, key=value, prefixed with [sitesoft-euvat].
 * - Only whitelisted context keys; never names, addresses, form data or VAT numbers.
 * - Identical events (level + event + country + code) are logged at most once
 *   per THROTTLE_SECONDS; the next line reports how many were suppressed.
 */
class VAT_Logger
{
    public const THROTTLE_SECONDS = 60;

    private const ALLOWED_CONTEXT = [
        'country',
        'code',
        'fault',
        'attempt',
        'retryable',
        'duration_ms',
        'context',
        'source',
        'message',
    ];

    /** @var callable */
    private $writer;

    /** @var array<string, int> */
    private array $logged_in_request = [];

    public function __construct(
        private VAT_Cache $cache,
        ?callable $writer = null,
    ) {
        $this->writer = $writer ?? static function (string $line): void {
            error_log($line);
        };
    }

    public function warning(string $event, array $context = []): void
    {
        $this->log('warning', $event, $context);
    }

    public function error(string $event, array $context = []): void
    {
        $this->log('error', $event, $context);
    }

    private function log(string $level, string $event, array $context): void
    {
        $signature = md5($level . '|' . $event . '|' . ($context['country'] ?? '') . '|' . ($context['code'] ?? ''));
        $throttle  = 'sseuvat_log_' . $signature;
        $counter   = 'sseuvat_logc_' . $signature;

        if (isset($this->logged_in_request[ $signature ]) || ! $this->cache->add($throttle, 1, self::THROTTLE_SECONDS)) {
            $this->logged_in_request[ $signature ] = 1;
            $suppressed                           = (int) $this->cache->get($counter);
            $this->cache->set($counter, $suppressed + 1, HOUR_IN_SECONDS);

            return;
        }
        $this->logged_in_request[ $signature ] = 1;

        $suppressed = (int) $this->cache->get($counter);
        if ($suppressed > 0) {
            $this->cache->delete($counter);
            $context['suppressed'] = $suppressed;
        }

        $parts = [
            '[sitesoft-euvat]',
            gmdate('Y-m-d\TH:i:s\Z'),
            'level=' . $level,
            'event=' . self::sanitize($event),
        ];

        foreach ($context as $key => $value) {
            if (! in_array($key, self::ALLOWED_CONTEXT, true) && $key !== 'suppressed') {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            }
            $value   = self::sanitize((string) $value);
            $parts[] = $key . '=' . (str_contains($value, ' ') ? '"' . $value . '"' : $value);
        }

        ($this->writer)(implode(' ', $parts));

        do_action('sitesoft_euvat_logged', $level, $event, $context);
    }

    /**
     * Single line, no quotes, max 200 chars, digit runs of 6+ masked
     * (so a VAT number can never end up in the log through an error message).
     */
    public static function sanitize(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        $value = preg_replace('/\d{6,}/', '######', $value) ?? '';
        $value = str_replace('"', "'", trim($value));

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 200);
        }

        return substr($value, 0, 200);
    }
}
