<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'quantity',
        'reference_type',
        'reference_id',
        'notes',
        'admin_id',
    ];

    // Relations
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }

    // Get related model based on reference_type
    public function reference()
    {
        switch ($this->reference_type) {
            case 'order':
                return $this->belongsTo(Order::class, 'reference_id');
            case 'return':
            // If you have a returns table
            // return $this->belongsTo(Return::class, 'reference_id');
            default:
                return null;
        }
    }
}
