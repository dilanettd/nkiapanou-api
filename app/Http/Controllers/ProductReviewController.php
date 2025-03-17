<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ProductReviewController extends Controller
{
    /**
     * Récupérer tous les avis avec filtres et pagination (pour l'admin)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        // Vérifier si l'utilisateur est un admin
        if (!Auth::user()->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access'
            ], 403);
        }

        $query = ProductReview::with(['product', 'user']);

        // Filtres
        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('rating')) {
            $query->where('rating', $request->rating);
        }

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Recherche
        if ($request->has('search') && $request->search !== '') {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('comment', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('product', function ($productQuery) use ($search) {
                        $productQuery->where('name', 'like', "%{$search}%");
                    });
            });
        }

        // Tri
        $sortBy = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');

        switch ($sortBy) {
            case 'rating':
                $query->orderBy('rating', $sortDirection);
                break;
            case 'created_at':
                $query->orderBy('created_at', $sortDirection);
                break;
            case 'product':
                $query->join('products', 'product_reviews.product_id', '=', 'products.id')
                    ->orderBy('products.name', $sortDirection)
                    ->select('product_reviews.*');
                break;
            case 'user':
                $query->join('users', 'product_reviews.user_id', '=', 'users.id')
                    ->orderBy('users.name', $sortDirection)
                    ->select('product_reviews.*');
                break;
            default:
                $query->orderBy('created_at', 'desc');
                break;
        }

        // Pagination
        $limit = $request->get('limit', 10);
        $reviews = $query->paginate($limit);

        return response()->json([
            'status' => 'success',
            'data' => [
                'reviews' => $reviews->items(),
                'current_page' => $reviews->currentPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
                'last_page' => $reviews->lastPage(),
            ]
        ]);
    }

    /**
     * Récupérer un avis spécifique par ID (pour l'admin)
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        // Vérifier si l'utilisateur est un admin
        if (!Auth::user()->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access'
            ], 403);
        }

        $review = ProductReview::with(['product', 'user'])->find($id);

        if (!$review) {
            return response()->json([
                'status' => 'error',
                'message' => 'Review not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $review
        ]);
    }

    /**
     * Récupérer les avis d'un produit spécifique (version publique)
     *
     * @param  int  $productId
     * @return \Illuminate\Http\Response
     */
    public function getProductReviews($productId)
    {
        $product = Product::find($productId);

        if (!$product) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product not found'
            ], 404);
        }

        $reviews = ProductReview::where('product_id', $productId)
            ->where('status', 'published')
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $reviews
        ]);
    }

    /**
     * Récupérer les meilleurs avis (publics, mieux notés)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function getTopReviews(Request $request)
    {
        $limit = $request->get('limit', 8);

        $reviews = ProductReview::where('status', 'published')
            ->with(['user', 'product'])
            ->orderBy('rating', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $reviews
        ]);
    }

    /**
     * Soumettre un nouvel avis
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'required|string|min:10'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = Auth::user();

        // Vérifier si l'utilisateur a déjà laissé un avis pour ce produit
        $existingReview = ProductReview::where('product_id', $request->product_id)
            ->where('user_id', $user->id)
            ->first();

        if ($existingReview) {
            return response()->json([
                'status' => 'error',
                'message' => 'You have already reviewed this product'
            ], 400);
        }

        // Créer l'avis
        $review = new ProductReview([
            'product_id' => $request->product_id,
            'user_id' => $user->id,
            'rating' => $request->rating,
            'comment' => $request->comment,
            'status' => 'published'
        ]);

        $review->save();
        $review->load(['user', 'product']);

        return response()->json([
            'status' => 'success',
            'message' => 'Review submitted successfully and pending approval',
            'data' => $review
        ], 201);
    }

    /**
     * Mettre à jour le statut d'un avis (admin seulement)
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function updateStatus(Request $request, $id)
    {
        // Vérifier si l'utilisateur est un admin
        if (!Auth::user()->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:published,pending,rejected'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $review = ProductReview::find($id);

        if (!$review) {
            return response()->json([
                'status' => 'error',
                'message' => 'Review not found'
            ], 404);
        }

        $review->status = $request->status;
        $review->save();
        $review->load(['user', 'product']);

        return response()->json([
            'status' => 'success',
            'message' => 'Review status updated successfully',
            'data' => $review
        ]);
    }

    /**
     * Supprimer un avis
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $user = Auth::user();
        $review = ProductReview::find($id);

        if (!$review) {
            return response()->json([
                'status' => 'error',
                'message' => 'Review not found'
            ], 404);
        }

        // Seul l'admin ou le propriétaire de l'avis peut le supprimer
        if (!$user->isAdmin() && $review->user_id !== $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized to delete this review'
            ], 403);
        }

        $review->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Review deleted successfully'
        ]);
    }

    /**
     * Obtenir les avis d'un utilisateur (pour son profil)
     *
     * @param  int  $userId
     * @return \Illuminate\Http\Response
     */
    public function getUserReviews($userId = null)
    {
        $user = Auth::user();

        // Si aucun ID n'est fourni, utiliser l'utilisateur connecté
        if ($userId === null) {
            $userId = $user->id;
        } else {
            // Vérifier si l'utilisateur demandé existe
            $requestedUser = User::find($userId);
            if (!$requestedUser) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Seul l'admin ou l'utilisateur lui-même peut voir tous ses avis
            if (!$user->isAdmin() && $userId != $user->id) {
                // Pour les autres utilisateurs, ne montrer que les avis publiés
                $reviews = ProductReview::where('user_id', $userId)
                    ->where('status', 'published')
                    ->with('product')
                    ->orderBy('created_at', 'desc')
                    ->get();

                return response()->json([
                    'status' => 'success',
                    'data' => $reviews
                ]);
            }
        }

        // Pour l'admin ou l'utilisateur lui-même, montrer tous les avis
        $reviews = ProductReview::where('user_id', $userId)
            ->with('product')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $reviews
        ]);
    }
}