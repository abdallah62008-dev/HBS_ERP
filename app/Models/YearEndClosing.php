<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class YearEndClosing extends Model
{
    public $timestamps = false;

    public const STATUSES = ['Draft', 'Processing', 'Completed', 'Failed'];

    protected $fillable = [
        'fiscal_year_id', 'new_fiscal_year_id', 'status',
        'backup_id', 'closing_report_pdf_url', 'closing_report_excel_url',
        'stock_carried_forward', 'marketer_balances_carried_forward',
        'supplier_balances_carried_forward', 'pending_collections_carried_forward',
        'notes', 'created_by', 'approved_by', 'created_at', 'completed_at',
    ];

    protected $casts = [
        'stock_carried_forward' => 'boolean',
        'marketer_balances_carried_forward' => 'boolean',
        'supplier_balances_carried_forward' => 'boolean',
        'pending_collections_carried_forward' => 'boolean',
        'created_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function newFiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'new_fiscal_year_id');
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(BackupLog::class, 'backup_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
