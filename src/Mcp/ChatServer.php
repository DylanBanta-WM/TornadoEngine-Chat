<?php

namespace TornadoEngine\Chat\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use TornadoEngine\Chat\Mcp\Tools\ApproveTool;
use TornadoEngine\Chat\Mcp\Tools\ArtisanTool;
use TornadoEngine\Chat\Mcp\Tools\CustomizeModuleTool;
use TornadoEngine\Chat\Mcp\Tools\InstallModuleTool;
use TornadoEngine\Chat\Mcp\Tools\RebuildTool;
use TornadoEngine\Chat\Mcp\Tools\RecentErrorsTool;
use TornadoEngine\Chat\Mcp\Tools\RenameChatTool;
use TornadoEngine\Chat\Mcp\Tools\SiteStatusTool;
use TornadoEngine\Chat\Mcp\Tools\UndoChangesTool;
use TornadoEngine\Chat\Mcp\Tools\ViewPageTool;

#[Name('Tornado Engine chat')]
#[Version('0.1.0')]
#[Instructions('Tools for building Tornado Engine from the Chat page: install_module installs a module from /workspace/web/modules on the website; rename_chat retitles the current chat. To check your work on the live site instead of guessing: site_status (versions, installs and updates, migrations, rebuilds, this site\'s changes to Core), recent_errors (the log), view_page (a page as you may see it). To act on it: artisan (migrate, cache clears and a few more), rebuild, and undo_chat_changes (this chat\'s changes to Core and customized modules, after asking). customize_module makes an installed module changeable. Everything they return goes through the broker. They\'re for admins\' chats.')]
class ChatServer extends Server
{
    protected array $tools = [
        ApproveTool::class,
        InstallModuleTool::class,
        RenameChatTool::class,
        SiteStatusTool::class,
        RecentErrorsTool::class,
        ViewPageTool::class,
        ArtisanTool::class,
        RebuildTool::class,
        UndoChangesTool::class,
        CustomizeModuleTool::class,
    ];
}
