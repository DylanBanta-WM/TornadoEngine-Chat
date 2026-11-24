<?php

namespace TornadoEngine\Chat\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;
use TornadoEngine\Uploads\Uploads;

#[Name('screenshot')]
#[Description('A screenshot of a page of the live website in broker view (people\'s details are pseudonyms), with anything the broker would still replace covered in black, like an uploaded image. Saves a PNG you then Read to see it, and returns the text read in it. Use it to check how a page you changed looks.')]
class ScreenshotTool extends Tool
{
    use ForAdmins;

    public function handle(Request $request): Response
    {
        if (! $this->admin()) {
            return Response::error('Only admins\' chats can take screenshots.');
        }
        $uploads = Uploads::class;
        if (! class_exists($uploads) || ! method_exists($uploads, 'cover')) {
            return Response::error('Screenshots need the Uploads module 0.10 or newer, which covers names in images.');
        }
        $path = '/'.ltrim((string) $request->get('path', '/'), '/');
        if (! preg_match('#^/[A-Za-z0-9/_.\-?=&%]*$#', $path) || str_starts_with($path, '/mcp') || str_starts_with($path, '/api/harness')) {
            return Response::error('path is a page of this website, for example /settings/updates.');
        }

        try {
            $shot = Http::timeout(60)->get(rtrim((string) config('chat.browser_url', 'http://chat-browser:3000'), '/').'/shot', [
                'path' => $path, 'width' => (int) ($request->get('width') ?: 1280), 'full' => $request->get('full') ? 1 : 0,
            ]);
        } catch (Throwable) {
            return Response::error('Chat\'s browser isn\'t running. It starts after a rebuild (Settings → Updates).');
        }
        if (! $shot->successful() || $shot->header('Content-Type') !== 'image/png') {
            return Response::error($this->safe('The page couldn\'t be shown: '.mb_substr($shot->body(), 0, 300)));
        }

        // The raw screenshot stays where the harness can't see it; Claude gets the covered copy.
        $raw = tempnam(sys_get_temp_dir(), 'shot');
        file_put_contents($raw, $shot->body());
        $dir = storage_path('app/claude/screenshots');
        File::ensureDirectoryExists($dir);
        foreach (File::files($dir) as $old) {
            if ($old->getMTime() < time() - 86400) {
                @unlink($old->getPathname());
            }
        }
        $name = now()->format('Ymd-His').'-'.Str::random(6).'.png';
        try {
            $covered = app($uploads)->cover($raw, "{$dir}/{$name}");
        } catch (Throwable $e) {
            return Response::error($this->safe('Couldn\'t check the screenshot for names, so it isn\'t shown: '.$e->getMessage()));
        } finally {
            @unlink($raw);
        }

        return Response::text("Saved /workspace/web/storage/app/claude/screenshots/{$name}: Read it to see the page. "
            .($covered['covered'] ? "{$covered['covered']} places were covered." : 'Nothing needed covering.')
            ."\n\nText in it:\n".mb_substr($covered['text'], 0, 8000));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('The page, for example /settings/updates.')->required(),
            'width' => $schema->integer()->description('The window width in pixels (360 to 1920, 1280 if left out).'),
            'full' => $schema->boolean()->description('The whole page, not just the top 900 pixels.'),
        ];
    }
}
