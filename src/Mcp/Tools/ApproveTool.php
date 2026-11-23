<?php

namespace TornadoEngine\Chat\Mcp\Tools;

use App\Harness\HarnessTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use TornadoEngine\Chat\Models\Approval;

/**
 * Claude Code's permission prompt tool (--permission-prompt-tool): shows Allow and Deny in the
 * chat and waits for the person's answer.
 */
#[Name('approve')]
#[Description('Asks the person in the chat whether a tool may run. Used by Claude Code for permission prompts.')]
class ApproveTool extends Tool
{
    /** How long to wait for an answer (seconds); nginx-harness allows 900. */
    public const WAIT = 840;

    public function handle(Request $request): Response
    {
        $task = HarnessTask::where('source', 'chat')->where('status', 'running')->latest('id')->first();
        if (! $task) {
            return $this->answer(false, 'There is no chat to ask.');
        }

        $input = (array) $request->get('input', []);

        // Claude Code's own question form can't show on the Chat page: the question goes in the reply instead.
        if ((string) $request->get('tool_name', '') === 'AskUserQuestion') {
            return $this->answer(false, 'Don\'t use AskUserQuestion here: it can\'t be shown on the Chat page. Ask your question (with any options) in your reply, and the person answers in their next message.');
        }

        $approval = Approval::create([
            'chat_conversation_id' => (int) $task->reference,
            'harness_task_id' => $task->id,
            'tool' => (string) $request->get('tool_name', ''),
            'input' => $input,
            'status' => 'pending',
        ]);

        @set_time_limit(self::WAIT + 30);
        $deadline = time() + (int) config('chat.approve_wait', self::WAIT);
        while ($approval->status === 'pending' && time() < $deadline && ! $task->fresh()->stop) {
            sleep((int) config('chat.approve_poll', 1));
            $approval->refresh();
        }

        if ($approval->status === 'pending') {
            $approval->update(['status' => 'denied']);
        }

        return $approval->status === 'allowed'
            ? $this->answer(true, input: $input)
            : $this->answer(false, 'The person said no. Ask them what they would like instead.');
    }

    private function answer(bool $allow, string $message = '', array $input = []): Response
    {
        return Response::text(json_encode($allow
            ? ['behavior' => 'allow', 'updatedInput' => (object) $input]
            : ['behavior' => 'deny', 'message' => $message]));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tool_name' => $schema->string()->required(),
            'input' => $schema->object(),
            'tool_use_id' => $schema->string(),
        ];
    }
}
