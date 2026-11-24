<?php

namespace TornadoEngine\Chat\Mcp\Tools;

use App\Updates\DistrictBranch;
use App\Updates\UpdateJob;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('customize_module')]
#[Description('Before changing an installed module this site doesn\'t maintain (one in /workspace/web/vendor): makes a working copy of its repository at the installed version in /workspace/web/modules/<folder>, on a "district" branch, and installs it from there. Then change the files in web/modules/<folder>: the site keeps the changes as commits and puts them on top of each update of that module. Returns the folder. For a module the site maintains, use work_on_module (GitHub module) instead.')]
class CustomizeModuleTool extends Tool
{
    use ForAdmins;

    public function handle(Request $request): Response
    {
        if (! $this->admin()) {
            return Response::error('Only admins\' chats can customize modules.');
        }
        if (! method_exists(DistrictBranch::class, 'copies')) {
            return Response::error('This Core version can\'t customize modules yet. Update Core first.');
        }
        $package = strtolower(trim((string) $request->get('package', '')));
        if (! is_dir(base_path("vendor/{$package}")) || ! str_contains($package, '/')) {
            return Response::error('package is an installed module\'s Composer name, for example tornado-engine/chat.');
        }
        if ($folder = DistrictBranch::copies()[$package] ?? null) {
            return Response::text("Already customized: change it in /workspace/web/modules/{$folder}.");
        }

        $result = $this->finished(UpdateJob::queue('customize', '', $package));
        $folder = DistrictBranch::copies()[$package] ?? null;

        return $folder ? Response::text("Customized: change it in /workspace/web/modules/{$folder}. The site commits your changes about a minute after this chat's reply ends, and they come along with each update.") : $result;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['package' => $schema->string()->description('The module, for example tornado-engine/chat.')->required()];
    }
}
