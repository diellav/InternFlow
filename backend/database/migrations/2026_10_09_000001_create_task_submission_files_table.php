<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_submission_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_submission_id')->constrained()->restrictOnDelete()->cascadeOnUpdate();
            $table->string('original_name');
            $table->string('storage_path')->unique();
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->timestampTz('created_at')->useCurrent();
            $table->index('task_submission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_submission_files');
    }
};
