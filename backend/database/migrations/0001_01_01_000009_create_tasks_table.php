<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_id')->constrained()->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('title');
            $table->text('description');
            $table->string('priority');
            $table->date('due_date')->nullable();
            $table->string('status')->default('ASSIGNED');
            $table->unsignedSmallInteger('progress_percent')->nullable();
            $table->timestampsTz();
            $table->index('internship_id');
            $table->index('assigned_by');
            $table->index('status');
        });

        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_priority_check CHECK (priority IN ('LOW', 'MEDIUM', 'HIGH'))");
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status IN ('ASSIGNED', 'IN_PROGRESS', 'SUBMITTED', 'REVISION_REQUIRED', 'APPROVED', 'CANCELLED'))");
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_progress_percent_check CHECK (progress_percent BETWEEN 0 AND 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
