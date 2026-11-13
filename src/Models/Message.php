<?php

namespace TornadoEngine\Chat\Models;

use App\Harness\HarnessTask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $table = 'chat_messages';

    protected $fillable = ['chat_conversation_id', 'text', 'harness_task_id'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(HarnessTask::class, 'harness_task_id');
    }
}
