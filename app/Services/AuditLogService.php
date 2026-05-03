<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Central writer for the audit_logs table.
 *
 * Per 03_RBAC_SECURITY_AUDIT.md every important action — create, update,
 * soft delete, restore, import, export, print, approve, reject, status
 * change, price change, inventory adjustment, purchase invoice approval,
 * marketer payout, year-end closing — must land here.
 *
 * For Eloquent models prefer `logModelChange()` so old/new values are
 * captured automatically; for non-model actions use `log()`.
 */
class AuditLogService
{
    /**
     * Record an arbitrary action.
     *
     * @param  array<string,mixed>|null  $oldValues
     * @param  array<string,mixed>|null  $newValues
     */
    public static function log(
        string $action,
        string $module,
        ?string $recordType = null,
        ?int $recordId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'module' => $module,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'old_values_json' => $oldValues,
            'new_values_json' => $newValues,
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 1024),
            'created_at' => now(),
        ]);
    }

    /**
     * Convenience: log a model change. Pass the model AFTER changes are
     * applied; this method computes the diff using getOriginal()/getChanges().
     *
     * For brand-new records, $action defaults to "created" and old values
     * are null. For updates, only changed columns are stored on both sides.
     */
    public static function logModelChange(
        Model $model,
        string $action,
        string $module,
        ?array $forcedOld = null,
        ?array $forcedNew = null,
    ): AuditLog {
        $old = $forcedOld;
        $new = $forcedNew;

        if ($old === null && $new === null) {
            $changes = $model->getChanges();
            unset($changes['updated_at']);

            if (! empty($changes)) {
                $original = $model->getOriginal();
                $old = array_intersect_key($original, $changes);
                $new = $changes;
            } else {
                // No changes: fall back to the current attributes (e.g. on create).
                $new = $model->getAttributes();
                unset($new['password'], $new['remember_token']);
            }
        }

        return self::log(
            action: $action,
            module: $module,
            recordType: $model::class,
            recordId: $model->getKey(),
            oldValues: $old ? self::redact($old) : null,
            newValues: $new ? self::redact($new) : null,
        );
    }

    /**
     * Strip secrets from any payload before persisting.
     *
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    private static function redact(array $values): array
    {
        foreach (['password', 'remember_token', 'api_token', 'access_token', 'secret'] as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '***';
            }
        }

        return $values;
    }
}
