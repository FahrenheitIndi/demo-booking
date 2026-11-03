<?php

namespace App\Http\Controllers;

use App\Exceptions\RoomUnavailableException;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(StoreBookingRequest $request): JsonResponse
    {
        try {
            // Пересечение отсекает ограничение БД, а не предварительный SELECT: так нет гонки
            // между проверкой и вставкой. Транзакция нужна, чтобы отклонённый INSERT откатился
            // до savepoint и не ломал внешнюю транзакцию (например, в тестах с RefreshDatabase).
            $booking = DB::transaction(fn (): Booking => Booking::create([
                'room_id' => $request->room()->id,
                'user_id' => $request->user()->id,
                'title' => $request->validated('title'),
                'starts_at' => $request->startsAt(),
                'ends_at' => $request->endsAt(),
            ]));
        } catch (QueryException $e) {
            throw RoomUnavailableException::fromQueryException($e) ?? $e;
        }

        Log::channel('bookings')->info('Booking created', [
            'booking_id' => $booking->id,
            'user_id' => $booking->user_id,
            'room_id' => $booking->room_id,
        ]);

        return response()->json($booking, 201);
    }

    public function destroy($id)
    {
        Booking::findOrFail($id)->delete();

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at.' – '.$b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}
