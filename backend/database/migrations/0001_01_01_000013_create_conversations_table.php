<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_id')->constrained()->restrictOnDelete()->cascadeOnUpdate();
            $table->timestampTz('created_at')->useCurrent();
            $table->index('internship_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
