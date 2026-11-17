<?php

namespace TornadoEngine\Chat;

use App\Harness\HarnessTask;
use RuntimeException;
use TornadoEngine\Broker\Broker;
use TornadoEngine\Chat\Models\Conversation;
use TornadoEngine\Chat\Models\Message;

/**
 * Sends people's messages to Claude Code in the harness. Every message goes through the broker
 * first, so Claude only ever sees pseudonyms.
 */
class Chat
{
    /** Claude Code's permission modes, as Claude Code names them. */
    public const MODES = [
        'bypassPermissions' => 'Bypass permissions',
        'auto' => 'Auto mode',
        'acceptEdits' => 'Accept edits',
        'plan' => 'Plan mode',
        'manual' => 'Manual',
    ];

    public const PROMPT_TOOL = 'mcp__tornado-chat__approve';

    public function __construct(private Broker $broker) {}

    public function send(Conversation $conversation, string $text, string $mode): Message
    {
        if ($reason = $this->broker->notReady()) {
            throw new RuntimeException($reason);
        }
        if ($conversation->running()) {
            throw new RuntimeException(__('Claude is still working on your last message.'));
        }

        $mode = array_key_exists($mode, self::MODES) ? $mode : 'bypassPermissions';
        $conversation->update(['mode' => $mode]);

        $task = HarnessTask::queue('chat', $this->broker->filter($text),
            reference: (string) $conversation->id,
            session: $this->session($conversation),
            mode: $mode,
            systemPrompt: self::systemPrompt(),
            permissionPromptTool: self::PROMPT_TOOL,
        );

        return $conversation->messages()->create(['text' => $text, 'harness_task_id' => $task->id]);
    }

    public function stop(Conversation $conversation): void
    {
        $conversation->messages()->latest('id')->first()?->task?->update(['stop' => true]);
        HarnessTask::where('source', 'chat')->where('reference', (string) $conversation->id)->where('status', 'queued')
            ->update(['status' => 'stopped', 'finished_at' => now()]);
    }

    /** The Claude Code session to continue: the one the last finished answer used. */
    private function session(Conversation $conversation): ?string
    {
        foreach ($conversation->messages()->with('task')->latest('id')->get() as $message) {
            if ($message->task?->result_session_id) {
                return $message->task->result_session_id;
            }
        }

        return null;
    }

    public static function systemPrompt(): string
    {
        return <<<'PROMPT'
You're talking with someone at a school district through Tornado Engine's Chat page on their website. They see your replies but not your tool calls. Keep replies short and plain.

People in some roles reach you only as pseudonyms, p-<10 hex>@<role> (for example p-0123456789@student), with names as [name p-…]. Never try to work out who someone is.

When they ask for a new tool:
1. Talk it through first: what it does, who uses it, what it needs. Ask before building if anything is unclear.
2. Build it as a new Tornado Engine module in /workspace/web/modules/<folder>, its own git repository with Composer package name local/<folder>. Follow /workspace/StyleGuide.MD (its Modules section, and phones). Never change anything outside that folder.
3. Call the install_module tool with the folder name. It installs the module on the website (Composer, migrations, styles) and says how it went. Call it again after changing migrations or styles.
4. Run its tests with `php artisan test` in /workspace/web, and fix what fails.
5. Tell them where to find it.

Don't run Composer, npm or docker yourself: install_module does that.
PROMPT;
    }
}
