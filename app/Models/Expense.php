<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'expense_category_id', 'title', 'amount', 'currency_code',
        'expense_date', 'payment_method',
        'related_order_id', 'related_campaign_id',
        'notes', 'attachment_url',
        'created_by', 'updated_by', 'deleted_by',
        // Finance Phase 4.
        'cashbox_id', 'payment_method_id', 'cashbox_transaction_id', 'cashbox_posted_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expense_date' => 'date',
        'cashbox_posted_at' => 'datetime',
    ];

    /* ────────────────────── Delete guard ────────────────────── */

    /**
     * Block soft-delete (and force-delete) of a posted expense at the
     * model layer. The controller already returns a flash error before
     * calling delete, but this hook is defence-in-depth — a future
     * console command, job, or seeder that calls `$expense->delete()`
     * directly will surface the same error instead of silently
     * orphaning a cashbox_transactions row.
     *
     * Phase 5+ refund / adjustment flow is the supported reversal path.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $expense) {
            if ($expense->isPosted()) {
                throw new \RuntimeException(
                    "Expense #{$expense->id} is posted to a cashbox "
                    . '(transaction #' . $expense->cashbox_transaction_id . ') '
                    . 'and cannot be deleted. Use a reversal flow.'
                );
            }
        });
    }

    /* ────────────────────── Relations ────────────────────── */

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function relatedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'related_order_id');
    }

    public function relatedCampaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'related_campaign_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* Phase 4 relations. */

    public function cashbox(): BelongsTo
    {
        return $this->belongsTo(Cashbox::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function cashboxTransaction(): BelongsTo
    {
        return $this->belongsTo(CashboxTransaction::class);
    }

    /* ────────────────────── Helpers / scopes ────────────────────── */

    /** True once a cashbox transaction has been written for this expense. */
    public function isPosted(): bool
    {
        return $this->cashbox_transaction_id !== null;
    }

    /** True if the expense is in a state that permits posting to a cashbox. */
    public function isPostable(): bool
    {
        return (float) $this->amount > 0 && ! $this->isPosted();
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->whereNotNull('cashbox_transaction_id');
    }

    public function scopeUnposted(Builder $query): Builder
    {
        return $query->whereNull('cashbox_transaction_id');
    }
}
