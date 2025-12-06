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

class RelayAdaptiveConfiguration
{
    /**
     * Number of horizontal cells in the adaptive cache.
     * Ideally this should scale with the number of unique keys in the database.
     * Supported values: 512 - 2^31.
     *
     * @var int
     */
    public $width;

    /**
     * Number of vertical cells.
     * Supported values: 1 - 8.
     *
     * @var int
     */
    public $depth;

    /**
     * Minimum number of events (reads + writes) before Relay
     * will use the ratio to determine if a key should remain cached.
     *
     * Using a negative number will invert this and Relay won't cache
     * a key until its seen at least that many events for the key.
     *
     * @var int
     */
    public $events;

    /**
     * Minimum ratio of reads to writes of a key to remain
     * cached (positive events) or be cached (negative events).
     *
     * @var float
     */
    public $ratio;

    /**
     * The formula used to calculate the read/write ratio of a key.
     *
     * - `pure`: reads / writes
     * - `scaled`: (reads / writes)^(1.01 * log(1 + reads + writes))
     *
     * @var string
     */
    public $formula;
}
