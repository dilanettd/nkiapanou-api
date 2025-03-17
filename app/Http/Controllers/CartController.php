<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CartController extends Controller
{
    /**
     * Récupère le panier de l'utilisateur courant
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCart(Request $request)
    {
        $user = Auth::user();

        // Récupère ou crée un panier pour l'utilisateur
        $cart = Cart::firstOrCreate(['user_id' => $user->id]);

        // Charge les articles du panier avec les détails du produit et ses images
        $cartItems = $cart->items()->with(['product', 'product.images', 'product.primaryImage'])->get();

        // Utiliser les accesseurs dans les modèles pour calculer le sous-total
        // Note: Éviter d'appeler directement getSubtotalAttribute() car c'est une méthode magique
        $subtotal = $cart->subtotal;

        return response()->json([
            'status' => 'success',
            'data' => [
                'cart' => $cart,
                'cart_items' => $cartItems,
                'subtotal' => $subtotal
            ]
        ]);
    }

    /**
     * Ajoute un produit au panier
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function addToCart(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = Auth::user();
        $productId = $request->product_id;
        $quantity = $request->quantity;

        // Vérifier que le produit est disponible
        $product = Product::find($productId);
        if (!$product) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product not found'
            ], 404);
        }

        if ($product->status !== 'active') {
            return response()->json([
                'status' => 'error',
                'message' => 'Product is not available'
            ], 400);
        }

        if ($product->quantity < $quantity) {
            return response()->json([
                'status' => 'error',
                'message' => 'Insufficient product quantity available',
                'available_quantity' => $product->quantity
            ], 400);
        }

        // Récupérer ou créer le panier
        $cart = Cart::firstOrCreate(['user_id' => $user->id]);

        // Utiliser la méthode du modèle Cart pour ajouter le produit
        $success = $cart->addProduct($productId, $quantity);

        if (!$success) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to add product to cart'
            ], 500);
        }

        // Récupérer l'élément du panier mis à jour
        $cartItem = $cart->items()->where('product_id', $productId)->first();
        if (!$cartItem) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve cart item'
            ], 500);
        }

        // Charger le produit avec les relations nécessaires
        $cartItem->load(['product', 'product.images', 'product.primaryImage']);

        return response()->json([
            'status' => 'success',
            'message' => 'Product added to cart',
            'data' => $cartItem
        ]);
    }

    /**
     * Met à jour la quantité d'un article du panier
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateCartItem(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = Auth::user();
        $cart = Cart::where('user_id', $user->id)->first();

        if (!$cart) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cart not found'
            ], 404);
        }

        $cartItem = CartItem::where('id', $id)
            ->where('cart_id', $cart->id)
            ->first();

        if (!$cartItem) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cart item not found'
            ], 404);
        }

        // Vérifier la disponibilité du produit
        $product = Product::find($cartItem->product_id);
        if (!$product) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product not found'
            ], 404);
        }

        if ($product->status !== 'active') {
            return response()->json([
                'status' => 'error',
                'message' => 'Product is not available'
            ], 400);
        }

        $quantity = $request->quantity;
        if ($product->quantity < $quantity) {
            return response()->json([
                'status' => 'error',
                'message' => 'Insufficient product quantity available',
                'available_quantity' => $product->quantity
            ], 400);
        }

        // Mettre à jour la quantité en utilisant la méthode du modèle Cart
        $success = $cart->updateQuantity($cartItem->product_id, $quantity);

        if (!$success) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update cart item'
            ], 500);
        }

        // Recharger l'élément du panier avec toutes ses relations
        $cartItem->refresh();
        $cartItem->load(['product', 'product.images', 'product.primaryImage']);

        return response()->json([
            'status' => 'success',
            'message' => 'Cart item updated',
            'data' => $cartItem
        ]);
    }

    /**
     * Supprime un article du panier
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function removeCartItem($id)
    {
        $user = Auth::user();
        $cart = Cart::where('user_id', $user->id)->first();

        if (!$cart) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cart not found'
            ], 404);
        }

        $cartItem = CartItem::where('id', $id)
            ->where('cart_id', $cart->id)
            ->first();

        if (!$cartItem) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cart item not found'
            ], 404);
        }

        $productId = $cartItem->product_id;

        // Supprimer l'article en utilisant la méthode du modèle Cart
        $success = $cart->removeProduct($productId);

        if (!$success) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to remove cart item'
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Cart item removed'
        ]);
    }

    /**
     * Vide le panier
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function clearCart()
    {
        $user = Auth::user();
        $cart = Cart::where('user_id', $user->id)->first();

        if (!$cart) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cart not found'
            ], 404);
        }

        // Vider le panier en utilisant la méthode du modèle Cart
        $success = $cart->clear();

        if (!$success) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to clear cart'
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Cart cleared'
        ]);
    }
}