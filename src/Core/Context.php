<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Core;

final class Context
{
    /**
     * @var string
     */
    private string $ip;
    /**
     * @var string
     */
    private string $method;
    /**
     * @var string|mixed
     */
    private string $uri;

    /**
     * @var array
     */
    private array $headers = [];
    /**
     * @var array
     */
    private array $query = [];
    /**
     * @var array
     */
    private array $body = [];
    /**
     * @var array
     */
    private array $cookies = [];

    /**
     * @var array
     */
    private array $attributes = [];

    /**
     * @var array|null Cached flattened payload
     */
    private ?array $cachedFlattenedPayload = null;

    /**
     * @var array|null Cached payload
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
     * @return string
     */
    public function getIp(): string
    {
        return $this->ip;
    }

    /**
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * @return string
     */
    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
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
     * @return array
     */
    public function getQuery(): array
    {
        return $this->query;
    }

    /**
     * @return array
     */
    public function getBody(): array
    {
        return $this->body;
    }

    /**
     * @return array
     */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /**
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
     * @return array
     */
    public function getFlattenedPayload(): array
    {
        if ($this->cachedFlattenedPayload !== null) {
            return $this->cachedFlattenedPayload;
        }

        $result = [];

        $iterator = function ($data) use (&$result, &$iterator) {
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

        // priority: CF / X-Real-IP / XFF (first)
        $cf = $headers['cf-connecting-ip'] ?? ($server['HTTP_CF_CONNECTING_IP'] ?? null);
        if (is_string($cf) && $cf !== '') {
            return trim(explode(',', $cf)[0]);
        }

        $xri = $headers['x-real-ip'] ?? ($server['HTTP_X_REAL_IP'] ?? null);
        if (is_string($xri) && $xri !== '') {
            return trim(explode(',', $xri)[0]);
        }

        $xff = $headers['x-forwarded-for'] ?? ($server['HTTP_X_FORWARDED_FOR'] ?? null);
        if (is_string($xff) && $xff !== '') {
            return trim(explode(',', $xff)[0]);
        }

        return $remoteAddr !== '' ? $remoteAddr : '0.0.0.0';
    }

    /**
     * @param array $server
     *
     * @return array
     */
    private static function extractHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($name)] = (string)$value;
            }
        }

        return $headers;
    }
}