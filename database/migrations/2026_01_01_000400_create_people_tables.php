<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employee_number', 30)->unique();
            $table->string('full_name');
            $table->string('phone', 30);
            $table->string('national_id', 30)->nullable();
            $table->enum('position', ['manager', 'accountant', 'receptionist', 'supervisor', 'administrative'])
                ->default('administrative');
            $table->date('employment_date');
            $table->decimal('base_salary', 12, 2)->default(0);
            $table->decimal('allowances', 12, 2)->default(0);
            $table->json('working_days')->nullable(); // day numbers, 0 = Sunday
            $table->time('work_start_time')->nullable();
            $table->time('work_end_time')->nullable();
            $table->enum('status', ['active', 'on_leave', 'terminated'])->default('active');
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index('phone');
            $table->index('national_id');
        });

        Schema::create('trainers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trainer_number', 30)->unique();
            $table->string('full_name');
            $table->string('phone', 30);
            $table->string('national_id', 30)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('address')->nullable();
            $table->string('photo_path')->nullable();
            $table->date('employment_date');
            $table->json('license_types')->nullable();
            $table->json('working_days')->nullable();
            $table->time('work_start_time')->nullable();
            $table->time('work_end_time')->nullable();
            $table->enum('status', ['active', 'on_leave', 'terminated'])->default('active');
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index('phone');
            $table->index('national_id');
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_trainer_id')->nullable()->constrained('trainers')->nullOnDelete();
            $table->string('name');
            $table->string('plate_number', 30)->unique();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->enum('transmission', ['manual', 'automatic'])->default('manual');
            $table->string('license_type', 40)->nullable();
            $table->enum('status', ['available', 'in_use', 'maintenance', 'inactive'])->default('available');
            $table->string('insurance_number', 60)->nullable();
            $table->date('insurance_expires_on')->nullable();
            $table->string('registration_number', 60)->nullable();
            $table->date('registration_expires_on')->nullable();
            $table->unsignedBigInteger('odometer_km')->nullable();
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });

        Schema::create('vehicle_maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['routine', 'repair', 'parts', 'inspection', 'other'])->default('routine');
            $table->string('title');
            $table->date('service_date');
            $table->decimal('cost', 12, 2)->default(0);
            $table->string('provider')->nullable();
            $table->unsignedBigInteger('odometer_km')->nullable();
            $table->date('next_service_on')->nullable();
            // Set once the maintenance cost has been posted to the expense ledger.
            $table->foreignId('expense_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['vehicle_id', 'service_date']);
        });

        Schema::create('trainees', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trainer_id')->nullable()->constrained('trainers')->nullOnDelete();
            $table->string('trainee_number', 30)->unique();
            $table->string('full_name');
            $table->string('phone', 30);
            $table->string('secondary_phone', 30)->nullable();
            $table->string('national_id', 30)->nullable();
            $table->date('birth_date')->nullable();
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->string('address')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('license_type', 40)->default('private');
            $table->date('registration_date');
            $table->enum('status', [
                'new', 'in_training', 'suspended', 'ready_for_exam', 'exam_scheduled',
                'passed', 'failed', 'completed', 'cancelled',
            ])->default('new');
            $table->date('exam_date')->nullable();
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index('phone');
            $table->index('national_id');
            $table->index('trainer_id');
            $table->index('registration_date');
        });

        Schema::create('trainee_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trainee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();

            $table->index(['trainee_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trainee_notes');
        Schema::dropIfExists('trainees');
        Schema::dropIfExists('vehicle_maintenances');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('trainers');
        Schema::dropIfExists('employees');
    }
};
