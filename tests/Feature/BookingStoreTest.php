<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
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
            ->assertJsonValidationErrors([
                'room_id' => 'Укажите комнату',
                'title' => 'Укажите название встречи',
                'starts_at' => 'Укажите время начала',
                'ends_at' => 'Укажите время окончания',
            ]);
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_returns_422_for_unknown_room(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postBooking(['room_id' => 999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id' => 'Такой комнаты нет']);
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
            ->assertJsonValidationErrors(['ends_at' => 'позже начала']);
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_returns_422_for_malformed_date(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postBooking(['starts_at' => 'завтра'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at' => 'ISO 8601']);
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
     * @return array<string, array{string, string, string, string}>
     */
    public static function invalidDurationsAndSteps(): array
    {
        return [
            'start not on 15-minute grid' => ['2026-11-10T10:05:00+03:00', '2026-11-10T11:05:00+03:00', 'starts_at', 'кратно 15 минутам'],
            'start with seconds' => ['2026-11-10T10:00:30+03:00', '2026-11-10T11:00:30+03:00', 'starts_at', 'кратно 15 минутам'],
            'end not on 15-minute grid' => ['2026-11-10T10:00:00+03:00', '2026-11-10T10:50:00+03:00', 'ends_at', 'кратно 15 минутам'],
            'shorter than 15 minutes' => ['2026-11-10T10:00:00+03:00', '2026-11-10T10:10:00+03:00', 'ends_at', 'кратно 15 минутам'],
            'longer than 4 hours' => ['2026-11-10T10:00:00+03:00', '2026-11-10T14:15:00+03:00', 'ends_at', 'Максимальная длительность 4 часа'],
        ];
    }

    #[DataProvider('invalidDurationsAndSteps')]
    public function test_returns_422_for_invalid_duration_or_step(string $startsAt, string $endsAt, string $field, string $message): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postBooking(['starts_at' => $startsAt, 'ends_at' => $endsAt])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field => $message]);
        $this->assertDatabaseCount('bookings', 0);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function validDurationsAndSteps(): array
    {
        return [
            'exactly 15 minutes' => ['2026-11-10T10:00:00+03:00', '2026-11-10T10:15:00+03:00'],
            'exactly 4 hours' => ['2026-11-10T10:00:00+03:00', '2026-11-10T14:00:00+03:00'],
            'start at :45' => ['2026-11-10T10:45:00+03:00', '2026-11-10T11:30:00+03:00'],
            'half-hour client offset' => ['2026-11-10T14:00:00+05:30', '2026-11-10T15:00:00+05:30'],
        ];
    }

    #[DataProvider('validDurationsAndSteps')]
    public function test_allows_valid_duration_and_step(string $startsAt, string $endsAt): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postBooking(['starts_at' => $startsAt, 'ends_at' => $endsAt])->assertCreated();

        $this->assertDatabaseCount('bookings', 1);
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function bookingsOutsideWorkingHours(): array
    {
        return [
            'moscow before opening' => ['2026-11-10T08:45:00+03:00', '2026-11-10T09:45:00+03:00', false],
            'moscow ending after closing' => ['2026-11-10T19:30:00+03:00', '2026-11-10T20:15:00+03:00', false],
            'moscow at 06:00 local sent with moscow offset' => ['2026-11-10T06:00:00+03:00', '2026-11-10T07:00:00+03:00', false],
            'novosibirsk night even though utc day is working' => ['2026-11-10T22:00:00Z', '2026-11-10T23:00:00Z', true],
        ];
    }

    #[DataProvider('bookingsOutsideWorkingHours')]
    public function test_returns_422_outside_office_working_hours(string $startsAt, string $endsAt, bool $inNovosibirsk): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postBooking([
            'room_id' => $this->room($inNovosibirsk)->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at' => 'рабочее время 09:00–20:00']);
        $this->assertDatabaseCount('bookings', 0);
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function bookingsInsideWorkingHours(): array
    {
        return [
            'moscow at opening' => ['2026-11-10T09:00:00+03:00', '2026-11-10T10:00:00+03:00', false],
            'moscow ending at closing' => ['2026-11-10T19:00:00+03:00', '2026-11-10T20:00:00+03:00', false],
            'novosibirsk 10:00 local sent with moscow offset' => ['2026-11-10T06:00:00+03:00', '2026-11-10T07:00:00+03:00', true],
            'novosibirsk 09:00 local sent in utc' => ['2026-11-10T02:00:00Z', '2026-11-10T03:00:00Z', true],
        ];
    }

    #[DataProvider('bookingsInsideWorkingHours')]
    public function test_allows_booking_inside_office_working_hours(string $startsAt, string $endsAt, bool $inNovosibirsk): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postBooking([
            'room_id' => $this->room($inNovosibirsk)->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ])->assertCreated();

        $this->assertDatabaseCount('bookings', 1);
    }

    /**
     * Существующая бронь 10:00–11:00 по Москве (07:00–08:00 UTC в базе), запрос с московским смещением.
     *
     * @return array<string, array{string, string}>
     */
    public static function overlappingRequests(): array
    {
        return [
            'starts inside existing' => ['2026-11-10T10:30:00+03:00', '2026-11-10T11:30:00+03:00'],
            'ends inside existing' => ['2026-11-10T09:30:00+03:00', '2026-11-10T10:30:00+03:00'],
            'inside existing' => ['2026-11-10T10:15:00+03:00', '2026-11-10T10:45:00+03:00'],
            'covers existing' => ['2026-11-10T09:00:00+03:00', '2026-11-10T12:00:00+03:00'],
            'same slot' => ['2026-11-10T10:00:00+03:00', '2026-11-10T11:00:00+03:00'],
        ];
    }

    #[DataProvider('overlappingRequests')]
    public function test_returns_409_when_room_is_already_booked(string $startsAt, string $endsAt): void
    {
        Sanctum::actingAs(User::factory()->create());
        $room = $this->room();
        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-10 07:00:00',
            'ends_at' => '2026-11-10 08:00:00',
        ]);

        $this->postBooking(['room_id' => $room->id, 'starts_at' => $startsAt, 'ends_at' => $endsAt])
            ->assertConflict()
            ->assertJsonPath('message', 'Комната занята в это время');
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_allows_back_to_back_bookings_in_same_room(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $room = $this->room();
        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-10 07:00:00',
            'ends_at' => '2026-11-10 08:00:00',
        ]);

        $this->postBooking([
            'room_id' => $room->id,
            'starts_at' => '2026-11-10T11:00:00+03:00',
            'ends_at' => '2026-11-10T12:00:00+03:00',
        ])->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_allows_same_time_in_another_room(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Booking::factory()->for($this->room())->create([
            'starts_at' => '2026-11-10 07:00:00',
            'ends_at' => '2026-11-10 08:00:00',
        ]);

        $this->postBooking([
            'room_id' => $this->room()->id,
            'starts_at' => '2026-11-10T10:00:00+03:00',
            'ends_at' => '2026-11-10T11:00:00+03:00',
        ])->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    private function room(bool $inNovosibirsk = false): Room
    {
        $office = $inNovosibirsk ? Office::factory()->novosibirsk() : Office::factory();

        return Room::factory()->for($office)->create();
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
