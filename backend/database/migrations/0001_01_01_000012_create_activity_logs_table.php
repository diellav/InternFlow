<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_id')->constrained()->restrictOnDelete()->cascadeOnUpdate();
            $table->date('activity_date');
            $table->string('title');
            $table->text('description');
            $table->decimal('hours', 5, 2)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['internship_id', 'activity_date']);
        });

        DB::statement('ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_hours_check CHECK (hours >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
