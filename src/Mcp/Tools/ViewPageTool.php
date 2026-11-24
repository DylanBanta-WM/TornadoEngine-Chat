<?php

namespace TornadoEngine\Chat\Mcp\Tools;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('view_page')]
#[Description('Opens a page of the live website (for example /settings/updates or /chat) in broker view, the way you may see it: people\'s details are pseudonyms. Returns its text (or the start of its HTML with html: true), and the status code. Use it to check a page you changed.')]
class ViewPageTool extends Tool
{
    use ForAdmins;

    public function handle(Request $request): Response
    {
        if (! $this->admin()) {
            return Response::error('Only admins\' chats can open the site\'s pages.');
        }
        $path = '/'.ltrim((string) $request->get('path', '/'), '/');
        if (! preg_match('#^/[A-Za-z0-9/_.\-?=&%]*$#', $path) || str_starts_with($path, '/mcp') || str_starts_with($path, '/api/harness')) {
            return Response::error('path is a page of this website, for example /settings/updates.');
        }

        // Through the website itself, in broker view (App\Http\Middleware\BrokerView signs in as Claude and pseudonymizes).
        $page = HttpRequest::create($path, 'GET', server: ['TORNADO_VIEW' => 'broker', 'HTTP_ACCEPT' => 'text/html']);
        $response = app(Kernel::class)->handle($page);
        $content = (string) $response->getContent();

        if ($request->get('html')) {
            $body = mb_substr($content, 0, 20000);
        } else {
            $content = (string) preg_replace('#<(script|style|svg)\b[^>]*>.*?</\1>#is', '', $content);
            $content = (string) preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr)\b[^>]*>#i', "\n", $content);
            $body = trim((string) preg_replace("/[ \t]+/", ' ', (string) preg_replace("/\n\s*\n+/", "\n", html_entity_decode(strip_tags($content)))));
            $body = mb_substr($body, 0, 15000);
        }
        $location = $response->headers->get('Location');

        return Response::text($this->safe("{$path}: {$response->getStatusCode()}".($location ? " → {$location}" : '')."\n\n{$body}"));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('The page, for example /settings/updates.')->required(),
            'html' => $schema->boolean()->description('The HTML instead of the text.'),
        ];
    }
}
