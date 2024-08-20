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

namespace Triangle\Exception;

use Throwable;
use Triangle\Ws\Request;
use Triangle\Ws\Response;

interface ExceptionHandlerInterface
{
    /**
     * Отчет об исключении
     * Этот метод вызывается для обработки исключения.
     * @param Throwable $exception Исключение, которое нужно обработать
     * @return void
     */
    public function report(Throwable $exception): void;

    /**
     * Рендеринг исключения
     * Этот метод вызывается для отображения исключения пользователю.
     * @param Request $request Текущий HTTP-запрос
     * @param Throwable $exception Исключение, которое нужно отобразить
     * @return Response Ответ, который следует отправить пользователю
     */
    public function render(Request $request, Throwable $exception): Response;
}
