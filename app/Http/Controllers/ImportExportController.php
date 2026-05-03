<?php

namespace App\Http\Controllers;

use App\Models\ExportLog;
use App\Models\ImportJob;
use App\Services\ExportCenterService;
use App\Services\Importers\ImporterRegistry;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hub page for /import-export — shows the available importers / exporters
 * and the most recent jobs so operators can resume or re-download.
 */
class ImportExportController extends Controller
{
    public function index(
        ImporterRegistry $registry,
        ExportCenterService $exporters,
    ): Response {
        return Inertia::render('ImportExport/Index', [
            'importers' => $registry->describeAll(),
            'exporters' => $exporters->describeAll(),
            'recent_imports' => ImportJob::query()
                ->with('createdBy:id,name')
                ->latest('id')->limit(8)->get(),
            'recent_exports' => ExportLog::query()
                ->with('exportedBy:id,name')
                ->latest('id')->limit(8)->get(),
        ]);
    }
}
