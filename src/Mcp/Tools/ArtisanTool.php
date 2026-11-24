<?php

namespace TornadoEngine\Chat\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('artisan')]
#[Description('Runs one of these artisan commands on the live website: migrate (runs new migrations), migrate:status, cache:clear, config:clear, view:clear, route:clear, optimize:clear, route:list, schedule:list, about. Output goes through the broker.')]
class ArtisanTool extends Tool
{
    use ForAdmins;

    /** command => options always given */
    public const ALLOWED = [
        'migrate' => ['--force' => true], 'migrate:status' => [], 'cache:clear' => [], 'config:clear' => [], 'view:clear' => [],
        'route:clear' => [], 'optimize:clear' => [], 'route:list' => ['--except-vendor' => true], 'schedule:list' => [], 'about' => [],
    ];

    public function handle(Request $request): Response
    {
        if (! $this->admin()) {
            return Response::error('Only admins\' chats can run artisan commands.');
        }
        $command = trim((string) $request->get('command', ''));
        if (! array_key_exists($command, self::ALLOWED)) {
            return Response::error('command is one of: '.implode(', ', array_keys(self::ALLOWED)).'.');
        }

        try {
            $code = Artisan::call($command, self::ALLOWED[$command]);
        } catch (Throwable $e) {
            return Response::error($this->safe($e->getMessage()));
        }

        return Response::text($this->safe("artisan {$command}: exit {$code}\n\n".mb_substr(Artisan::output(), 0, 15000)));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['command' => $schema->string()->enum(array_keys(self::ALLOWED))->required()];
    }
}
