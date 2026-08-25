<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('student_id')->constrained('student_profiles', 'user_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->unsignedInteger('version_no');
            $table->text('submission_text')->nullable();
            $table->string('resource_url')->nullable();
            $table->timestampTz('submitted_at');
            $table->unique(['task_id', 'version_no']);
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_submissions');
    }
};
