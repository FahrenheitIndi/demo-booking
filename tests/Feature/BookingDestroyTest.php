<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_cancels_own_booking_and_gets_204(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->deleteJson("/api/bookings/{$booking->id}")->assertNoContent();

        $this->assertModelMissing($booking);
    }

    public function test_returns_403_for_someone_elses_booking(): void
    {
        $booking = Booking::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson("/api/bookings/{$booking->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Отменить бронь может только её автор');

        $this->assertModelExists($booking);
    }

    public function test_returns_404_for_unknown_booking(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/bookings/999')->assertNotFound();
    }

    public function test_returns_401_without_token(): void
    {
        $booking = Booking::factory()->create();

        $this->deleteJson("/api/bookings/{$booking->id}")->assertUnauthorized();

        $this->assertModelExists($booking);
    }
}
