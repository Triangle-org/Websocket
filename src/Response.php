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

namespace Triangle\Ws;

use Throwable;
use function filemtime;
use function gmdate;


/**
 * Класс Response
 * Этот класс представляет собой пользовательский ответ, который наследует от базового класса Http\Response.
 *
 * @link https://www.php.net/manual/en/class.httpresponse.php
 */
class Response extends \localzet\Server\Protocols\Http\Response
{
    /**
     * @var Throwable|null $exception Исключение, связанное с ответом.
     */
    protected ?Throwable $exception = null;

    /**
     * Получить или установить исключение.
     *
     * @param Throwable|null $exception Исключение для установки.
     * @return Throwable|null
     */
    public function exception(Throwable $exception = null): ?Throwable
    {
        if ($exception) {
            $this->exception = $exception;
        }
        return $this->exception;
    }
}

