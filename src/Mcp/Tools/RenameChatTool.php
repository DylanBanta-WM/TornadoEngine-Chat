<?php

namespace TornadoEngine\Chat\Mcp\Tools;

use App\Harness\HarnessTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use TornadoEngine\Chat\Models\Conversation;

#[Name('rename_chat')]
#[Description('Gives the current chat a new title in the history list. Only for when the title no longer fits what the conversation is about. Does nothing if the person named the chat themselves.')]
class RenameChatTool extends Tool
{
    public function handle(Request $request): Response
    {
        $task = HarnessTask::where('source', 'chat')->where('status', 'running')->latest('id')->first();
        $conversation = $task ? Conversation::find((int) $task->reference) : null;
        if (! $conversation) {
            return Response::error('There is no chat to rename.');
        }
        if ($conversation->renamed) {
            return Response::text('The person named this chat themselves, so it was left as it is.');
        }

        $title = Str::limit(trim((string) preg_replace('/\s+/', ' ', (string) $request->get('title', ''))), 60, '…');
        if ($title === '') {
            return Response::error('title is the new name, a few plain words.');
        }

        $conversation->timestamps = false; // renaming isn't use: it must not extend how long the chat is kept
        $conversation->update(['title' => $title]);

        return Response::text('Renamed the chat.');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required()->description('A short plain title (at most 60 characters) for what the chat is about, with no names, IDs or pseudonyms.'),
        ];
    }
}
