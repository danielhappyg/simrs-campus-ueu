<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinics', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id')->unique();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('doctors', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->string('name');
            $table->string('specialty')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('clinic_id');
        });

        Schema::create('clinic_schedules', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->string('label');
            $table->string('day_label')->nullable();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['clinic_id', 'doctor_id']);
        });

        Schema::table('patients', function (Blueprint $table): void {
            $table->string('nik', 16)->nullable()->after('medical_record_number');
            $table->string('place_of_birth')->nullable()->after('full_name');
            $table->string('religion')->nullable()->after('sex');
            $table->string('education')->nullable()->after('religion');
            $table->string('occupation')->nullable()->after('education');
            $table->string('province')->nullable()->after('occupation');
            $table->string('city')->nullable()->after('province');
            $table->string('district')->nullable()->after('city');
            $table->string('village')->nullable()->after('district');
            $table->string('address_line')->nullable()->after('village');
            $table->string('domicile')->nullable()->after('address_line');
            $table->string('email')->nullable()->after('phone');
            $table->string('ethnicity')->nullable()->after('email');
            $table->string('language')->nullable()->after('ethnicity');
            $table->text('notes')->nullable()->after('language');
            $table->string('responsible_party_name')->nullable()->after('notes');

            $table->index('nik');
        });

        Schema::table('encounters', function (Blueprint $table): void {
            $table->foreignId('clinic_id')->nullable()->after('clinic_name')->constrained('clinics')->nullOnDelete();
            $table->foreignId('doctor_id')->nullable()->after('clinic_id')->constrained('doctors')->nullOnDelete();
            $table->foreignId('clinic_schedule_id')->nullable()->after('doctor_id')->constrained('clinic_schedules')->nullOnDelete();
            $table->string('doctor_name')->nullable()->after('clinic_schedule_id');
            $table->string('schedule_label')->nullable()->after('doctor_name');
            $table->date('visit_date')->nullable()->after('schedule_label');
            $table->string('admission_mode')->nullable()->after('visit_date');
            $table->string('insurance_number')->nullable()->after('payer_type');
            $table->string('booking_code')->nullable()->after('insurance_number');
            $table->unsignedInteger('queue_number')->nullable()->after('booking_code');
        });
    }

    public function down(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('clinic_schedule_id');
            $table->dropConstrainedForeignId('doctor_id');
            $table->dropConstrainedForeignId('clinic_id');
            $table->dropColumn([
                'doctor_name',
                'schedule_label',
                'visit_date',
                'admission_mode',
                'insurance_number',
                'booking_code',
                'queue_number',
            ]);
        });

        Schema::table('patients', function (Blueprint $table): void {
            $table->dropIndex(['nik']);
            $table->dropColumn([
                'nik',
                'place_of_birth',
                'religion',
                'education',
                'occupation',
                'province',
                'city',
                'district',
                'village',
                'address_line',
                'domicile',
                'email',
                'ethnicity',
                'language',
                'notes',
                'responsible_party_name',
            ]);
        });

        Schema::dropIfExists('clinic_schedules');
        Schema::dropIfExists('doctors');
        Schema::dropIfExists('clinics');
    }
};
