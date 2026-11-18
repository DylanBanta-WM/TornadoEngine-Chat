<?php

namespace TornadoEngine\Chat;

use App\Harness\HarnessTask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use TornadoEngine\Broker\Broker;
use TornadoEngine\Chat\Models\Conversation;
use TornadoEngine\Uploads\Models\Upload;

/**
 * A conversation for the page: what people wrote, and what Claude answered and did, with
 * pseudonyms turned back into real people (for the website only).
 */
class Transcript
{
    public function __construct(private Broker $broker) {}

    /**
     * @return list<array{text: string, at: \DateTimeInterface, status: ?string, items: list<array<string, mixed>>, approvals: Collection}>
     */
    /** @param  bool  $asClaude  as Claude got it: pseudonyms, not names (to check the broker) */
    public function turns(Conversation $conversation, bool $asClaude = false): array
    {
        $this->asClaude = $asClaude;
        $approvals = $conversation->approvals()->orderBy('id')->get()->groupBy('harness_task_id');
        $turns = [];

        foreach ($conversation->messages()->with('task.events')->get() as $message) {
            $task = $message->task;
            $turns[] = [
                'text' => $asClaude && $task ? $task->prompt : $message->text,
                'files' => class_exists(Upload::class)
                    ? Upload::where('source', 'chat')->where('reference', (string) $message->id)->orderBy('id')->get()->map(fn (Upload $upload) => $this->file($upload))->all() : [],
                'at' => $message->created_at,
                'status' => $task?->status,
                'error' => $task?->status === 'failed' ? $this->failure($task) : null,
                'items' => $task ? $this->items($task) : [],
                'approvals' => $approvals->get($task?->id, collect()),
            ];
        }

        return $turns;
    }

    /**
     * A file attached to a message. Images can be opened: the one uploaded, or in "What Claude sees", the copy Claude got.
     *
     * @return array{name: string, image: ?string}
     */
    private function file(Upload $upload): array
    {
        $image = null;
        if ($upload->isImage() && Route::has('uploads.image')) {
            $preview = isset($upload->forClaude()['image.png']) ? route('uploads.preview', $upload).'?v='.$upload->updated_at?->timestamp : null;
            // Browsers (other than Safari) can't show TIFF, so the PNG copy stands in for it.
            $image = $this->asClaude || preg_match('/\.tiff?$/i', $upload->name) ? $preview : route('uploads.image', $upload);
        }

        return ['name' => $this->asClaude ? app(Broker::class)->filter($upload->name) : $upload->name, 'image' => $image];
    }

    /** @return list<array<string, mixed>> */
    private function items(HarnessTask $task): array
    {
        $items = [];
        foreach ($task->events as $event) {
            $event = $event->event;
            if (($event['type'] ?? null) !== 'assistant') {
                continue;
            }
            foreach ($event['message']['content'] ?? [] as $block) {
                if (($block['type'] ?? null) === 'text' && trim($block['text'] ?? '') !== '') {
                    $items[] = ['kind' => 'text', 'html' => Str::markdown($this->show($block['text']), ['html_input' => 'escape', 'allow_unsafe_links' => false])];
                } elseif (($block['type'] ?? null) === 'tool_use' && ($label = $this->activity($block['name'] ?? '', $block['input'] ?? []))) {
                    $items[] = ['kind' => 'activity', 'text' => $this->show($label)];
                }
            }
        }

        return $items;
    }

    /** A short line for something Claude did, or null to leave it out. */
    public function activity(string $tool, array $input): ?string
    {
        $path = fn ($key = 'file_path') => Str::after((string) ($input[$key] ?? ''), '/workspace/web/');

        return match (true) {
            $tool === 'Bash' => __('Ran :command', ['command' => Str::limit((string) ($input['command'] ?? ''), 200)]),
            $tool === 'Read' => __('Read :path', ['path' => $path()]),
            in_array($tool, ['Edit', 'MultiEdit'], true) => __('Edited :path', ['path' => $path()]),
            $tool === 'Write' => __('Wrote :path', ['path' => $path()]),
            in_array($tool, ['Glob', 'Grep'], true) => __('Searched for :pattern', ['pattern' => (string) ($input['pattern'] ?? '')]),
            $tool === 'mcp__tornado-broker__gam' => __('Ran gam :args', ['args' => implode(' ', (array) ($input['args'] ?? []))]),
            $tool === 'mcp__tornado-chat__install_module' => __('Installed the :folder module', ['folder' => (string) ($input['folder'] ?? '')]),
            in_array($tool, ['TodoWrite', 'ExitPlanMode', 'mcp__tornado-broker__broker_info'], true) => null,
            default => $tool,
        };
    }

    private function failure(HarnessTask $task): string
    {
        foreach ($task->events->reverse() as $event) {
            if (($event->event['type'] ?? null) === 'result' && ($event->event['is_error'] ?? false)) {
                return $this->show((string) ($event->event['result'] ?? __('Claude stopped with an error.')));
            }
        }

        return $task->error ? $this->show($task->error) : __('Claude stopped with an error.');
    }

    private bool $asClaude = false;

    /** Pseudonyms back to names for the person reading, unless they asked to see what Claude sees. */
    private function show(string $text): string
    {
        return $this->asClaude ? $text : $this->broker->display($text);
    }
}
