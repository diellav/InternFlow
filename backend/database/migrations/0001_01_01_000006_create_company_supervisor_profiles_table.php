<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_supervisor_profiles', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('company_id')->constrained()->restrictOnDelete()->cascadeOnUpdate();
            $table->string('job_title')->nullable();
            $table->string('verification_status')->default('PENDING');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_comment')->nullable();
            $table->index('company_id');
            $table->index('verification_status');
            $table->index('reviewed_by');
        });

        DB::statement("ALTER TABLE company_supervisor_profiles ADD CONSTRAINT company_supervisor_profiles_verification_status_check CHECK (verification_status IN ('PENDING', 'APPROVED', 'REJECTED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('company_supervisor_profiles');
    }
};
