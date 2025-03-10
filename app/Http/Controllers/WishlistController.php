<?php

namespace App\Http\Controllers;

use App\Models\Wishlist;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class WishlistController extends Controller
{
    /**
     * Display the wishlist items for authenticated user.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $userId = Auth::id();

        // Récupérer tous les éléments de la liste de souhaits de l'utilisateur avec les détails du produit
        $wishlistItems = Wishlist::with('product.images')
            ->where('user_id', $userId)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $wishlistItems
        ]);
    }

    /**
     * Add a product to the wishlist.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = Auth::id();
        $productId = $request->product_id;

        // Vérifier si le produit est déjà dans la liste de souhaits
        $existingItem = Wishlist::where('user_id', $userId)
            ->where('product_id', $productId)
            ->first();

        if ($existingItem) {
            return response()->json([
                'status' => 'success',
                'message' => 'Le produit est déjà dans votre liste de souhaits',
                'data' => $existingItem
            ]);
        }

        // Ajouter le produit à la liste de souhaits
        $wishlistItem = Wishlist::create([
            'user_id' => $userId,
            'product_id' => $productId,
        ]);

        // Charger les détails du produit pour la réponse
        $wishlistItem->load('product');

        return response()->json([
            'status' => 'success',
            'message' => 'Produit ajouté à la liste de souhaits',
            'data' => $wishlistItem
        ], 201);
    }

    /**
     * Check if a product is in the wishlist.
     *
     * @param  int  $productId
     * @return \Illuminate\Http\Response
     */
    public function check($productId)
    {
        $userId = Auth::id();

        $exists = Wishlist::where('user_id', $userId)
            ->where('product_id', $productId)
            ->exists();

        return response()->json([
            'status' => 'success',
            'in_wishlist' => $exists
        ]);
    }

    /**
     * Remove a product from the wishlist.
     *
     * @param  int  $productId
     * @return \Illuminate\Http\Response
     */
    public function destroy($productId)
    {
        $userId = Auth::id();

        // Vérifier si le produit est dans la liste de souhaits
        $wishlistItem = Wishlist::where('user_id', $userId)
            ->where('product_id', $productId)
            ->first();

        if (!$wishlistItem) {
            return response()->json([
                'status' => 'error',
                'message' => 'Produit non trouvé dans la liste de souhaits',
            ], 404);
        }

        // Supprimer l'élément de la liste de souhaits
        $wishlistItem->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Produit retiré de la liste de souhaits',
        ]);
    }

    /**
     * Clear all items from the wishlist.
     *
     * @return \Illuminate\Http\Response
     */
    public function clear()
    {
        $userId = Auth::id();

        // Supprimer tous les éléments de la liste de souhaits
        Wishlist::where('user_id', $userId)->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Liste de souhaits vidée avec succès',
        ]);
    }
}