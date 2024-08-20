<?php declare(strict_types=1);

/**
 * @package     Triangle HTTP Component
 * @link        https://github.com/Triangle-org/Http
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

namespace Triangle\Exception;

use Psr\Log\LoggerInterface;
use Throwable;
use Triangle\Ws\Request;
use Triangle\Ws\Response;
use function nl2br;
use function responseJson;
use function request;

/**
 * Class Events
 */
class ExceptionHandler implements ExceptionHandlerInterface
{
    /**
     * Не сообщать об исключениях этих типов
     * @var array
     */
    public array $dontReport = [BusinessException::class];

    /**
     * @var LoggerInterface|null
     */
    protected ?LoggerInterface $logger = null;


    /**
     * Конструктор обработчика исключений.
     * @param LoggerInterface|null $logger
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Отчет об исключении
     * @param Throwable $exception
     * @return void
     */
    public function report(Throwable $exception): void
    {
        if ($this->shouldnt($exception, config('exception.dont_report') ?: $this->dontReport)) {
            return;
        }

        $context = [];

        try {
            if ($request = request()) {
                $context['request'] = $request->toArray();
            }
        } catch (Throwable) {
        }

        $this->logger->error($exception->getMessage(), $context);
    }

    /**
     * Проверка, следует ли игнорировать исключение
     * @param Throwable $e
     * @param array $exceptions
     * @return bool
     */
    protected function shouldnt(Throwable $e, array $exceptions): bool
    {
        foreach ($exceptions as $type) {
            if ($e instanceof $type) {
                return true;
            }
        }
        return false;
    }

    /**
     * Рендеринг исключения
     * @param Request $request
     * @param Throwable $exception
     * @return Response
     * @throws Throwable
     */
    public function render(Request $request, Throwable $exception): Response
    {
        if (method_exists($exception, 'render')) {
            return $exception->render($request);
        }

        return response($exception->getMessage(), $exception->getCode() ?: 500);
    }
}
