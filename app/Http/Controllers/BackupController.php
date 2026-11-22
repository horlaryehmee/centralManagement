<?php

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Models\Event;
use App\Models\Installation;
use App\Services\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Requesting, downloading and deleting website backups (admin). */
class BackupController extends Controller
{
    public function __construct(protected BackupService $backups) {}

    public function store(Request $request, Installation $installation): RedirectResponse
    {
        $data = $request->validate(['include_files' => ['boolean']]);

        if (! $installation->supports('backup')) {
            return back()->with('error', 'This website’s version can’t make backups from here yet. Update it first.');
        }
        if (in_array($installation->status, ['pending', 'revoked'], true)) {
            return back()->with('error', 'This website isn’t connected.');
        }
        $this->backups->expireStale($installation);
        if ($installation->backups()->active()->exists()) {
            return back()->with('error', 'A backup is already on its way for this website.');
        }

        $installation->backups()->create(['include_files' => $request->boolean('include_files', true), 'requested_by' => $request->user()->id]);
        Event::log('backup.requested', 'Backup requested'.($request->boolean('include_files', true) ? ' (database and files).' : ' (database only).'), ['installation' => $installation]);

        return back()->with('success', 'Backup requested. The website creates it at its next check-in (within '.$installation->check_interval.' minutes) and sends it here.');
    }

    public function download(Backup $backup): StreamedResponse
    {
        Event::log('backup.downloaded', 'Backup #'.$backup->id.' downloaded.', ['installation' => $backup->installation]);

        return $this->backups->download($backup);
    }

    public function destroy(Backup $backup): RedirectResponse
    {
        $installation = $backup->installation;
        $active = $backup->isActive();
        $this->backups->delete($backup);
        Event::log('backup.deleted', $active ? 'Backup request cancelled.' : 'Backup deleted.', ['installation' => $installation]);

        return back()->with('success', $active ? 'Backup request cancelled.' : 'Backup deleted.');
    }
}
