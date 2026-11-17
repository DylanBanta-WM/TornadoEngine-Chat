<?php

namespace TornadoEngine\Chat\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use TornadoEngine\Chat\Chat;
use TornadoEngine\Chat\Models\Approval;
use TornadoEngine\Chat\Models\Conversation;
use TornadoEngine\Chat\Transcript;

class ChatController extends Controller
{
    public function index(Request $request): View
    {
        return view('chat::index', [
            'conversations' => Conversation::where('user_id', $request->user()->id)->latest('updated_at')->get(),
        ]);
    }

    public function start(Request $request, Chat $chat): RedirectResponse
    {
        $data = $this->message($request);
        $conversation = Conversation::create(['user_id' => $request->user()->id, 'title' => Str::limit($data['text'], 60)]);

        try {
            $chat->send($conversation, $data['text']);
        } catch (RuntimeException $e) {
            $conversation->delete();

            return back()->withErrors(['text' => $e->getMessage()])->withInput();
        }

        return redirect()->route('chat.show', $conversation);
    }

    public function show(Request $request, Conversation $conversation, Transcript $transcript): View
    {
        $this->own($request, $conversation);

        return view('chat::show', [
            'conversation' => $conversation,
            'turns' => $transcript->turns($conversation),
            'running' => $conversation->running(),
            'conversations' => Conversation::where('user_id', $request->user()->id)->latest('updated_at')->take(20)->get(),
        ]);
    }

    /** The conversation so far, for the page to refresh while Claude works. */
    public function turns(Request $request, Conversation $conversation, Transcript $transcript): JsonResponse
    {
        $this->own($request, $conversation);

        return response()->json([
            'html' => view('chat::turns', ['conversation' => $conversation, 'turns' => $transcript->turns($conversation)])->render(),
            'running' => $conversation->running(),
        ]);
    }

    public function send(Request $request, Conversation $conversation, Chat $chat): RedirectResponse
    {
        $this->own($request, $conversation);
        $data = $this->message($request);

        try {
            $chat->send($conversation, $data['text']);
            $conversation->touch();
        } catch (RuntimeException $e) {
            return back()->withErrors(['text' => $e->getMessage()])->withInput();
        }

        return redirect()->route('chat.show', $conversation);
    }

    /** Keeps a chat (saved), or lets it be deleted 14 days after it was last used. */
    public function save(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->own($request, $conversation);
        $conversation->timestamps = false;
        $conversation->update(['saved' => ! $conversation->saved]);

        return back();
    }

    public function stop(Request $request, Conversation $conversation, Chat $chat): RedirectResponse
    {
        $this->own($request, $conversation);
        $chat->stop($conversation);

        return redirect()->route('chat.show', $conversation);
    }

    public function answer(Request $request, Conversation $conversation, Approval $approval): RedirectResponse|JsonResponse
    {
        $this->own($request, $conversation);
        abort_unless($approval->chat_conversation_id === $conversation->id, 404);

        if ($approval->status === 'pending') {
            $approval->update(['status' => $request->boolean('allow') ? 'allowed' : 'denied']);
        }

        return $request->expectsJson() ? response()->json(['status' => $approval->status]) : redirect()->route('chat.show', $conversation);
    }

    /** @return array{text: string} */
    private function message(Request $request): array
    {
        return $request->validate([
            'text' => ['required', 'string', 'max:20000'],
        ]);
    }

    private function own(Request $request, Conversation $conversation): void
    {
        abort_unless($conversation->user_id === $request->user()->id, 404);
    }
}
