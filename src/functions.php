<?php

/**
 * @package     Triangle Websocket Component
 * @link        https://github.com/Triangle-org/Websocket
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2023-2024 Triangle Framework Team
 * @license     https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as published
 *              by the Free Software Foundation, either version 3 of the License, or
 *              (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <triangle@localzet.com>
 */

use localzet\Server;
use localzet\Server\Connection\TcpConnection;
use support\Request;
use support\Response;
use Triangle\Ws\App;

/**
 * @param mixed $data
 * @param int $status
 * @param array $headers
 * @return Response
 */
function response(mixed $data = 'OK', int $status = 200, array $headers = []): Response
{
    $body = [
        'status' => $status,
        'data' => $data
    ];

    if (config('app.debug')) {
        $body['debug'] = config('app.debug');
    }

    return new Response($status, ['Content-Type' => 'application/json'] + $headers, json($body));
}

if (!function_exists('jsonp')) {
    /**
     * @param $data
     * @param string $callbackName
     * @return Response
     */
    function jsonp($data, string $callbackName = 'callback'): Response
    {
        if (!is_scalar($data) && null !== $data) {
            $data = json_encode($data);
        }
        return new Response(200, [], "$callbackName($data)");
    }
}

/**
 * @return TcpConnection|null
 */
function connection(): ?TcpConnection
{
    return App::connection();
}

/**
 * @return \Triangle\Ws\Request|Request
 */
function request(): \Triangle\Ws\Request|Request
{
    return App::request();
}

/**
 * @return Server|null
 */
function server(): ?Server
{
    return App::server();
}