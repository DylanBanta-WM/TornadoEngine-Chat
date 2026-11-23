<?php

namespace TornadoEngine\Chat\Models;

use App\Harness\HarnessTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use TornadoEngine\Uploads\Uploads;

class Conversation extends Model
{
    protected $table = 'chat_conversations';

    /** Days a chat is kept after it was last used, unless it's saved. */
    public const KEEP_DAYS = 14;

    protected $fillable = ['user_id', 'title', 'session_id', 'mode', 'saved', 'renamed'];

    protected function casts(): array
    {
        return ['saved' => 'boolean', 'renamed' => 'boolean'];
    }

    /**
     * Deletes temporary chats not used for KEEP_DAYS days, with what Claude did in them.
     */
    public static function prune(): int
    {
        $old = self::where('saved', false)->where('updated_at', '<', now()->subDays(self::KEEP_DAYS))->get();
        foreach ($old as $conversation) {
            HarnessTask::where('source', 'chat')->where('reference', (string) $conversation->id)->delete();
            if (class_exists(Uploads::class)) {
                app(Uploads::class)->forget('chat', $conversation->messages()->pluck('id')->all());
            }
            $conversation->delete();
        }

        return $old->count();
    }

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
        // messages() lists oldest first, so reorder for the newest.
        $task = $this->messages()->reorder('id', 'desc')->first()?->task;

        return $task !== null && ! $task->finished();
    }
}
