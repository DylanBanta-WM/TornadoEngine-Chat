<?php

namespace TornadoEngine\Chat\Mcp\Tools;

use App\Updates\Rebuild;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('rebuild')]
#[Description('Rebuilds the website\'s containers (what an install of PHP extensions or a module\'s own containers needs): action start asks the server\'s rebuild helper to do it; action status says whether it\'s running and how the last one ended. The site is briefly unavailable while it rebuilds.')]
class RebuildTool extends Tool
{
    use ForAdmins;

    public function handle(Request $request): Response
    {
        if (! $this->admin()) {
            return Response::error('Only admins\' chats can rebuild the site.');
        }
        if ($request->get('action') === 'start') {
            if (! Rebuild::helper()) {
                return Response::error('The server\'s rebuild helper isn\'t running, so a person has to run docker compose up -d --build on the server.');
            }
            if (! Rebuild::running()) {
                Rebuild::request();
            }

            return Response::text('Asked the server to rebuild. Check with action status in a minute or two.');
        }

        $status = Rebuild::status();

        return Response::text($this->safe('Running: '.(Rebuild::running() ? 'yes' : 'no').'. Needed: '.(Rebuild::needed() ? 'yes' : 'no').'.'
            .($status ? " Last: {$status['state']}".($status['exit'] !== null ? " (exit {$status['exit']})" : '').".\n\n".mb_substr(Rebuild::log(), -3000) : '')));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['action' => $schema->string()->enum(['start', 'status'])->required()];
    }
}
