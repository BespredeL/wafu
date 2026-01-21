<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Core;

final class Context
{
    /**
     * Client IP address.
     *
     * @var string
     */
    private string $ip;

    /**
     * HTTP method.
     *
     * @var string
     */
    private string $method;

    /**
     * Requested URI.
     *
     * @var string|mixed
     */
    private string $uri;

    /**
     * HTTP headers.
     *
     * @var array
     */
    private array $headers = [];

    /**
     * Query parameters.
     *
     * @var array
     */
    private array $query = [];

    /**
     * Request body.
     *
     * @var array
     */
    private array $body = [];

    /**
     * Cookies.
     *
     * @var array
     */
    private array $cookies = [];

    /**
     * Attributes.
     *
     * @var array
     */
    private array $attributes = [];

    /**
     * Cached flattened payload
     *
     * @var array|null
     */
    private ?array $cachedFlattenedPayload = null;

    /**
     * Cached payload
     *
     * @var array|null
     */
    private ?array $cachedPayload = null;

    /**
     * @param array $server                $_SERVER or adapted array
     * @param array $query                 $_GET
     * @param array $body                  $_POST / parsed body
     * @param array $cookies               $_COOKIE
     * @param array $headers               HTTP headers
     * @param array $trustedProxies        CIDR/IP list of trusted proxies
     * @param bool  $trustForwardedHeaders whether to trust XFF/X-Real-IP (only if REMOTE_ADDR is in trustedProxies)
     */
    public function __construct(
        array $server,
        array $query = [],
        array $body = [],
        array $cookies = [],
        array $headers = [],
        array $trustedProxies = [],
        bool  $trustForwardedHeaders = false
    )
    {
        $this->method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        $this->uri = $server['REQUEST_URI'] ?? '/';

        $this->query = $query;
        $this->body = $body;
        $this->cookies = $cookies;

        $this->headers = $headers ?: self::extractHeaders($server);

        $this->ip = self::resolveIp(
            $server,
            $this->headers,
            $trustedProxies,
            $trustForwardedHeaders
        );
    }

    /**
     * Get client IP address.
     *
     * @return string
     */
    public function getIp(): string
    {
        return $this->ip;
    }

    /**
     * Get HTTP method.
     *
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Get requested URI.
     *
     * @return string
     */
    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * Get HTTP headers.
     *
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Get HTTP header value.
     *
     * @param string $name
     *
     * @return string|null
     */
    public function getHeader(string $name): ?string
    {
        $key = strtolower($name);
        return $this->headers[$key] ?? null;
    }

    /**
     * Get query parameters.
     *
     * @return array
     */
    public function getQuery(): array
    {
        return $this->query;
    }

    /**
     * Get request body.
     *
     * @return array
     */
    public function getBody(): array
    {
        return $this->body;
    }

    /**
     * Get cookies.
     *
     * @return array
     */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /**
     * Get merged query, body and cookies.
     *
     * @return array
     */
    public function getPayload(): array
    {
        if ($this->cachedPayload !== null) {
            return $this->cachedPayload;
        }

        return $this->cachedPayload = array_merge_recursive($this->query, $this->body, $this->cookies);
    }

    /**
     * Get flattened payload.
     *
     * @return array
     */
    public function getFlattenedPayload(): array
    {
        if ($this->cachedFlattenedPayload !== null) {
            return $this->cachedFlattenedPayload;
        }

        $result = [];

        $iterator = static function ($data) use (&$result, &$iterator) {
            if (!is_array($data)) {
                $result[] = (string)$data;
                return;
            }

            foreach ($data as $value) {
                if (is_array($value)) {
                    $iterator($value);
                } else {
                    $result[] = (string)$value;
                }
            }
        };

        $iterator($this->getPayload());

        return $this->cachedFlattenedPayload = $result;
    }

    /**
     * Set attribute.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return void
     */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Get attribute.
     *
     * @param string     $key
     * @param mixed|null $default
     *
     * @return mixed
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Resolve client IP address.
     *
     * @param array $server
     * @param array $headers
     * @param array $trustedProxies
     * @param bool  $trustForwardedHeaders
     *
     * @return string
     */
    private static function resolveIp(
        array $server,
        array $headers,
        array $trustedProxies,
        bool  $trustForwardedHeaders
    ): string
    {
        $remoteAddr = (string)($server['REMOTE_ADDR'] ?? '');

        // if not trusting forwarded headers or proxy not trusted => REMOTE_ADDR
        $proxyTrusted = ($remoteAddr !== '' && $trustedProxies !== [])
            ? Net::ipMatchesAny($remoteAddr, $trustedProxies)
            : false;

        if (!$trustForwardedHeaders || !$proxyTrusted) {
            return $remoteAddr !== '' ? $remoteAddr : '0.0.0.0';
        }

        // Normalize header keys to lowercase for consistent lookup
        $normalizedHeaders = [];
        foreach ($headers as $key => $value) {
            $normalizedHeaders[strtolower($key)] = $value;
        }

        // priority: CF / X-Real-IP / XFF (first)
        $cf = $normalizedHeaders['cf-connecting-ip'] ?? ($server['HTTP_CF_CONNECTING_IP'] ?? null);
        if (is_string($cf) && $cf !== '') {
            $ip = trim(explode(',', $cf)[0]);
            if (Net::isValidIp($ip)) {
                return $ip;
            }
        }

        $xri = $normalizedHeaders['x-real-ip'] ?? ($server['HTTP_X_REAL_IP'] ?? null);
        if (is_string($xri) && $xri !== '') {
            $ip = trim(explode(',', $xri)[0]);
            if (Net::isValidIp($ip)) {
                return $ip;
            }
        }

        $xff = $normalizedHeaders['x-forwarded-for'] ?? ($server['HTTP_X_FORWARDED_FOR'] ?? null);
        if (is_string($xff) && $xff !== '') {
            $ip = trim(explode(',', $xff)[0]);
            if (Net::isValidIp($ip)) {
                return $ip;
            }
        }

        return $remoteAddr !== '' ? $remoteAddr : '0.0.0.0';
    }

    /**
     * Extract HTTP headers from $_SERVER.
     *
     * @param array $server
     *
     * @return array
     */
    private static function extractHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $name = str_replace('_', '-', substr($key, 5));
            $normalizedKey = strtolower($name);

            // Handle duplicate headers by keeping the last value
            // Some servers may send multiple values for the same header
            if (isset($headers[$normalizedKey])) {
                // If header already exists, append with comma (RFC 7230)
                $headers[$normalizedKey] .= ', ' . (string)$value;
            } else {
                $headers[$normalizedKey] = (string)$value;
            }
        }

        return $headers;
    }
}