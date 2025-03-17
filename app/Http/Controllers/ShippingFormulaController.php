<?php

namespace App\Http\Controllers;

use App\Models\ShippingFormula;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ShippingFormulaController extends Controller
{
    /**
     * Display a listing of all shipping formulas.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $shippingFormulas = ShippingFormula::where('is_active', true)->get();

        return response()->json([
            'status' => 'success',
            'data' => $shippingFormulas
        ]);
    }

    /**
     * Store a newly created shipping formula in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'country_code' => 'required|string|size:2',
            'country_name' => 'required|string|max:255',
            'base_fee' => 'required|numeric|min:0',
            'price_per_kg' => 'required|numeric|min:0',
            'price_per_cubic_meter' => 'nullable|numeric|min:0',
            'min_shipping_fee' => 'required|numeric|min:0',
            'max_weight' => 'nullable|numeric|min:0',
            'currency' => 'required|string|size:3',
            'handling_fee_percentage' => 'nullable|numeric|min:0|max:100',
            'is_active' => 'boolean',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Force uppercase for country code
        $request->merge(['country_code' => strtoupper($request->country_code)]);

        // Create shipping formula
        $shippingFormula = ShippingFormula::create($request->all());

        return response()->json([
            'status' => 'success',
            'data' => $shippingFormula
        ], 201);
    }

    /**
     * Display the specified shipping formula.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $shippingFormula = ShippingFormula::find($id);

        if (!$shippingFormula) {
            return response()->json([
                'status' => 'error',
                'message' => 'Shipping formula not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $shippingFormula
        ]);
    }

    /**
     * Update the specified shipping formula in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $shippingFormula = ShippingFormula::find($id);

        if (!$shippingFormula) {
            return response()->json([
                'status' => 'error',
                'message' => 'Shipping formula not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'country_code' => 'sometimes|required|string|size:2',
            'country_name' => 'sometimes|required|string|max:255',
            'base_fee' => 'sometimes|required|numeric|min:0',
            'price_per_kg' => 'sometimes|required|numeric|min:0',
            'price_per_cubic_meter' => 'nullable|numeric|min:0',
            'min_shipping_fee' => 'sometimes|required|numeric|min:0',
            'max_weight' => 'nullable|numeric|min:0',
            'currency' => 'sometimes|required|string|size:3',
            'handling_fee_percentage' => 'nullable|numeric|min:0|max:100',
            'is_active' => 'sometimes|boolean',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Force uppercase for country code if present
        if ($request->has('country_code')) {
            $request->merge(['country_code' => strtoupper($request->country_code)]);
        }

        $shippingFormula->update($request->all());

        return response()->json([
            'status' => 'success',
            'data' => $shippingFormula
        ]);
    }

    /**
     * Remove the specified shipping formula from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $shippingFormula = ShippingFormula::find($id);

        if (!$shippingFormula) {
            return response()->json([
                'status' => 'error',
                'message' => 'Shipping formula not found'
            ], 404);
        }

        $shippingFormula->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Shipping formula deleted successfully'
        ]);
    }

    /**
     * Display shipping formula for a specific country.
     *
     * @param  string  $countryCode
     * @return \Illuminate\Http\Response
     */
    public function getByCountry($countryCode)
    {
        $countryCode = strtoupper($countryCode);
        $shippingFormula = ShippingFormula::where('country_code', $countryCode)
            ->where('is_active', true)
            ->first();

        if (!$shippingFormula) {
            return response()->json([
                'status' => 'error',
                'message' => 'No shipping available for this country'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $shippingFormula
        ]);
    }

    /**
     * Calculate shipping cost for a cart.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\Response
     */
    public function calculateForCart(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cart_id' => 'required|exists:carts,id',
            'country_code' => 'required|string|size:2',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $countryCode = strtoupper($request->country_code);
        $cartId = $request->cart_id;

        // Get the shipping formula for this country
        $shippingFormula = ShippingFormula::where('country_code', $countryCode)
            ->where('is_active', true)
            ->first();

        if (!$shippingFormula) {
            return response()->json([
                'status' => 'error',
                'message' => 'No shipping available for this country'
            ], 404);
        }

        // Get the cart with items and products
        $cart = Cart::with(['items.product'])->find($cartId);

        if (!$cart || $cart->items->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cart is empty or not found'
            ], 404);
        }

        // Calculate total weight and volume
        $totalWeight = 0;
        $totalVolume = 0;

        foreach ($cart->items as $item) {
            $product = $item->product;
            $quantity = $item->quantity;

            // Add weight
            if ($product->weight) {
                $totalWeight += $product->weight * $quantity;
            }

            // Calculate volume if dimensions are available
            if ($product->dimensions) {
                // Assuming dimensions are stored as "LxWxH" in cm
                $dimensions = explode('x', $product->dimensions);
                if (count($dimensions) === 3) {
                    // Convert cm³ to m³
                    $volume = ($dimensions[0] * $dimensions[1] * $dimensions[2]) / 1000000;
                    $totalVolume += $volume * $quantity;
                }
            }
        }

        // Check if weight exceeds maximum allowed
        if ($shippingFormula->max_weight && $totalWeight > $shippingFormula->max_weight) {
            return response()->json([
                'status' => 'error',
                'message' => 'Order weight exceeds maximum allowed for shipping to this country',
                'data' => [
                    'max_weight' => $shippingFormula->max_weight,
                    'cart_weight' => $totalWeight
                ]
            ], 400);
        }

        // Calculate shipping cost
        $shippingCost = $shippingFormula->calculateShippingCost($totalWeight, $totalVolume);

        return response()->json([
            'status' => 'success',
            'data' => [
                'shipping_cost' => $shippingCost,
                'currency' => $shippingFormula->currency,
                'country_code' => $shippingFormula->country_code,
                'country_name' => $shippingFormula->country_name,
                'weight' => $totalWeight,
                'volume' => $totalVolume,
                'calculation_details' => [
                    'base_fee' => $shippingFormula->base_fee,
                    'weight_cost' => $totalWeight * $shippingFormula->price_per_kg,
                    'volume_cost' => $totalVolume && $shippingFormula->price_per_cubic_meter ?
                        $totalVolume * $shippingFormula->price_per_cubic_meter : 0,
                    'handling_fee' => $shippingFormula->handling_fee_percentage > 0 ?
                        ($shippingFormula->base_fee + ($totalWeight * $shippingFormula->price_per_kg)) *
                        ($shippingFormula->handling_fee_percentage / 100) : 0,
                    'min_shipping_fee' => $shippingFormula->min_shipping_fee
                ]
            ]
        ]);
    }
}