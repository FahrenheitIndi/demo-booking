<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_request_creates_booking_and_returns_201(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postBooking([
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-10T10:00:00+03:00',
            'ends_at' => '2026-11-10T11:00:00+03:00',
        ]);

        $response->assertCreated()
            ->assertJsonPath('room_id', $room->id)
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('title', 'Планёрка');
        $this->assertDatabaseHas('bookings', [
            'room_id' => $room->id,
            'user_id' => $user->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-10 07:00:00',
            'ends_at' => '2026-11-10 08:00:00',
        ]);
    }

    public function test_ignores_user_id_from_request_body(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postBooking(['user_id' => $otherUser->id])->assertCreated();

        $this->assertDatabaseHas('bookings', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('bookings', ['user_id' => $otherUser->id]);
    }

    public function test_returns_401_without_token(): void
    {
        $this->postJson('/api/bookings', [])->assertUnauthorized();
    }

    public function test_returns_422_for_empty_payload(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id', 'title', 'starts_at', 'ends_at']);
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_returns_422_for_unknown_room(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postBooking(['room_id' => 999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id']);
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_returns_422_when_end_is_not_after_start(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postBooking([
            'starts_at' => '2026-11-10T11:00:00+03:00',
            'ends_at' => '2026-11-10T10:00:00+03:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_at']);
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_returns_422_for_malformed_date(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postBooking(['starts_at' => 'завтра'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_allows_booking_in_the_past(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postBooking([
            'starts_at' => '2020-03-02T10:00:00+03:00',
            'ends_at' => '2020-03-02T11:00:00+03:00',
        ])->assertCreated();

        $this->assertDatabaseCount('bookings', 1);
    }

    /**
     * Валидный запрос на комнату в Москве; отдельные поля переопределяются.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function postBooking(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/bookings', array_merge([
            'room_id' => Room::factory()->create()->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-10T10:00:00+03:00',
            'ends_at' => '2026-11-10T11:00:00+03:00',
        ], $overrides));
    }
}
