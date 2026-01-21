<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Core;

final class Net
{

    /**
     * Check: IP is included in CIDR (IPv4/IPv6).
     *
     * @param string $ip
     * @param string $cidr
     *
     * @return bool
     */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        if ($ip === '' || $cidr === '' || !str_contains($cidr, '/')) {
            return false;
        }

        [$subnet, $maskStr] = array_pad(explode('/', $cidr, 2), 2, '');
        $mask = (int)$maskStr;

        $ipBin = @inet_pton($ip);
        $subBin = @inet_pton($subnet);

        if ($ipBin === false || $subBin === false) {
            return false;
        }

        $len = strlen($ipBin);
        if (strlen($subBin) !== $len) {
            return false; // v4 vs v6 mismatch
        }

        $maxMask = $len * 8;
        if ($mask < 0 || $mask > $maxMask) {
            return false;
        }

        // /0 matches everything
        if ($mask === 0) {
            return true;
        }

        $bytes = intdiv($mask, 8);
        $bits = $mask % 8;

        // compare full bytes
        if ($bytes > 0) {
            if (substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) {
                return false;
            }
        }

        // compare remaining bits
        if ($bits > 0) {
            $ipByte = ord($ipBin[$bytes]);
            $subByte = ord($subBin[$bytes]);
            $maskByte = (0xFF << (8 - $bits)) & 0xFF;

            return (($ipByte & $maskByte) === ($subByte & $maskByte));
        }

        return true;
    }


    /**
     * Check IP against a list of rules: IP or CIDR.
     *
     * @param string $ip
     * @param array  $rules
     *
     * @return bool
     */
    public static function ipMatchesAny(string $ip, array $rules): bool
    {
        foreach ($rules as $rule) {
            if (!is_string($rule) || $rule === '') {
                continue;
            }

            if (str_contains($rule, '/')) {
                if (self::ipInCidr($ip, $rule)) {
                    return true;
                }
            } elseif ($ip === $rule) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate IP address format (IPv4 or IPv6).
     *
     * @param string $ip
     *
     * @return bool
     */
    public static function isValidIp(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6) !== false;
    }

    /**
     * Check if IP address is internal/private (localhost, private ranges, reserved).
     *
     * @param string $ip
     *
     * @return bool
     */
    public static function isInternalIp(string $ip): bool
    {
        if ($ip === '') {
            return true;
        }

        // Validate IP format first
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        // Check for localhost IP variants
        $localhostIps = ['127.0.0.1', '::1', '0.0.0.0'];
        if (in_array(strtolower($ip), $localhostIps, true)) {
            return true;
        }

        // Check for private/reserved IP ranges
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * Check if hostname resolves to internal IP address.
     *
     * @param string $hostname
     *
     * @return bool
     */
    public static function isInternalHost(string $hostname): bool
    {
        if ($hostname === '') {
            return true;
        }

        $hostname = strtolower(trim($hostname));

        // Check for localhost variants
        $localhostPatterns = ['localhost', '127.0.0.1', '::1', '0.0.0.0', 'localhost.localdomain'];
        if (in_array($hostname, $localhostPatterns, true)) {
            return true;
        }

        // Check if hostname is an IP address
        if (filter_var($hostname, FILTER_VALIDATE_IP) !== false) {
            return self::isInternalIp($hostname);
        }

        // Resolve hostname to IP
        $ip = @gethostbyname($hostname);
        if ($ip === $hostname || $ip === '') {
            // Failed to resolve - consider as internal for safety
            return true;
        }

        return self::isInternalIp($ip);
    }

    /**
     * Validate URL and check if it's safe for external requests (not internal).
     *
     * @param string $url
     *
     * @return bool
     */
    public static function isSafeExternalUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        // Validate URL format
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'])) {
            return false;
        }

        // Only allow HTTP and HTTPS
        $scheme = strtolower($parsed['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        // Check host
        $host = $parsed['host'] ?? '';
        if ($host === '') {
            return false;
        }

        // Check if host is internal
        if (self::isInternalHost($host)) {
            return false;
        }

        return true;
    }
}