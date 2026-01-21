<?php

declare(strict_types=1);

namespace Bespredel\Wafu\RemoteRules;

use RuntimeException;

final class RulesManager
{
    /**
     * @param HttpClient $httpClient
     * @param FileCache  $cache
     * @param array      $settings
     */
    public function __construct(
        private HttpClient $httpClient,
        private FileCache  $cache,
        private array      $settings
    )
    {
    }

    /**
     * Returns the remote config (array) or null if the remote is disabled/not accessible.
     *
     * @throws \SodiumException
     */
    public function fetchConfig(): ?array
    {
        if (!($this->settings['enabled'] ?? false)) {
            return null;
        }

        $endpoint = (string)($this->settings['endpoint'] ?? '');
        if ($endpoint === '') {
            return null;
        }

        $now = time();

        // 1) reading the cache
        $cached = $this->cache->read();
        $etag = is_array($cached) ? (string)($cached['_etag'] ?? '') : '';
        $fetchedAt = is_array($cached) ? (int)($cached['_fetched_at'] ?? 0) : 0;
        $ttl = is_array($cached) ? (int)($cached['_ttl'] ?? 0) : 0;

        $maxTtl = (int)($this->settings['max_ttl'] ?? 0);
        if ($maxTtl > 0 && $ttl > $maxTtl) {
            $ttl = $maxTtl;
        }

        if (is_array($cached) && $this->cache->isFresh($now, $fetchedAt, $ttl)) {
            $config = $cached['ruleset']['config'] ?? null;
            return is_array($config) ? $config : null;
        }

        // 2) server request (ETag)
        $headers = (array)($this->settings['headers'] ?? []);
        if ($etag !== '') {
            $headers['If-None-Match'] = $etag;
        }

        $resp = $this->httpClient->get($endpoint, $headers);
        $status = (int)($resp['status'] ?? 0);

        // 304 => using cache
        if ($status === 304 && is_array($cached)) {
            $config = $cached['ruleset']['config'] ?? null;
            return is_array($config) ? $config : null;
        }

        // failure => fallback
        if ($status < 200 || $status >= 300) {
            if (($this->settings['use_cache_on_error'] ?? true) && is_array($cached)) {
                $config = $cached['ruleset']['config'] ?? null;
                return is_array($config) ? $config : null;
            }

            return null;
        }

        $body = (string)($resp['body'] ?? '');

        $maxJsonSize = (int)($this->settings['max_json_size'] ?? 10485760); // 10MB default
        if (strlen($body) > $maxJsonSize) {
            if (($this->settings['use_cache_on_error'] ?? true) && is_array($cached)) {
                $config = $cached['ruleset']['config'] ?? null;
                return is_array($config) ? $config : null;
            }
            return null;
        }

        $ruleset = json_decode($body, true, 32); // Max depth 32
        if (!is_array($ruleset)) {
            if (($this->settings['use_cache_on_error'] ?? true) && is_array($cached)) {
                $config = $cached['ruleset']['config'] ?? null;
                return is_array($config) ? $config : null;
            }

            return null;
        }

        // 3) validation ruleset
        $this->validateRuleset($ruleset);

        // 4) signature (optional)
        $this->verifySignatureIfEnabled($ruleset);

        // 5) calculating TTL
        $ttl = (int)($ruleset['ttl'] ?? 300);
        if ($ttl < 0) {
            $ttl = 0;
        }
        if ($maxTtl > 0 && $ttl > $maxTtl) {
            $ttl = $maxTtl;
        }

        // 6) save the cache
        $respHeaders = (array)($resp['headers'] ?? []);
        $newEtag = (string)($respHeaders['etag'] ?? '');

        $this->cache->write([
            '_etag'       => $newEtag,
            '_fetched_at' => $now,
            '_ttl'        => $ttl,
            'ruleset'     => $ruleset,
        ]);

        $config = $ruleset['config'] ?? null;

        return is_array($config) ? $config : null;
    }

    /**
     * Validate ruleset.
     *
     * @param array $ruleset
     *
     * @return void
     */
    private function validateRuleset(array $ruleset): void
    {
        if (!isset($ruleset['config']) || !is_array($ruleset['config'])) {
            throw new RuntimeException('Invalid ruleset: missing config');
        }
    }

    /**
     * Verify signature if enabled.
     *
     * @param array $ruleset
     *
     * @return void
     *
     * @throws \SodiumException
     */
    private function verifySignatureIfEnabled(array $ruleset): void
    {
        $sigCfg = (array)($this->settings['signature'] ?? []);
        if (!($sigCfg['enabled'] ?? false)) {
            return;
        }

        $algo = (string)($sigCfg['algo'] ?? 'ed25519');
        $field = (string)($sigCfg['field'] ?? 'signature');
        $pubB64 = (string)($sigCfg['public_key_base64'] ?? '');

        if ($algo !== 'ed25519') {
            throw new RuntimeException('Signature algo not supported: ' . $algo);
        }

        if ($pubB64 === '') {
            throw new RuntimeException('Signature public key missing');
        }

        if (!isset($ruleset[$field]) || !is_string($ruleset[$field]) || $ruleset[$field] === '') {
            throw new RuntimeException('Ruleset signature missing');
        }

        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new RuntimeException('ext-sodium required for signature verification');
        }

        $sig = base64_decode($ruleset[$field], true);
        $pub = base64_decode($pubB64, true);

        if ($sig === false || $pub === false) {
            throw new RuntimeException('Invalid base64 in signature or public key');
        }

        // Important point: sign the ruleset without the signature field
        $tmp = $ruleset;
        unset($tmp[$field]);

        $payload = json_encode($tmp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new RuntimeException('Failed to serialize ruleset for signature verification');
        }

        $ok = sodium_crypto_sign_verify_detached($sig, $payload, $pub);
        if (!$ok) {
            throw new RuntimeException('Ruleset signature verification failed');
        }
    }
}