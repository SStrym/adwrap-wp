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

class RelayConfiguration
{
    /**
     * Whether to use Relay's in-memory cache.
     *
     * @var bool
     */
    public $cache;

    /**
     * Whether to register Relay event listeners.
     *
     * @var bool
     */
    public $listeners;

    /**
     * Whether to enable client-side invalidation.
     *
     * @var bool
     */
    public $invalidations;

    /**
     * When set, only keys matching these patterns will be cached in Relay's in-memory cache, unless they match `relay.ignored`.
     *
     * @var ?array<string>
     */
    public $allowed;

    /**
     * Keys matching these patterns will not be cached in Relay's in-memory cache.
     *
     * @var ?array<string>
     */
    public $ignored;

    /**
     * The adaptive cache configuration.
     *
     * @var RelayAdaptiveConfiguration
     */
    public $adaptive;
}
