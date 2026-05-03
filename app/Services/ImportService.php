<?php

namespace App\Services;

use App\Models\ImportJob;
use App\Models\ImportJobRow;
use App\Services\Importers\ImporterRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\HeadingRowImport;
use RuntimeException;
use Throwable;

/**
 * Orchestrates the import lifecycle:
 *
 *   1. uploadAndPreview() — accepts the file, persists it, parses headers,
 *      records "Validating" job. Reads every row, runs the importer's
 *      validateRow() + findDuplicate(), inserts ImportJobRow rows with
 *      Success/Failed/Duplicate status — but does NOT persist any
 *      domain records yet.
 *
 *   2. commit() — actually creates the records for rows that previewed
 *      as Success. Records the created_record_type/id on each row so
 *      undo can find them. Updates job counters + sets status=Completed.
 *
 *   3. undo() — soft-deletes (or otherwise reverses) every persisted
 *      record. Sets status=Undone. Refuses if importer.canUndo() is
 *      false or job is older than UNDO_WINDOW_HOURS.
 *
 * The two-step preview/commit flow lets operators see errors before
 * touching the database.
 */
class ImportService
{
    private const UNDO_WINDOW_HOURS = 72;

    public function __construct(
        private readonly ImporterRegistry $registry,
    ) {}

    /**
     * Step 1: persist the file, parse rows, run per-row validation,
     * insert ImportJobRow rows. Returns the freshly-created job.
     */
    public function uploadAndPreview(string $importType, UploadedFile $file): ImportJob
    {
        $importer = $this->registry->get($importType);

        $path = $file->store("imports/{$importType}", 'public');
        $absolutePath = Storage::disk('public')->path($path);

        $job = ImportJob::create([
            'import_type' => $importType,
            'original_file_name' => $file->getClientOriginalName(),
            'file_url' => Storage::url($path),
            'status' => 'Validating',
            'can_undo' => $importer->canUndo(),
            'created_by' => Auth::id(),
            'created_at' => now(),
        ]);

        try {
            // Read headers + rows. Maatwebsite returns array of arrays
            // — first sheet, with header row as keys.
            $sheets = Excel::toArray(new \stdClass(), $absolutePath);
            $rows = $sheets[0] ?? [];

            if (empty($rows)) {
                $job->update(['status' => 'Failed', 'completed_at' => now()]);
                throw new RuntimeException('Uploaded file is empty.');
            }

            $headerRow = array_map(
                fn ($h) => is_string($h) ? strtolower(trim($h)) : $h,
                array_shift($rows),
            );

            $expected = array_map('strtolower', $importer->headers());
            $missingHeaders = array_diff($expected, $headerRow);
            if (! empty($missingHeaders) && count($missingHeaders) === count($expected)) {
                $job->update(['status' => 'Failed', 'completed_at' => now()]);
                throw new RuntimeException(
                    'No expected headers found. Expected: ' . implode(', ', $importer->headers()),
                );
            }

            $rowNumber = 1; // header is row 1
            $success = 0; $failed = 0; $duplicate = 0;

            foreach ($rows as $rawRow) {
                $rowNumber++;

                // Skip blank rows (all-empty)
                $cleanRow = array_filter($rawRow, fn ($v) => $v !== null && $v !== '');
                if (empty($cleanRow)) continue;

                $assoc = self::associate($headerRow, $rawRow);

                $error = $importer->validateRow($assoc);
                $status = 'Success'; $createdType = null; $createdId = null;

                if ($error) {
                    $status = 'Failed';
                    $failed++;
                } elseif ($importer->findDuplicate($assoc)) {
                    $status = 'Duplicate';
                    $error = 'Duplicate of an existing record.';
                    $duplicate++;
                } else {
                    $success++;
                }

                ImportJobRow::create([
                    'import_job_id' => $job->id,
                    'row_number' => $rowNumber,
                    'raw_data_json' => $assoc,
                    'status' => $status,
                    'error_message' => $error,
                    'created_record_type' => $createdType,
                    'created_record_id' => $createdId,
                    'created_at' => now(),
                ]);
            }

            $job->update([
                'total_rows' => $success + $failed + $duplicate,
                'successful_rows' => $success,
                'failed_rows' => $failed,
                'duplicate_rows' => $duplicate,
                'status' => 'Ready',
            ]);

            AuditLogService::log('preview', 'imports', ImportJob::class, $job->id, newValues: [
                'type' => $importType, 'total' => $success + $failed + $duplicate,
                'success' => $success, 'failed' => $failed, 'duplicate' => $duplicate,
            ]);
        } catch (Throwable $e) {
            $job->update(['status' => 'Failed', 'completed_at' => now()]);
            throw $e;
        }

        return $job->refresh();
    }

