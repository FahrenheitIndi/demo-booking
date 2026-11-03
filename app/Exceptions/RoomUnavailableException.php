<?php

namespace App\Exceptions;

use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Комната уже занята на запрошенный интервал. Единственный источник этого
 * правила — exclusion constraint bookings_no_overlap в PostgreSQL, поэтому
 * исключение рождается из ошибки БД, а не из предварительной проверки.
 */
class RoomUnavailableException extends RuntimeException
{
    private const string EXCLUSION_VIOLATION_SQLSTATE = '23P01';

    private const string CONSTRAINT_NAME = 'bookings_no_overlap';

    public static function fromQueryException(QueryException $exception): ?self
    {
        $isNoOverlapViolation = $exception->getCode() === self::EXCLUSION_VIOLATION_SQLSTATE
            && str_contains($exception->getMessage(), self::CONSTRAINT_NAME);

        return $isNoOverlapViolation ? new self('Комната занята в это время', previous: $exception) : null;
    }
}
