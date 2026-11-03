<?php

namespace App\Http\Requests;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
     * Бизнес-правила, которым нужны уже распарсенные даты: проверяются после базовых правил.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            $this->validateDurationAndStep(...),
            $this->validateWorkingHours(...),
        ];
    }

    private function validateWorkingHours(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['room_id', 'starts_at', 'ends_at'])) {
            return;
        }

        $office = $this->room()->office;

        if (! $office->coversWorkingHours($this->startsAt(), $this->endsAt())) {
            $validator->errors()->add(
                'starts_at',
                'Бронь должна укладываться в рабочее время '.Office::OPENS_AT.'–'.Office::CLOSES_AT." по времени офиса {$office->name}",
            );
        }
    }

    private function validateDurationAndStep(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['starts_at', 'ends_at'])) {
            return;
        }

        $startsAt = $this->startsAt();
        $endsAt = $this->endsAt();
        $step = Booking::STEP_MINUTES;

        if (! $this->isOnGrid($startsAt)) {
            $validator->errors()->add('starts_at', "Время начала должно быть кратно {$step} минутам (10:00, 10:15…)");
        }

        if (! $this->isOnGrid($endsAt)) {
            $validator->errors()->add('ends_at', "Время окончания должно быть кратно {$step} минутам (10:00, 10:15…)");
        }

        if ($validator->errors()->hasAny(['starts_at', 'ends_at'])) {
            return;
        }

        if ($startsAt->diffInMinutes($endsAt) > Booking::MAX_DURATION_MINUTES) {
            $validator->errors()->add('ends_at', 'Максимальная длительность '.(Booking::MAX_DURATION_MINUTES / 60).' часа');
        }
    }

    private function isOnGrid(CarbonImmutable $moment): bool
    {
        return $moment->second === 0 && $moment->minute % Booking::STEP_MINUTES === 0;
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
