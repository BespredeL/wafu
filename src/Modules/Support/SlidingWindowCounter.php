<?php

declare(strict_types=1);

namespace Bespredel\Wafu\Modules\Support;

use Bespredel\Wafu\Storage\FileStorage;

final class SlidingWindowCounter
{
    /**
     * Constructor.
     *
     * @param FileStorage $storage
     */
    public function __construct(private FileStorage $storage)
    {
    }

    /**
     * Hit the counter.
     *
     * @param string $key      The key to hit
     * @param int    $limit    The limit to hit
     * @param int    $interval The interval to hit
     *
     * @return array Array containing the count and whether the limit was exceeded
     */
    public function hit(string $key, int $limit, int $interval): array
    {
        $now = time();
        $windowStart = $now - $interval;

        $data = $this->storage->read($key);
        $buckets = $this->normalizeBuckets($data['b'] ?? []);
        if ($buckets === [] && isset($data['t']) && is_array($data['t'])) {
            foreach ($data['t'] as $ts) {
                if (!is_numeric($ts)) {
                    continue;
                }
                $second = (int)$ts;
                if ($second > 0) {
                    $buckets[$second] = ($buckets[$second] ?? 0) + 1;
                }
            }
        }

        foreach ($buckets as $ts => $count) {
            if ($ts < $windowStart) {
                unset($buckets[$ts]);
            }
        }

        $buckets[$now] = ($buckets[$now] ?? 0) + 1;

        $total = 0;
        foreach ($buckets as $count) {
            $total += $count;
        }

        $this->storage->write($key, [
            'b'  => $buckets,
            'ls' => $now,
        ]);

        return [
            'count'    => $total,
            'exceeded' => $total > $limit,
        ];
    }

    /**
     * Normalize the buckets.
     *
     * @param mixed $rawBuckets The raw buckets
     *
     * @return array
     */
    private function normalizeBuckets(mixed $rawBuckets): array
    {
        if (!is_array($rawBuckets)) {
            return [];
        }

        $normalized = [];
        foreach ($rawBuckets as $ts => $count) {
            if (!is_numeric($ts) || !is_numeric($count)) {
                continue;
            }

            $second = (int)$ts;
            $hits = max(0, (int)$count);
            if ($second <= 0 || $hits <= 0) {
                continue;
            }
            $normalized[$second] = $hits;
        }

        ksort($normalized);

        return $normalized;
    }
}
