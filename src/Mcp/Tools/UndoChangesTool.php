<?php

namespace TornadoEngine\Chat\Mcp\Tools;

use App\Harness\HarnessTask;
use App\Updates\DistrictBranch;
use App\Updates\UpdateJob;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('undo_chat_changes')]
#[Description('Undoes every change this chat made to Core\'s own files (the site keeps them as commits), as new commits. Changes to modules in web/modules you undo yourself with git there; changes made with patch_file you undo by patching the file back. Ask the person first.')]
class UndoChangesTool extends Tool
{
    use ForAdmins;

    public function handle(Request $request): Response
    {
        if (! $this->admin() || ! ($chat = $this->chat())) {
            return Response::error('Only admins\' chats can undo changes.');
        }
        if (! class_exists(DistrictBranch::class) || ! method_exists(DistrictBranch::class, 'undo')) {
            return Response::error('This Core version can\'t undo changes yet. Update Core first.');
        }
        $runs = HarnessTask::where('source', 'chat')->where('reference', (string) $chat->id)->pluck('id')->all();
        $job = UpdateJob::queue('undo', implode(',', $runs));

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
}
