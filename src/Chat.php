<?php

namespace TornadoEngine\Chat;

use App\Harness\HarnessTask;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Module;
use App\Modules\ModuleRegistry;
use App\Support\Setup;
use Illuminate\Support\Str;
use RuntimeException;
use TornadoEngine\Broker\Broker;
use TornadoEngine\Chat\Models\Conversation;
use TornadoEngine\Chat\Models\Message;
use TornadoEngine\Uploads\Models\Upload;

/**
 * Sends people's messages to Claude Code in the harness. Every message goes through the broker
 * first, so Claude only ever sees pseudonyms.
 */
class Chat
{
    /** Claude Code's auto mode: it decides what's safe, and asks (Allow or Deny) about the rest. */
    public const MODE = 'auto';

    public const PROMPT_TOOL = 'mcp__tornado-chat__approve';

    /**
     * The models people can choose, as Claude Code aliases: each is always the newest model of its kind, so the
     * list keeps up with new releases. '' is the harness's default.
     */
    public const MODELS = ['' => 'Default', 'opus' => 'Opus (most capable)', 'sonnet' => 'Sonnet (balanced)', 'haiku' => 'Haiku (fastest)'];

    /** The model this person last chose ('' for the default). */
    public static function modelFor(?User $user): string
    {
        $model = $user ? (string) Setting::get("chat.model.{$user->id}") : '';

        return array_key_exists($model, self::MODELS) ? $model : '';
    }

    public static function chooseModel(User $user, ?string $model): void
    {
        if ($model !== null && array_key_exists($model, self::MODELS)) {
            Setting::put("chat.model.{$user->id}", $model === '' ? null : $model);
        }
    }

    public function __construct(private Broker $broker) {}

