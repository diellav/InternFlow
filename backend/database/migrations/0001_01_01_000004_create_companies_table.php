<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('industry')->nullable();
            $table->text('address')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('verification_status')->default('PENDING');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->timestampTz('verified_at')->nullable();
            $table->text('verification_note')->nullable();
            $table->timestamps();
            $table->index('verification_status');
            $table->index('verified_by');
        });

        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_verification_status_check CHECK (verification_status IN ('PENDING', 'APPROVED', 'REJECTED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
