<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OfficeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'timezone'])]
class Office extends Model
{
    /** @use HasFactory<OfficeFactory> */
    use HasFactory;

    public const string OPENS_AT = '09:00';

    public const string CLOSES_AT = '20:00';

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /**
     * Укладывается ли интервал в рабочее время офиса по его местному времени.
     * Закрытие берётся в местный день начала, поэтому переход через полночь тоже отсекается.
     */
    public function coversWorkingHours(CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        $localStart = $startsAt->setTimezone($this->timezone);
        $localEnd = $endsAt->setTimezone($this->timezone);

        $opensAt = $localStart->setTimeFromTimeString(self::OPENS_AT);
        $closesAt = $localStart->setTimeFromTimeString(self::CLOSES_AT);

        return $localStart->greaterThanOrEqualTo($opensAt) && $localEnd->lessThanOrEqualTo($closesAt);
    }
}
