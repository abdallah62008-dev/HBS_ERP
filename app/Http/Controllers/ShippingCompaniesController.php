<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShippingCompanyRequest;
use App\Http\Requests\ShippingRateRequest;
use App\Models\ShippingCompany;
use App\Models\ShippingRate;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ShippingCompaniesController extends Controller
{
    public function index(): Response
    {
        $companies = ShippingCompany::query()
            ->withCount(['shipments', 'rates'])
            ->orderBy('name')
            ->get();

        return Inertia::render('Shipping/Companies/Index', [
            'companies' => $companies,
        ]);
    }

    public function store(ShippingCompanyRequest $request): RedirectResponse
    {
        $company = ShippingCompany::create([
            ...$request->validated(),
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        AuditLogService::logModelChange($company, 'created', 'shipping');

        return redirect()->route('shipping-companies.index')
            ->with('success', "Shipping company \"{$company->name}\" created.");
    }

    public function update(ShippingCompanyRequest $request, ShippingCompany $shippingCompany): RedirectResponse
    {
        $shippingCompany->fill([
            ...$request->validated(),
            'updated_by' => Auth::id(),
        ])->save();

        AuditLogService::logModelChange($shippingCompany, 'updated', 'shipping');

        return back()->with('success', 'Company updated.');
    }

    public function destroy(ShippingCompany $shippingCompany): RedirectResponse
    {
        if ($shippingCompany->shipments()->exists()) {
            return back()->with('error', 'Cannot delete a shipping company with shipments. Mark it Inactive instead.');
        }

        $shippingCompany->delete();

        AuditLogService::log('deleted', 'shipping', ShippingCompany::class, $shippingCompany->id);

        return back()->with('success', 'Company deleted.');
    }

    /* Rates list / store / update / delete — nested under a company */

    public function rates(ShippingCompany $shippingCompany): Response
    {
        $rates = $shippingCompany->rates()
            ->orderBy('country')
            ->orderBy('city')
            ->paginate(50);

        return Inertia::render('Shipping/Rates/Index', [
            'company' => $shippingCompany,
            'rates' => $rates,
        ]);
    }

    public function storeRate(ShippingRateRequest $request, ShippingCompany $shippingCompany): RedirectResponse
    {
        $data = $request->validated();
        $data['shipping_company_id'] = $shippingCompany->id;

        ShippingRate::create([
            ...$data,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return back()->with('success', 'Rate added.');
    }

    public function updateRate(ShippingRateRequest $request, ShippingCompany $shippingCompany, ShippingRate $rate): RedirectResponse
    {
        $rate->fill([
            ...$request->validated(),
            'updated_by' => Auth::id(),
        ])->save();

        return back()->with('success', 'Rate updated.');
    }

    public function destroyRate(ShippingCompany $shippingCompany, ShippingRate $rate): RedirectResponse
    {
        $rate->delete();
        return back()->with('success', 'Rate deleted.');
    }
}
