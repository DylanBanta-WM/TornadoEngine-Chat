<?php

namespace TornadoEngine\Chat\Mcp\Tools;

use App\Harness\HarnessTask;
use App\Models\User;
use TornadoEngine\Broker\Broker;
use TornadoEngine\Chat\Models\Conversation;

/** The site tools are for admins' chats: the person in the chat that's running. What they return goes through the broker. */
trait ForAdmins
{
    private function chat(): ?Conversation
    {
        $task = HarnessTask::where('source', 'chat')->where('status', 'running')->latest('id')->first();

        return $task ? Conversation::find((int) $task->reference) : null;
    }

    private function admin(): ?User
    {
        $user = $this->chat()?->user;

        return $user && $user->can('administration') ? $user : null;
    }

    private function safe(string $text): string
    {
        return app(Broker::class)->filter($text);
    }
}
