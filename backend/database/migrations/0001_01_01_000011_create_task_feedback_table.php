<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->unique()->constrained('task_submissions')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('supervisor_id')->constrained('company_supervisor_profiles', 'user_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('decision');
            $table->text('comment')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index('supervisor_id');
        });

        DB::statement("ALTER TABLE task_feedback ADD CONSTRAINT task_feedback_decision_check CHECK (decision IN ('APPROVED', 'REVISION_REQUIRED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('task_feedback');
    }
};
