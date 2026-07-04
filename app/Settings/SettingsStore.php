<?php

declare(strict_types=1);

namespace App\Settings;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Thin key/value store over the `lemma_settings` table — the runtime-mutable instance settings
 * (set at install by {@see \App\Setup\SetupService} and edited from Settings › General).
 *
 * Unlike `.env`, rows are shared across every app instance and apply on the next request with no
 * restart. Rows are loaded once per instance (the service is container-shared, so once per request)
 * and memoized; writes invalidate the cache.
 */
final class SettingsStore
{
    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function __construct(private readonly ApplicationContext $context)
    {
    }

    /** @return array<string,string> all rows, key => value */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $out = [];
        foreach (db($this->context)->table('lemma_settings')->get() as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key !== '') {
                $out[$key] = (string) ($row['value'] ?? '');
            }
        }

        return $this->cache = $out;
    }

    public function get(string $key): ?string
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Remove a stored override so the config/.env fallback shows through
     * (homepage-setting spec §0: clearing must DELETE the row — an empty
     * string row would shadow the fallback with '').
     */
    public function forget(string $key): void
    {
        db($this->context)->table('lemma_settings')->where(['key' => $key])->delete();
        $this->cache = null;
    }

    /**
     * Upsert each pair into `lemma_settings`.
     *
     * @param array<string,string> $pairs
     */
    public function putMany(array $pairs): void
    {
        if ($pairs === []) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        foreach ($pairs as $key => $value) {
            // `key` is the (non-integer) primary key, so upsert is check-then-write (mirrors SetupService).
            $existing = db($this->context)->table('lemma_settings')->where(['key' => $key])->first();
            if ($existing === null) {
                db($this->context)->table('lemma_settings')->insert([
                    'key' => $key,
                    'value' => $value,
                    'updated_at' => $now,
                ]);
            } else {
                db($this->context)->table('lemma_settings')->where(['key' => $key])->update([
                    'value' => $value,
                    'updated_at' => $now,
                ]);
            }
        }

        $this->cache = null;
    }
}
