<?php

namespace App\Http\Controllers;

use App\Models\ReturnReason;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ReturnReasonsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Returns/Reasons', [
            'reasons' => ReturnReason::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('return_reasons', 'name')],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'in:Active,Inactive'],
        ]);

        $reason = ReturnReason::create($data);
        AuditLogService::logModelChange($reason, 'created', 'returns');

        return back()->with('success', 'Reason added.');
    }

    public function update(Request $request, ReturnReason $returnReason): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('return_reasons', 'name')->ignore($returnReason->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'in:Active,Inactive'],
        ]);

        $returnReason->fill($data)->save();
        AuditLogService::logModelChange($returnReason, 'updated', 'returns');

        return back()->with('success', 'Reason updated.');
    }

    public function destroy(ReturnReason $returnReason): RedirectResponse
    {
        $returnReason->delete();
        AuditLogService::log('deleted', 'returns', ReturnReason::class, $returnReason->id);
        return back()->with('success', 'Reason deleted.');
    }
}
