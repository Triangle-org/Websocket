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

use Closure;
use ErrorException;
use InvalidArgumentException;
use localzet\Server;
use localzet\Server\Connection\TcpConnection;
use localzet\Server\Protocols\Http;
use localzet\Server\Protocols\Websocket;
use localzet\ServerAbstract;
use Monolog\Logger;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use Throwable;
use Triangle\Engine\Autoload;
use Triangle\Engine\Config;
use Triangle\Engine\Context;
use Triangle\Engine\Path;
use Triangle\Engine\Plugin;
use Triangle\Exception\ExceptionHandler;
use Triangle\Exception\ExceptionHandlerInterface;
use Triangle\Middleware\Bootstrap as Middleware;
use Triangle\Middleware\MiddlewareInterface;
use Triangle\Request;
use Triangle\Response;
use Triangle\Router;
use Triangle\Router\Dispatcher;
use Triangle\Router\RouteObject;
use function array_merge;
use function array_reduce;
use function array_values;
use function count;
use function current;
use function explode;
use function get_class_methods;
use function gettype;
use function in_array;
use function is_a;
use function is_array;
use function is_string;
use function key;
use function method_exists;
use function next;
use function strtolower;
use function trim;

/**
 * Class App
 */
class App extends ServerAbstract
{
    /**
     * @var array
     */
    protected static array $connectionsMap = [];

    /**
     * Реакция на HTTP-рукопожатие перед WS-соединением
     * @param TcpConnection $connection
     * @param Http\Request $request
     * @return void|Http\Response
     * @throws Throwable
     */
    public function onWebsocketConnect(TcpConnection &$connection, Http\Request $request): mixed
    {
        try {
            // Генерация уникального идентификатора для соединения
            $connection->uuid = generateId();
            $path = $request->path();

            // Установка контекста соединения
            Context::set(TcpConnection::class, $connection);

            // Проверка безопасности URL
            if (static::unsafeUri($path)) {
                static::close_http($connection, 422);
                return null;
            }

            // Диспетчеризация маршрута на основе пути запроса
            $routeInfo = Router::dispatch('GET', $request->path());
            $middlewares = [];
            switch ($routeInfo[0]) {
                case Dispatcher::FOUND:
                    // Маршрут найден
                    $routeInfo[0] = 'route';
                    $callback = $routeInfo[1];
                    $args = !empty($routeInfo[2]) ? $routeInfo[2] : null;
                    $route = clone $routeInfo[3];
                    $app = $controller = $action = '';

                    // Установка параметров маршрута, если они есть
                    if ($args) {
                        $route->setParams($args);
                    }

                    // Получение middleware для маршрута
                    foreach ($route->getMiddleware() as $className) {
                        $middlewares[] = [$className, 'process'];
                    }

                    // Определение контроллера и действия
                    if (is_array($callback)) {
                        $controller = $callback[0];
                        $plugin = Plugin::app_by_class($controller);
                        $app = static::getAppByController($controller);
                        $action = static::getRealMethod($controller, $callback[1]) ?? '';
                    } else {
                        $plugin = Plugin::app_by_path($path);
                    }

                    // Получаем обратный вызов
                    $callback = static::getCallback($plugin, $app, $callback, $args, true, $middlewares);
                    // Собираем обратные вызовы
                    static::collectCallbacks($path, [$callback, $plugin, $app, $controller ?: '', $action, $route]);
                    // Получаем обратные вызовы
                    $callback = static::getCallbacks($path, $request);
                    break;
                case Dispatcher::NOT_FOUND:
                    // Парсим контроллер и действие из пути
                    $controllerAndAction = static::parseControllerAction($path);

                    // Получаем плагин по пути или из контроллера и действия
                    $plugin = $controllerAndAction['plugin'] ?? Plugin::app_by_path($path);

                    // Если контроллер и действие не найдены или маршрут по умолчанию отключен
                    if (!$controllerAndAction
                        || Router::isDefaultRouteDisabled($plugin, $controllerAndAction['app'] ?: '*')
                        || Router::isDefaultRouteDisabled($controllerAndAction['controller'])
                        || Router::isDefaultRouteDisabled([$controllerAndAction['controller'], $controllerAndAction['action']])
                    ) { // Устанавливаем плагин в запросе
                        $request->plugin = $plugin;

                        // Получаем обратный вызов для отката
                        $fallback = Router::getFallback($plugin ?? '');

                        // Устанавливаем приложение, контроллер и действие в запросе
                        $request->app = $request->controller = $request->action = '';

                        // Отправляем обратный вызов
                        static::close_http($connection, $fallback ? $fallback($request) : 404);
                        return;
                    }

                    $app = $controllerAndAction['app'];
                    $controller = $controllerAndAction['controller'];
                    $action = $controllerAndAction['action'];

                    // Получение callback для контроллера и действия
                    $callback = static::getCallback($plugin, $app, [$controller, $action]);
                    // Собираем обратные вызовы
                    static::collectCallbacks($path, [$callback, $plugin, $app, $controller, $action, null]);
                    // Получаем обратные вызовы
                    $callback = static::getCallbacks($path, $request);
                    break;
                case Dispatcher::METHOD_NOT_ALLOWED:
                    // Метод не поддерживается
                    static::close_http($connection, 405);
                    return null;
            }

            // Обновление карты соединений
            if (!isset(static::$connectionsMap[$path])) {
                static::$connectionsMap[$path] = [$connection->uuid => $connection];
            } else {
                static::$connectionsMap[$path][$connection->uuid] = $connection;
            }

            return $callback($request instanceof Request ? $request->getData() : $request);
        } catch (Throwable $e) {
            static::close_http($connection, static::exceptionResponse($e, $request));
            return null;
        }
    }

