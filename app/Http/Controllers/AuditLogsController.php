<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only viewer over the audit_logs table that's been written to since
 * Phase 1. Heavy filters live in the URL so /audit-logs?module=orders
 * deep-links from anywhere in the app.
 */
class AuditLogsController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['user_id', 'module', 'action', 'record_type', 'q', 'from', 'to']);

        $logs = AuditLog::query()
            ->with('user:id,name,email')
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['module'] ?? null, fn ($q, $v) => $q->where('module', $v))
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($filters['record_type'] ?? null, fn ($q, $v) => $q->where('record_type', 'like', "%{$v}%"))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('action', 'like', "%{$term}%")
                        ->orWhere('module', 'like', "%{$term}%")
                        ->orWhere('record_type', 'like', "%{$term}%");
                });
            })
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('AuditLogs/Index', [
            'logs' => $logs,
            'filters' => $filters,
            'modules' => AuditLog::query()->select('module')->distinct()->orderBy('module')->pluck('module'),
            'actions' => AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action'),
            'users' => User::orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function show(AuditLog $log): Response
    {
        $log->load('user:id,name,email');
        return Inertia::render('AuditLogs/Show', [
            'log' => $log,
        ]);
    }
}
