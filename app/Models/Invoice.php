<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'seller_id',
        'invoice_number',
        'total',
        'discount_amount',
        'loyalty_points_earned',
        'loyalty_points_redeemed',
        'sale_date',
        'due_date',
        'payment_status',
        'payment_method',
        'buyer_name',
        'buyer_phone',
        'buyer_address',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'due_date' => 'date',
            'total' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'loyalty_points_earned' => 'integer',
            'loyalty_points_redeemed' => 'integer',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
