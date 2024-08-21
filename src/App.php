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
use Triangle\Exception\ExceptionHandler;
use Triangle\Exception\ExceptionHandlerInterface;
use Triangle\Middleware\Bootstrap as Middleware;
use Triangle\Middleware\MiddlewareInterface;
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
class App
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
        Http::requestClass(static::$requestClass);
        Autoload::loadAll($server);
    }

    /**
     * @param TcpConnection $connection
     * @param Http\Request $request
     * @return void
     * @throws Throwable
     */
    public function onWebsocketConnect(TcpConnection &$connection, Http\Request $request): void
    {
        try {
            $connection->uuid = generateId();
            $path = $request->path();

            // Проверка безопасности URL
            if (!$path ||
                str_contains($path, '..') ||
                str_contains($path, "\\") ||
                str_contains($path, "\0")
            ) {
                Server::log('Небезопасный URL: ' . $path . PHP_EOL);
                static::close_http($connection, 422);
                return;
            }

            // Проверка на 404 и 405
            $routeInfo = Router::dispatch('GET', $request->path());
            switch ($routeInfo[0]) {
                case Dispatcher::FOUND:
                    $routeInfo[0] = 'route';
                    $callback = $routeInfo[1];
                    $app = $controller = $action = '';
                    $args = !empty($routeInfo[2]) ? $routeInfo[2] : null;
                    $route = clone $routeInfo[3];

                    if ($args) {
                        $route->setParams($args);
                    }

                    if (is_array($callback)) {
                        $controller = $callback[0];
                        $plugin = static::getPluginByClass($controller);
                        $app = static::getAppByController($controller);
                        $action = static::getRealMethod($controller, $callback[1]) ?? '';
                    } else {
                        $plugin = static::getPluginByPath($path);
                    }

                    $callback = static::getCallback($plugin, $app, $callback, $args);
                    static::$callbacks[$path] = [$callback, $plugin, $app, $controller ?: '', $action, $route];
                    if (count(static::$callbacks) >= 1024) {
                        unset(static::$callbacks[key(static::$callbacks)]);
                    }
                    break;
                case Dispatcher::NOT_FOUND:
                    // Парсим контроллер и действие из пути
                    $controllerAndAction = static::parseControllerAction($path);
                    $plugin = $controllerAndAction['plugin'] ?? static::getPluginByPath($path);

                    // Получаем приложение, контроллер и действие
                    $app = $controllerAndAction['app'];
                    $controller = $controllerAndAction['controller'];
                    $action = $controllerAndAction['action'];

                    // Получаем обратный вызов
                    $callback = static::getCallback($plugin, $app, [$controller, $action]);
                    static::$callbacks[$path] = [$callback, $plugin, $app, $controller, $action, null];
                    if (count(static::$callbacks) >= 1024) {
                        unset(static::$callbacks[key(static::$callbacks)]);
                    }

                    Server::log('Не найден URL: ' . $path . PHP_EOL);
                    static::close_http($connection, 404);
                    return;
                case Dispatcher::METHOD_NOT_ALLOWED:
                    Server::log('Метот GET не поддержиается для ' . $path . PHP_EOL);
                    static::close_http($connection, 405);
                    return;
            }

            if (!isset(static::$connectionsMap[$path])) {
                static::$connectionsMap[$path] = [$connection->uuid => $connection];
            } else {
                static::$connectionsMap[$path][$connection->uuid] = $connection;
            }
            Server::log('Рукопожатие успешно: ' . $path . PHP_EOL);
        } catch (Throwable $e) {
            static::close_http($connection, static::exceptionResponse($e, $request));
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
    public function onMessage(mixed $connection, mixed $request): void
    {
        try {
            $buffer = $request;
            /** @var Request $request */
            $request = $connection->request;
            $request->ws_buffer = $buffer;

            // Устанавливаем контекст для соединения и запроса
            Context::set(TcpConnection::class, $connection);
            Context::set(Request::class, $connection->request);

            // Получаем путь из запроса
            $path = $request->path();

            // Если для данного ключа уже есть обратные вызовы
            if (isset(static::$callbacks[$path])) {
                // Получаем обратные вызовы
                [$callback, $request->plugin, $request->app, $request->controller, $request->action, $request->route] = static::$callbacks[$path];

                // Отправляем обратный вызов
                static::send($connection, $callback($request));
                return;
            }

            static::close($connection, 404);
        } catch (Throwable $e) {
            // Если возникло исключение, отправляем ответ на исключение
            static::send($connection, static::exceptionResponse($e, $request));
        } finally {
            Context::delete(TcpConnection::class);
            Context::delete(Request::class);
        }
    }

    /**
     * @param string|Response|null $data
     * @param bool $excludeCurrent
     * @return void
     * @throws Throwable
     */
    public static function sendToAll(string|Response|null $data = null, bool $excludeCurrent = false): void
    {
        foreach (static::$server::getAllServers() as $server) {
            foreach ($server->connections as $id => $connection) {
                if ($excludeCurrent && $connection->uuid === static::connection()->uuid) continue;
                static::send($connection, $data);
            }
        }
    }

    /**
     * @param string|Response|null $data
     * @param bool $excludeCurrent
     * @return void
     * @throws Throwable
     */
    public static function sendToGroup(string|Response|null $data = null, bool $excludeCurrent = false): void
    {
        $path = static::connection()->request->path();
        foreach (static::$connectionsMap[$path] ?? [] as $uuid => $connection) {
            if ($excludeCurrent && $uuid === static::connection()->uuid) continue;
            static::send($connection, $data);
        }
    }

    /**
     * @param TcpConnection|mixed $connection
     * @param string|Response|null $data
     * @throws Throwable
     */
    protected static function send(TcpConnection $connection, string|Response|null $data = null): void
    {
        $connection->send($data instanceof Http\Response ? $data->rawBody() : $data);
    }

    /**
     * @param TcpConnection $connection
     * @param int $status
     * @param mixed|null $data
     * @return void
     * @throws Throwable
     */
    public static function close(TcpConnection $connection, int $status = 204, mixed $data = null): void
    {
        $connection->close(json(['status' => $status, 'data' => $data ?? Http\Response::PHRASES[$status]]));
    }

    /**
     * @param TcpConnection $connection
     * @param int|Response $status
     * @param mixed|null $data
     * @return void
     * @throws Throwable
     */
    public static function close_http(TcpConnection $connection, int|Response $status = 204, mixed $data = null): void
    {
        if ($status instanceof Response) {
            if ($e = $status->exception()) {
                $data = $e->getMessage();
            } else {
                $data = $status->rawBody();
            }
            $status = $status->getStatusCode();
        }
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
        try {
            // Получаем приложение и плагин из запроса
            $app = $request->app ?: '';
            $plugin = $request->plugin ?: '';
            // Получаем конфигурацию исключений
            $exceptionConfig = static::config($plugin, 'exception');
            // Получаем класс обработчика исключений по умолчанию
            $defaultException = $exceptionConfig[''] ?? ExceptionHandler::class;
            // Получаем класс обработчика исключений для приложения
            $exceptionHandlerClass = $exceptionConfig[$app] ?? $defaultException;

            // Создаем экземпляр обработчика исключений
            /** @var ExceptionHandlerInterface $exceptionHandler */
            $exceptionHandler = static::container($plugin)->make($exceptionHandlerClass, [
                'logger' => static::$logger,
            ]);
            // Отправляем отчет об исключении
            $exceptionHandler->report($e);
            // Создаем ответ на исключение
            $response = $exceptionHandler->render($request, $e);
            $response->exception($e);
            return $response;
        } catch (Throwable $e) {
            // Если возникло исключение при обработке исключения, создаем ответ с кодом 500
            $response = new Response(500, [], static::config($plugin ?? '', 'app.debug') ? (string)$e : $e->getMessage());
            $response->exception($e);
            return $response;
        }
    }

    /**
     * @return TcpConnection|null
     */
    public static function connection(): TcpConnection|null
    {
        return Context::get(TcpConnection::class);
    }

    /**
     * @return Request|null
     */
    public static function request(): Request|null
    {
        return Context::get(Request::class);
    }

    /**
     * @return Server|null
     */
    public static function server(): ?Server
    {
        return static::$server;
    }

    /**
     * Конфигурация
     * @param string $plugin
     * @param string $key
     * @param $default
     * @return array|mixed|null
     */
    protected static function config(string $plugin, string $key, $default = null): mixed
    {
        return Config::get($plugin ? "plugin.$plugin.$key" : $key, $default);
    }

    /**
     * @param string $plugin
     * @return ContainerInterface|array|null
     */
    public static function container(string $plugin = ''): ContainerInterface|array|null
    {
        return static::config($plugin, 'container');
    }

    /**
     * Функция для получения обратного вызова.
     *
     * @param string $plugin Плагин.
     * @param string $app Приложение.
     * @param mixed $call Вызов.
     * @param array|null $args Аргументы.
     * @param bool $withGlobalMiddleware Использовать глобальное промежуточное ПО.
     * @param RouteObject|null $route Маршрут.
     * @return callable|Closure Возвращает обратный вызов.
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected static function getCallback(string $plugin, string $app, mixed $call, array $args = null, bool $withGlobalMiddleware = true, RouteObject $route = null): callable|Closure
    {
        $args = $args === null ? null : array_values($args);
        $middlewares = [];
        // Если есть маршрут, получаем промежуточное ПО маршрута
        if ($route) {
            $routeMiddlewares = $route->getMiddleware();
            foreach ($routeMiddlewares as $className) {
                $middlewares[] = [$className, 'process'];
            }
        }
        // Добавляем глобальное промежуточное ПО
        $middlewares = array_merge($middlewares, Middleware::getMiddleware($plugin, $app, $withGlobalMiddleware));

        // Создаем экземпляры промежуточного ПО
        foreach ($middlewares as $key => $item) {
            $middleware = $item[0];
            if (is_string($middleware)) {
                $middleware = static::container($plugin)->get($middleware);
            } elseif ($middleware instanceof Closure) {
                $middleware = call_user_func($middleware, static::container($plugin));
            }
            if (!$middleware instanceof MiddlewareInterface) {
                throw new InvalidArgumentException('Not support middleware type');
            }
            $middlewares[$key][0] = $middleware;
        }

        // Проверяем, нужно ли внедрять зависимости в вызов
        $needInject = static::isNeedInject($call, $args);
        if (is_array($call) && is_string($call[0])) {
            $controllerReuse = static::config($plugin, 'app.controller_reuse', true);
            if (!$controllerReuse) {
                if ($needInject) {
                    $call = function ($request, ...$args) use ($call, $plugin) {
                        $call[0] = static::container($plugin)->make($call[0]);
                        $reflector = static::getReflector($call);
                        $args = static::resolveMethodDependencies($plugin, $request, $args, $reflector);
                        return $call(...$args);
                    };
                    $needInject = false;
                } else {
                    $call = function ($request, ...$args) use ($call, $plugin) {
                        $call[0] = static::container($plugin)->make($call[0]);
                        return $call($request, ...$args);
                    };
                }
            } else {
                $call[0] = static::container($plugin)->get($call[0]);
            }
        }

        // Если нужно внедрить зависимости, внедряем их
        if ($needInject) {
            $call = static::resolveInject($plugin, $call);
        }

        // Если есть промежуточное ПО, создаем цепочку вызовов
        if ($middlewares) {
            $callback = array_reduce($middlewares, function ($carry, $pipe) {
                return function ($request) use ($carry, $pipe) {
                    try {
                        return $pipe($request, $carry);
                    } catch (Throwable $e) {
                        return static::exceptionResponse($e, $request);
                    }
                };
            }, function ($request) use ($call, $args) {
                try {
                    if ($request instanceof Request) {
                        $request = $request->getData();
                    }
                    if ($args === null) {
                        $response = $call($request);
                    } else {
                        $response = $call($request, ...$args);
                    }
                } catch (Throwable $e) {
                    return static::exceptionResponse($e, $request);
                }
                if (!$response instanceof Response) {
                    if (!is_string($response)) {
                        $response = static::stringify($response);
                    }
                    $response = new Response(200, [], $response);
                }
                return $response;
            });
        } else {
            // Если нет промежуточного ПО, создаем обратный вызов
            $callback = function ($request) use ($call, $args) {
                try {
                    if ($request instanceof Request) {
                        $request = $request->getData();
                    }
                    if ($args === null) {
                        $response = $call($request);
                    } else {
                        $response = $call($request, ...$args);
                    }
                } catch (Throwable $e) {
                    return static::exceptionResponse($e, $request);
                }
                if (!$response instanceof Response) {
                    if (!is_string($response)) {
                        $response = static::stringify($response);
                    }
                    $response = new Response(200, [], $response);
                }
                return $response;
            };
        }
        return $callback;
    }

    /**
     * Check whether inject is required
     * @param $call
     * @param $args
     * @return bool
     * @throws ReflectionException
     */
    protected static function isNeedInject($call, $args): bool
    {
        if (is_array($call) && !method_exists($call[0], $call[1])) {
            return false;
        }
        $args = $args ?: [];
        $reflector = static::getReflector($call);
        $reflectionParameters = $reflector->getParameters();
        if (!$reflectionParameters) {
            return false;
        }
        $firstParameter = current($reflectionParameters);
        unset($reflectionParameters[key($reflectionParameters)]);
        $adaptersList = ['int', 'string', 'bool', 'array', 'object', 'float', 'mixed', 'resource'];
        foreach ($reflectionParameters as $parameter) {
            if ($parameter->hasType() && !in_array($parameter->getType()->getName(), $adaptersList)) {
                return true;
            }
        }
        if (!$firstParameter->hasType()) {
            return count($args) > count($reflectionParameters);
        }

        if (!is_a(static::$requestClass, $firstParameter->getType()->getName())) {
            return true;
        }

        return false;
    }

    /**
     * Get reflector.
     *
     * @param $call
     * @return ReflectionFunction|ReflectionMethod
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
        // Спецификация информации о параметрах
        $args = array_values($args);
        $parameters = [$request];
        // Массив классов рефлексии для циклических параметров, каждый $parameter представляет собой объект рефлексии параметров
        foreach ($reflector->getParameters() as $parameter) {
            // Переменный параметр
            if (null !== key($args)) {
                $parameters[] = current($args);
            } else {
                // Указывает, имеет ли текущий параметр значение по умолчанию. Если да, возвращает true
                $parameters[] = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
            }
            // Потребление квоты переменных
            next($args);
        }

        // Возвращает результат замены параметров
        return $parameters;
    }

    /**
     * @param string $plugin
     * @param array|Closure $call
     * @return Closure
     * @see Dependency injection through reflection information
     */
    protected static function resolveInject(string $plugin, array|Closure $call): Closure
    {
        return function (mixed $request, ...$args) use ($plugin, $call) {
            $reflector = static::getReflector($call);
            $args = static::resolveMethodDependencies($plugin, $request, $args, $reflector);
            return $call(...$args);
        };
    }

    /**
     * @param string $controllerClass
     * @return string
     */
    public static function getPluginByClass(string $controllerClass): string
    {
        $controllerClass = trim($controllerClass, '\\');
        $tmp = explode('\\', $controllerClass, 3);
        if ($tmp[0] !== 'plugin') {
            return '';
        }
        return $tmp[1] ?? '';
    }

    /**
     * @param string $controllerClass
     * @return mixed|string
     */
    protected static function getAppByController(string $controllerClass): mixed
    {
        $controllerClass = trim($controllerClass, '\\');
        $tmp = explode('\\', $controllerClass, 5);
        $pos = $tmp[0] === 'plugin' ? 3 : 1;
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
     * Функция для получения плагина по пути.
     *
     * @param string $path Путь.
     * @return string Возвращает имя плагина, если он найден, иначе возвращает пустую строку.
     */
    public static function getPluginByPath(string $path): string
    {
        // Удаляем слэши с начала и конца пути
        $path = trim($path, '/');
        // Разбиваем путь на части
        $tmp = explode('/', $path, 3);
        // Если первая часть пути не равна 'app', возвращаем пустую строку
        if ($tmp[0] !== 'app') {
            return '';
        }
        // Возвращаем вторую часть пути (имя плагина) или пустую строку, если она не существует
        return $tmp[1] ?? '';
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

        // Разбиваем путь на части
        $pathExplode = explode('/', trim($path, '/'));

        // Проверяем, является ли путь плагином
        $isPlugin = isset($pathExplode[1]) && $pathExplode[0] === 'app';

        // Получаем префиксы для конфигурации, пути и класса
        $configPrefix = $isPlugin ? "plugin.$pathExplode[1]." : '';
        $pathPrefix = $isPlugin ? "/app/$pathExplode[1]" : '';
        $classPrefix = $isPlugin ? "plugin\\$pathExplode[1]" : '';

        // Получаем суффикс контроллера из конфигурации
        $suffix = Config::get("{$configPrefix}app.controller_suffix", '');

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
                'plugin' => static::getPluginByClass($controllerClass),
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
        $basePath = $explodes[0] === 'plugin' ? Path::basePath('plugin') : app_path();
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
