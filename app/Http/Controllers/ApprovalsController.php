<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Services\ApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ApprovalsController extends Controller
{
    public function __construct(
        private readonly ApprovalService $approvals,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['status', 'type']);

        $requests = ApprovalRequest::query()
            ->with(['requestedBy:id,name', 'reviewedBy:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('approval_type', $v))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Approvals/Index', [
            'requests' => $requests,
            'filters' => $filters,
            'types' => ApprovalRequest::TYPES,
            'pending_count' => ApprovalRequest::pending()->count(),
        ]);
    }

    public function show(ApprovalRequest $approval): Response
    {
        $approval->load(['requestedBy:id,name,email', 'reviewedBy:id,name']);

        // Best-effort load of the related record for context.
        $related = null;
        if ($approval->related_type && $approval->related_id && class_exists($approval->related_type)) {
            $related = $approval->related_type::find($approval->related_id);
        }

        return Inertia::render('Approvals/Show', [
            'request' => $approval,
            'related' => $related,
        ]);
    }

    public function approve(Request $request, ApprovalRequest $approval): RedirectResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        try {
            $this->approvals->approve($approval, $data['notes'] ?? null);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Approved and applied.');
    }

    public function reject(Request $request, ApprovalRequest $approval): RedirectResponse
    {
        $data = $request->validate(['notes' => ['required', 'string', 'min:5', 'max:500']]);

        try {
            $this->approvals->reject($approval, $data['notes']);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Request rejected.');
    }
}
