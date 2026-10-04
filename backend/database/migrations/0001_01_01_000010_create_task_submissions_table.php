<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->restrictOnDelete()->cascadeOnUpdate();
            $table->unsignedInteger('version_no');
            $table->text('submission_text')->nullable();
            $table->string('resource_url')->nullable();
            $table->timestampTz('submitted_at');
            $table->unique(['task_id', 'version_no']);
        });

        DB::statement('ALTER TABLE task_submissions ADD CONSTRAINT task_submissions_version_no_check CHECK (version_no > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('task_submissions');
    }
};
