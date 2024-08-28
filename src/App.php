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
use Triangle\Router;
use Triangle\Router\Dispatcher;
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
     * @var callable[]
     */
    protected static array $callbacks = [];

    /**
     * @var Server|null
     */
    protected static ?Server $server = null;

    /**
     * @var Logger|null
     */
    protected static ?Logger $logger = null;

    /**
     * @var string|null
     */
    protected static ?string $requestClass = null;

    /**
     * @var array
     */
    protected static array $connectionsMap = [];

    /**
     * @param string $requestClass
     * @param Logger $logger
     * @param string|null $basePath
     * @param string|null $appPath
     * @param string|null $configPath
     * @param string|null $publicPath
     * @param string|null $runtimePath
     */
    public function __construct(
        string $requestClass,
        Logger $logger,
        string $basePath = null,
        string $appPath = null,
        string $configPath = null,
        string $publicPath = null,
        string $runtimePath = null,
    )
    {
        static::$requestClass = $requestClass;
        static::$logger = $logger;

        new Path(
            basePath: $basePath ?? Path::basePath(),
            configPath: $configPath ?? Path::configPath(),
            appPath: $appPath ?? config('server.app_path', config('app.app_path', Path::appPath())),
            publicPath: $publicPath ?? config('server.public_path', config('app.public_path', Path::publicPath())),
            runtimePath: $runtimePath ?? config('server.runtime_path', config('app.runtime_path', Path::runtimePath())),
        );
    }

    /**
     * @param Server $server
     * @return void
     * @throws ErrorException
     */
    public function onServerStart(Server &$server): void
    {
        static::$server = $server;
        Websocket::requestClass(static::$requestClass);
        Autoload::loadAll($server);
    }

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
            if (!$path ||
                str_contains($path, '..') ||
                str_contains($path, "\\") ||
                str_contains($path, "\0")
            ) {
                // Логирование небезопасного URL и закрытие соединения с кодом 422
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
                    $routeMiddlewares = $route->getMiddleware();
                    foreach ($routeMiddlewares as $className) {
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

                    // Получение callback для маршрута
                    $callback = static::getCallback($plugin, $callback, $args);
                    break;
                case Dispatcher::NOT_FOUND:
                    // Маршрут не найден
                    $route = null;
                    $controllerAndAction = static::parseControllerAction($path);
                    $plugin = $controllerAndAction['plugin'] ?? Plugin::app_by_path($path);

                    $app = $controllerAndAction['app'];
                    $controller = $controllerAndAction['controller'];
                    $action = $controllerAndAction['action'];

                    // Получение callback для контроллера и действия
                    $callback = static::getCallback($plugin, [$controller, $action]);
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

            // Сохранение callback и информации о соединении
            static::$callbacks[$path] = [$callback, $plugin, $app, $controller ?: '', $action, $route];
            if (count(static::$callbacks) >= 1024) {
                unset(static::$callbacks[key(static::$callbacks)]);
            }

            $callback = function ($request) use ($connection) {
                return $connection->response;
            };

            // Получение и обработка middleware
            $middlewares = array_merge($middlewares, Middleware::getMiddleware($plugin, $app, true));
            foreach ($middlewares as $key => $item) {
                $middleware = $item[0];
                if (is_string($middleware)) {
                    $middleware = static::container($plugin)->get($middleware);
                } elseif ($middleware instanceof Closure) {
                    $middleware = call_user_func($middleware, static::container($plugin));
                }
                if (!$middleware instanceof MiddlewareInterface) {
                    throw new InvalidArgumentException('Неподдерживаемый тип middleware');
                }
                $middlewares[$key][0] = $middleware;
            }

            // Объединение middleware с callback
            if ($middlewares) {
                $callback = array_reduce($middlewares, function ($carry, $pipe) {
                    return function ($request) use ($carry, $pipe) {
                        try {
                            return $pipe($request, $carry);
                        } catch (Throwable $e) {
                            return static::exceptionResponse($e, $request);
                        }
                    };
                }, $callback);
            }

            return $callback($request);
        } catch (Throwable $e) {
            // Обработка исключений и закрытие соединения
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
                [$callback, $request->plugin, $request->app, $request->controller, $request->action, $request->route] = static::$callbacks[$path];
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

    /**
     * Функция для создания ответа на исключение.
     *
     * @param Throwable $e Исключение.
     * @param mixed $request Запрос.
     * @return Response Возвращает ответ.
     */
    protected static function exceptionResponse(Throwable $e, mixed $request): Response
    {
        // Получение приложения и плагина из запроса
        $app = $request?->app ?: '';
        $plugin = $request?->plugin ?: '';

        try {
            // Получение конфигурации обработчика исключений
            $exceptionConfig = static::config($plugin, 'exception');
            $defaultException = $exceptionConfig[''] ?? ExceptionHandler::class;
            $exceptionHandlerClass = $exceptionConfig[$app] ?? $defaultException;

            // Создание экземпляра обработчика исключений
            /** @var ExceptionHandlerInterface $exceptionHandler */
            $exceptionHandler = static::container($plugin)->make($exceptionHandlerClass, [
                'logger' => static::$logger,
            ]);

            // Сообщение об исключении
            $exceptionHandler->report($e);
            // Создание ответа на исключение
            $response = $exceptionHandler->render($request, $e);
            $response->exception($e);
            return $response;
        } catch (Throwable $e) {
            // Создание ответа на исключение в случае ошибки в обработчике исключений
            $response = new Response(500, [], static::config($plugin ?? '', 'app.debug') ? (string)$e : $e->getMessage());
            $response->exception($e);
            return $response;
        }
    }

    /**
     * Получение текущего соединения.
     *
     * @return TcpConnection|null Возвращает текущее соединение или null.
     */
    public static function connection(): TcpConnection|null
    {
        // Получаем текущее соединение из контекста
        return Context::get(TcpConnection::class);
    }

    /**
     * Получение текущего запроса.
     *
     * @return Request|null Возвращает текущий запрос или null.
     */
    public static function request(): Request|null
    {
        // Получаем текущий запрос из контекста
        return Context::get(Request::class);
    }

    /**
     * Получение текущего сервера.
     *
     * @return Server|null Возвращает текущий сервер или null.
     */
    public static function server(): ?Server
    {
        // Возвращаем текущий сервер
        return static::$server;
    }

    /**
     * Получение конфигурации.
     *
     * @param string $plugin Плагин.
     * @param string $key Ключ конфигурации.
     * @param mixed|null $default Значение по умолчанию.
     * @return array|mixed|null Возвращает значение конфигурации или значение по умолчанию.
     */
    protected static function config(string $plugin, string $key, mixed $default = null): mixed
    {
        // Получаем значение конфигурации для указанного плагина и ключа
        return Config::get($plugin ? config('app.plugin_alias', 'plugin') . ".$plugin.$key" : $key, $default);
    }

    /**
     * Получение контейнера зависимостей.
     *
     * @param string $plugin Плагин.
     * @return ContainerInterface|array|null Возвращает контейнер зависимостей или null.
     */
    public static function container(string $plugin = ''): ContainerInterface|array|null
    {
        // Получаем контейнер зависимостей для указанного плагина
        return static::config($plugin, 'container');
    }

    /**
     * Функция для получения обратного вызова.
     *
     * @param string|null $plugin Плагин.
     * @param mixed $call Вызов.
     * @param array|null $args Аргументы.
     * @return callable|Closure Возвращает обратный вызов.
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected static function getCallback(?string $plugin, mixed $call, array $args = null): callable|Closure
    {
        // Преобразование аргументов в массив значений, если они не равны null
        $args = $args === null ? null : array_values($args);
        $plugin ??= '';

        // Проверяем, нужно ли внедрять зависимости в вызов
        $needInject = static::isNeedInject($call, $args);
        if (is_array($call) && is_string($call[0])) {
            // Проверка, нужно ли повторно использовать контроллер
            $controllerReuse = static::config($plugin, 'app.controller_reuse', true);
            if (!$controllerReuse) {
                if ($needInject) {
                    // Внедрение зависимостей и создание контроллера
                    $call = function ($request, ...$args) use ($call, $plugin) {
                        $call[0] = static::container($plugin)->make($call[0]);
                        $reflector = static::getReflector($call);
                        $args = static::resolveMethodDependencies($plugin, $request, $args, $reflector);
                        return $call(...$args);
                    };
                    $needInject = false;
                } else {
                    // Создание контроллера без внедрения зависимостей
                    $call = function ($request, ...$args) use ($call, $plugin) {
                        $call[0] = static::container($plugin)->make($call[0]);
                        return $call($request, ...$args);
                    };
                }
            } else {
                // Получение контроллера из контейнера
                $call[0] = static::container($plugin)->get($call[0]);
            }
        }

        // Если нужно внедрить зависимости, внедряем их
        if ($needInject) {
            $call = static::resolveInject($plugin, $call);
        }

        // Возвращаем функцию обратного вызова
        return function ($request) use ($call, $args) {
            try {
                // Получение данных из запроса
                $buffer = $request instanceof Request ? $request->getData() : $request;
                // Вызов функции обратного вызова с аргументами
                $response = $args === null ? $call($buffer) : $call($buffer, ...$args);
            } catch (Throwable $e) {
                // Обработка исключений и возврат ответа с ошибкой
                return static::exceptionResponse($e, $request);
            }

            // Возврат ответа
            return $response instanceof Response ? $response : new Response(200, [], static::stringify($response));
        };
    }

    /**
     * Проверка, требуется ли внедрение зависимостей.
     *
     * @param mixed $call Вызов.
     * @param mixed $args Аргументы.
     * @return bool Возвращает true, если требуется внедрение зависимостей.
     * @throws ReflectionException
     */
    protected static function isNeedInject($call, $args): bool
    {
        // Проверка, существует ли метод в классе
        if (is_array($call) && !method_exists($call[0], $call[1])) {
            return false;
        }
        $args = $args ?: [];
        // Получение рефлектора для вызова
        $reflector = static::getReflector($call);
        $reflectionParameters = $reflector->getParameters();
        if (!$reflectionParameters) {
            return false;
        }
        $firstParameter = current($reflectionParameters);
        unset($reflectionParameters[key($reflectionParameters)]);
        $adaptersList = ['int', 'string', 'bool', 'array', 'object', 'float', 'mixed', 'resource'];
        // Проверка типов параметров
        foreach ($reflectionParameters as $parameter) {
            if ($parameter->hasType() && !in_array($parameter->getType()->getName(), $adaptersList)) {
                return true;
            }
        }
        if (!$firstParameter->hasType()) {
            return count($args) > count($reflectionParameters);
        }

        // Проверка, является ли первый параметр экземпляром класса запроса
        if (!is_a(static::$requestClass, $firstParameter->getType()->getName())) {
            return true;
        }

        return false;
    }

    /**
     * Получение рефлектора.
     *
     * @param mixed $call Вызов.
     * @return ReflectionFunction|ReflectionMethod Возвращает рефлектор функции или метода.
     * @throws ReflectionException
     */
    protected static function getReflector($call): ReflectionMethod|ReflectionFunction
    {
        if ($call instanceof Closure || is_string($call)) {
            return new ReflectionFunction($call);
        }
        return new ReflectionMethod($call[0], $call[1]);
    }

    /**
     * Функция для получения зависимых параметров.
     *
     * @param string $plugin Плагин.
     * @param mixed $request Запрос.
     * @param array $args Аргументы.
     * @param ReflectionFunctionAbstract $reflector Рефлектор.
     * @return array Возвращает массив с зависимыми параметрами.
     */
    protected static function resolveMethodDependencies(string $plugin, mixed $request, array $args, ReflectionFunctionAbstract $reflector): array
    {
        // Преобразование аргументов в массив значений
        $args = array_values($args);
        // Инициализация массива параметров с запросом
        $parameters = [$request];

        // Проходим по всем параметрам рефлектора
        foreach ($reflector->getParameters() as $parameter) {
            // Если есть текущий аргумент, добавляем его в параметры
            if (null !== key($args)) {
                $parameters[] = current($args);
            } else {
                // Иначе добавляем значение по умолчанию или null
                $parameters[] = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
            }

            // Переходим к следующему аргументу
            next($args);
        }

        // Возвращаем массив параметров
        return $parameters;
    }

    /**
     * Функция для внедрения зависимостей через информацию о рефлексии.
     *
     * @param string $plugin Плагин.
     * @param array|Closure $call Вызов.
     * @return Closure Возвращает замыкание.
     */
    protected static function resolveInject(string $plugin, array|Closure $call): Closure
    {
        return function (mixed $request, ...$args) use ($plugin, $call) {
            // Получаем рефлектор для вызова
            $reflector = static::getReflector($call);
            // Получаем зависимые параметры для вызова
            $args = static::resolveMethodDependencies($plugin, $request, $args, $reflector);
            // Выполняем вызов с зависимыми параметрами
            return $call(...$args);
        };
    }

    /**
     * @param string $controllerClass
     * @return mixed|string
     */
    protected static function getAppByController(string $controllerClass): mixed
    {
        $controllerClass = trim($controllerClass, '\\');
        $tmp = explode('\\', $controllerClass, 5);
        $pos = $tmp[0] === config('app.plugin_alias', 'plugin') ? 3 : 1;
        if (!isset($tmp[$pos])) {
            return '';
        }
        return strtolower($tmp[$pos]) === 'controller' ? '' : $tmp[$pos];
    }

    /**
     * Получить метод
     * @param string $class
     * @param string $method
     * @return string
     */
    protected static function getRealMethod(string $class, string $method): string
    {
        $method = strtolower($method);
        $methods = get_class_methods($class);
        foreach ($methods as $candidate) {
            if (strtolower($candidate) === $method) {
                return $candidate;
            }
        }
        return $method;
    }

    /**
     * Функция для разбора контроллера и действия из пути.
     *
     * @param string $path Путь.
     * @return array|false Возвращает массив с информацией о контроллере и действии, если они найдены, иначе возвращает false.
     * @throws ReflectionException
     */
    protected static function parseControllerAction(string $path): false|array
    {
        // Удаляем дефисы из пути
        $path = str_replace(['-', '//'], ['', '/'], $path);

        static $cache = [];
        if (isset($cache[$path])) {
            return $cache[$path];
        }

        // Проверяем, является ли путь плагином
        $plugin = Plugin::app_by_path($path);

        // Получаем суффикс контроллера из конфигурации
        $suffix = static::config($plugin, 'app.controller_suffix', '');

        // Получаем префиксы для конфигурации, пути и класса
        $pathPrefix = $plugin ? "/" . config('app.plugin_uri', 'app') . "/$plugin" : '';
        $classPrefix = $plugin ? config('app.plugin_alias', 'plugin') . "\\$plugin" : '';

        // Получаем относительный путь
        $relativePath = trim(substr($path, strlen($pathPrefix)), '/');
        $pathExplode = $relativePath ? explode('/', $relativePath) : [];

        // По умолчанию действие - это 'index'
        $action = 'index';

        // Пытаемся угадать контроллер и действие
        if (!$controllerAction = static::guessControllerAction($pathExplode, $action, $suffix, $classPrefix)) {
            // Если контроллер и действие не найдены и путь состоит из одной части, возвращаем false
            if (count($pathExplode) <= 1) {
                return false;
            }

            $action = end($pathExplode);
            unset($pathExplode[count($pathExplode) - 1]);
            $controllerAction = static::guessControllerAction($pathExplode, $action, $suffix, $classPrefix);
        }

        if ($controllerAction && !isset($path[256])) {
            $cache[$path] = $controllerAction;
            if (count($cache) > 1024) {
                unset($cache[key($cache)]);
            }
        }

        return $controllerAction;
    }


    /**
     * Функция для предположения контроллера и действия.
     *
     * @param array $pathExplode Массив с разделенными частями пути.
     * @param string $action Название действия.
     * @param string $suffix Суффикс.
     * @param string $classPrefix Префикс класса.
     * @return array|false Возвращает массив с информацией о контроллере и действии, если они найдены, иначе возвращает false.
     * @throws ReflectionException
     */
    protected static function guessControllerAction(array $pathExplode, string $action, string $suffix, string $classPrefix): false|array
    {
        // Создаем карту возможных путей к контроллеру
        $map[] = trim("$classPrefix\\app\\controller\\" . implode('\\', $pathExplode), '\\');
        foreach ($pathExplode as $index => $section) {
            $tmp = $pathExplode;
            array_splice($tmp, $index, 1, [$section, 'controller']);
            $map[] = trim("$classPrefix\\" . implode('\\', array_merge(['app'], $tmp)), '\\');
        }
        foreach ($map as $item) {
            $map[] = $item . '\\index';
        }

        // Проверяем каждый возможный путь
        foreach ($map as $controllerClass) {
            // Удаляем xx\xx\controller
            if (str_ends_with($controllerClass, '\\controller')) {
                continue;
            }
            $controllerClass .= $suffix;
            // Если контроллер и действие найдены, возвращаем информацию о них
            if ($controllerAction = static::getControllerAction($controllerClass, $action)) {
                return $controllerAction;
            }
        }

        // Если контроллер или действие не найдены, возвращаем false
        return false;
    }


    /**
     * Функция для получения контроллера и действия.
     *
     * @param string $controllerClass Имя класса контроллера.
     * @param string $action Название действия.
     * @return array|false Возвращает массив с информацией о контроллере и действии, если они найдены, иначе возвращает false.
     * @throws ReflectionException
     */
    protected static function getControllerAction(string $controllerClass, string $action): false|array
    {
        // Отключаем вызов магических методов
        if (str_starts_with($action, '__')) {
            return false;
        }

        // Если класс контроллера и действие найдены, возвращаем информацию о них
        if (($controllerClass = static::getController($controllerClass)) && ($action = static::getAction($controllerClass, $action))) {
            return [
                'plugin' => Plugin::app_by_class($controllerClass),
                'app' => static::getAppByController($controllerClass),
                'controller' => $controllerClass,
                'action' => $action
            ];
        }

        // Если класс контроллера или действие не найдены, возвращаем false
        return false;
    }

    /**
     * Функция для получения контроллера.
     *
     * @param string $controllerClass Имя класса контроллера.
     * @return string|false Возвращает имя класса контроллера, если он найден, иначе возвращает false.
     * @throws ReflectionException
     */
    protected static function getController(string $controllerClass): false|string
    {
        // Если класс контроллера существует, возвращаем его имя
        if (class_exists($controllerClass)) {
            return (new ReflectionClass($controllerClass))->name;
        }

        // Разбиваем полное имя класса на части
        $explodes = explode('\\', strtolower(ltrim($controllerClass, '\\')));
        $basePath = $explodes[0] === config('app.plugin_alias', 'plugin') ? Path::basePath(config('app.plugin_alias', 'plugin')) : app_path();
        unset($explodes[0]);
        $fileName = array_pop($explodes) . '.php';
        $found = true;

        // Ищем соответствующую директорию
        foreach ($explodes as $pathSection) {
            if (!$found) {
                break;
            }
            $dirs = scan_dir($basePath, false);
            $found = false;
            foreach ($dirs as $name) {
                $path = "$basePath/$name";
                if (is_dir($path) && strtolower($name) === $pathSection) {
                    $basePath = $path;
                    $found = true;
                    break;
                }
            }
        }

        // Если директория не найдена, возвращаем false
        if (!$found) {
            return false;
        }

        // Ищем файл контроллера в директории
        foreach (scandir($basePath) ?: [] as $name) {
            if (strtolower($name) === $fileName) {
                require_once "$basePath/$name";
                if (class_exists($controllerClass, false)) {
                    return (new ReflectionClass($controllerClass))->name;
                }
            }
        }

        // Если файл контроллера не найден, возвращаем false
        return false;
    }

    /**
     * Функция для получения действия контроллера.
     *
     * @param string $controllerClass Имя класса контроллера.
     * @param string $action Название действия.
     * @return string|false Возвращает название действия, если оно найдено, иначе возвращает false.
     */
    protected static function getAction(string $controllerClass, string $action): false|string
    {
        // Получаем все методы класса контроллера
        $methods = get_class_methods($controllerClass);
        $lowerAction = strtolower($action);
        $found = false;

        // Проверяем, есть ли метод, соответствующий действию
        foreach ($methods as $candidate) {
            if (strtolower($candidate) === $lowerAction) {
                $action = $candidate;
                $found = true;
                break;
            }
        }

        // Если действие найдено, возвращаем его
        if ($found) {
            return $action;
        }

        // Если действие не является публичным методом, возвращаем false
        if (method_exists($controllerClass, $action)) {
            return false;
        }

        // Если в классе контроллера есть метод __call, возвращаем действие
        if (method_exists($controllerClass, '__call')) {
            return $action;
        }

        // В противном случае возвращаем false
        return false;
    }

    /**
     * @param mixed $data
     * @return string
     */
    protected static function stringify(mixed $data): string
    {
        $type = gettype($data);
        switch ($type) {
            case 'string':
                return $data;
            case 'boolean':
                return $data ? 'true' : 'false';
            case 'NULL':
                return 'NULL';
            case 'array':
                return 'Array';
            case 'object':
                if (!method_exists($data, '__toString')) {
                    return 'Object';
                }
        }

        return (string)$data;
    }
}
