<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaypalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PaypalPaymentController extends Controller
{
    protected $paypalService;

    public function __construct(PaypalService $paypalService)
    {
        $this->paypalService = $paypalService;
    }

    /**
     * Create a PayPal order
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createOrder(Request $request)
    {
        // Validate request data
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'currency' => 'required|string|size:3',
            'order_id' => 'required|integer|exists:orders,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Get the order
        $order = Order::findOrFail($request->order_id);

        // Verify the amount matches
        if ($order->total_amount != $request->amount) {
            return response()->json([
                'success' => false,
                'message' => 'Amount does not match the order total'
            ], 400);
        }

        // Create order metadata
        $metadata = [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            // Use the email of the user associated with the order
            'customer_email' => User::find($order->user_id)->email ?? 'client@example.com',
        ];

        try {
            // Create PayPal order
            $response = $this->paypalService->createOrder(
                $request->amount,
                strtoupper($request->currency),
                $metadata
            );

            if (!$response['success']) {
                Log::error('Error creating PayPal order', [
                    'error' => $response['error'],
                    'order_id' => $order->id
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Error creating payment'
                ], 500);
            }

            // Update order with PayPal order ID
            $order->payment_id = $response['paypalOrderId'];
            $order->payment_method = 'paypal';
            $order->payment_status = 'pending';
            $order->save();

            // Create transaction record
            $transaction = new Transaction();
            $transaction->order_id = $order->id;
            $transaction->user_id = $order->user_id;
            $transaction->amount = $order->total_amount;
            $transaction->currency = $request->currency;
            $transaction->payment_method = 'paypal';
            $transaction->payment_id = $response['paypalOrderId'];
            $transaction->status = 'pending';
            $transaction->transaction_type = 'payment';
            $transaction->reference_number = $order->order_number;
            $transaction->billing_email = User::find($order->user_id)->email ?? 'client@example.com';
            $transaction->save();

            return response()->json([
                'success' => true,
                'paypalOrderId' => $response['paypalOrderId']
            ]);
        } catch (\Exception $e) {
            Log::error('Exception creating PayPal order', [
                'error' => $e->getMessage(),
                'order_id' => $order->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error creating payment'
            ], 500);
        }
    }

    /**
     * Capture payment after approval
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function capturePayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'paypal_order_id' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            // Capture the payment
            $result = $this->paypalService->capturePayment($request->paypal_order_id);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment capture failed'
                ], 400);
            }

            // Find associated order
            $order = Order::where('payment_id', $request->paypal_order_id)->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            // Update order status
            $order->payment_status = 'paid';
            $order->status = 'processing';
            $order->save();

            // Update transaction
            $transaction = Transaction::where('payment_id', $request->paypal_order_id)->first();
            if ($transaction) {
                $transaction->status = 'completed';
                $transaction->payment_response = json_encode($result['captureData']);
                $transaction->save();
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment captured successfully',
                'order' => $order
            ]);
        } catch (\Exception $e) {
            Log::error('Error capturing PayPal payment', [
                'error' => $e->getMessage(),
                'paypal_order_id' => $request->paypal_order_id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error processing payment'
            ], 500);
        }
    }

    /**
     * Handle PayPal webhooks
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->all();
        $headers = $request->header();

        try {
            // Verify webhook signature
            $verified = $this->paypalService->verifyWebhookSignature($payload, $headers);

            if (!$verified) {
                Log::error('PayPal webhook: Invalid signature');
                return response('', 400);
            }

            // Process the webhook event
            $eventType = $payload['event_type'] ?? '';

            switch ($eventType) {
                case 'PAYMENT.CAPTURE.COMPLETED':
                    $this->handlePaymentCompleted($payload);
                    break;
                case 'PAYMENT.CAPTURE.DENIED':
                    $this->handlePaymentDenied($payload);
                    break;
                case 'PAYMENT.CAPTURE.REFUNDED':
                    $this->handlePaymentRefunded($payload);
                    break;
                // Handle other event types as needed
            }

            return response('', 200);
        } catch (\Exception $e) {
            Log::error('PayPal webhook processing error', [
                'error' => $e->getMessage()
            ]);
            return response('', 500);
        }
    }

    /**
     * Handle payment completed webhook event
     *
     * @param array $payload
     * @return void
     */
    private function handlePaymentCompleted($payload)
    {
        $resource = $payload['resource'] ?? [];
        $paypalOrderId = $resource['supplementary_data']['related_ids']['order_id'] ?? null;

        if (!$paypalOrderId) {
            Log::error('PayPal webhook: Missing order ID in payment completed event');
            return;
        }

        $order = Order::where('payment_id', $paypalOrderId)->first();

        if ($order && $order->payment_status !== 'paid') {
            $order->payment_status = 'paid';
            $order->status = 'processing';
            $order->save();

            // Update transaction
            $transaction = Transaction::where('payment_id', $paypalOrderId)->first();
            if ($transaction) {
                $transaction->status = 'completed';
                $transaction->payment_response = json_encode($resource);
                $transaction->save();
            }

            Log::info('PayPal payment completed via webhook', [
                'order_id' => $order->id,
                'payment_id' => $paypalOrderId
            ]);
        }
    }

    /**
     * Handle payment denied webhook event
     *
     * @param array $payload
     * @return void
     */
    private function handlePaymentDenied($payload)
    {
        $resource = $payload['resource'] ?? [];
        $paypalOrderId = $resource['supplementary_data']['related_ids']['order_id'] ?? null;

        if (!$paypalOrderId) {
            Log::error('PayPal webhook: Missing order ID in payment denied event');
            return;
        }

        $order = Order::where('payment_id', $paypalOrderId)->first();

        if ($order) {
            $order->payment_status = 'failed';
            $order->save();

            // Update transaction
            $transaction = Transaction::where('payment_id', $paypalOrderId)->first();
            if ($transaction) {
                $transaction->status = 'failed';
                $transaction->payment_response = json_encode($resource);
                $transaction->save();
            }

            Log::warning('PayPal payment denied', [
                'order_id' => $order->id,
                'payment_id' => $paypalOrderId
            ]);
        }
    }

    /**
     * Handle payment refunded webhook event
     *
     * @param array $payload
     * @return void
     */
    private function handlePaymentRefunded($payload)
    {
        $resource = $payload['resource'] ?? [];
        $paypalOrderId = $resource['supplementary_data']['related_ids']['order_id'] ?? null;

        if (!$paypalOrderId) {
            Log::error('PayPal webhook: Missing order ID in payment refunded event');
            return;
        }

        $order = Order::where('payment_id', $paypalOrderId)->first();

        if ($order) {
            $order->payment_status = 'refunded';
            $order->save();

            // Create refund transaction
            $originalTransaction = Transaction::where('payment_id', $paypalOrderId)
                ->where('transaction_type', 'payment')
                ->first();

            if ($originalTransaction) {
                $refundTransaction = new Transaction();
                $refundTransaction->order_id = $order->id;
                $refundTransaction->user_id = $order->user_id;
                $refundTransaction->amount = $originalTransaction->amount;
                $refundTransaction->currency = $originalTransaction->currency;
                $refundTransaction->payment_method = 'paypal';
                $refundTransaction->payment_id = $paypalOrderId . '_refund';
                $refundTransaction->status = 'completed';
                $refundTransaction->transaction_type = 'refund';
                $refundTransaction->reference_number = $order->order_number;
                $refundTransaction->billing_email = $originalTransaction->billing_email;
                $refundTransaction->parent_transaction_id = $originalTransaction->id;
                $refundTransaction->payment_response = json_encode($resource);
                $refundTransaction->save();
            }

            Log::info('PayPal payment refunded', [
                'order_id' => $order->id,
                'payment_id' => $paypalOrderId
            ]);
        }
    }
}