    /**
     * Step 2: commit the previewed rows. Persists each Success row by
     * delegating to importer.persistRow(). Failed/Duplicate rows are
     * left untouched.
     */
    public function commit(ImportJob $job): ImportJob
    {
        if ($job->status !== 'Ready') {
            throw new RuntimeException("Job must be in Ready state to commit (currently {$job->status}).");
        }

        $importer = $this->registry->get($job->import_type);

        $job->update(['status' => 'Processing']);

        $persisted = 0; $errors = 0;

        $successRows = $job->rows()->where('status', 'Success')->get();

        foreach ($successRows as $row) {
            try {
                DB::transaction(function () use ($row, $importer) {
                    $record = $importer->persistRow($row->raw_data_json);
                    $row->forceFill([
                        'created_record_type' => $record::class,
                        'created_record_id' => $record->getKey(),
                    ])->save();
                });
                $persisted++;
            } catch (Throwable $e) {
                $row->forceFill([
                    'status' => 'Failed',
                    'error_message' => 'Persist error: ' . $e->getMessage(),
                ])->save();
                $errors++;
            }
        }

        $job->forceFill([
            'status' => $errors === 0 ? 'Completed' : ($persisted > 0 ? 'Completed' : 'Failed'),
            'successful_rows' => $persisted,
            'failed_rows' => $job->failed_rows + $errors,
            'completed_at' => now(),
        ])->save();

        AuditLogService::log('committed', 'imports', ImportJob::class, $job->id, newValues: [
            'type' => $job->import_type,
            'persisted' => $persisted,
            'late_failures' => $errors,
        ]);

        return $job->refresh();
    }

    /**
     * Step 3: undo a completed import. Iterates the import_job_rows
     * with a created_record_id and asks the importer to reverse each.
     */
    public function undo(ImportJob $job): ImportJob
    {
        if ($job->status !== 'Completed') {
            throw new RuntimeException('Only Completed jobs can be undone.');
        }
        if (! $job->can_undo) {
            throw new RuntimeException("Imports of type '{$job->import_type}' cannot be undone.");
        }
        if ($job->completed_at && $job->completed_at->lt(now()->subHours(self::UNDO_WINDOW_HOURS))) {
            throw new RuntimeException('Undo window (' . self::UNDO_WINDOW_HOURS . 'h) has expired.');
        }

        $importer = $this->registry->get($job->import_type);
        $reversed = 0;

        $rows = $job->rows()
            ->whereNotNull('created_record_id')
            ->whereNotNull('created_record_type')
            ->get();

        DB::transaction(function () use ($rows, $importer, $job, &$reversed) {
            foreach ($rows as $row) {
                $class = $row->created_record_type;
                $record = class_exists($class) ? $class::find($row->created_record_id) : null;
                if ($record) {
                    $importer->undoRecord($record);
                    $reversed++;
                }
            }
            $job->forceFill([
                'status' => 'Undone',
                'undone_at' => now(),
                'undone_by' => Auth::id(),
            ])->save();
        });

        AuditLogService::log('undone', 'imports', ImportJob::class, $job->id, newValues: [
            'type' => $job->import_type,
            'reversed' => $reversed,
        ]);

        return $job->refresh();
    }

    /**
     * Pair a row's positional cells with the header keys. Excess cells
     * are dropped; missing cells become null.
     *
     * @param  array<int,string>  $headers
     * @param  array<int,mixed>  $row
     * @return array<string,mixed>
     */
    private static function associate(array $headers, array $row): array
    {
        $assoc = [];
        foreach ($headers as $idx => $key) {
            if ($key === null || $key === '') continue;
            $assoc[$key] = $row[$idx] ?? null;
        }
        return $assoc;
    }
}
