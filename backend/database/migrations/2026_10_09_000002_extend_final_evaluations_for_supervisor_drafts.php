<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('final_evaluations', function (Blueprint $table) {
            $table->integer('quality_of_work')->nullable();
            $table->integer('initiative')->nullable();
        });
        DB::statement('ALTER TABLE final_evaluations ALTER COLUMN submitted_at DROP NOT NULL');
        DB::statement('ALTER TABLE final_evaluations ADD CONSTRAINT final_evaluations_quality_of_work_check CHECK (quality_of_work BETWEEN 1 AND 5)');
        DB::statement('ALTER TABLE final_evaluations ADD CONSTRAINT final_evaluations_initiative_check CHECK (initiative BETWEEN 1 AND 5)');
    }

    public function down(): void
    {
        if (DB::table('final_evaluations')->whereNull('submitted_at')->exists()) {
            throw new RuntimeException('Draft evaluations must be resolved before reverting this migration.');
        }
        DB::statement('ALTER TABLE final_evaluations ALTER COLUMN submitted_at SET NOT NULL');
        Schema::table('final_evaluations', fn (Blueprint $table) => $table->dropColumn(['quality_of_work', 'initiative']));
    }
};
