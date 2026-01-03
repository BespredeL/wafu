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
}