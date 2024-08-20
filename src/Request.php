<?php declare(strict_types=1);

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

namespace Triangle\Ws;

use Triangle\Router\RouteObject;
use function current;

/**
 * Класс Request
 * Этот класс представляет собой пользовательский запрос, который наследует от базового класса Http\Request.
 *
 * @link https://www.php.net/manual/en/class.httprequest.php
 */
class Request extends \localzet\Server\Protocols\Http\Request
{
    /**
     * @var string|null $plugin Имя плагина, связанного с запросом.
     */
    public ?string $plugin = null;

    /**
     * @var string|null $app Имя приложения, связанного с запросом.
     */
    public ?string $app = null;

    /**
     * @var string|null $controller Имя контроллера, связанного с запросом.
     */
    public ?string $controller = null;

    /**
     * @var string|null $action Имя действия, связанного с запросом.
     */
    public ?string $action = null;

    /**
     * @var RouteObject|null $route Маршрут, связанный с запросом.
     */
    public ?RouteObject $route = null;

    /**
     * @var string|null
     */
    public ?string $ws_buffer = null;

    /**
     * @return string|null
     */
    public function getData(): ?string
    {
        return $this->ws_buffer;
    }

    /**
     * Получение IP-адреса
     *
     * @return string|null IP-адрес
     */
    public function getRequestIp(): ?string
    {
        $ip = $this->header(
            'x-real-ip',
            $this->header(
                'x-forwarded-for',
                $this->header(
                    'client-ip',
                    $this->header(
                        'x-client-ip',
                        $this->header(
                            'remote-addr',
                            $this->header(
                                'via'
                            )
                        )
                    )
                )
            )
        );
        if (is_string($ip)) {
            $ip = current(explode(',', $ip));
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }
}
