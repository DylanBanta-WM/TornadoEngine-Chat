<?php

namespace TornadoEngine\Chat\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use TornadoEngine\Chat\Mcp\Tools\ApproveTool;
use TornadoEngine\Chat\Mcp\Tools\InstallModuleTool;
use TornadoEngine\Chat\Mcp\Tools\RenameChatTool;

#[Name('Tornado Engine chat')]
#[Version('0.1.0')]
#[Instructions('Tools for building Tornado Engine modules from the Chat page: install_module installs a module from /workspace/web/modules on the website; rename_chat retitles the current chat.')]
class ChatServer extends Server
{
    protected array $tools = [
        ApproveTool::class,
        InstallModuleTool::class,
        RenameChatTool::class,
    ];
}
