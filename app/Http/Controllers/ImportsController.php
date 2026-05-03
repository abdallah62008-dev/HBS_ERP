<?php

namespace App\Http\Controllers;

use App\Models\ImportJob;
use App\Services\ImportService;
use App\Services\Importers\ImporterRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ImportsController extends Controller
{
    public function __construct(
        private readonly ImportService $service,
        private readonly ImporterRegistry $registry,
    ) {}

    public function index(): Response
    {
        $jobs = ImportJob::with('createdBy:id,name', 'undoneBy:id,name')
            ->latest('id')
            ->paginate(20);

        return Inertia::render('ImportExport/Imports/Index', [
            'jobs' => $jobs,
        ]);
    }

    public function create(Request $request): Response
    {
        $type = $request->query('type', 'products');

        return Inertia::render('ImportExport/Imports/Create', [
            'type' => $type,
            'importers' => $this->registry->describeAll(),
        ]);
    }

    /**
     * Step 1 endpoint — handles the file upload and creates the
     * preview. Redirects to the preview page.
     */
    public function upload(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', $this->registry->slugs())],
            'file' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv'],
        ]);

        try {
            $job = $this->service->uploadAndPreview($data['type'], $request->file('file'));
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('imports.show', $job)
            ->with('success', "Preview ready: {$job->successful_rows} ok, {$job->failed_rows} failed, {$job->duplicate_rows} duplicate.");
    }

    public function show(ImportJob $import): Response
    {
        $rows = $import->rows()
            ->orderBy('row_number')
            ->paginate(50);

        return Inertia::render('ImportExport/Imports/Show', [
            'job' => $import,
            'rows' => $rows,
        ]);
    }

    /**
     * Step 2 — commit the previewed rows.
     */
    public function commit(ImportJob $import): RedirectResponse
    {
        try {
            $this->service->commit($import);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Committed {$import->fresh()->successful_rows} record(s).");
    }

    public function undo(ImportJob $import): RedirectResponse
    {
        try {
            $this->service->undo($import);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Import reversed.');
    }
}
