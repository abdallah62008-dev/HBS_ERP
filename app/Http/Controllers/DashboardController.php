<?php

namespace App\Http\Controllers;

use App\Models\FiscalYear;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Phase 1 dashboard. Real KPIs (orders, profit, low stock, etc.) are
     * added in later phases — for now we surface a sanity-check of the
     * foundation so the operator can confirm the install is healthy.
     */
    public function __invoke(): Response
    {
        $openYear = FiscalYear::where('status', 'Open')->latest('start_date')->first();

        return Inertia::render('Dashboard', [
            'stats' => [
                'roles' => Role::count(),
                'permissions' => Permission::count(),
                'users' => User::where('status', 'Active')->count(),
                'fiscal_year' => $openYear?->name,
                'fiscal_year_status' => $openYear ? "Open · ends {$openYear->end_date->toDateString()}" : null,
            ],
        ]);
    }
}
