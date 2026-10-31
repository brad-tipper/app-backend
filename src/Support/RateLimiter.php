<?php

namespace BradTipper\AppBackend\Support;

final class RateLimiter
{
    /**
     * Counts one hit against `$key`; returns false once `$max` hits have landed
     * inside the current `$windowSeconds` window.
     */
    public static function hit(string $key, int $max, int $windowSeconds): bool
    {
        $hash = sha1($key);
        $now = time();

        // Opportunistic cleanup keeps the table small without a scheduled job.
        if (random_int(1, 100) === 1) {
            RateLimitBucket::get()->filter('ResetAt:LessThan', $now)->removeAll();
        }

        /** @var RateLimitBucket|null $bucket */
        $bucket = RateLimitBucket::get()->filter('BucketKey', $hash)->first();
        if (!$bucket) {
            RateLimitBucket::create(['BucketKey' => $hash, 'Hits' => 1, 'ResetAt' => $now + $windowSeconds])->write();
            return true;
        }
        if ($bucket->ResetAt <= $now) {
            $bucket->Hits = 1;
            $bucket->ResetAt = $now + $windowSeconds;
            $bucket->write();
            return true;
        }
        if ($bucket->Hits >= $max) {
            return false;
        }
        $bucket->Hits++;
        $bucket->write();
        return true;
    }
}
