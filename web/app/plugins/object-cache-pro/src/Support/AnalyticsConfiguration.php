<?php
/**
 * Copyright © 2019-2025 Rhubarb Tech Inc. All Rights Reserved.
 *
 * The Object Cache Pro Software and its related materials are property and confidential
 * information of Rhubarb Tech Inc. Any reproduction, use, distribution, or exploitation
 * of the Object Cache Pro Software and its related materials, in whole or in part,
 * is strictly forbidden unless prior permission is obtained from Rhubarb Tech Inc.
 *
 * In addition, any reproduction, use, distribution, or exploitation of the Object Cache Pro
 * Software and its related materials, in whole or in part, is subject to the End-User License
 * Agreement accessible in the included `LICENSE` file, or at: https://objectcache.pro/eula
 */

declare(strict_types=1);

namespace RedisCachePro\Support;

class AnalyticsConfiguration
{
    /**
     * Whether to collect and display analytics.
     *
     * @var bool
     */
    public $enabled;

    /**
     * Whether to restore analytics data after cache flushes.
     *
     * @var bool
     */
    public $persist;

    /**
     * The number of seconds to keep analytics before purging them.
     *
     * @var int
     */
    public $retention;

    /**
     * The sample rate for analytics in the range of 0 to 100.
     *
     * @var int|float
     */
    public $sample_rate;

    /**
     * Whether to print a HTML comment with non-sensitive metrics.
     *
     * @var bool
     */
    public $footnote;

    /**
     * The list of optional properties to include to analytics.
     *
     * @var array<string>
     */
    public $include;
}
