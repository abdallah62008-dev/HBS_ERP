<?php

namespace App\Http\Controllers;

use App\Models\ExportLog;
use App\Services\ExportCenterService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportsController extends Controller
{
    public function __construct(
        private readonly ExportCenterService $service,
    ) {}

    public function index(): Response
    {
        return Inertia::render('ImportExport/Exports/Index', [
            'exporters' => $this->service->describeAll(),
            'recent' => ExportLog::with('exportedBy:id,name')->latest('id')->paginate(30),
        ]);
    }

    public function download(Request $request): BinaryFileResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string'],
            'filters' => ['nullable', 'array'],
        ]);

        $filters = $data['filters'] ?? [];

        return $this->service->download($data['type'], $filters, $request);
    }
}
