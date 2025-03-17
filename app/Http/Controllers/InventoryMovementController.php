<?php

namespace App\Http\Controllers;

use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class InventoryMovementController extends Controller
{
    /**
     * Affiche la liste des mouvements d'inventaire avec filtres et pagination
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
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

        $query = InventoryMovement::with(['product', 'admin']);

        // Filtrer par produit
        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // Filtrer par type de référence
        if ($request->has('reference_type')) {
            $query->where('reference_type', $request->reference_type);
        }

        // Filtrer par date
        if ($request->has('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        // Tri
        $sortBy = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');

        // Validation des paramètres de tri
        $allowedSortFields = ['created_at', 'quantity', 'product_id', 'reference_type'];
        if (!in_array($sortBy, $allowedSortFields)) {
            $sortBy = 'created_at';
        }

        $allowedDirections = ['asc', 'desc'];
        if (!in_array($sortDirection, $allowedDirections)) {
            $sortDirection = 'desc';
        }

        $query->orderBy($sortBy, $sortDirection);

        // Pagination
        $limit = $request->get('limit', 10);
        $movements = $query->paginate($limit);

        // Enrichir avec des informations supplémentaires si nécessaire
        foreach ($movements as $movement) {
            // Si c'est une commande, ajouter les informations de la commande
            if ($movement->reference_type === 'order' && $movement->reference_id) {
                $movement->order = DB::table('orders')
                    ->where('id', $movement->reference_id)
                    ->select('id', 'order_number', 'status')
                    ->first();
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'movements' => $movements->items(),
                'current_page' => $movements->currentPage(),
                'per_page' => $movements->perPage(),
                'total' => $movements->total(),
                'last_page' => $movements->lastPage(),
            ]
        ]);
    }

    /**
     * Enregistre un nouveau mouvement d'inventaire
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Vérifier si l'utilisateur est un admin
        if (!Auth::user()->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|not_in:0',
            'reference_type' => 'required|in:order,manual,return,adjustment,initial',
            'reference_id' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Commencer une transaction pour s'assurer que les deux opérations réussissent ou échouent ensemble
        DB::beginTransaction();

        try {
            // Créer le mouvement d'inventaire
            $movement = new InventoryMovement([
                'product_id' => $request->product_id,
                'quantity' => $request->quantity,
                'reference_type' => $request->reference_type,
                'reference_id' => $request->reference_id,
                'notes' => $request->notes,
                'admin_id' => Auth::user()->admin->id,
            ]);

            $movement->save();

            // Mettre à jour la quantité de produit
            $product = Product::find($request->product_id);
            $newQuantity = $product->quantity + $request->quantity;

            // Vérifier que la quantité ne devient pas négative
            if ($newQuantity < 0) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Insufficient stock available'
                ], 400);
            }

            $product->update(['quantity' => $newQuantity]);

            // Mettre à jour le statut du produit si nécessaire
            if ($newQuantity == 0) {
                $product->update(['status' => 'out_of_stock']);
            } elseif ($product->status == 'out_of_stock' && $newQuantity > 0) {
                $product->update(['status' => 'active']);
            }

            // Charger les relations pour la réponse
            $movement->load(['product', 'admin']);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'data' => $movement
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create inventory movement',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Affiche un mouvement d'inventaire spécifique
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
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

        $movement = InventoryMovement::with(['product', 'admin'])->find($id);

        if (!$movement) {
            return response()->json([
                'status' => 'error',
                'message' => 'Inventory movement not found'
            ], 404);
        }

        // Enrichir avec des informations de référence si nécessaire
        if ($movement->reference_type === 'order' && $movement->reference_id) {
            $movement->order = DB::table('orders')
                ->where('id', $movement->reference_id)
                ->select('id', 'order_number', 'status')
                ->first();
        }

        return response()->json([
            'status' => 'success',
            'data' => $movement
        ]);
    }

    /**
     * Récupérer l'historique des mouvements pour un produit spécifique
     *
     * @param int $productId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProductHistory($productId)
    {
        // Vérifier si l'utilisateur est un admin
        if (!Auth::user()->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access'
            ], 403);
        }

        $product = Product::find($productId);

        if (!$product) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product not found'
            ], 404);
        }

        $movements = InventoryMovement::where('product_id', $productId)
            ->with('admin')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'product' => $product,
                'movements' => $movements
            ]
        ]);
    }

    /**
     * Obtenir une synthèse des mouvements d'inventaire (pour le tableau de bord)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSummary()
    {
        // Vérifier si l'utilisateur est un admin
        if (!Auth::user()->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access'
            ], 403);
        }

        // Mouvements récents (7 derniers jours)
        $recentMovements = InventoryMovement::with(['product', 'admin'])
            ->where('created_at', '>=', now()->subDays(7))
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Produits à faible stock
        $lowStockProducts = Product::whereRaw('quantity <= low_stock_threshold')
            ->where('quantity', '>', 0)
            ->with('category')
            ->orderBy('quantity', 'asc')
            ->limit(10)
            ->get();

        // Produits épuisés
        $outOfStockProducts = Product::where('quantity', 0)
            ->with('category')
            ->orderBy('updated_at', 'desc')
            ->limit(10)
            ->get();

        // Total des mouvements par type
        $movementsByType = InventoryMovement::select('reference_type', DB::raw('count(*) as count'))
            ->groupBy('reference_type')
            ->get()
            ->pluck('count', 'reference_type')
            ->toArray();

        return response()->json([
            'status' => 'success',
            'data' => [
                'recent_movements' => $recentMovements,
                'low_stock_products' => $lowStockProducts,
                'out_of_stock_products' => $outOfStockProducts,
                'movements_by_type' => $movementsByType
            ]
        ]);
    }
}