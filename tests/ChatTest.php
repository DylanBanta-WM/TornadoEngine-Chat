<?php

namespace TornadoEngine\Chat\Tests;

use App\Harness\HarnessTask;
use App\Models\Setting;
use App\Models\User;
use App\Updates\UpdateJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use TornadoEngine\Broker\Filter;
use TornadoEngine\Broker\Models\Person;
use TornadoEngine\Broker\Pseudonym;
use TornadoEngine\Chat\Chat;
use TornadoEngine\Chat\Mcp\ChatServer;
use TornadoEngine\Chat\Mcp\Tools\ApproveTool;
use TornadoEngine\Chat\Mcp\Tools\InstallModuleTool;
use TornadoEngine\Chat\Mcp\Tools\RenameChatTool;
use TornadoEngine\Chat\Models\Approval;
use TornadoEngine\Chat\Models\Conversation;
use TornadoEngine\Cron\Cron;
use TornadoEngine\Uploads\Models\Upload;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    private string $student = 'wm30ann@school.test';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('broker.refreshed_at', now()->toIso8601String());
        Person::create(['kind' => 'student', 'email' => $this->student, 'pseudonym' => Pseudonym::for($this->student), 'values' => [[$this->student, 'email'], ['Annabel Quince', 'name']]]);
        Person::create(['kind' => 'staff', 'email' => 'kim@school.test', 'pseudonym' => Pseudonym::for('kim@school.test'), 'values' => [['kim@school.test', 'email']]]);
        app(Filter::class)->forget();
        config(['chat.approve_wait' => 0, 'chat.install_wait' => 0, 'chat.approve_poll' => 0]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function pseudonym(): string
    {
        return Pseudonym::for($this->student).'@student';
    }

    public function test_messages_reach_claude_only_as_pseudonyms(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/chat', ['text' => "Make a tool to email {$this->student}'s teacher about Annabel Quince"])
            ->assertRedirect();

        $task = HarnessTask::sole();
        $this->assertStringNotContainsString($this->student, $task->prompt);
        $this->assertStringNotContainsString('Annabel', $task->prompt);
        $this->assertStringContainsString($this->pseudonym(), $task->prompt);
        $this->assertSame(['chat', 'auto', 'mcp__tornado-chat__approve'], [$task->source, $task->permission_mode, $task->permission_prompt_tool]);
        $this->assertStringContainsString('StyleGuide.MD', $task->system_prompt);

        // The person sees what they wrote.
        $conversation = Conversation::sole();
        $this->actingAs($admin)->get("/chat/{$conversation->id}")->assertSee("email {$this->student}", false)->assertSee('Waiting for the harness');
    }

    public function test_claudes_answer_shows_real_people_and_what_it_did(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/chat', ['text' => 'Hello']);
        $task = HarnessTask::sole();
        $task->update(['status' => 'done', 'result_session_id' => 'session-1']);
        foreach ([
            ['type' => 'system', 'subtype' => 'init', 'session_id' => 'session-1'],
            ['type' => 'assistant', 'message' => ['content' => [
                ['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => 'php artisan test']],
                ['type' => 'tool_use', 'name' => 'Write', 'input' => ['file_path' => '/workspace/web/modules/notes/composer.json']],
                ['type' => 'text', 'text' => '**[name '.Pseudonym::for($this->student)."]** is {$this->pseudonym()}. <script>alert(1)</script>"],
            ]]],
        ] as $event) {
            $task->events()->create(['event' => $event]);
        }

        $conversation = Conversation::sole();
        $this->actingAs($admin)->get("/chat/{$conversation->id}")
            ->assertSee('Ran php artisan test')
            ->assertSee('Wrote modules/notes/composer.json')
            ->assertSee('<strong>Annabel Quince</strong>', false)
            ->assertSee($this->student)
            ->assertDontSee('<script>alert(1)</script>', false);

        // The next message continues the same Claude Code session.
        $this->actingAs($admin)->post("/chat/{$conversation->id}/messages", ['text' => 'Thanks']);
        $this->assertSame(['session-1', 'auto'], [HarnessTask::latest('id')->first()->session_id, HarnessTask::latest('id')->first()->permission_mode]);
    }

    public function test_one_message_at_a_time_and_it_can_be_stopped(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/chat', ['text' => 'Build something']);
        $conversation = Conversation::sole();

        $this->actingAs($admin)->from("/chat/{$conversation->id}")->post("/chat/{$conversation->id}/messages", ['text' => 'Again'])
            ->assertSessionHasErrors(['text' => 'Claude is still working on your last message.']);

        $this->actingAs($admin)->post("/chat/{$conversation->id}/stop");
        $this->assertSame('stopped', HarnessTask::sole()->status);
    }

    public function test_nothing_is_sent_until_the_broker_is_ready(): void
    {
        Setting::put('broker.refreshed_at', null);

        $this->actingAs($this->admin())->from('/chat')->post('/chat', ['text' => 'Hello'])->assertSessionHasErrors('text');
        $this->assertSame(0, HarnessTask::count());
        $this->assertSame(0, Conversation::count());
    }

    public function test_permission_prompts_show_in_the_chat(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/chat', ['text' => 'Build it']);
        HarnessTask::sole()->update(['status' => 'running']);

        // Nobody answers in time: denied.
        ChatServer::tool(ApproveTool::class, ['tool_name' => 'Bash', 'input' => ['command' => 'rm -rf modules/notes']])->assertSee('"behavior":"deny"');
        $conversation = Conversation::sole();
        $this->actingAs($admin)->get("/chat/{$conversation->id}")->assertSee('rm -rf modules/notes')->assertSee('Denied');

        // The person allows it.
        Approval::created(fn (Approval $approval) => $approval->update(['status' => 'allowed']));
        ChatServer::tool(ApproveTool::class, ['tool_name' => 'Write', 'input' => ['file_path' => '/workspace/web/modules/notes/x.php']])->assertSee('"behavior":"allow"');
    }

    public function test_people_answer_prompts_with_allow_or_deny(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/chat', ['text' => 'Build it']);
        $conversation = Conversation::sole();
        $approval = Approval::create(['chat_conversation_id' => $conversation->id, 'harness_task_id' => HarnessTask::sole()->id, 'tool' => 'Bash', 'input' => ['command' => 'php artisan test']]);

        $this->actingAs($admin)->get("/chat/{$conversation->id}")->assertSee('Allow')->assertSee('Deny');
        $this->actingAs($admin)->post("/chat/{$conversation->id}/approvals/{$approval->id}", ['allow' => '1']);
        $this->assertSame('allowed', $approval->fresh()->status);
    }

    public function test_install_module_queues_the_folder_for_the_updater(): void
    {
        ChatServer::tool(InstallModuleTool::class, ['folder' => '../core'])->assertHasErrors();
        ChatServer::tool(InstallModuleTool::class, ['folder' => 'zz-missing'])->assertHasErrors();

        $folder = base_path('modules/zz-chat-test');
        File::ensureDirectoryExists($folder);
        file_put_contents("{$folder}/composer.json", json_encode(['name' => 'local/zz-chat-test', 'extra' => ['tornado' => ['provider' => 'Local\\ZzChatTest\\Provider']]]));
        try {
            ChatServer::tool(InstallModuleTool::class, ['folder' => 'zz-chat-test'])->assertSee('hasn\'t finished');
        } finally {
            File::deleteDirectory($folder);
        }

        $job = UpdateJob::sole();
        $this->assertSame(['module', 'local/zz-chat-test', 'modules/zz-chat-test'], [$job->kind, $job->package, $job->repository]);
    }

    public function test_install_module_waits_for_the_updater_and_reports_the_install(): void
    {
        config(['chat.install_wait' => 10]);
        // The updater finishes it straight away (in the database, as the real one does).
        UpdateJob::created(fn (UpdateJob $job) => \DB::table('update_jobs')->where('id', $job->id)->update(['status' => 'done', 'log' => 'Linked.']));
        $folder = base_path('modules/zz-chat-test');
        File::ensureDirectoryExists($folder);
        file_put_contents("{$folder}/composer.json", json_encode(['name' => 'local/zz-chat-test', 'extra' => ['tornado' => ['provider' => 'Local\\ZzChatTest\\Provider']]]));
        try {
            ChatServer::tool(InstallModuleTool::class, ['folder' => 'zz-chat-test'])->assertSee('Installed local/zz-chat-test.');
        } finally {
            File::deleteDirectory($folder);
        }
    }

    public function test_only_people_with_the_permission_and_only_their_own_chats(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/chat', ['text' => 'Mine']);
        $conversation = Conversation::sole();

        $this->actingAs(User::factory()->create())->get('/chat')->assertForbidden();
        $this->actingAs($this->admin())->get("/chat/{$conversation->id}")->assertNotFound();
    }

    public function test_the_system_prompt_names_the_districts_packages_and_installed_modules(): void
    {
        Setting::put('site.url', 'https://te.westmschools.com');
        $prompt = Chat::systemPrompt();

        $this->assertStringContainsString('Composer package name westmschools/<folder>', $prompt);
        $this->assertStringContainsString('Broker', $prompt);
        $this->assertStringContainsString('$cron->job(...)', $prompt);
        $this->assertStringContainsString('Sharing a module', $prompt);

        Setting::put('site.url', null);
        $this->assertSame('local', Chat::vendor());
    }

    public function test_the_clock_shows_how_long_an_unsaved_chat_has_left(): void
    {
        $admin = $this->admin();
        $chat = Conversation::create(['user_id' => $admin->id, 'title' => 'Temporary']);
        Conversation::whereKey($chat->id)->update(['updated_at' => now()->subDays(3)->subHour()]);

        $this->actingAs($admin)->get("/chat/{$chat->id}")->assertSee('11d')->assertSee('This chat will expire in 11 days unless saved.');

        Conversation::whereKey($chat->id)->update(['updated_at' => now()->subDays(13)->subHours(18)]);
        $this->actingAs($admin)->get("/chat/{$chat->id}")->assertSee('6h')->assertSee('This chat will expire in 6 hours unless saved.');

        $chat->update(['saved' => true]);
        $this->actingAs($admin)->get("/chat/{$chat->id}")->assertDontSee('unless saved');
    }

    public function test_people_can_rename_their_chats_and_claude_then_leaves_the_name_alone(): void
    {
        $admin = $this->admin();
        $chat = Conversation::create(['user_id' => $admin->id, 'title' => 'First question']);
        HarnessTask::create(['source' => 'chat', 'reference' => (string) $chat->id, 'status' => 'running', 'prompt' => 'First question']);
        $updatedAt = $chat->fresh()->updated_at;

        $this->travel(1)->hours();
        $this->actingAs($admin)->post("/chat/{$chat->id}/rename", ['title' => ''])->assertSessionHasErrors('title');

        ChatServer::tool(RenameChatTool::class, ['title' => 'Planning a field trip form'])->assertOk();
        $this->assertSame('Planning a field trip form', $chat->fresh()->title);
        $this->assertFalse($chat->fresh()->renamed);

        $this->actingAs($admin)->post("/chat/{$chat->id}/rename", ['title' => 'My trips'])->assertRedirect();
        $this->assertSame('My trips', $chat->fresh()->title);
        $this->assertTrue($chat->fresh()->renamed);

        ChatServer::tool(RenameChatTool::class, ['title' => 'Something else'])->assertSee('named this chat themselves');
        $this->assertSame('My trips', $chat->fresh()->title);
        $this->assertEquals($updatedAt, $chat->fresh()->updated_at);

        $other = User::factory()->create();
        $this->actingAs($other)->post("/chat/{$chat->id}/rename", ['title' => 'Mine now'])->assertForbidden();
    }

    public function test_chats_are_deleted_14_days_after_they_were_last_used_unless_saved(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/chat', ['text' => 'Old and temporary']);
        $this->actingAs($admin)->post('/chat', ['text' => 'Old but saved']);
        $this->actingAs($admin)->post('/chat', ['text' => 'Recent']);
        [$old, $saved, $recent] = Conversation::orderBy('id')->get()->all();

        $this->actingAs($admin)->post("/chat/{$saved->id}/save");
        $this->assertTrue($saved->fresh()->saved);
        $this->actingAs($admin)->get('/chat')->assertSeeInOrder(['History', 'Saved'])->assertDontSee('name="mode"', false);
        $this->actingAs($admin)->get("/chat/{$saved->id}")->assertSee('Unsave');

        Conversation::whereKey([$old->id, $saved->id])->update(['updated_at' => now()->subDays(15)]);
        app(Cron::class)->run(app(Cron::class)->find('chat.prune'));

        $this->assertSame([$saved->id, $recent->id], Conversation::orderBy('id')->pluck('id')->all());
        $this->assertSame(0, HarnessTask::where('reference', (string) $old->id)->count(), 'what Claude did goes too');
    }

    public function test_a_second_message_shows_claude_working_until_it_replies(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/chat', ['text' => 'Hello']);
        $conversation = Conversation::first();
        HarnessTask::query()->update(['status' => 'done']);

        $this->actingAs($admin)->post("/chat/{$conversation->id}/messages", ['text' => 'Make a module']);

        $this->assertTrue($conversation->fresh()->running());
        $this->actingAs($admin)->get("/chat/{$conversation->id}")->assertSee('Stop')->assertSee('running: true', false);
        $this->actingAs($admin)->getJson("/chat/{$conversation->id}/turns")->assertJson(['running' => true]);
        $this->actingAs($admin)->from("/chat/{$conversation->id}")->post("/chat/{$conversation->id}/messages", ['text' => 'Again'])->assertSessionHasErrors();
    }

    public function test_the_admin_can_change_the_system_prompt(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/administration/chat')->assertOk()->assertSee('{modules}')->assertDontSee('Restore default');
        $this->actingAs(User::factory()->create())->post('/administration/chat/prompt', ['prompt' => 'Nope'])->assertForbidden();

        $this->actingAs($admin)->post('/administration/chat/prompt', ['prompt' => 'Be brief. Modules: {modules}. Vendor: {vendor}.']);
        $this->assertMatchesRegularExpression('/^Be brief\. Modules: .*Chat.*\. Vendor: \w+\.\n\nWhen you publish.*keep its current visibility.*The chat has a title.*rename_chat/s', Chat::systemPrompt());
        $this->actingAs($admin)->post('/chat', ['text' => 'Hello']);
        $this->assertStringStartsWith('Be brief.', HarnessTask::latest('id')->first()->system_prompt);

        $this->actingAs($admin)->post('/administration/chat/prompt', ['restore' => 1]);
        $this->assertNull(Setting::get('chat.system_prompt'));
        $this->assertStringContainsString('Installed modules, and what each offers', Chat::systemPrompt());
    }

    public function test_attaching_files_warns_about_sensitive_information(): void
    {
        $this->actingAs($this->admin())->get('/chat')
            ->assertSee('Don&#039;t upload sensitive information', false)
            ->assertSee('The broker tries to remove student information from files before Claude reads them')
            ->assertDontSee('Does this file contain student information?');
    }

    public function test_modules_tell_claude_what_they_offer_and_admins_can_edit_it_on_their_settings_page(): void
    {
        $admin = $this->admin();
        // Broker has no text of its own: it's listed by name.
        $this->assertStringContainsString("\n- Broker\n", Chat::systemPrompt());

        $this->actingAs($admin)->get('/administration/broker')->assertSee('What Claude knows about Broker');
        $this->actingAs($admin)->post('/administration/chat/module-prompt', ['package' => 'tornado-engine/broker', 'prompt' => 'Edited for this district.']);
        $this->assertStringContainsString("\n- Broker: Edited for this district.\n", Chat::systemPrompt());

        $this->actingAs($admin)->post('/administration/chat/module-prompt', ['package' => 'tornado-engine/broker', 'restore' => 1]);
        $this->assertStringContainsString("\n- Broker\n", Chat::systemPrompt());
        $this->assertStringContainsString('- GAM: Google Workspace: you can run GAM commands', Chat::systemPrompt(), "a module's own text");

        $this->actingAs($admin)->post('/administration/chat/module-prompt', ['package' => 'nobody/nothing', 'prompt' => 'x'])->assertNotFound();
        $this->actingAs(User::factory()->create())->post('/administration/chat/module-prompt', ['package' => 'tornado-engine/broker', 'prompt' => 'x'])->assertForbidden();
    }

    public function test_what_claude_sees_shows_pseudonyms_instead_of_names(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/chat', ['text' => 'Write to Annabel Quince']);
        $conversation = Conversation::first();
        $task = HarnessTask::first();
        $task->events()->create(['event' => ['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'Done for [name '.Pseudonym::for($this->student).'].']]]]]);

        $this->actingAs($admin)->get("/chat/{$conversation->id}")->assertSee('Done for Annabel Quince.')->assertSee('What Claude sees');
        $this->actingAs($admin)->get("/chat/{$conversation->id}?claude=1")
            ->assertSee('Write to [name '.Pseudonym::for($this->student).']', false)
            ->assertSee('Done for [name '.Pseudonym::for($this->student).'].', false)
            ->assertDontSee('Write to Annabel')
            ->assertDontSee('Done for Annabel');
        $this->actingAs($admin)->getJson("/chat/{$conversation->id}/turns?claude=1")->assertJsonMissing(['html' => 'Annabel']);
    }

    public function test_attached_images_show_in_the_conversation(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/chat', ['text' => 'Look at this']);
        $message = Conversation::first()->messages()->first();
        Storage::fake('uploads');
        Storage::disk('uploads')->put('1/photo.jpg', 'jpeg bytes');
        $upload = Upload::create(['user_id' => $admin->id, 'name' => 'photo.jpg', 'mime' => 'image/jpeg', 'size' => 10, 'path' => '1/photo.jpg', 'share' => 'text', 'source' => 'chat', 'reference' => (string) $message->id]);
        Upload::create(['user_id' => $admin->id, 'name' => 'notes.txt', 'mime' => 'text/plain', 'size' => 10, 'path' => '1/notes.txt', 'share' => 'text', 'source' => 'chat', 'reference' => (string) $message->id]);

        $this->actingAs($admin)->get("/chat/{$message->chat_conversation_id}")
            ->assertSee('data-gallery="'.route('uploads.image', $upload).'"', false)
            ->assertSee('notes.txt');
        $this->actingAs($admin)->get(route('uploads.image', $upload))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs(User::factory()->create())->get(route('uploads.image', $upload))->assertForbidden();
        // Claude never got the image (it wasn't prepared), so "What Claude sees" doesn't show it.
        $this->actingAs($admin)->get("/chat/{$message->chat_conversation_id}?claude=1")->assertDontSee('data-gallery=', false);
    }

    public function test_a_file_alone_can_be_the_message(): void
    {
        $admin = $this->admin();
        Storage::fake('uploads');
        $upload = Upload::create(['user_id' => $admin->id, 'name' => 'notes.txt', 'mime' => 'text/plain', 'size' => 10, 'path' => '1/notes.txt', 'share' => 'text']);

        $this->actingAs($admin)->post('/chat', ['text' => '', 'uploads' => [$upload->id]])->assertRedirect();

        $conversation = Conversation::sole();
        $message = $conversation->messages()->sole();
        $this->assertSame('notes.txt', $conversation->title);
        $this->assertSame('', $message->text);
        $this->assertStringStartsWith("The user sent these files (copies for you, read them with the Read tool):\n- notes.txt", HarnessTask::sole()->prompt);
        $this->assertSame(['chat', (string) $message->id], [$upload->fresh()->source, $upload->fresh()->reference]);
        $this->actingAs($admin)->get("/chat/{$conversation->id}")->assertOk()->assertSee('notes.txt')->assertDontSee('rounded-3xl bg-gray-100', false);

        // In a chat, too.
        HarnessTask::query()->update(['status' => 'done']);
        $second = Upload::create(['user_id' => $admin->id, 'name' => 'more.txt', 'mime' => 'text/plain', 'size' => 10, 'path' => '1/more.txt', 'share' => 'text']);
        $this->actingAs($admin)->post("/chat/{$conversation->id}/messages", ['uploads' => [$second->id]])->assertRedirect();
        $this->assertSame(2, HarnessTask::count());
        $this->assertSame('notes.txt', $conversation->fresh()->title);
    }

    public function test_text_is_needed_without_a_file(): void
    {
        $admin = $this->admin();
        $theirs = Upload::create(['user_id' => User::factory()->create()->id, 'name' => 'notes.txt', 'mime' => 'text/plain', 'size' => 10, 'path' => '1/notes.txt', 'share' => 'text']);

        $this->actingAs($admin)->post('/chat', ['text' => ''])->assertSessionHasErrors('text');
        $this->actingAs($admin)->post('/chat', ['text' => '', 'uploads' => [$theirs->id]])->assertSessionHasErrors('text');
        $this->assertSame([0, 0], [Conversation::count(), HarnessTask::count()]);
        $this->assertNull($theirs->fresh()->source);
    }

    public function test_with_more_than_one_vendor_name_claude_asks_which_for_each_module(): void
    {
        $admin = $this->admin();
        $this->assertSame([Chat::vendor()], Chat::vendors());
        $this->assertStringNotContainsString('more than one vendor name', Chat::systemPrompt());

        $this->actingAs($admin)->post('/administration/chat/vendors', ['vendors' => Chat::vendor().', tornado-engine'])->assertSessionHas('chat_vendors_status');
        $this->assertSame([Chat::vendor(), 'tornado-engine'], Chat::vendors());
        $this->assertStringContainsString('ask the user which one', Chat::systemPrompt());
        $this->assertStringContainsString('tornado-engine is for official Tornado Engine modules', Chat::systemPrompt());

        $this->actingAs($admin)->post('/administration/chat/vendors', ['vendors' => 'Bad Name!'])->assertSessionHasErrors('vendors');
        $this->actingAs(User::factory()->create())->post('/administration/chat/vendors', ['vendors' => 'x'])->assertForbidden();
    }

    public function test_claude_code_question_forms_are_turned_down_without_asking(): void
    {
        $this->actingAs($this->admin())->post('/chat', ['text' => 'Hi']);
        HarnessTask::query()->update(['status' => 'running']);

        ChatServer::tool(ApproveTool::class, ['tool_name' => 'AskUserQuestion', 'input' => ['questions' => []]])->assertSee('Ask your question');
        $this->assertSame(0, Approval::count(), 'nothing for the person to allow or deny');
    }
}
