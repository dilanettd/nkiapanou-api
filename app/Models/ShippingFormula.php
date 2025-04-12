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
    public function calculateShippingCost($weight, $volume)
    {
        // Convertir les entrées en nombres pour s'assurer qu'elles sont numériques
        $weight = floatval($weight);
        $volume = floatval($volume);
        $baseRate = floatval($this->base_fee);
        $pricePerKg = floatval($this->price_per_kg);
        $minShippingFee = floatval($this->min_shipping_fee);

        // Calculer le coût basé sur le poids
        $weightCost = $weight * $pricePerKg;

        // Calculer le coût basé sur le volume (si applicable)
        $volumeCost = 0;
        if ($volume > 0 && $this->price_per_cubic_meter) {
            $volumeCost = $volume * floatval($this->price_per_cubic_meter);
        }

        // Déterminer le coût le plus élevé entre le poids et le volume
        $costBasedOnDimensions = max($weightCost, $volumeCost);

        // Ajouter le tarif de base
        $totalCost = $baseRate + $costBasedOnDimensions;

        // Ajouter les frais de manutention (si applicable)
        if ($this->handling_fee_percentage > 0) {
            $handlingFee = $totalCost * (floatval($this->handling_fee_percentage) / 100);
            $totalCost += $handlingFee;
        }

        // Appliquer le tarif minimum d'expédition si nécessaire
        return max($totalCost, $minShippingFee);
    }
}