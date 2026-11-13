<?php

namespace TornadoEngine\Chat\Mcp\Tools;

use App\Updates\UpdateJob;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use TornadoEngine\Broker\Broker;

#[Name('install_module')]
#[Description('Installs (or refreshes) a module from /workspace/web/modules/<folder> on the website: Composer, migrations and styles. Waits until it is done and says how it went.')]
class InstallModuleTool extends Tool
{
    public function __construct(private Broker $broker) {}

    public function handle(Request $request): Response
    {
        $folder = (string) $request->get('folder', '');
        if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $folder)) {
            return Response::error('folder is the module\'s folder name in web/modules, for example "notes".');
        }

        $composer = json_decode((string) @file_get_contents(base_path("modules/{$folder}/composer.json")), true);
        if (! is_array($composer) || ! isset($composer['name'], $composer['extra']['tornado']['provider'])) {
            return Response::error("web/modules/{$folder}/composer.json needs a name and an extra.tornado section with a provider (see StyleGuide.MD).");
        }

        $job = UpdateJob::queue('module', 'dev', strtolower($composer['name']), "modules/{$folder}");

        @set_time_limit(900);
        $deadline = time() + (int) config('chat.install_wait', 840);
        while (in_array($job->status, ['queued', 'running'], true) && time() < $deadline) {
            sleep(3);
            $job->refresh();
        }

        $log = $this->broker->filter(mb_substr((string) $job->log, -4000));

        return match ($job->status) {
            'done' => Response::text("Installed {$composer['name']}.".($job->notes ? ' '.implode(' ', $job->notes) : '')."\n\n{$log}"),
            'failed' => Response::error("Installing failed:\n\n{$log}"),
            default => Response::error('The updater hasn\'t finished yet. It may not be running (docker compose up -d on the server).'),
        };
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'folder' => $schema->string()->description('The module\'s folder in web/modules, e.g. "notes".')->required(),
        ];
    }
}