    /** @param  list<int>  $uploads  files they attached (the Uploads module), by id */
    public function send(Conversation $conversation, string $text, array $uploads = []): Message
    {
        if ($reason = $this->broker->notReady()) {
            throw new RuntimeException($reason);
        }
        if ($conversation->running()) {
            throw new RuntimeException(__('Claude is still working on your last message.'));
        }

        $attached = $uploads && class_exists(Upload::class)
            ? Upload::whereIn('id', $uploads)->where('user_id', $conversation->user_id)->whereNull('source')->orderBy('id')->get() : collect();
        $text = trim($text);
        if ($text === '' && $attached->isEmpty()) {
            throw new RuntimeException(__('Write a message.'));
        }

        // A file alone names a new chat.
        if ($text === '' && $conversation->title === '') {
            $conversation->title = Str::limit($attached->first()->name, 60);
        }
        $conversation->touch();

        $files = $attached->map->describeForClaude()->implode("\n");
        $prompt = match (true) {
            $attached->isEmpty() => $this->broker->filter($text),
            $text === '' => "The user sent these files (copies for you, read them with the Read tool):\n".$files,
            default => $this->broker->filter($text)."\n\nFiles they attached (copies for you, read them with the Read tool):\n".$files,
        };

        // The model goes along on Core 0.36+, which knows about it.
        $model = collect((new \ReflectionMethod(HarnessTask::class, 'queue'))->getParameters())->contains(fn ($p) => $p->getName() === 'model')
            ? ['model' => self::modelFor($conversation->user) ?: null] : [];
        $task = HarnessTask::queue(...[
            'source' => 'chat',
            'prompt' => $prompt,
            'reference' => (string) $conversation->id,
            'session' => $this->session($conversation),
            'mode' => self::MODE,
            'systemPrompt' => self::systemPrompt(),
            'permissionPromptTool' => self::PROMPT_TOOL,
            ...$model,
        ]);

        $message = $conversation->messages()->create(['text' => $text, 'harness_task_id' => $task->id]);
        $attached->each->update(['source' => 'chat', 'reference' => (string) $message->id]);

        return $message;
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

Everyone in the district's directories reaches you only as a pseudonym, p-<10 hex>@<role> (for example p-0123456789@student, or @person for someone with no role), with names as [name p-…] and IDs, phone numbers and other values as placeholders like [id p-…-…], [id v-…] or [phone v-…]. The person you're talking with sees the real value in place of every pseudonym and placeholder, so to show them a value, write its placeholder. Ask the person questions in your reply (they answer in their next message), not with the AskUserQuestion tool. Never try to work out who someone is. If you see what looks like a real person's name, email or ID that wasn't replaced with a placeholder, tell the user it came through unprotected before you continue.

Installed modules, and what each offers the tools you build (read their code in /workspace/web/vendor/tornado-engine/ to see how):
{modules}

Tools are Tornado Engine modules in /workspace/web/modules/<folder>, each its own git repository with Composer package name {vendor}/<folder>. Follow /workspace/StyleGuide.MD. Where a change goes: a new tool is a module of its own in /workspace/web/modules; Core's own files (/workspace/web/app, config, database, resources, routes, tests) you change directly, and the site keeps those changes across Core updates; an installed module in /workspace/web/vendor you change with work_on_module if this site maintains it, otherwise with patch_file (both from the GitHub module), never by editing vendor/ directly. Never change Core's version number; releases set it. Settings pages are Administration tabs ($administration->tab(...), ->card(...)). Reuse what the installed modules offer rather than building your own, and list the modules a tool uses as its requirements. If a request needs something no installed module offers (for example directory data), say so before building.

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

    /** What Claude knows about a module: as edited on its settings page, or what the module says ("prompt" in extra.tornado). */
    public static function modulePrompt(Module $module): ?string
    {
        $edited = Setting::get('chat.module_prompt.'.$module->package);

        return $edited !== null ? ($edited !== '' ? $edited : null) : $module->prompt;
    }

    /** The system prompt as set in Administration → Chat, or the built-in one. */
    public static function promptTemplate(): string
    {
        return Setting::get('chat.system_prompt') ?: self::DEFAULT_PROMPT;
    }

    public static function systemPrompt(): string
    {
        $vendors = self::vendors();
        $prompt = strtr(self::promptTemplate(), [
            '{modules}' => implode("\n", array_map(fn ($module) => '- '.$module->name.(($info = self::modulePrompt($module)) ? ': '.$info : ''), app(ModuleRegistry::class)->compatible())),
            '{vendor}' => $vendors[0],
        ]);
        if (count($vendors) > 1) {
            $prompt .= "\n\nThis site builds modules under more than one vendor name: ".implode(', ', $vendors).'. Before you start a new module, ask the user which one it\'s for, and use it in the package name (<vendor>/<folder>).'
                .(in_array('tornado-engine', $vendors, true) ? ' tornado-engine is for official Tornado Engine modules, which every district can install; the others are for this district.' : '');
        }

        $prompt .= "\n\nWhen you change a module that was published before (a module you built, or one you got with work_on_module), publish the change too, once it works and its tests pass, so the published copy never falls behind. Don't ask which visibility or version it should have: keep its current visibility (private, public or packagist) and use the next version after its newest tag, and keep its other existing settings (name, vendor, requirements, Core range) as they are, unless the person asks you to change any of them. Ask only for a module that has never been published. Don't ask a person to confirm what they already decided earlier in the chat.";
        $prompt .= "\n\nThe chat has a title in the person's history. After you reply, if the title no longer reflects what the conversation is about, call the rename_chat tool with a short plain title (no names, IDs or pseudonyms). Don't rename a chat whose title still fits; the tool leaves chats the person named themselves alone.";

        return $prompt;
    }

    /**
     * The vendor names modules built here can use (Administration → Chat): the district's own first. With more
     * than one (a district that also maintains official modules, say), Claude asks which for each new module.
     *
     * @return list<string>
     */
    public static function vendors(): array
    {
        $saved = json_decode(Setting::get('chat.vendors') ?? 'null', true);

        return is_array($saved) && $saved !== [] ? array_values($saved) : [self::vendor()];
    }
}
