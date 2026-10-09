<?php

namespace Sitesoft\GravityForms\VATChecker;

/**
 * Small storage wrapper used for result caching, locks and log throttling.
 *
 * - With a persistent object cache (Redis/Memcached) it uses wp_cache_* directly,
 *   so add() is atomic and get() can bypass the in-request runtime cache.
 * - Without one it falls back to transients (options table). add() is then a
 *   best-effort check-and-set, which is good enough to dampen request storms.
 */
class VAT_Cache
{
    public const GROUP = 'sitesoft_euvat';

    /**
     * @return mixed false when not found
     */
    public function get(string $key, bool $fresh = false)
    {
        if ($this->uses_object_cache()) {
            return wp_cache_get($key, self::GROUP, $fresh);
        }

        if ($fresh) {
            $this->flush_runtime_transient_cache($key);
        }

        return get_transient($key);
    }

    public function set(string $key, $value, int $ttl): bool
    {
        if ($ttl <= 0) {
            return false;
        }

        if ($this->uses_object_cache()) {
            return (bool) wp_cache_set($key, $value, self::GROUP, $ttl);
        }

        return (bool) set_transient($key, $value, $ttl);
    }

    /**
     * Store only when the key does not exist yet.
     */
    public function add(string $key, $value, int $ttl): bool
    {
        if ($this->uses_object_cache()) {
            return (bool) wp_cache_add($key, $value, self::GROUP, $ttl);
        }

        if ($this->get($key, true) !== false) {
            return false;
        }

        return $this->set($key, $value, $ttl);
    }

    public function delete(string $key): void
    {
        if ($this->uses_object_cache()) {
            wp_cache_delete($key, self::GROUP);

            return;
        }

        delete_transient($key);
    }

    protected function uses_object_cache(): bool
    {
        return function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
    }

    /**
     * get_option() caches values and misses for the duration of the request.
     * When polling for a result written by another PHP process we need the DB value.
     */
    private function flush_runtime_transient_cache(string $key): void
    {
        $options = [ '_transient_' . $key, '_transient_timeout_' . $key ];

        foreach ($options as $option) {
            wp_cache_delete($option, 'options');
        }

        $notoptions = wp_cache_get('notoptions', 'options');
        if (is_array($notoptions)) {
            foreach ($options as $option) {
                unset($notoptions[ $option ]);
            }
            wp_cache_set('notoptions', $notoptions, 'options');
        }
    }
}
