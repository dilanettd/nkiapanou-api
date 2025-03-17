<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class TransactionController extends Controller
{
    /**
     * Display a listing of transactions.
     * 
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        // Vérifier les permissions d'administrateur
        $this->checkAdminAccess();

        $query = Transaction::with(['order']);

        // Filtrer par commande
        if ($request->has('order_id')) {
            $query->where('order_id', $request->order_id);
        }

        // Filtrer par statut
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Filtrer par méthode de paiement
        if ($request->has('payment_method') && $request->payment_method !== 'all') {
            $query->where('payment_method', $request->payment_method);
        }

        // Filtrer par type de transaction
        if ($request->has('transaction_type') && $request->transaction_type !== 'all') {
            $query->where('transaction_type', $request->transaction_type);
        }

        // Filtrer par date
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Pagination
        $perPage = $request->get('per_page', 15);
        $transactions = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => [
                'transactions' => $transactions->items(),
                'pagination' => [
                    'current_page' => $transactions->currentPage(),
                    'per_page' => $transactions->perPage(),
                    'total' => $transactions->total(),
                    'last_page' => $transactions->lastPage(),
                ]
            ]
        ]);
    }

    /**
     * Store a newly created transaction in storage.
     * 
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:orders,id',
            'payment_method' => 'required|string',
            'payment_id' => 'required|string',
            'amount' => 'required|numeric|min:0',
            'currency' => 'required|string|size:3',
            'status' => 'required|string',
            'transaction_type' => 'required|string',
            'reference_number' => 'nullable|string',
            'fee_amount' => 'nullable|numeric|min:0',
            'billing_email' => 'nullable|email',
            'billing_name' => 'nullable|string',
            'payment_method_details' => 'nullable|string',
            'parent_transaction_id' => 'nullable|exists:transactions,id',
            'notes' => 'nullable|string',
            'payment_response' => 'nullable|json'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Créer la transaction
        $transaction = Transaction::create($request->all());

        // Mettre à jour le statut de paiement de la commande si nécessaire
        if ($transaction->transaction_type === Transaction::TYPE_PAYMENT && $transaction->status === Transaction::STATUS_COMPLETED) {
            $order = Order::find($transaction->order_id);
            if ($order) {
                $order->update(['payment_status' => 'paid']);
            }
        } elseif ($transaction->transaction_type === Transaction::TYPE_REFUND && $transaction->status === Transaction::STATUS_COMPLETED) {
            $order = Order::find($transaction->order_id);
            if ($order) {
                $order->update(['payment_status' => 'refunded']);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Transaction created successfully',
            'data' => $transaction
        ], 201);
    }

    /**
     * Display the specified transaction.
     * 
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $transaction = Transaction::with(['order'])->find($id);

        if (!$transaction) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaction not found'
            ], 404);
        }

        // Vérifier si l'utilisateur a accès à cette transaction
        $this->authorizeAccess($transaction);

        return response()->json([
            'status' => 'success',
            'data' => $transaction
        ]);
    }

    /**
     * Update the specified transaction in storage.
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        // Vérifier les permissions d'administrateur
        $this->checkAdminAccess();

        $transaction = Transaction::find($id);

        if (!$transaction) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaction not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'sometimes|string',
            'notes' => 'nullable|string',
            'payment_response' => 'nullable|json'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Mettre à jour uniquement certains champs
        $transaction->update($request->only(['status', 'notes', 'payment_response']));

        // Mettre à jour le statut de paiement de la commande si nécessaire
        if ($request->has('status')) {
            $order = Order::find($transaction->order_id);
            if ($order) {
                if ($transaction->transaction_type === Transaction::TYPE_PAYMENT && $request->status === Transaction::STATUS_COMPLETED) {
                    $order->update(['payment_status' => 'paid']);
                } elseif ($transaction->transaction_type === Transaction::TYPE_REFUND && $request->status === Transaction::STATUS_COMPLETED) {
                    $order->update(['payment_status' => 'refunded']);
                }
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Transaction updated successfully',
            'data' => $transaction
        ]);
    }

    /**
     * Remove the specified transaction from storage.
     * Cette méthode n'est pas recommandée pour les transactions
     * car il faut conserver toutes les traces des paiements.
     */
    public function destroy($id)
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Deleting transactions is not allowed for audit purposes'
        ], 403);
    }

    /**
     * Get transactions for an order.
     * 
     * @param int $orderId
     * @return \Illuminate\Http\Response
     */
    public function getOrderTransactions($orderId)
    {
        $order = Order::findOrFail($orderId);

        // Vérifier si l'utilisateur a accès à cette commande
        $user = Auth::user();
        if (!$user->isAdmin() && $order->user_id !== $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to view these transactions'
            ], 403);
        }

        $transactions = Transaction::where('order_id', $orderId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $transactions
        ]);
    }

    /**
     * Process a refund.
     * 
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function processRefund(Request $request)
    {
        // Vérifier les permissions d'administrateur
        $this->checkAdminAccess();

        $validator = Validator::make($request->all(), [
            'parent_transaction_id' => 'required|exists:transactions,id',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Récupérer la transaction parente
        $parentTransaction = Transaction::find($request->parent_transaction_id);

        // Vérifier si c'est un paiement et s'il peut être remboursé
        if ($parentTransaction->transaction_type !== Transaction::TYPE_PAYMENT) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only payment transactions can be refunded'
            ], 400);
        }

        if ($parentTransaction->status !== Transaction::STATUS_COMPLETED) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only completed transactions can be refunded'
            ], 400);
        }

        // Vérifier le montant du remboursement
        $refundedAmount = Transaction::where('parent_transaction_id', $parentTransaction->id)
            ->where('transaction_type', 'LIKE', '%refund%')
            ->where('status', Transaction::STATUS_COMPLETED)
            ->sum('amount');

        $availableForRefund = $parentTransaction->amount - $refundedAmount;

        if ($request->amount > $availableForRefund) {
            return response()->json([
                'status' => 'error',
                'message' => 'Refund amount exceeds available amount',
                'data' => [
                    'available_for_refund' => $availableForRefund
                ]
            ], 400);
        }

        // Déterminer le type de remboursement
        $refundType = $request->amount < $parentTransaction->amount ?
            Transaction::TYPE_PARTIAL_REFUND : Transaction::TYPE_REFUND;

        // Créer la transaction de remboursement
        $refundTransaction = Transaction::create([
            'order_id' => $parentTransaction->order_id,
            'payment_method' => $parentTransaction->payment_method,
            'payment_id' => 'refund_' . time(), // À remplacer par l'ID réel du remboursement
            'amount' => $request->amount,
            'currency' => $parentTransaction->currency,
            'status' => Transaction::STATUS_PENDING, // Initialement en attente
            'transaction_type' => $refundType,
            'reference_number' => $parentTransaction->reference_number,
            'billing_email' => $parentTransaction->billing_email,
            'billing_name' => $parentTransaction->billing_name,
            'parent_transaction_id' => $parentTransaction->id,
            'notes' => $request->reason ?? 'Manual refund',
        ]);

        // Ici, vous pourriez implémenter l'intégration avec Stripe/PayPal pour effectuer le remboursement réel
        // Puis mettre à jour le statut et l'ID de paiement en fonction de la réponse

        return response()->json([
            'status' => 'success',
            'message' => 'Refund transaction created successfully',
            'data' => $refundTransaction
        ], 201);
    }

    public function getStatistics(Request $request)
    {
        // Vérifier les permissions d'administrateur
        $this->checkAdminAccess();

        // Période par défaut: 30 derniers jours
        $startDate = $request->get('start_date', now()->subDays(30)->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));

        // Total des transactions réussies
        $successfulTransactions = Transaction::where('status', Transaction::STATUS_COMPLETED)
            ->where('transaction_type', Transaction::TYPE_PAYMENT)
            ->whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->count();

        // Montant total des paiements réussis
        $totalPayments = Transaction::where('status', Transaction::STATUS_COMPLETED)
            ->where('transaction_type', Transaction::TYPE_PAYMENT)
            ->whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->sum('amount');

        // Montant total des remboursements
        $totalRefunds = Transaction::where('status', Transaction::STATUS_COMPLETED)
            ->whereIn('transaction_type', [Transaction::TYPE_REFUND, Transaction::TYPE_PARTIAL_REFUND])
            ->whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->sum('amount');

        // Frais de transaction
        $totalFees = Transaction::where('status', Transaction::STATUS_COMPLETED)
            ->whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->sum('fee_amount');

        // Répartition par méthode de paiement
        $paymentMethodsStats = Transaction::where('status', Transaction::STATUS_COMPLETED)
            ->where('transaction_type', Transaction::TYPE_PAYMENT)
            ->whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->selectRaw('payment_method, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('payment_method')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
                'successful_transactions' => $successfulTransactions,
                'total_payments' => $totalPayments,
                'total_refunds' => $totalRefunds,
                'net_revenue' => $totalPayments - $totalRefunds,
                'total_fees' => $totalFees,
                'payment_methods' => $paymentMethodsStats
            ]
        ]);
    }

    /**
     * Récupérer les transactions d'un utilisateur.
     * 
     * @return \Illuminate\Http\Response
     */
    public function getUserTransactions(Request $request)
    {
        $user = Auth::user();

        $query = Transaction::whereHas('order', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        });

        // Filtrer par statut
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Pagination
        $transactions = $query->with(['order'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 10));

        return response()->json([
            'status' => 'success',
            'data' => [
                'transactions' => $transactions->items(),
                'pagination' => [
                    'current_page' => $transactions->currentPage(),
                    'per_page' => $transactions->perPage(),
                    'total' => $transactions->total(),
                    'last_page' => $transactions->lastPage(),
                ]
            ]
        ]);
    }

    /**
     * Méthode utilitaire pour vérifier les permissions d'administrateur.
     * 
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    private function checkAdminAccess()
    {
        $user = Auth::user();
        if (!$user || !$user->isAdmin()) {
            abort(403, 'You do not have permission to access this resource');
        }
    }

    /**
     * Méthode utilitaire pour vérifier si l'utilisateur a accès à une transaction.
     * 
     * @param Transaction $transaction
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    private function authorizeAccess(Transaction $transaction)
    {
        $user = Auth::user();

        // Les administrateurs ont accès à toutes les transactions
        if ($user->isAdmin()) {
            return;
        }

        // Les utilisateurs normaux ont accès uniquement à leurs propres transactions
        $order = $transaction->order;
        if (!$order || $order->user_id !== $user->id) {
            abort(403, 'You do not have permission to access this transaction');
        }
    }
}