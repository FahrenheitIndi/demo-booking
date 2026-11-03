<?php

namespace App\Http\Requests;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBookingRequest extends FormRequest
{
    private const string TIME_FORMAT_MESSAGE = 'Время должно быть в формате ISO 8601 со смещением, например 2026-11-10T10:00:00+03:00';

    private ?Room $room = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string|Closure>>
     */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['bail', 'required', 'date', $this->requireOffset(...)],
            'ends_at' => ['bail', 'required', 'date', $this->requireOffset(...)],
        ];
    }

    /**
     * Без смещения строка была бы молча прочитана как UTC и бронь сдвинулась бы
     * на разницу с поясом клиента. Лучше честный 422. Это ошибка контракта,
     * адресована разработчику клиента, а не пользователю формы.
     */
    private function requireOffset(string $attribute, mixed $value, Closure $fail): void
    {
        if (! isset(date_parse((string) $value)['zone_type'])) {
            $fail(self::TIME_FORMAT_MESSAGE);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'room_id.required' => 'Укажите комнату',
            'room_id.integer' => 'Идентификатор комнаты должен быть числом',
            'room_id.exists' => 'Такой комнаты нет',
            'title.required' => 'Укажите название встречи',
            'title.string' => 'Название должно быть строкой',
            'title.max' => 'Название не длиннее :max символов',
            'starts_at.required' => 'Укажите время начала',
            'starts_at.date' => self::TIME_FORMAT_MESSAGE,
            'ends_at.required' => 'Укажите время окончания',
            'ends_at.date' => self::TIME_FORMAT_MESSAGE,
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
            $this->validateEndIsAfterStart(...),
            $this->validateDurationAndStep(...),
            $this->validateWorkingHours(...),
        ];
    }

    /**
     * Не правило after:starts_at в rules(): оно сравнивало бы ends_at с ещё
     * не проверенной строкой starts_at и давало ложную вторую ошибку.
     */
    private function validateEndIsAfterStart(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['starts_at', 'ends_at'])) {
            return;
        }

        if ($this->endsAt()->lessThanOrEqualTo($this->startsAt())) {
            $validator->errors()->add('ends_at', 'Время окончания должно быть позже начала');
        }
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
