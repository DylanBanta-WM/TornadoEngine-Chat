<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('session_id')->nullable(); // Claude Code session, continued by each message
            $table->string('mode')->default('bypassPermissions');
            $table->timestamps();
        });

        // What people wrote (as they wrote it), and the harness task that answered.
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_conversation_id')->constrained()->cascadeOnDelete();
            $table->longText('text');
            $table->foreignId('harness_task_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // Claude asking to do something (Manual, Accept edits, Auto mode and Plan mode).
        Schema::create('chat_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('harness_task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tool');
            $table->json('input');
            $table->string('status', 10)->default('pending'); // pending, allowed, denied
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_approvals');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
    }
};
