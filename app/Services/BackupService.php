<?php

namespace App\Services;

use App\Models\BackupLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Manual backup writer.
 *
 * Approach: dump every business-data table to a JSON file, zip the
 * lot, and persist a backup_logs row with the URL.
 *
 * Why JSON-zip and not mysqldump?
 *   - Works on Windows (Laragon) without shell access to mysqldump.
 *   - Self-contained — no MySQL version compatibility worries.
 *   - Restorable later via a small Artisan command (not built here;
 *     can be added in production hardening).
 *
 * For very large databases, this should be replaced with mysqldump
 * via Symfony Process. Phase 8 default is JSON because it's portable.
 */
class BackupService
{
    /**
     * @var array<int,string> Tables included in a Database backup.
     * Order matters for restore-style use — parents before children.
     */
    private const TABLES = [
        // Auth + structural
        'roles', 'permissions', 'role_permissions', 'user_permissions', 'users',
        'settings', 'fiscal_years',
        // Operations
        'customers', 'customer_addresses', 'customer_tags',
        'categories', 'products', 'product_variants', 'product_price_history',
        'warehouses', 'inventory_movements', 'stock_adjustments',
        'stock_counts', 'stock_count_items',
        'suppliers', 'purchase_invoices', 'purchase_invoice_items', 'supplier_payments',
        'orders', 'order_items', 'order_status_history', 'order_notes',
        'shipping_companies', 'shipping_rates', 'shipments', 'shipping_labels',
        'collections', 'returns', 'return_reasons', 'attachments',
        'expense_categories', 'expenses', 'ad_campaigns',
        'marketer_price_groups', 'marketers', 'marketer_product_prices',
        'marketer_transactions', 'marketer_wallets',
        'staff_targets', 'notifications',
        'import_jobs', 'import_job_rows', 'export_logs',
        'approval_requests', 'backup_logs', 'year_end_closings',
        'audit_logs',
    ];

    /**
     * Run a Database backup. Returns the BackupLog row.
     */
    public function runDatabaseBackup(?string $notes = null): BackupLog
    {
        $log = BackupLog::create([
            'backup_type' => 'Database',
            'status' => 'Failed', // optimistic update on success
            'notes' => $notes,
            'created_by' => Auth::id(),
            'created_at' => now(),
        ]);

        try {
            $disk = Storage::disk('public');
            $stamp = now()->format('Ymd-His');
            $folder = "backups/{$stamp}";
            $disk->makeDirectory($folder);

            $totalRows = 0;
            $files = [];

            foreach (self::TABLES as $table) {
                if (! \Schema::hasTable($table)) continue;
                $rows = DB::table($table)->get();
                $relPath = "{$folder}/{$table}.json";
                $disk->put($relPath, $rows->toJson(JSON_UNESCAPED_UNICODE));
                $files[] = $disk->path($relPath);
                $totalRows += $rows->count();
            }

            // Zip the dump folder for a single download.
            $zipPath = $disk->path("{$folder}/backup-{$stamp}.zip");
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create zip archive.');
            }
            foreach ($files as $f) {
                $zip->addFile($f, basename($f));
            }
            $zip->close();

            // Optional cleanup of the loose .json files now that we have a zip.
            foreach ($files as $f) @unlink($f);

            $size = self::humanBytes(filesize($zipPath));

            $log->forceFill([
                'status' => 'Success',
                'file_url' => Storage::url("{$folder}/backup-{$stamp}.zip"),
                'size' => $size,
                'notes' => trim(($notes ?: '') . " · {$totalRows} rows across " . count(self::TABLES) . ' tables'),
            ])->save();

            AuditLogService::log('backup_completed', 'backup',
                BackupLog::class, $log->id,
                newValues: ['rows' => $totalRows, 'size' => $size],
            );
        } catch (Throwable $e) {
            $log->forceFill([
                'status' => 'Failed',
                'notes' => trim(($notes ?: '') . ' · ' . substr($e->getMessage(), 0, 500)),
            ])->save();

            AuditLogService::log('backup_failed', 'backup',
                BackupLog::class, $log->id,
                newValues: ['error' => $e->getMessage()],
            );

            throw $e;
        }

        return $log->refresh();
    }

    /** Most recent successful backup (used by year-end closing gate). */
    public function latestSuccessfulBackup(): ?BackupLog
    {
        return BackupLog::where('status', 'Success')
            ->latest('id')
            ->first();
    }

    private static function humanBytes(int|false $bytes): string
    {
        if ($bytes === false) return '—';
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $b = (float) $bytes;
        while ($b >= 1024 && $i < count($units) - 1) { $b /= 1024; $i++; }
        return round($b, 2) . ' ' . $units[$i];
    }
}