    /**
     * Функция для обработки сообщений.
     *
     * @param mixed $connection Соединение TCP.
     * @param mixed $request Запрос.
     * @return void
     * @throws Throwable
     */
    public function onMessage(mixed &$connection, mixed $request): void
    {
        // Буферизация запроса
        $buffer = $request;
        /** @var Request $request */
        $request = $connection->request;
        $request->ws_buffer = $buffer;

        try {
            // Установка контекста запроса
            Context::set(Request::class, $request);

            // Получение пути запроса
            $path = $request->path();

            // Проверка наличия callback для данного пути
            if (isset(static::$callbacks[$path])) {
                $callback = static::getCallbacks($path, $request);
                // Отправка ответа с использованием callback
                static::send($connection, $callback($request));
                return;
            }

            // Закрытие соединения с кодом 404, если callback не найден
            static::close($connection, 404);
        } catch (Throwable $e) {
            // Отправка ответа с информацией об исключении
            static::send($connection, static::exceptionResponse($e, $request));
        } finally {
            // Удаление контекста запроса
            Context::delete(Request::class);
        }
    }

    /**
     * Функция для получения обратного вызова.
     *
     * @param string|null $plugin Плагин.
     * @param string $app
     * @param mixed $call Вызов.
     * @param array $args Аргументы.
     * @param bool $withGlobalMiddleware
     * @param array|null $middlewares
     * @return callable|Closure Возвращает обратный вызов.
     */
    public static function getCallback(?string $plugin, string $app, mixed $call, array $args = [], bool $withGlobalMiddleware = true, ?array $middlewares = []): callable|Closure
    {
        $plugin ??= '';
        $isController = is_array($call) && is_string($call[0]);
        $container = static::container($plugin) ?? static::container();
        $middlewares = array_merge(
            $middlewares,
            Middleware::getMiddleware($plugin, $app, $isController ? $call[0] : '', $withGlobalMiddleware)
        );

        // Создаем экземпляры промежуточного ПО
        foreach ($middlewares as $key => $item) {
            $middleware = $item[0];
            if (is_string($middleware)) {
                $middleware = $container->get($middleware);
            } elseif ($middleware instanceof Closure) {
                $middleware = call_user_func($middleware, $container);
            }
            if (!$middleware instanceof MiddlewareInterface) {
                throw new InvalidArgumentException('Неподдерживаемый тип middleware');
            }
            $middlewares[$key][0] = $middleware;
        }

        // Проверяем, нужно ли внедрять зависимости в вызов
        $needInject = static::isNeedInject($call, $args);
        $anonymousArgs = array_values($args);
        if ($isController) {
            $controllerReuse = static::config($plugin, 'app.controller_reuse', true);
            if (!$controllerReuse) {
                if ($needInject) {
                    $call = function ($request) use ($call, $plugin, $args, $container) {
                        $call[0] = $container->make($call[0]);
                        $reflector = static::getReflector($call);
                        $args = array_values(static::resolveMethodDependencies($container, $request, array_merge($request->all(), $args), $reflector, static::config($plugin, 'app.debug')));
                        return $call(...$args);
                    };
                    $needInject = false;
                } else {
                    $call = function ($request, ...$anonymousArgs) use ($call, $plugin, $container) {
                        $call[0] = $container->make($call[0]);
                        return $call($request, ...$anonymousArgs);
                    };
                }
            } else {
                $call[0] = $container->get($call[0]);
            }
        }

        // Если нужно внедрить зависимости, внедряем их
        if ($needInject) {
            $call = static::resolveInject($plugin, $call, $args);
        }

        $callback = function ($request) use ($call, $anonymousArgs) {
            try {
                $buffer = $request instanceof Request ? $request->getData() : $request;
                $response = $anonymousArgs ? $call($buffer, ...$anonymousArgs) : $call($buffer);
            } catch (Throwable $e) {
                return static::exceptionResponse($e, $request);
            }

            return $response instanceof Response ? $response : new Response(200, [], static::stringify($response));
        };

        return $middlewares ? array_reduce($middlewares, function ($carry, $pipe) {
            return function ($request) use ($carry, $pipe) {
                try {
                    return $pipe($request, $carry);
                } catch (Throwable $e) {
                    return static::exceptionResponse($e, $request);
                }
            };
        }, $callback) : $callback;
    }

