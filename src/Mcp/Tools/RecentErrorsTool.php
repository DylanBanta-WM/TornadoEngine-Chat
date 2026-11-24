<?php

namespace TornadoEngine\Chat\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('recent_errors')]
#[Description('The latest errors in the website\'s log (storage/logs/laravel.log), newest last, each with the start of its stack trace. People\'s details are replaced by the broker.')]
class RecentErrorsTool extends Tool
{
    use ForAdmins;

    public function handle(Request $request): Response
    {
        if (! $this->admin()) {
            return Response::error('Only admins\' chats can read the site\'s errors.');
        }
        $file = storage_path('logs/laravel.log');
        if (! is_file($file)) {
            return Response::text('No errors logged.');
        }
        $size = filesize($file);
        $handle = fopen($file, 'r');
        fseek($handle, max(0, $size - 400000));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        $entries = preg_split('/^(?=\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/m', $tail, -1, PREG_SPLIT_NO_EMPTY);
        $errors = array_values(array_filter($entries, fn ($entry) => preg_match('/^\[[^\]]+\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY)/', $entry)));
        $count = max(1, min(20, (int) ($request->get('count') ?: 5)));
        $shown = array_map(fn ($entry) => mb_substr(implode("\n", array_slice(explode("\n", trim($entry)), 0, 12)), 0, 2500), array_slice($errors, -$count));

        return Response::text($shown ? $this->safe(implode("\n\n---\n\n", $shown)) : 'No recent errors.');
    }

    public function schema(JsonSchema $schema): array
    {
        return ['count' => $schema->integer()->description('How many (1 to 20, 5 if left out).')];
    }
}
