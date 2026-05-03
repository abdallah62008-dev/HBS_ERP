<?php

namespace App\Services\Importers;

use RuntimeException;

/**
 * Maps import_type slugs → importer classes. Centralised so the
 * controller, the import service, and the UI all agree on the
 * supported set.
 *
 * When you add a new importer, register it here.
 */
class ImporterRegistry
{
    /**
     * @var array<string, class-string<ImporterContract>>
     */
    private const MAP = [
        'products' => ProductImporter::class,
        'customers' => CustomerImporter::class,
        'expenses' => ExpenseImporter::class,
        'stock' => StockImporter::class,
        'price_updates' => PriceUpdateImporter::class,
    ];

    public function get(string $slug): ImporterContract
    {
        if (! isset(self::MAP[$slug])) {
            throw new RuntimeException("Unknown importer slug: {$slug}");
        }

        $class = self::MAP[$slug];
        return app($class);
    }

    /**
     * @return array<int, array{slug:string, label:string, headers:array<int,string>, header_notes:array<string,string>, can_undo:bool}>
     */
    public function describeAll(): array
    {
        $out = [];
        foreach (self::MAP as $slug => $class) {
            /** @var ImporterContract $imp */
            $imp = app($class);
            $out[] = [
                'slug' => $slug,
                'label' => $imp->label(),
                'headers' => $imp->headers(),
                'header_notes' => $imp->headerNotes(),
                'can_undo' => $imp->canUndo(),
            ];
        }
        return $out;
    }

    /** @return array<int,string> */
    public function slugs(): array
    {
        return array_keys(self::MAP);
    }
}
