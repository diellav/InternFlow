<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('final_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_id')->unique()->constrained()->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('evaluator_id')->constrained('company_supervisor_profiles', 'user_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->integer('technical_skills')->nullable();
            $table->integer('communication')->nullable();
            $table->integer('teamwork')->nullable();
            $table->integer('responsibility')->nullable();
            $table->decimal('overall_score', 5, 2)->nullable();
            $table->text('comments')->nullable();
            $table->timestampTz('submitted_at');
            $table->index('evaluator_id');
        });

        DB::statement('ALTER TABLE final_evaluations ADD CONSTRAINT final_evaluations_technical_skills_check CHECK (technical_skills BETWEEN 1 AND 5)');
        DB::statement('ALTER TABLE final_evaluations ADD CONSTRAINT final_evaluations_communication_check CHECK (communication BETWEEN 1 AND 5)');
        DB::statement('ALTER TABLE final_evaluations ADD CONSTRAINT final_evaluations_teamwork_check CHECK (teamwork BETWEEN 1 AND 5)');
        DB::statement('ALTER TABLE final_evaluations ADD CONSTRAINT final_evaluations_responsibility_check CHECK (responsibility BETWEEN 1 AND 5)');
        DB::statement('ALTER TABLE final_evaluations ADD CONSTRAINT final_evaluations_overall_score_check CHECK (overall_score BETWEEN 1 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('final_evaluations');
    }
};
