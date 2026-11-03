<?php

namespace App\Http\Requests;

use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    private ?Room $room = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ];
    }

    /**
     * Начало брони в UTC. Клиент присылает ISO 8601 с любым смещением,
     * нормализуем здесь, чтобы cast модели не отбросил смещение молча.
     */
    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->input('starts_at'))->utc();
    }

    public function endsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->input('ends_at'))->utc();
    }

    public function room(): Room
    {
        return $this->room ??= Room::with('office')->findOrFail($this->integer('room_id'));
    }
}
