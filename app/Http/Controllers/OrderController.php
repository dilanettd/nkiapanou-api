<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\UserAddress;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    /**
     * Display a listing of orders with pagination and filters
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        // Vérifier si l'utilisateur est admin
        if (!Auth::user()->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access',
            ], 403);
        }

        // Paramètres de pagination et filtrage
        $page = $request->query('page', 1);
        $limit = $request->query('limit', 10);
        $userId = $request->query('user_id');
        $status = $request->query('status');
        $paymentStatus = $request->query('payment_status');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $search = $request->query('search');
        $sortBy = $request->query('sort_by', 'created_at');
        $sortDirection = $request->query('sort_direction', 'desc');

        // Requête de base
        $query = Order::with(['user', 'items.product']);

        // Appliquer les filtres
        if ($userId) {
            $query->where('user_id', $userId);
        }

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($paymentStatus && $paymentStatus !== 'all') {
            $query->where('payment_status', $paymentStatus);
        }

        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        // Tri
        $query->orderBy($sortBy, $sortDirection);

        // Pagination
        $orders = $query->paginate($limit, ['*'], 'page', $page);

        return response()->json([
            'status' => 'success',
            'data' => [
                'orders' => $orders->items(),
                'current_page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'last_page' => $orders->lastPage(),
            ],
        ]);
    }

    /**
     * Get the order history for authenticated user with statistics
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function getOrdersHistory(Request $request)
    {
        $user = Auth::user();

        // Récupérer toutes les commandes de l'utilisateur
        $orders = Order::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        // Calculer les statistiques
        $totalSpent = $orders->where('payment_status', 'paid')->sum('total_amount');
        $ordersCount = $orders->count();
        $completedOrdersCount = $orders->whereIn('status', ['delivered'])->count();
        $pendingOrdersCount = $orders->whereIn('status', ['pending', 'processing', 'shipped'])->count();
        $cancelledOrdersCount = $orders->where('status', 'cancelled')->count();

        // Statistiques mensuelles des 6 derniers mois
        $sixMonthsAgo = now()->subMonths(6)->startOfMonth();
        $monthlyStats = Order::where('user_id', $user->id)
            ->where('created_at', '>=', $sixMonthsAgo)
            ->where('payment_status', 'paid')
            ->selectRaw('YEAR(created_at) as year, MONTH(created_at) as month, SUM(total_amount) as total, COUNT(*) as count')
            ->groupBy('year', 'month')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'year' => $item->year,
                    'month' => $item->month,
                    'month_name' => date('F', mktime(0, 0, 0, $item->month, 1)),
                    'total' => $item->total,
                    'count' => $item->count,
                ];
            });

        // Répartition par statut pour un graphique
        $statusDistribution = $orders->groupBy('status')
            ->map(function ($items, $status) {
                return [
                    'status' => $status,
                    'count' => $items->count(),
                ];
            })->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_spent' => $totalSpent,
                'orders_count' => $ordersCount,
                'completed_orders_count' => $completedOrdersCount,
                'pending_orders_count' => $pendingOrdersCount,
                'cancelled_orders_count' => $cancelledOrdersCount,
                'monthly_stats' => $monthlyStats,
                'status_distribution' => $statusDistribution,
                'recent_orders' => $orders->take(5)->values(),
            ],
        ]);
    }

    /**
     * Store a newly created order in storage
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'payment_method' => 'required|string|in:stripe,paypal',
            'shipping_method' => 'required|string',
            'notes' => 'nullable|string',
            'shipping_address_id' => 'required|exists:user_addresses,id',
            'billing_address_id' => 'nullable|exists:user_addresses,id',
            'shipping_fee' => 'required|numeric|min:0',
            'tax_amount' => 'required|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'total_amount' => 'required|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Récupérer les adresses
            $shippingAddress = UserAddress::findOrFail($request->shipping_address_id);

            // Vérifier que l'adresse appartient à l'utilisateur
            if ($shippingAddress->user_id !== $user->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized access to this address',
                ], 403);
            }

            // Si l'adresse de facturation n'est pas fournie, utiliser l'adresse de livraison
            if ($request->billing_address_id) {
                $billingAddress = UserAddress::findOrFail($request->billing_address_id);

                // Vérifier que l'adresse appartient à l'utilisateur
                if ($billingAddress->user_id !== $user->id) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Unauthorized access to this address',
                    ], 403);
                }
            } else {
                // Rechercher une adresse de facturation par défaut
                $billingAddress = UserAddress::where('user_id', $user->id)
                    ->where('address_type', 'billing')
                    ->where('is_default', true)
                    ->first();

                // Si aucune adresse de facturation, utiliser l'adresse de livraison
                if (!$billingAddress) {
                    $billingAddress = $shippingAddress;
                }
            }

            // Calculer le total des articles
            $totalAmount = 0;
            $items = [];

            foreach ($request->items as $itemData) {
                $product = Product::findOrFail($itemData['product_id']);

                // Vérifier la disponibilité du stock
                if ($product->quantity < $itemData['quantity']) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Not enough stock for product: {$product->name}",
                    ], 400);
                }

                // Utiliser le prix du produit dans la base de données pour la sécurité
                // mais nous pouvons utiliser le prix fourni pour référence (discounts, etc.)
                $itemPrice = $product->discount_price ?? $product->price;
                $itemTotal = $itemPrice * $itemData['quantity'];
                $totalAmount += $itemTotal;

                $items[] = [
                    'product_id' => $product->id,
                    'quantity' => $itemData['quantity'],
                    'price' => $itemPrice,
                    'total' => $itemTotal,
                ];
            }

            // Utiliser le montant total fourni par le client
            $totalAmount = $request->total_amount;

            // Vérification supplémentaire (optionnelle) pour s'assurer que le montant total est cohérent
            $calculatedTotal = $this->calculateItemsTotal($request->items) + $request->shipping_fee + $request->tax_amount - ($request->discount_amount ?? 0);

            // Si le montant total fourni diffère significativement du montant calculé (tolérance de 1€)
            if (abs($totalAmount - $calculatedTotal) > 1) {
                // Log pour débogage
                Log::warning("Montant total incohérent. Fourni: {$totalAmount}, Calculé: {$calculatedTotal}", [
                    'user_id' => $user->id,
                    'items' => $request->items,
                    'shipping_fee' => $request->shipping_fee,
                    'tax_amount' => $request->tax_amount,
                    'discount_amount' => $request->discount_amount ?? 0
                ]);
            }

            // Vérifier si une commande similaire en attente existe déjà pour cet utilisateur
            $existingOrder = $this->findSimilarPendingOrder($user->id, $request);

            if ($existingOrder) {
                // Mettre à jour la date de la commande existante
                $existingOrder->touch();
                $existingOrder->payment_method = $request->payment_method;
                $existingOrder->save();

                DB::commit();

                // Charger les relations pour la réponse
                $existingOrder->load(['user', 'items.product']);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Existing order updated',
                    'data' => $existingOrder,
                    'is_existing' => true,
                ]);
            }

            // Aucune commande similaire trouvée, créer une nouvelle commande
            $order = Order::create([
                'user_id' => $user->id,
                'order_number' => $this->generateOrderNumber(),
                'total_amount' => $totalAmount,
                'shipping_fee' => $request->shipping_fee,
                'tax_amount' => $request->tax_amount,
                'discount_amount' => $request->discount_amount ?? 0,
                'payment_method' => $request->payment_method,
                'payment_status' => 'pending', // En attente de paiement
                'status' => 'pending',
                'billing_address' => $billingAddress->address_line1,
                'billing_city' => $billingAddress->city,
                'billing_postal_code' => $billingAddress->postal_code,
                'billing_country' => $billingAddress->country,
                'shipping_address' => $shippingAddress->address_line1,
                'shipping_city' => $shippingAddress->city,
                'shipping_postal_code' => $shippingAddress->postal_code,
                'shipping_country' => $shippingAddress->country,
                'notes' => $request->notes,
            ]);

            // Créer les éléments de commande
            foreach ($items as $item) {
                // Mettre à jour le stock du produit uniquement pour les nouvelles commandes
                $product = Product::findOrFail($item['product_id']);
                $product->quantity -= $item['quantity'];
                $product->save();

                $order->items()->create($item);
            }

            DB::commit();

            // Charger les relations pour la réponse
            $order->load(['user', 'items.product']);

            return response()->json([
                'status' => 'success',
                'message' => 'Order created successfully',
                'data' => $order,
                'is_existing' => false,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Find a similar pending order for the user
     * 
     * @param int $userId
     * @param Request $request
     * @return Order|null
     */
    private function findSimilarPendingOrder($userId, Request $request)
    {
        // Trouver les commandes en attente de cet utilisateur
        $pendingOrders = Order::where('user_id', $userId)
            ->where('status', 'pending')
            ->where('payment_status', 'pending')
            ->with('items')
            ->get();

        foreach ($pendingOrders as $order) {
            // 1. Vérifier si le montant total est similaire (tolérance de 1€)
            if (abs($order->total_amount - $request->total_amount) > 1) {
                continue;
            }

            // 2. Vérifier si les adresses correspondent
            $shippingAddress = UserAddress::findOrFail($request->shipping_address_id);

            if (
                $order->shipping_address !== $shippingAddress->address_line1 ||
                $order->shipping_postal_code !== $shippingAddress->postal_code
            ) {
                continue;
            }

            // 3. Vérifier si les articles sont les mêmes
            $requestItems = collect($request->items)->sortBy('product_id');
            $orderItems = $order->items->sortBy('product_id');

            // Vérifier si le nombre d'articles est identique
            if ($requestItems->count() !== $orderItems->count()) {
                continue;
            }

            // Vérifier si les produits et les quantités correspondent
            $itemsMatch = true;
            foreach ($requestItems as $index => $requestItem) {
                $orderItem = $orderItems->values()[$index] ?? null;

                if (
                    !$orderItem ||
                    $orderItem->product_id != $requestItem['product_id'] ||
                    $orderItem->quantity != $requestItem['quantity']
                ) {
                    $itemsMatch = false;
                    break;
                }
            }

            if ($itemsMatch) {
                return $order;
            }
        }

        return null;
    }

    /**
     * Calculate the total amount of items in an order
     * 
     * @param array $items
     * @return float
     */
    private function calculateItemsTotal(array $items)
    {
        $total = 0;

        foreach ($items as $item) {
            $product = Product::findOrFail($item['product_id']);
            $price = $product->discount_price ?? $product->price;
            $total += $price * $item['quantity'];
        }

        return $total;
    }

    /**
     * Generate a unique order number
     * 
     * @return string
     */
    private function generateOrderNumber()
    {
        // Préfixe + timestamp + random
        $prefix = 'ORD-';
        $timestamp = time();
        $random = mt_rand(1000, 9999);
        return $prefix . $timestamp . '-' . $random;
    }

    /**
     * Calculate shipping fee based on country and order total
     * 
     * @param string $country
     * @param float $orderTotal
     * @return float
     */
    private function calculateShippingFee($country, $orderTotal)
    {
        // Cette méthode peut être adaptée selon votre logique de calcul des frais de livraison

        // Exemple de logique simplifiée:
        // Livraison gratuite à partir de 100€
        if ($orderTotal >= 100) {
            return 0;
        }

        // Tarifs par zone
        $domesticCountries = ['FR', 'France']; // France
        $euCountries = ['DE', 'IT', 'ES', 'BE', 'LU', 'NL', 'PT', 'AT']; // Europe

        if (in_array($country, $domesticCountries)) {
            return 5.99; // Livraison nationale
        } elseif (in_array($country, $euCountries)) {
            return 12.99; // Livraison Europe
        } else {
            return 24.99; // Livraison internationale
        }
    }

    /**
     * Display the specified order
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $user = Auth::user();
        $order = Order::with(['user', 'items.product'])->find($id);

        if (!$order) {
            return response()->json([
                'status' => 'error',
                'message' => 'Order not found',
            ], 404);
        }

        // Vérifier les autorisations (admin ou propriétaire de la commande)
        if (!$user->isAdmin() && $order->user_id !== $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access',
            ], 403);
        }

        return response()->json([
            'status' => 'success',
            'data' => $order,
        ]);
    }

    /**
     * Update the order status
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function updateStatus(Request $request, $id)
    {
        // Vérifier si l'utilisateur est admin
        if (!Auth::user()->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:pending,processing,shipped,delivered,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $order = Order::find($id);

        if (!$order) {
            return response()->json([
                'status' => 'error',
                'message' => 'Order not found',
            ], 404);
        }

        try {
            // Si annulation, remettre les produits en stock
            if ($request->status === 'cancelled' && $order->status !== 'cancelled') {
                DB::beginTransaction();

                foreach ($order->items as $item) {
                    $product = $item->product;
                    $product->quantity += $item->quantity;
                    $product->save();
                }
            }

            $order->status = $request->status;
            $order->save();

            if (isset($db) && $db instanceof DB) {
                DB::commit();
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Order status updated successfully',
                'data' => $order->fresh()->load(['user', 'items.product']),
            ]);
        } catch (\Exception $e) {
            if (isset($db) && $db instanceof DB) {
                DB::rollBack();
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update order status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update the payment status
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function updatePaymentStatus(Request $request, $id)
    {
        // Vérifier si l'utilisateur est admin
        if (!Auth::user()->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'payment_status' => 'required|string|in:pending,paid,failed,refunded',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $order = Order::find($id);

        if (!$order) {
            return response()->json([
                'status' => 'error',
                'message' => 'Order not found',
            ], 404);
        }

        $order->payment_status = $request->payment_status;
        $order->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Payment status updated successfully',
            'data' => $order->fresh()->load(['user', 'items.product']),
        ]);
    }

    /**
     * Update the tracking number
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function updateTrackingNumber(Request $request, $id)
    {
        // Vérifier si l'utilisateur est admin
        if (!Auth::user()->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'tracking_number' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $order = Order::find($id);

        if (!$order) {
            return response()->json([
                'status' => 'error',
                'message' => 'Order not found',
            ], 404);
        }

        $order->tracking_number = $request->tracking_number;
        $order->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Tracking number updated successfully',
            'data' => $order->fresh()->load(['user', 'items.product']),
        ]);
    }

    /**
     * Get orders for the authenticated user
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function getUserOrders(Request $request)
    {
        $user = Auth::user();

        // Paramètres de pagination
        $page = $request->query('page', 1);
        $limit = $request->query('limit', 10);
        $status = $request->query('status');

        $query = Order::with(['items.product'])
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc');

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        $orders = $query->paginate($limit, ['*'], 'page', $page);

        return response()->json([
            'status' => 'success',
            'data' => [
                'orders' => $orders->items(),
                'current_page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'last_page' => $orders->lastPage(),
            ],
        ]);
    }
}