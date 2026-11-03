<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingNoOverlapConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_rejects_overlapping_bookings_in_same_room(): void
    {
        $room = Room::factory()->create();
        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-10 10:00:00',
            'ends_at' => '2026-11-10 11:00:00',
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('bookings_no_overlap');

        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-10 10:30:00',
            'ends_at' => '2026-11-10 11:30:00',
        ]);
    }

    public function test_database_allows_back_to_back_bookings_in_same_room(): void
    {
        $room = Room::factory()->create();

        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-10 10:00:00',
            'ends_at' => '2026-11-10 11:00:00',
        ]);
        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-10 11:00:00',
            'ends_at' => '2026-11-10 12:00:00',
        ]);

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_database_allows_overlapping_bookings_in_different_rooms(): void
    {
        Booking::factory()->create([
            'starts_at' => '2026-11-10 10:00:00',
            'ends_at' => '2026-11-10 11:00:00',
        ]);
        Booking::factory()->create([
            'starts_at' => '2026-11-10 10:30:00',
            'ends_at' => '2026-11-10 11:30:00',
        ]);

        $this->assertDatabaseCount('bookings', 2);
    }
}
