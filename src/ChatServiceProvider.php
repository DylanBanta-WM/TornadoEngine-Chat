<?php

namespace TornadoEngine\Chat;

use App\Harness\HarnessMcp;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use TornadoEngine\Access\Access;
use TornadoEngine\Nav\Nav;

class ChatServiceProvider extends ServiceProvider
{
    public function boot(HarnessMcp $mcp): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'chat');
        $this->loadRoutesFrom(__DIR__.'/../routes/chat.php');

        $mcp->server('tornado-chat', '/mcp/chat');

        // The setup admin; with the Access module, roles can be given it.
        Gate::define('chat.use', fn (User $user) => (bool) $user->is_admin);
        $this->callAfterResolving(Access::class, function (Access $access) {
            $access->permission('chat.use', __('Use Chat'), 'Chat');
        });

        $this->callAfterResolving(Nav::class, function (Nav $nav) {
            $nav->add(__('Chat'), 'chat.index', active: 'chat.*', order: 400, can: 'chat.use');
            if (method_exists($nav, 'tool')) {
                $nav->tool(__('Chat'), 'chat.index', __('Ask Claude for a new tool, and it builds it.'), section: __('Tools'), icon: 'chat', color: 'indigo', can: 'chat.use');
            }
        });
    }
}
