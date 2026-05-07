<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\UpdateTicketRequest;
use App\Models\Ticket;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 7 — Tickets module CRUD.
 *
 * Permission gating lives at the route layer (tickets.view / .create /
 * .edit / .delete / .manage). Per-record ownership is enforced HERE so
 * marketers can never see or mutate someone else's ticket even by
 * direct URL guess.
 *
 * Status is admin-controlled. The creator can update subject/message
 * while the ticket is open; admins/managers/customer-service may set
 * status (governed by `tickets.manage`).
 */
class TicketsController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['q', 'status']);

        $query = Ticket::query()
            ->with('user:id,name,email')
            ->status($filters['status'] ?? null)
            ->search($filters['q'] ?? null)
            ->latest('id');

        // Marketers (and any user without tickets.manage) only see their
        // own tickets. Mirrors Order::scopeForCurrentMarketer pattern.
        if (! $request->user()->hasPermission('tickets.manage')) {
            $query->ownedBy(Auth::id());
        }

        return Inertia::render('Tickets/Index', [
            'tickets' => $query->paginate(20)->withQueryString(),
            'filters' => $filters,
            'statuses' => Ticket::STATUSES,
            'can_manage' => $request->user()->hasPermission('tickets.manage'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Tickets/Create', [
            'statuses' => Ticket::STATUSES,
            'can_manage' => Auth::user()?->hasPermission('tickets.manage') ?? false,
        ]);
    }

    public function store(StoreTicketRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // Status is admin-set only. Drop incoming status when the
        // submitter doesn't carry tickets.manage; force the default.
        if (! $request->user()->hasPermission('tickets.manage')) {
            unset($data['status']);
        }

        $ticket = Ticket::create([
            ...$data,
            'user_id' => Auth::id(),
            'status' => $data['status'] ?? Ticket::STATUS_OPEN,
        ]);

        AuditLogService::logModelChange($ticket, 'created', 'tickets');

        return redirect()
            ->route('tickets.show', $ticket)
            ->with('success', 'Ticket created.');
    }

    public function show(Request $request, Ticket $ticket): Response
    {
        $this->authorizeOwnership($request, $ticket);

        $ticket->load('user:id,name,email');

        return Inertia::render('Tickets/Show', [
            'ticket' => $ticket,
            'can_edit' => $this->canEdit($request, $ticket),
            'can_delete' => $request->user()->hasPermission('tickets.delete')
                || $request->user()->hasPermission('tickets.manage'),
        ]);
    }

    public function edit(Request $request, Ticket $ticket): Response
    {
        $this->authorizeOwnership($request, $ticket);

        if (! $this->canEdit($request, $ticket)) {
            abort(403, 'You cannot edit this ticket.');
        }

        $ticket->load('user:id,name,email');

        return Inertia::render('Tickets/Edit', [
            'ticket' => $ticket,
            'statuses' => Ticket::STATUSES,
            'can_manage' => $request->user()->hasPermission('tickets.manage'),
        ]);
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->authorizeOwnership($request, $ticket);

        if (! $this->canEdit($request, $ticket)) {
            abort(403, 'You cannot edit this ticket.');
        }

        $data = $request->validated();
        $oldStatus = $ticket->status;

        // Only admins/managers/customer-service (carriers of tickets.manage)
        // can change status. Strip it for everyone else.
        if (! $request->user()->hasPermission('tickets.manage')) {
            unset($data['status']);
        }

        $ticket->fill($data)->save();

        $action = ($oldStatus !== $ticket->status) ? 'status_changed' : 'updated';
        AuditLogService::logModelChange($ticket, $action, 'tickets');

        return redirect()
            ->route('tickets.show', $ticket)
            ->with('success', 'Ticket updated.');
    }

    public function destroy(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorizeOwnership($request, $ticket);

        // Hard delete is fine — tickets table has no soft-deletes column.
        $ticket->delete();

        AuditLogService::log(
            action: 'deleted',
            module: 'tickets',
            recordType: Ticket::class,
            recordId: $ticket->id,
            oldValues: ['subject' => $ticket->subject, 'status' => $ticket->status],
        );

        return redirect()
            ->route('tickets.index')
            ->with('success', 'Ticket deleted.');
    }

    /**
     * 403 if the requester is neither the ticket's owner nor carrying
     * tickets.manage. Called from show/edit/update/destroy to prevent
     * IDOR via direct URL access.
     */
    private function authorizeOwnership(Request $request, Ticket $ticket): void
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $isOwner = $ticket->user_id === $user->id;
        $canManage = $user->hasPermission('tickets.manage');

        if (! $isOwner && ! $canManage) {
            abort(403, 'You do not have access to this ticket.');
        }
    }

    /**
     * The ticket creator may always edit their open ticket's body.
     * Admins (tickets.edit or tickets.manage) may edit at any time.
     */
    private function canEdit(Request $request, Ticket $ticket): bool
    {
        $user = $request->user();
        if ($user->hasPermission('tickets.edit') || $user->hasPermission('tickets.manage')) {
            return true;
        }
        // Owner-only: can edit while ticket is still open.
        return $ticket->user_id === $user->id && $ticket->status === Ticket::STATUS_OPEN;
    }
}
