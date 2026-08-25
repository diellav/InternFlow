<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
    }

    public function down(): void
    {
        Schema::dropIfExists('final_evaluations');
    }
};
