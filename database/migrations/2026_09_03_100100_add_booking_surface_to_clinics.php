<?php

use App\Support\Registration\ClinicBookingSurface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', function (Blueprint $table): void {
            $table->string('booking_surface', 32)
                ->default(ClinicBookingSurface::OUTPATIENT)
                ->after('name');
            $table->index('booking_surface');
        });

        DB::table('clinics')->where('code', 'IGD')->update([
            'booking_surface' => ClinicBookingSurface::EMERGENCY,
        ]);

        DB::table('clinics')->whereIn('code', ['ANESTESI', 'RAD', 'PK'])->update([
            'booking_surface' => ClinicBookingSurface::SUPPORTING,
        ]);
    }

    public function down(): void
    {
        Schema::table('clinics', function (Blueprint $table): void {
            $table->dropIndex(['booking_surface']);
            $table->dropColumn('booking_surface');
        });
    }
};
