<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'order_id',
        'payment_method',
        'payment_id',
        'amount',
        'currency',
        'status',
        'transaction_type',
        'reference_number',
        'fee_amount',
        'billing_email',
        'billing_name',
        'payment_method_details',
        'parent_transaction_id',
        'notes',
        'payment_response',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'amount' => 'float',
        'fee_amount' => 'float',
        'payment_response' => 'array',
    ];

    /**
     * Payment method constants
     */
    const METHOD_STRIPE = 'stripe';
    const METHOD_PAYPAL = 'paypal';
    const METHOD_BANK_TRANSFER = 'bank_transfer';

    /**
     * Transaction status constants
     */
    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_REFUNDED = 'refunded';
    const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    /**
     * Transaction type constants
     */
    const TYPE_PAYMENT = 'payment';
    const TYPE_REFUND = 'refund';
    const TYPE_PARTIAL_REFUND = 'partial_refund';

    /**
     * Get the order associated with the transaction.
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the parent transaction (for refunds).
     */
    public function parentTransaction()
    {
        return $this->belongsTo(Transaction::class, 'parent_transaction_id');
    }

    /**
     * Get the child transactions (refunds of this transaction).
     */
    public function childTransactions()
    {
        return $this->hasMany(Transaction::class, 'parent_transaction_id');
    }

    /**
     * Scope a query to only include successful transactions.
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope a query to only include payments (not refunds).
     */
    public function scopePayments($query)
    {
        return $query->where('transaction_type', self::TYPE_PAYMENT);
    }

    /**
     * Scope a query to only include refunds.
     */
    public function scopeRefunds($query)
    {
        return $query->whereIn('transaction_type', [self::TYPE_REFUND, self::TYPE_PARTIAL_REFUND]);
    }

    /**
     * Get formatted amount with currency.
     */
    public function getFormattedAmountAttribute()
    {
        return number_format($this->amount, 2) . ' ' . $this->currency;
    }

    /**
     * Get net amount (amount - fee_amount).
     */
    public function getNetAmountAttribute()
    {
        return $this->amount - ($this->fee_amount ?? 0);
    }

    /**
     * Check if transaction is a refund.
     */
    public function isRefund()
    {
        return in_array($this->transaction_type, [self::TYPE_REFUND, self::TYPE_PARTIAL_REFUND]);
    }

    /**
     * Check if transaction is fully refunded.
     */
    public function isFullyRefunded()
    {
        if ($this->transaction_type !== self::TYPE_PAYMENT) {
            return false;
        }

        $refundedTotal = $this->childTransactions()
            ->whereIn('transaction_type', [self::TYPE_REFUND, self::TYPE_PARTIAL_REFUND])
            ->where('status', self::STATUS_COMPLETED)
            ->sum('amount');

        return $refundedTotal >= $this->amount;
    }
}