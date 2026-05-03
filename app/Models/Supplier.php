<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'phone', 'email', 'address', 'city', 'country',
        'notes', 'status', 'created_by', 'updated_by', 'deleted_by',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function purchaseInvoices(): HasMany
    {
        return $this->hasMany(PurchaseInvoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    /**
     * Sum of approved purchase invoices minus payments. Negative means
     * the supplier owes us; positive means we owe them.
     */
    public function balance(): float
    {
        $invoiced = $this->purchaseInvoices()
            ->whereIn('status', ['Received', 'Partially Received', 'Paid', 'Partially Paid', 'Unpaid'])
            ->sum('total_amount');

        $paid = $this->payments()->sum('amount');

        return (float) ($invoiced - $paid);
    }
}
