<?php

namespace Tests\Unit;

use App\Exceptions\RoomUnavailableException;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;

class RoomUnavailableExceptionTest extends TestCase
{
    public function test_maps_violation_of_no_overlap_constraint(): void
    {
        $queryException = $this->queryException('23P01', 'conflicting key value violates exclusion constraint "bookings_no_overlap"');

        $exception = RoomUnavailableException::fromQueryException($queryException);

        $this->assertInstanceOf(RoomUnavailableException::class, $exception);
        $this->assertSame($queryException, $exception->getPrevious());
    }

    public function test_ignores_violation_of_another_exclusion_constraint(): void
    {
        $queryException = $this->queryException('23P01', 'conflicting key value violates exclusion constraint "other_constraint"');

        $this->assertNull(RoomUnavailableException::fromQueryException($queryException));
    }

    public function test_ignores_unique_violation_mentioning_constraint_name(): void
    {
        $queryException = $this->queryException('23505', 'duplicate key value violates unique constraint "bookings_no_overlap"');

        $this->assertNull(RoomUnavailableException::fromQueryException($queryException));
    }

    private function queryException(string $sqlState, string $message): QueryException
    {
        $pdoException = new class($message, $sqlState) extends PDOException
        {
            public function __construct(string $message, string $sqlState)
            {
                parent::__construct($message);
                $this->code = $sqlState;
            }
        };

        return new QueryException('pgsql', 'insert into bookings', [], $pdoException);
    }
}
