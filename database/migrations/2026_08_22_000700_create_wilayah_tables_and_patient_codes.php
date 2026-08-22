<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wilayah_provinces', function (Blueprint $table): void {
            $table->string('code', 16)->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('wilayah_regencies', function (Blueprint $table): void {
            $table->string('code', 16)->primary();
            $table->string('province_code', 16);
            $table->string('name');
            $table->timestamps();

            $table->foreign('province_code')->references('code')->on('wilayah_provinces')->cascadeOnDelete();
            $table->index('province_code');
        });

        Schema::create('wilayah_districts', function (Blueprint $table): void {
            $table->string('code', 16)->primary();
            $table->string('regency_code', 16);
            $table->string('name');
            $table->timestamps();

            $table->foreign('regency_code')->references('code')->on('wilayah_regencies')->cascadeOnDelete();
            $table->index('regency_code');
        });

        Schema::create('wilayah_villages', function (Blueprint $table): void {
            $table->string('code', 16)->primary();
            $table->string('district_code', 16);
            $table->string('name');
            $table->timestamps();

            $table->foreign('district_code')->references('code')->on('wilayah_districts')->cascadeOnDelete();
            $table->index('district_code');
        });

        Schema::table('patients', function (Blueprint $table): void {
            $table->string('province_code', 16)->nullable()->after('occupation');
            $table->string('city_code', 16)->nullable()->after('province');
            $table->string('district_code', 16)->nullable()->after('city');
            $table->string('village_code', 16)->nullable()->after('district');

            $table->index('province_code');
            $table->index('city_code');
            $table->index('district_code');
            $table->index('village_code');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropColumn([
                'province_code',
                'city_code',
                'district_code',
                'village_code',
            ]);
        });

        Schema::dropIfExists('wilayah_villages');
        Schema::dropIfExists('wilayah_districts');
        Schema::dropIfExists('wilayah_regencies');
        Schema::dropIfExists('wilayah_provinces');
    }
};