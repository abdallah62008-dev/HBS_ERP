<?php

namespace App\Http\Controllers;

use App\Http\Requests\WarehouseRequest;
use App\Models\Warehouse;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class WarehousesController extends Controller
{
    public function index(): Response
    {
        $warehouses = Warehouse::query()
            ->withCount('movements')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return Inertia::render('Warehouses/Index', [
            'warehouses' => $warehouses,
        ]);
    }

    public function store(WarehouseRequest $request): RedirectResponse
    {
        $warehouse = DB::transaction(function () use ($request) {
            $data = $request->validated();
            // Only one default warehouse at a time.
            if (! empty($data['is_default'])) {
                Warehouse::where('is_default', true)->update(['is_default' => false]);
            }

            $warehouse = Warehouse::create([
                ...$data,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            AuditLogService::logModelChange($warehouse, 'created', 'inventory');

            return $warehouse;
        });

        return redirect()->route('warehouses.index')
            ->with('success', "Warehouse \"{$warehouse->name}\" created.");
    }

    public function update(WarehouseRequest $request, Warehouse $warehouse): RedirectResponse
    {
        DB::transaction(function () use ($request, $warehouse) {
            $data = $request->validated();
            if (! empty($data['is_default'])) {
                Warehouse::where('is_default', true)
                    ->where('id', '!=', $warehouse->id)
                    ->update(['is_default' => false]);
            }

            $warehouse->fill([...$data, 'updated_by' => Auth::id()])->save();

            AuditLogService::logModelChange($warehouse, 'updated', 'inventory');
        });

        return redirect()->route('warehouses.index')
            ->with('success', 'Warehouse updated.');
    }

    public function destroy(Warehouse $warehouse): RedirectResponse
    {
        if ($warehouse->movements()->exists()) {
            return back()->with('error', 'Cannot delete a warehouse that has inventory movements. Mark it Inactive instead.');
        }

        $warehouse->delete();

        AuditLogService::log(
            action: 'deleted',
            module: 'inventory',
            recordType: Warehouse::class,
            recordId: $warehouse->id,
        );

        return redirect()->route('warehouses.index')->with('success', 'Warehouse deleted.');
    }
}
