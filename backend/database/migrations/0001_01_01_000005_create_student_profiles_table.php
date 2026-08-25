<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('student_number')->unique();
            $table->string('study_program');
            $table->unsignedSmallInteger('study_year')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_profiles');
    }
};
