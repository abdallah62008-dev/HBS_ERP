<?php

namespace App\Http\Controllers;

use App\Models\BackupLog;
use App\Services\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class BackupsController extends Controller
{
    public function __construct(
        private readonly BackupService $service,
    ) {}

    public function index(): Response
    {
        $backups = BackupLog::with('createdBy:id,name')
            ->latest('id')
            ->paginate(30);

        return Inertia::render('Backups/Index', [
            'backups' => $backups,
            'last_success' => $this->service->latestSuccessfulBackup(),
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $log = $this->service->runDatabaseBackup($data['notes'] ?? null);
        } catch (Throwable $e) {
            return back()->with('error', "Backup failed: " . $e->getMessage());
        }

        return back()->with('success', "Backup completed ({$log->size}).");
    }
}
