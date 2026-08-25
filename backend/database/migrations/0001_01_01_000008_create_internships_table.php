<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('student_profiles', 'user_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('company_id')->constrained()->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('company_supervisor_id')->constrained('company_supervisor_profiles', 'user_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('coordinator_id')->constrained('academic_coordinator_profiles', 'user_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('position_title');
            $table->text('description')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index('student_id');
            $table->index('company_id');
            $table->index('company_supervisor_id');
            $table->index('coordinator_id');
            $table->index('status');
        });

        DB::statement("ALTER TABLE internships ADD CONSTRAINT internships_status_check CHECK (status IN ('DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'ACTIVE', 'COMPLETED', 'REJECTED', 'REVISION_REQUIRED'))");
        DB::statement('ALTER TABLE internships ADD CONSTRAINT internships_date_range_check CHECK (end_date >= start_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('internships');
    }
};
