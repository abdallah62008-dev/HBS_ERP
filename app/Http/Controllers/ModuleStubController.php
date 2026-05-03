<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the generic "Coming Soon" page for sidebar destinations whose
 * controllers haven't been built yet. Each placeholder route in
 * routes/web.php maps to this controller and supplies the module label
 * + the implementation phase via defaults().
 *
 * As later phases land, replace each placeholder Route entry with the
 * real controller and the matching ComingSoon stub disappears.
 */
class ModuleStubController extends Controller
{
    public function __invoke(Request $request, string $module, ?string $phase = null, ?string $description = null): Response
    {
        return Inertia::render('ComingSoon', [
            'module' => $module,
            'phase' => $phase,
            'description' => $description,
        ]);
    }
}
