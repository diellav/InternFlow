<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_id')->constrained()->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('student_id')->constrained('student_profiles', 'user_id')->restrictOnDelete()->cascadeOnUpdate();
            $table->date('activity_date');
            $table->string('title');
            $table->text('description');
            $table->decimal('hours', 5, 2)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['internship_id', 'activity_date']);
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
