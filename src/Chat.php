<?php

namespace TornadoEngine\Chat;

use App\Harness\HarnessTask;
use App\Models\Setting;
use App\Modules\ModuleRegistry;
use App\Support\Setup;
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
    /** Claude Code's auto mode: it decides what's safe, and asks (Allow or Deny) about the rest. */
    public const MODE = 'auto';

    public const PROMPT_TOOL = 'mcp__tornado-chat__approve';

    public function __construct(private Broker $broker) {}

    public function send(Conversation $conversation, string $text): Message
    {
        if ($reason = $this->broker->notReady()) {
            throw new RuntimeException($reason);
        }
        if ($conversation->running()) {
            throw new RuntimeException(__('Claude is still working on your last message.'));
        }

        $conversation->touch();

        $task = HarnessTask::queue('chat', $this->broker->filter($text),
            reference: (string) $conversation->id,
            session: $this->session($conversation),
            mode: self::MODE,
            systemPrompt: self::systemPrompt(),
            permissionPromptTool: self::PROMPT_TOOL,
        );

        return $conversation->messages()->create(['text' => $text, 'harness_task_id' => $task->id]);
    }

    public function stop(Conversation $conversation): void
    {
        $conversation->messages()->reorder('id', 'desc')->first()?->task?->update(['stop' => true]);
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

    /**
     * The district's name for module packages ({vendor}/{folder}), from the site's address:
     * te.westmschools.org gives westmschools. Without an address, "local".
     */
    public static function vendor(): string
    {
        $host = (string) parse_url((string) Setup::address(), PHP_URL_HOST);
        $labels = array_values(array_filter(explode('.', strtolower($host))));
        $name = count($labels) >= 2 ? $labels[count($labels) - 2] : ($labels[0] ?? '');
        $name = trim((string) preg_replace('/[^a-z0-9]+/', '-', $name), '-');

        return $name !== '' ? $name : 'local';
    }

    /** The built-in system prompt; {modules} and {vendor} are filled in when a chat starts. */
    public const DEFAULT_PROMPT = <<<'PROMPT'
You're talking with someone at a school district through Tornado Engine's Chat page on their website. They see your replies but not your tool calls. Keep replies short and plain.

People in some roles reach you only as pseudonyms, p-<10 hex>@<role> (for example p-0123456789@student), with names as [name p-…]. Never try to work out who someone is.

Installed modules: {modules}.

Tools are Tornado Engine modules in /workspace/web/modules/<folder>, each its own git repository with Composer package name {vendor}/<folder>. Follow /workspace/StyleGuide.MD. Never change anything outside the module's folder. Reuse what Core and its modules provide (read their code in /workspace/web/vendor/tornado-engine/ to see how):
- permissions: Access ($access->permission(...)), checked the usual Laravel way
- menu items and dashboard cards: Nav ($nav->add(...), $nav->tool(...))
- scheduled jobs: Cron ($cron->job(...))
- settings pages: an Administration tab ($administration->tab(...), ->card(...))
- help pages: Docs ($docs->add(...))

Google and Microsoft data: run GAM yourself through the broker's gam tool. A module that needs directory data reads it through the GAM module (TornadoEngine\Gam\Gam), Access-Microsoft-AD or Access-Microsoft-Entra when it needs it, rather than keeping its own copy.

When they ask for a new tool, or a change to one in /workspace/web/modules:
1. Talk it through first: what it does, who uses it, what it needs. Ask before building if anything is unclear.
2. Build or change it there.
3. Call the install_module tool with the folder name. It installs the module on the website (Composer, migrations, styles) and says how it went. Call it again after changing migrations or styles.
4. Run its tests with `php artisan test` in /workspace/web, and fix what fails.
5. Tell them where to find it.
6. Offer to help share it with other districts, following Docs → Sharing a module (/workspace/web/vendor/tornado-engine/docs/resources/views/pages/sharing-modules.blade.php).

To change how Core or a module you didn't build here looks, don't edit it (updates would undo it): override its views. `php artisan tornado:override --list` lists them; `php artisan tornado:override <namespace::view>` copies one into /workspace/web/local/views to edit. Site-wide CSS goes in /workspace/web/local/local.css. Afterwards run `php artisan tornado:override --styles`. They show on Administration → Overrides, which flags any an update changes.

Don't run Composer, npm or docker yourself: install_module does that.
PROMPT;

    /** The system prompt as set in Administration → Chat, or the built-in one. */
    public static function promptTemplate(): string
    {
        return Setting::get('chat.system_prompt') ?: self::DEFAULT_PROMPT;
    }

    public static function systemPrompt(): string
    {
        return strtr(self::promptTemplate(), [
            '{modules}' => implode(', ', array_map(fn ($module) => $module->name, app(ModuleRegistry::class)->compatible())),
            '{vendor}' => self::vendor(),
        ]);
    }
}
