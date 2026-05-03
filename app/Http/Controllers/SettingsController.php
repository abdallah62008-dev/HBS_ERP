<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\AuditLogService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin UI over the settings table. Settings are grouped into logical
 * sections (general, tax, orders, shipping, etc.) for the page layout
 * but stored as flat key/value rows.
 */
class SettingsController extends Controller
{
    public function index(): Response
    {
        $settings = Setting::query()
            ->orderBy('setting_group')
            ->orderBy('setting_key')
            ->get();

        // Grouped for the page; SettingsService::all() returns the typed
        // values so the UI can render checkboxes for booleans, etc.
        $grouped = $settings->groupBy('setting_group');

        return Inertia::render('Settings/Index', [
            'grouped' => $grouped,
            'all_typed' => SettingsService::all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'changes' => ['required', 'array'],
            // Each entry: ['key' => string, 'value' => mixed]
            'changes.*.key' => ['required', 'string', 'max:255'],
            'changes.*.value' => ['nullable'],
        ]);

        $oldValues = SettingsService::all();
        $changedKeys = [];

        foreach ($data['changes'] as $change) {
            $key = $change['key'];
            $value = $change['value'];

            $existing = Setting::where('setting_key', $key)->first();
            if (! $existing) continue;

            // Cast incoming value to declared value_type to keep the
            // SettingsService output stable.
            $typed = $this->castIncoming($value, $existing->value_type);

            if (($oldValues[$key] ?? null) === $typed) continue;

            SettingsService::set($key, $typed, $existing->setting_group, $existing->value_type);
            $changedKeys[] = $key;
        }

        if (! empty($changedKeys)) {
            AuditLogService::log(
                action: 'updated',
                module: 'settings',
                recordType: Setting::class,
                newValues: ['keys' => $changedKeys],
            );
        }

        return back()->with('success', count($changedKeys) > 0
            ? count($changedKeys) . ' setting(s) updated.'
            : 'No changes.');
    }

    private function castIncoming(mixed $value, string $valueType): mixed
    {
        if ($value === null) return null;

        return match ($valueType) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'number' => is_numeric($value) ? (str_contains((string) $value, '.') ? (float) $value : (int) $value) : 0,
            'json' => is_string($value) ? json_decode($value, true) : $value,
            default => (string) $value,
        };
    }
}
