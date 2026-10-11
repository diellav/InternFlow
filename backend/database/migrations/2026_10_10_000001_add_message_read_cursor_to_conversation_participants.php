<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table): void {
            $table->foreignId('last_read_message_id')->nullable()->constrained('messages')->restrictOnDelete()->cascadeOnUpdate();
        });
        Schema::table('messages', function (Blueprint $table): void {
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('conversation_participants', fn (Blueprint $table) => $table->dropConstrainedForeignId('last_read_message_id'));
        Schema::table('messages', fn (Blueprint $table) => $table->dropIndex(['conversation_id', 'id']));
    }
};
