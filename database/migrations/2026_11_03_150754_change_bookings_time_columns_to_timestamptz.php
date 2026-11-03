<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Начало и конец брони — моменты времени, а не «стенные часы». timestamptz
 * хранит те же 8 байт, но литералы со смещением в SQL трактует правильно,
 * а timestamp молча отбрасывал смещение. Данные были в UTC, поэтому
 * конвертация AT TIME ZONE 'UTC' не меняет ни одного момента.
 * Ограничение пересобирается, потому что tsrange не принимает timestamptz.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT bookings_no_overlap');

        Schema::table('bookings', function (Blueprint $table) {
            $table->timestampTz('starts_at')->using("starts_at AT TIME ZONE 'UTC'")->change();
            $table->timestampTz('ends_at')->using("ends_at AT TIME ZONE 'UTC'")->change();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE bookings
            ADD CONSTRAINT bookings_no_overlap
            EXCLUDE USING gist (room_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT bookings_no_overlap');

        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('starts_at')->using("starts_at AT TIME ZONE 'UTC'")->change();
            $table->timestamp('ends_at')->using("ends_at AT TIME ZONE 'UTC'")->change();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE bookings
            ADD CONSTRAINT bookings_no_overlap
            EXCLUDE USING gist (room_id WITH =, tsrange(starts_at, ends_at, '[)') WITH &&)
        SQL);
    }
};
