<?php

use App\Http\Middleware\AuthenticateHarness;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use TornadoEngine\Broker\Http\OnlyFromHarness;
use TornadoEngine\Chat\Http\ChatController;
use TornadoEngine\Chat\Mcp\ChatServer;

// Claude's tools, for the harness only.
Mcp::web('/mcp/chat', ChatServer::class)->middleware([OnlyFromHarness::class, AuthenticateHarness::class]);

Route::middleware(['web', 'auth', 'can:administration'])->post('/administration/chat/prompt', [ChatController::class, 'prompt'])->name('chat.prompt');
Route::middleware(['web', 'auth', 'can:administration'])->post('/administration/chat/vendors', [ChatController::class, 'vendors'])->name('chat.vendors');
Route::middleware(['web', 'auth', 'can:administration'])->post('/administration/chat/module-prompt', [ChatController::class, 'modulePrompt'])->name('chat.module-prompt');

Route::middleware(['web', 'auth', 'can:chat.use'])
    ->prefix('chat')
    ->name('chat.')
    ->group(function () {
        Route::get('/', [ChatController::class, 'index'])->name('index');
        Route::post('/', [ChatController::class, 'start'])->name('start');
        Route::get('/{conversation}', [ChatController::class, 'show'])->name('show');
        Route::get('/{conversation}/turns', [ChatController::class, 'turns'])->name('turns');
        Route::post('/{conversation}/messages', [ChatController::class, 'send'])->name('send');
        Route::post('/{conversation}/stop', [ChatController::class, 'stop'])->name('stop');
        Route::post('/{conversation}/save', [ChatController::class, 'save'])->name('save');
        Route::post('/{conversation}/approvals/{approval}', [ChatController::class, 'answer'])->name('answer');
    });
