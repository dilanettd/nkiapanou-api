<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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
            'payment_id' => 'nullable|string',
            'billing_address' => 'required|string',
            'billing_city' => 'required|string',
            'billing_postal_code' => 'required|string',
            'billing_country' => 'required|string',
            'shipping_address' => 'required|string',
            'shipping_city' => 'required|string',
            'shipping_postal_code' => 'required|string',
            'shipping_country' => 'required|string',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'shipping_fee' => 'required|numeric|min:0',
            'tax_amount' => 'required|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
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

                $itemPrice = $product->price;
                $itemTotal = $itemPrice * $itemData['quantity'];
                $totalAmount += $itemTotal;

                $items[] = [
                    'product_id' => $product->id,
                    'quantity' => $itemData['quantity'],
                    'price' => $itemPrice,
                    'total' => $itemTotal,
                ];

                // Mettre à jour le stock du produit
                $product->quantity -= $itemData['quantity'];
                $product->save();
            }

            // Ajouter les frais d'expédition et les taxes
            $totalAmount += $request->shipping_fee + $request->tax_amount;

            // Soustraire les remises
            if ($request->discount_amount) {
                $totalAmount -= $request->discount_amount;
            }

            // Créer la commande
            $order = Order::create([
                'user_id' => $user->id,
                'total_amount' => $totalAmount,
                'shipping_fee' => $request->shipping_fee,
                'tax_amount' => $request->tax_amount,
                'discount_amount' => $request->discount_amount ?? 0,
                'payment_method' => $request->payment_method,
                'payment_id' => $request->payment_id,
                'payment_status' => $request->payment_id ? 'paid' : 'pending',
                'status' => 'pending',
                'billing_address' => $request->billing_address,
                'billing_city' => $request->billing_city,
                'billing_postal_code' => $request->billing_postal_code,
                'billing_country' => $request->billing_country,
                'shipping_address' => $request->shipping_address,
                'shipping_city' => $request->shipping_city,
                'shipping_postal_code' => $request->shipping_postal_code,
                'shipping_country' => $request->shipping_country,
                'notes' => $request->notes,
            ]);

            // Créer les éléments de commande
            foreach ($items as $item) {
                $order->items()->create($item);
            }

            DB::commit();

            // Charger les relations pour la réponse
            $order->load(['user', 'items.product']);

            return response()->json([
                'status' => 'success',
                'message' => 'Order created successfully',
                'data' => $order,
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