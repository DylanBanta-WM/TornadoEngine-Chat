<?php

namespace TornadoEngine\Chat\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $table = 'chat_conversations';

    protected $fillable = ['user_id', 'title', 'session_id', 'mode'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'chat_conversation_id')->orderBy('id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'chat_conversation_id');
    }

    /** Whether Claude is working on (or waiting to start) the last message. */
    public function running(): bool
    {
        $task = $this->messages()->latest('id')->first()?->task;

        return $task !== null && ! $task->finished();
    }
}
