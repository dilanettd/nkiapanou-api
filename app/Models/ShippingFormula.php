<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingFormula extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'country_code',
        'country_name',
        'base_fee',
        'price_per_kg',
        'price_per_cubic_meter',
        'min_shipping_fee',
        'max_weight',
        'currency',
        'handling_fee_percentage',
        'is_active',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'base_fee' => 'float',
        'price_per_kg' => 'float',
        'price_per_cubic_meter' => 'float',
        'min_shipping_fee' => 'float',
        'max_weight' => 'float',
        'handling_fee_percentage' => 'float',
        'is_active' => 'boolean',
    ];

    /**
     * Calculate shipping cost based on weight and volume
     * 
     * @param float $weight Weight in kg
     * @param float|null $volume Volume in cubic meters (optional)
     * @return float Calculated shipping cost
     */
    public function calculateShippingCost($weight, $volume = null)
    {
        // Base formula: base_fee + (weight * price_per_kg)
        $cost = $this->base_fee + ($weight * $this->price_per_kg);

        // Add volume-based cost if provided and applicable
        if ($volume !== null && $this->price_per_cubic_meter !== null) {
            $cost += $volume * $this->price_per_cubic_meter;
        }

        // Apply handling fee percentage
        if ($this->handling_fee_percentage > 0) {
            $cost *= (1 + ($this->handling_fee_percentage / 100));
        }

        // Ensure minimum shipping fee
        if ($cost < $this->min_shipping_fee) {
            $cost = $this->min_shipping_fee;
        }

        return $cost;
    }
}