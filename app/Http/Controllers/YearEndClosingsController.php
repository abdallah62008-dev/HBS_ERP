<?php

namespace App\Http\Controllers;

use App\Models\FiscalYear;
use App\Models\YearEndClosing;
use App\Services\YearEndClosingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class YearEndClosingsController extends Controller
{
    public function __construct(
        private readonly YearEndClosingService $service,
    ) {}

    public function index(): Response
    {
        return Inertia::render('YearEnd/Index', [
            'closings' => YearEndClosing::with(['fiscalYear', 'newFiscalYear', 'createdBy:id,name', 'backup'])
                ->latest('id')
                ->get(),
            'open_year' => FiscalYear::where('status', 'Open')->latest('start_date')->first(),
            'all_years' => FiscalYear::orderByDesc('start_date')->get(),
        ]);
    }

    /**
     * Review snapshot — counts of open business + last successful backup.
     * Operator reads this before clicking the close confirmation.
     */
    public function review(FiscalYear $fiscalYear): Response
    {
        return Inertia::render('YearEnd/Review', [
            'snapshot' => $this->service->reviewSnapshot($fiscalYear),
            'expected_confirmation' => "CLOSE {$fiscalYear->name}",
        ]);
    }

    public function close(Request $request, FiscalYear $fiscalYear): RedirectResponse
    {
        $data = $request->validate([
            'confirmation' => ['required', 'string'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $closing = $this->service->close($fiscalYear, $data['confirmation'], $data['notes'] ?? null);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('year-end.index')
            ->with('success', "Closed fiscal year {$fiscalYear->name}. New year opened.");
    }
}
