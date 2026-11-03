<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * starts_at/ends_at — моменты времени (timestamptz), а не «стенные часы»:
 * литерал со смещением в SQL сравнивается корректно, сессия базы работает в UTC.
 */
class BookingTimeStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_sql_literal_with_offset_matches_stored_instant(): void
    {
        Booking::factory()->create([
            'starts_at' => '2026-11-10 03:00:00',
            'ends_at' => '2026-11-10 04:00:00',
        ]);

        $found = Booking::whereRaw('starts_at = ?', ['2026-11-10T10:00:00+07:00'])->exists();

        $this->assertTrue($found);
    }

    public function test_booking_sent_with_any_offset_is_read_back_as_the_same_instant(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $room = Room::factory()->for(Office::factory()->novosibirsk())->create();

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-10T10:00:00+07:00',
            'ends_at' => '2026-11-10T11:00:00+07:00',
        ])->assertCreated();

        $this->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.starts_at', '2026-11-10T03:00:00.000000Z')
            ->assertJsonPath('data.0.ends_at', '2026-11-10T04:00:00.000000Z');
    }

    public function test_database_session_runs_in_utc(): void
    {
        $this->assertSame('UTC', DB::selectOne('show timezone')->TimeZone);
    }
}
