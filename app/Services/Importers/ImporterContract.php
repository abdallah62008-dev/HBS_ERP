<?php

namespace App\Services\Importers;

use Illuminate\Database\Eloquent\Model;

/**
 * Contract every concrete importer must implement.
 *
 * The flow:
 *   1. headers()        → expected column names for the template
 *   2. validateRow()    → check a single row before persisting it
 *   3. persistRow()     → create the record (called inside a transaction)
 *   4. canUndo()        → whether the importer's records can be safely deleted
 *   5. undoRecord()     → reverse one row's persisted record (default: soft delete)
 *
 * Concrete importers extend AbstractImporter and only override the
 * specifics they need.
 */
interface ImporterContract
{
    /** Short human label shown on the upload page. */
    public function label(): string;

    /** Slug stored in import_jobs.import_type — used by the registry. */
    public function slug(): string;

    /** Column names the template expects, in order. */
    public function headers(): array;

    /** Optional column descriptions / examples for the upload help text. */
    public function headerNotes(): array;

    /**
     * Validate one row's raw associative array. Return null if OK,
     * otherwise a human-readable error string.
     *
     * @param  array<string,mixed>  $row
     */
    public function validateRow(array $row): ?string;

    /**
     * Detect a duplicate of an existing record. Return the existing
     * model if found (the row will be marked Duplicate and skipped),
     * otherwise null.
     *
     * @param  array<string,mixed>  $row
     */
    public function findDuplicate(array $row): ?Model;

    /**
     * Persist one validated row. Return the created model so its
     * type+id are recorded on the import_job_row for traceability and
     * undo.
     *
     * @param  array<string,mixed>  $row
     */
    public function persistRow(array $row): Model;

    /** Whether the importer's job can be undone after completion. */
    public function canUndo(): bool;

    /**
     * Reverse one created record. Default behaviour (in AbstractImporter)
     * is a soft-delete. Override for non-soft-deletable models or when
     * cascading effects need explicit cleanup.
     */
    public function undoRecord(Model $record): void;
}