    /**
     * @param TcpConnection $connection
     * @return void
     */
    public function onClose(mixed &$connection): void
    {
        // Удаление контекста соединения
        Context::delete(TcpConnection::class);

        // Обновление карты соединений
        if (isset(static::$connectionsMap[$connection->request->path()][$connection->uuid])) {
            unset(static::$connectionsMap[$connection->request->path()][$connection->uuid]);
        }
    }

    /**
     * Отправка данных всем соединениям.
     *
     * @param string|Response|null $data Данные для отправки.
     * @param bool $excludeCurrent Исключить текущее соединение.
     * @return void
     * @throws Throwable
     */
    public static function sendToAll(string|Response|null $data = null, bool $excludeCurrent = false): void
    {
        // Проходим по всем серверам
        foreach (static::$server::getAllServers() as $server) {
            // Проходим по всем соединениям сервера
            foreach ($server->connections as $id => $connection) {
                // Исключаем текущее соединение, если это указано
                if ($excludeCurrent && $connection->uuid === static::connection()->uuid) continue;
                // Отправляем данные соединению
                static::send($connection, $data);
            }
        }
    }

    /**
     * Отправка данных группе соединений.
     *
     * @param string|Response|null $data Данные для отправки.
     * @param bool $excludeCurrent Исключить текущее соединение.
     * @return void
     * @throws Throwable
     */
    public static function sendToGroup(string|Response|null $data = null, bool $excludeCurrent = false): void
    {
        // Получаем путь запроса текущего соединения
        $path = static::connection()->request->path();
        // Проходим по всем соединениям в карте соединений для данного пути
        foreach (static::$connectionsMap[$path] ?? [] as $uuid => $connection) {
            // Исключаем текущее соединение, если это указано
            if ($excludeCurrent && $uuid === static::connection()->uuid) continue;
            // Отправляем данные соединению
            static::send($connection, $data);
        }
    }

    /**
     * Отправка данных конкретному соединению.
     *
     * @param TcpConnection|mixed $connection Соединение TCP.
     * @param string|Response|null $data Данные для отправки.
     * @throws Throwable
     */
    protected static function send(TcpConnection $connection, string|Response|null $data = null): void
    {
        // Отправляем данные соединению
        $connection->send(is_string($data) ? $data : $data->rawBody());
    }

    /**
     * Закрытие соединения с отправкой статуса и данных.
     *
     * @param TcpConnection $connection Соединение TCP.
     * @param int $status Статус закрытия.
     * @param mixed|null $data Данные для отправки.
     * @return void
     * @throws Throwable
     */
    public static function close(TcpConnection $connection, int $status = 204, mixed $data = null): void
    {
        // Закрываем соединение с отправкой JSON-ответа
        $connection->close(json(['status' => $status, 'data' => $data ?? Http\Response::PHRASES[$status]]));
    }

    /**
     * Закрытие HTTP-соединения с отправкой статуса и данных.
     *
     * @param TcpConnection $connection Соединение TCP.
     * @param int|Response $status Статус закрытия или объект ответа.
     * @param mixed|null $data Данные для отправки.
     * @return void
     * @throws Throwable
     */
    public static function close_http(TcpConnection $connection, int|Response $status = 204, mixed $data = null): void
    {
        // Если статус является объектом ответа
        if ($status instanceof Response) {
            // Получаем сообщение об ошибке, если есть
            if ($e = $status->exception()) {
                $data = $e->getMessage();
            } else {
                $data = $status->rawBody();
            }
            // Получаем статусный код из объекта ответа
            $status = $status->getStatusCode();
        }
        // Закрываем соединение с отправкой HTTP-ответа
        $connection->close((string)new Http\Response($status, [], json(['status' => $status, 'data' => $data ?? Server\Protocols\Http\Response::PHRASES[$status]])));
    }

}
