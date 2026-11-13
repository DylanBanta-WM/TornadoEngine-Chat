<?php

namespace TornadoEngine\Chat\Models;

use Illuminate\Database\Eloquent\Model;

class Approval extends Model
{
    protected $table = 'chat_approvals';

    protected $fillable = ['chat_conversation_id', 'harness_task_id', 'tool', 'input', 'status'];

    protected function casts(): array
    {
        return ['input' => 'array'];
    }
}
