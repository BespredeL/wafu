<?php

declare(strict_types=1);

namespace Bespredel\Wafu\RemoteRules;

use Bespredel\Wafu\Core\Net;

final class HttpClient
{

    /**
     * Get HTTP response.
     *
     * @param string $url
     * @param array  $headers
     *
     * @return array
     */
    public function get(string $url, array $headers = []): array
    {
        // Validate URL and check for SSRF protection
        if (!Net::isSafeExternalUrl($url)) {
            return ['status' => 0, 'headers' => [], 'body' => ''];
        }

        // Use ext-curl if available, otherwise use stream
        if (function_exists('curl_init')) {
            return $this->curlGet($url, $headers);
        }

        return $this->streamGet($url, $headers);
    }

    /**
     * Get HTTP response using cURL.
     *
     * @param string $url
     * @param array  $headers
     *
     * @return array
     */
    private function curlGet(string $url, array $headers): array
    {
        $ch = curl_init($url);

        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_MAXREDIRS       => 0,
            CURLOPT_TIMEOUT         => 5,
            CURLOPT_CONNECTTIMEOUT  => 3,
            CURLOPT_HTTPHEADER      => $h,
            CURLOPT_HEADER          => true,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS, // Only HTTP/HTTPS
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, // Only HTTP/HTTPS for redirects (if enabled)
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_USERAGENT       => 'WAFU-HttpClient/1.0 (+https://github.com/BespredeL/wafu)',
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            return ['status' => 0, 'headers' => [], 'body' => ''];
        }

        $headerSize = (int)($info['header_size'] ?? 0);
        $headerRaw = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        return [
            'status'  => (int)($info['http_code'] ?? 0),
            'headers' => $this->parseHeaders($headerRaw),
            'body'    => (string)$body,
        ];
    }

    /**
     * Get HTTP response using stream.
     *
     * @param string $url
     * @param array  $headers
     *
     * @return array
     */
    private function streamGet(string $url, array $headers): array
    {
        // Build header lines
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }

        // Ensure explicit User-Agent if not provided
        $hasUa = false;
        foreach ($headers as $k => $v) {
            if (strtolower($k) === 'user-agent') {
                $hasUa = true;
                break;
            }
        }

        if (!$hasUa) {
            $headerLines[] = 'User-Agent: WAFU-HttpClient/1.0 (+https://github.com/BespredeL/wafu)';
        }

        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'header'          => implode("\r\n", $headerLines),
                'timeout'         => 5,
                'ignore_errors'   => true,
                'follow_location' => 0,
                'max_redirects'   => 0,
            ],
            'ssl'  => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
            ],
        ]);

        $body = @file_get_contents($url, false, $ctx);
        $body = $body === false ? '' : $body;

        $status = 0;
        $respHeaders = [];
        if (isset($http_response_header) && is_array($http_response_header)) {
            $respHeaders = $this->parseHeaders(implode("\r\n", $http_response_header));
            // first line: HTTP/1.1 200 OK
            $first = $http_response_header[0] ?? '';
            if (preg_match('/\s(\d{3})\s/', $first, $m)) {
                $status = (int)$m[1];
            }
        }

        return [
            'status'  => $status,
            'headers' => $respHeaders,
            'body'    => (string)$body,
        ];
    }

    /**
     * Parse HTTP headers.
     *
     * @param string $raw
     *
     * @return array
     */
    private function parseHeaders(string $raw): array
    {
        $headers = [];
        $lines = preg_split('/\r\n|\n|\r/', $raw) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$k, $v] = explode(':', $line, 2);
            $k = strtolower(trim($k));
            $v = trim($v);

            if ($k === '') {
                continue;
            }

            // Handle duplicate headers according to RFC 7230
            // Multiple values should be combined with comma
            if (isset($headers[$k])) {
                $headers[$k] .= ', ' . $v;
            } else {
                $headers[$k] = $v;
            }
        }

        return $headers;
    }
}