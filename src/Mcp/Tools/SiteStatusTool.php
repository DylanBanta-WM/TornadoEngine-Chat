<?php

namespace TornadoEngine\Chat\Mcp\Tools;

use App\Modules\ModuleRegistry;
use App\Updates\DistrictBranch;
use App\Updates\Rebuild;
use App\Updates\UpdateJob;
use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('site_status')]
#[Description('The live website: Core\'s version, installed modules (and which are working copies in web/modules), recent installs and updates with the end of their logs, pending migrations, whether a rebuild is needed or running, and this site\'s own changes to Core. Use it to check your work instead of guessing.')]
class SiteStatusTool extends Tool
{
    use ForAdmins;

    public function handle(Request $request): Response
    {
        if (! $this->admin()) {
            return Response::error('Only admins\' chats can see the site\'s status.');
        }

        $lines = ['Core '.config('tornado.version')];
        $lines[] = "\nModules:";
        foreach (app(ModuleRegistry::class)->all() as $module) {
            $linked = is_link(base_path('vendor/'.$module->package));
            $lines[] = "- {$module->package} {$module->version}".($linked ? ' (working copy in web/modules)' : '').($module->compatible ? '' : ' (off: needs a newer Core or PHP extensions)');
        }

        $lines[] = "\nRecent installs and updates:";
        foreach (UpdateJob::latest('id')->take(5)->get() as $job) {
            $lines[] = "- #{$job->id} {$job->label()}: {$job->status}".($job->finished_at ? ' ('.$job->finished_at->diffForHumans().')' : '');
            if (in_array($job->status, ['failed', 'running'], true)) {
                $lines[] = '  '.str_replace("\n", "\n  ", trim(mb_substr((string) $job->log, -1500)));
            }
        }

        Artisan::call('migrate:status', ['--pending' => true]);
        $pending = substr_count(Artisan::output(), 'Pending');
        $lines[] = "\nMigrations not run yet: {$pending}";
        $lines[] = 'Rebuild: '.(Rebuild::running() ? 'running' : (Rebuild::needed() ? 'needed' : 'not needed')).(Rebuild::helper() ? '' : ' (the rebuild helper isn\'t running)');

        if (class_exists(DistrictBranch::class)) {
            $district = DistrictBranch::remembered();
            $lines[] = "\nThis site's changes to Core (on top of ".($district['base'] ?? '?').'): '.(count($district['commits']) ?: 'none');
            foreach ($district['commits'] as $commit) {
                $lines[] = "- {$commit['message']}";
            }
        }

        return Response::text($this->safe(implode("\n", $lines)));
    }
}
