<?php

namespace BradTipper\AppBackend\Support;

use SilverStripe\ORM\DataObject;

/**
 * Fixed-window counter used by RateLimiter (replaces Supabase's rate_limit_buckets).
 *
 * @property string $BucketKey
 * @property int $Hits
 * @property int $ResetAt
 */
class RateLimitBucket extends DataObject
{
    private static string $table_name = 'AppRateLimitBucket';

    private static array $db = [
        'BucketKey' => 'Varchar(64)',
        'Hits' => 'Int',
        'ResetAt' => 'Int',
    ];

    private static array $indexes = [
        'BucketKey' => ['type' => 'unique', 'columns' => ['BucketKey']],
        'ResetAt' => true,
    ];
}
