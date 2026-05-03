<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdCampaignRequest;
use App\Models\AdCampaign;
use App\Models\Product;
use App\Services\AdCampaignService;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AdCampaignsController extends Controller
{
    public function __construct(
        private readonly AdCampaignService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['q', 'platform', 'status']);

        $campaigns = AdCampaign::query()
            ->with('product:id,sku,name')
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where('name', 'like', "%{$term}%"))
            ->when($filters['platform'] ?? null, fn ($q, $v) => $q->where('platform', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->latest('start_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Ads/Index', [
            'campaigns' => $campaigns,
            'filters' => $filters,
            'platforms' => AdCampaign::PLATFORMS,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Ads/Create', [
            'products' => Product::where('status', 'Active')->orderBy('name')->get(['id', 'sku', 'name']),
            'platforms' => AdCampaign::PLATFORMS,
        ]);
    }

    public function store(AdCampaignRequest $request): RedirectResponse
    {
        $campaign = AdCampaign::create([
            ...$request->validated(),
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        AuditLogService::logModelChange($campaign, 'created', 'ads');

        return redirect()->route('ads.show', $campaign)->with('success', 'Campaign created.');
    }

    public function show(AdCampaign $ad): Response
    {
        // Recompute live so the show page is always current.
        $this->service->rollup($ad);
        $ad->load('product:id,sku,name', 'expenses.category:id,name');

        return Inertia::render('Ads/Show', [
            'campaign' => $ad,
        ]);
    }

    public function edit(AdCampaign $ad): Response
    {
        return Inertia::render('Ads/Edit', [
            'campaign' => $ad,
            'products' => Product::where('status', 'Active')->orderBy('name')->get(['id', 'sku', 'name']),
            'platforms' => AdCampaign::PLATFORMS,
        ]);
    }

    public function update(AdCampaignRequest $request, AdCampaign $ad): RedirectResponse
    {
        $ad->fill([...$request->validated(), 'updated_by' => Auth::id()])->save();
        AuditLogService::logModelChange($ad, 'updated', 'ads');
        return redirect()->route('ads.show', $ad)->with('success', 'Campaign updated.');
    }

    public function destroy(AdCampaign $ad): RedirectResponse
    {
        $ad->delete();
        AuditLogService::log('deleted', 'ads', AdCampaign::class, $ad->id);
        return redirect()->route('ads.index')->with('success', 'Campaign deleted.');
    }

    /**
     * On-demand rollup recompute (button on the campaign show page).
     */
    public function rollup(AdCampaign $ad): RedirectResponse
    {
        $this->service->rollup($ad);
        return back()->with('success', 'Metrics recomputed.');
    }
}
