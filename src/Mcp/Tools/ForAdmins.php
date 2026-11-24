<?php

namespace TornadoEngine\Chat\Mcp\Tools;

use App\Harness\HarnessTask;
use App\Models\User;
use App\Updates\UpdateJob;
use Laravel\Mcp\Response;
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

    /** Waits for an updater job, then says how it went (the end of its log). */
    private function finished(UpdateJob $job): Response
    {
        @set_time_limit(400);
        $deadline = time() + (int) config('chat.install_wait', 360);
        $job->refresh();
        while (in_array($job->status, ['queued', 'running'], true) && time() < $deadline) {
            sleep(3);
            $job->refresh();
        }
        $log = $this->safe(trim(mb_substr((string) $job->log, -2000)));

        return match ($job->status) {
            'done' => Response::text($log),
            'failed' => Response::error($log),
            default => Response::error('The updater hasn\'t finished yet. It may not be running.'),
        };
    }

    private function safe(string $text): string
    {
        return app(Broker::class)->filter($text);
    }
}
