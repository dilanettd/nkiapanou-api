<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class StripePaymentController extends Controller
{
    protected $stripeService;

    public function __construct(StripeService $stripeService)
    {
        $this->stripeService = $stripeService;
    }

    public function createPaymentIntent(Request $request)
    {
        // Validation des données
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'currency' => 'required|string|size:3',
            'order_id' => 'required|integer|exists:orders,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Récupérer la commande
        $order = Order::findOrFail($request->order_id);

        // Vérifier que le montant correspond
        if ($order->total_amount != $request->amount) {
            return response()->json([
                'success' => false,
                'message' => 'Le montant ne correspond pas à celui de la commande'
            ], 400);
        }

        // Récupérer l'utilisateur lié à la commande
        $user = User::find($order->user_id);
        $userEmail = $user ? $user->email : 'client@example.com';
        $userName = $user ? $user->name : 'Client';

        // Créer le PaymentIntent avec des métadonnées
        $metadata = [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'customer_email' => $userEmail,
        ];

        $result = $this->stripeService->createPaymentIntent(
            $request->amount,
            strtolower($request->currency),
            $metadata
        );

        if (!$result['success']) {
            Log::error('Erreur lors de la création du PaymentIntent', [
                'error' => $result['error'],
                'order_id' => $order->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du paiement'
            ], 500);
        }

        // Mettre à jour la commande avec l'ID du PaymentIntent
        $order->payment_id = $result['paymentIntentId'];
        $order->payment_method = 'stripe';
        $order->payment_status = 'pending';
        $order->save();

        // Créer une transaction en utilisant les attributs mass-assignable
        Transaction::create([
            'order_id' => $order->id,
            'amount' => $order->total_amount,
            'currency' => $request->currency,
            'payment_method' => Transaction::METHOD_STRIPE,
            'payment_id' => $result['paymentIntentId'],
            'status' => Transaction::STATUS_PENDING,
            'transaction_type' => Transaction::TYPE_PAYMENT,
            'reference_number' => $order->order_number,
            'billing_email' => $userEmail,
            'billing_name' => $userName,
            'payment_method_details' => 'Stripe',
            'notes' => 'Paiement initial pour la commande ' . $order->order_number,
        ]);

        // Journaliser la création de la transaction
        Log::info('Transaction créée avec succès', [
            'order_id' => $order->id,
            'payment_id' => $result['paymentIntentId']
        ]);

        return response()->json([
            'success' => true,
            'clientSecret' => $result['clientSecret']
        ]);
    }

    /**
     * Confirmer le succès d'un paiement
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function confirmPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'payment_intent_id' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            // Récupérer le PaymentIntent
            $paymentIntent = $this->stripeService->getPaymentIntent($request->payment_intent_id);

            // Vérifier le statut du paiement
            if ($paymentIntent->status !== 'succeeded') {
                return response()->json([
                    'success' => false,
                    'message' => 'Le paiement n\'a pas encore été complété'
                ], 400);
            }

            // Trouver la commande associée
            $order = Order::where('payment_id', $request->payment_intent_id)->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Commande introuvable'
                ], 404);
            }

            // Mettre à jour le statut de la commande
            $order->payment_status = 'paid';
            $order->status = 'processing'; // Mettre à jour le statut de la commande
            $order->save();

            // Mettre à jour la transaction
            $transaction = Transaction::where('payment_id', $request->payment_intent_id)->first();
            if ($transaction) {
                $transaction->status = 'completed';
                $transaction->payment_response = json_encode($paymentIntent);
                $transaction->save();
            }

            return response()->json([
                'success' => true,
                'message' => 'Paiement confirmé avec succès',
                'order' => $order
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la confirmation du paiement', [
                'error' => $e->getMessage(),
                'payment_intent_id' => $request->payment_intent_id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la confirmation du paiement'
            ], 500);
        }
    }

    /**
     * Gérer les webhooks Stripe
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('stripe.webhook.secret');

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sigHeader,
                $webhookSecret
            );
        } catch (\UnexpectedValueException $e) {
            // Payload invalide
            Log::error('Webhook Stripe: Payload invalide', ['error' => $e->getMessage()]);
            return response('', 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Signature invalide
            Log::error('Webhook Stripe: Signature invalide', ['error' => $e->getMessage()]);
            return response('', 400);
        }

        // Gérer l'événement
        switch ($event->type) {
            case 'payment_intent.succeeded':
                $paymentIntent = $event->data->object;
                $this->handleSuccessfulPayment($paymentIntent);
                break;
            case 'payment_intent.payment_failed':
                $paymentIntent = $event->data->object;
                $this->handleFailedPayment($paymentIntent);
                break;
            // Gérer d'autres types d'événements si nécessaire
        }

        return response('', 200);
    }

    /**
     * Gérer un paiement réussi via webhook
     *
     * @param \Stripe\PaymentIntent $paymentIntent
     * @return void
     */
    private function handleSuccessfulPayment($paymentIntent)
    {
        $order = Order::where('payment_id', $paymentIntent->id)->first();

        if ($order && $order->payment_status !== 'paid') {
            $order->payment_status = 'paid';
            $order->status = 'processing'; // Passer la commande en traitement
            $order->save();

            // Mettre à jour la transaction associée
            $transaction = Transaction::where('payment_id', $paymentIntent->id)->first();
            if ($transaction) {
                $transaction->status = 'completed';
                $transaction->payment_response = json_encode($paymentIntent);
                $transaction->save();
            }

            // Vous pouvez ajouter d'autres actions ici
            // comme envoyer un email de confirmation, etc.
            Log::info('Paiement réussi via webhook', [
                'order_id' => $order->id,
                'payment_id' => $paymentIntent->id
            ]);
        }
    }

    /**
     * Gérer un paiement échoué via webhook
     *
     * @param \Stripe\PaymentIntent $paymentIntent
     * @return void
     */
    private function handleFailedPayment($paymentIntent)
    {
        $order = Order::where('payment_id', $paymentIntent->id)->first();

        if ($order) {
            $order->payment_status = 'failed';
            $order->save();

            // Mettre à jour la transaction associée
            $transaction = Transaction::where('payment_id', $paymentIntent->id)->first();
            if ($transaction) {
                $transaction->status = 'failed';
                $transaction->payment_response = json_encode($paymentIntent);
                $transaction->notes = $paymentIntent->last_payment_error?->message ?? 'Échec de paiement';
                $transaction->save();
            }

            Log::warning('Paiement échoué', [
                'order_id' => $order->id,
                'payment_id' => $paymentIntent->id,
                'error' => $paymentIntent->last_payment_error?->message ?? 'Inconnu'
            ]);
        }
    }
}