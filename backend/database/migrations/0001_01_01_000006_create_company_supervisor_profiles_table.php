<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_supervisor_profiles', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('company_id')->constrained()->restrictOnDelete()->cascadeOnUpdate();
            $table->string('job_title')->nullable();
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_supervisor_profiles');
    }
};
