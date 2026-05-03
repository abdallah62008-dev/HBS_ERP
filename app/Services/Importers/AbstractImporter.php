<?php

namespace App\Services\Importers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Default behaviours shared by all importers. Concrete classes override
 * what they need — most need only `headers()`, `validateRow()`,
 * `findDuplicate()` and `persistRow()`.
 */
abstract class AbstractImporter implements ImporterContract
{
    public function headerNotes(): array
    {
        return [];
    }

    public function findDuplicate(array $row): ?Model
    {
        return null;
    }

    public function canUndo(): bool
    {
        return true;
    }

    /**
     * Default: soft-delete if available, else hard-delete.
     */
    public function undoRecord(Model $record): void
    {
        if (in_array(SoftDeletes::class, class_uses_recursive($record), true)) {
            $record->delete();
        } else {
            $record->forceDelete();
        }
    }

    /**
     * Helper: pull a column from the row using either the canonical
     * header or any of the supplied aliases. Returns null if not present
     * or empty string.
     */
    protected function pick(array $row, string $key, array $aliases = []): ?string
    {
        foreach (array_merge([$key], $aliases) as $k) {
            if (array_key_exists($k, $row)) {
                $v = $row[$k];
                $v = is_string($v) ? trim($v) : $v;
                if ($v === '' || $v === null) continue;
                return is_string($v) ? $v : (string) $v;
            }
        }
        return null;
    }

    protected function pickFloat(array $row, string $key, float $default = 0.0): float
    {
        $v = $this->pick($row, $key);
        return $v === null ? $default : (float) str_replace([','], '', $v);
    }

    protected function pickInt(array $row, string $key, int $default = 0): int
    {
        $v = $this->pick($row, $key);
        return $v === null ? $default : (int) $v;
    }
}
