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

namespace RedisCachePro\Plugin\Api;

use WP_Error;
use WP_REST_Server;

use RedisCachePro\ObjectCaches\ObjectCacheInterface;

class Commands extends Controller
{
    /**
     * The resource name of this controller's route.
     *
     * @var string
     */
    protected $resource_name = 'commands';

    /**
     * Register all REST API routes.
     *
     * @return void
     */
    public function register_routes()
    {
        register_rest_route($this->namespace, "/{$this->resource_name}", [
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete_item'],
                'permission_callback' => [$this, 'delete_item_permissions_check'],
                'args' => $this->get_endpoint_args_for_item_schema(WP_REST_Server::DELETABLE),
            ],
            'schema' => [$this, 'get_public_item_schema'],
        ]);
    }

    /**
     * Returns the REST API response for the request.
     *
     * @param  \WP_REST_Request  $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function delete_item($request)
    {
        global $wp_object_cache;

        if (! $wp_object_cache instanceof ObjectCacheInterface) {
            return $this->notSupportedError();
        }

        $connection = $wp_object_cache->connection();

        if (! $connection) {
            return $this->notConnectedError();
        }

        if (! $connection->config('RESETSTAT')) {
            return new WP_Error(
                'command_failed',
                'The command statistics could not be reset, possibly due to missing permissions.',
                ['status' => 400]
            );
        }

        /** @var \WP_REST_Response $response */
        $response = rest_ensure_response(true);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }
}
