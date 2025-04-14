<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class PaypalService
{
    private $baseUrl;
    private $clientId;
    private $clientSecret;
    private $webhookId;
    private $accessToken;
    private $isProduction;

    public function __construct()
    {
        $this->isProduction = Config::get('paypal.mode') === 'live';
        $this->baseUrl = $this->isProduction
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
        $this->clientId = Config::get('paypal.client_id');
        $this->clientSecret = Config::get('paypal.client_secret');
        $this->webhookId = Config::get('paypal.webhook_id');
    }

    /**
     * Get OAuth access token
     *
     * @return string
     * @throws \Exception
     */
    private function getAccessToken()
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        try {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()
                ->post("{$this->baseUrl}/v1/oauth2/token", [
                    'grant_type' => 'client_credentials'
                ]);

            if ($response->successful()) {
                $this->accessToken = $response->json('access_token');
                return $this->accessToken;
            }

            Log::error('Failed to get PayPal access token', [
                'status' => $response->status(),
                'response' => $response->json()
            ]);

            throw new \Exception('Failed to get PayPal access token');
        } catch (\Exception $e) {
            Log::error('Exception getting PayPal access token', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create a PayPal order
     *
     * @param float $amount
     * @param string $currency
     * @param array $metadata
     * @return array
     */
    public function createOrder($amount, $currency, $metadata = [])
    {
        try {
            $accessToken = $this->getAccessToken();

            $payload = [
                'intent' => 'CAPTURE',
                'purchase_units' => [
                    [
                        'amount' => [
                            'currency_code' => $currency,
                            'value' => number_format($amount, 2, '.', '')
                        ],
                        'reference_id' => $metadata['order_number'] ?? uniqid('order-'),
                        'custom_id' => $metadata['order_id'] ?? null,
                        'description' => 'Order #' . ($metadata['order_number'] ?? 'Unknown')
                    ]
                ],
                'application_context' => [
                    'brand_name' => Config::get('app.name', 'Online Store'),
                    'shipping_preference' => 'NO_SHIPPING',
                    'user_action' => 'PAY_NOW',
                    'return_url' => Config::get('app.url') . '/payment/confirmation',
                    'cancel_url' => Config::get('app.url') . '/cart'
                ]
            ];

            $response = Http::withToken($accessToken)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'PayPal-Request-Id' => uniqid('paypal-request-')
                ])
                ->post("{$this->baseUrl}/v2/checkout/orders", $payload);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'paypalOrderId' => $data['id'],
                    'status' => $data['status'],
                    'links' => $data['links']
                ];
            }

            Log::error('Failed to create PayPal order', [
                'status' => $response->status(),
                'response' => $response->json()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to create PayPal order: ' . $response->body()
            ];
        } catch (\Exception $e) {
            Log::error('Exception creating PayPal order', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Exception creating PayPal order: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Capture an approved PayPal order payment
     *
     * @param string $orderId
     * @return array
     */
    public function capturePayment($orderId)
    {
        try {
            $accessToken = $this->getAccessToken();

            $response = Http::withToken($accessToken)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'PayPal-Request-Id' => uniqid('paypal-capture-')
                ])
                ->post("{$this->baseUrl}/v2/checkout/orders/{$orderId}/capture");

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'captureData' => $data,
                    'captureId' => $data['purchase_units'][0]['payments']['captures'][0]['id'] ?? null,
                    'status' => $data['status']
                ];
            }

            Log::error('Failed to capture PayPal payment', [
                'status' => $response->status(),
                'response' => $response->json(),
                'order_id' => $orderId
            ]);

            return [
                'success' => false,
                'error' => 'Failed to capture PayPal payment: ' . $response->body()
            ];
        } catch (\Exception $e) {
            Log::error('Exception capturing PayPal payment', [
                'error' => $e->getMessage(),
                'order_id' => $orderId
            ]);

            return [
                'success' => false,
                'error' => 'Exception capturing PayPal payment: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Verify webhook signature
     *
     * @param array $payload
     * @param array $headers
     * @return bool
     */
    public function verifyWebhookSignature($payload, $headers)
    {
        try {
            $accessToken = $this->getAccessToken();

            $webhookId = $this->webhookId;

            // Get the necessary headers
            $authAlgo = $headers['paypal-auth-algo'][0] ?? '';
            $certUrl = $headers['paypal-cert-url'][0] ?? '';
            $transmissionId = $headers['paypal-transmission-id'][0] ?? '';
            $transmissionSig = $headers['paypal-transmission-sig'][0] ?? '';
            $transmissionTime = $headers['paypal-transmission-time'][0] ?? '';

            $verificationData = [
                'webhook_id' => $webhookId,
                'auth_algo' => $authAlgo,
                'cert_url' => $certUrl,
                'transmission_id' => $transmissionId,
                'transmission_sig' => $transmissionSig,
                'transmission_time' => $transmissionTime,
                'webhook_event' => $payload
            ];

            $response = Http::withToken($accessToken)
                ->withHeaders([
                    'Content-Type' => 'application/json'
                ])
                ->post("{$this->baseUrl}/v1/notifications/verify-webhook-signature", $verificationData);

            if ($response->successful()) {
                $data = $response->json();
                return $data['verification_status'] === 'SUCCESS';
            }

            Log::error('Failed to verify PayPal webhook signature', [
                'status' => $response->status(),
                'response' => $response->json()
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Exception verifying PayPal webhook signature', [
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Refund a payment
     *
     * @param string $captureId
     * @param float|null $amount
     * @param string $currency
     * @param string $note
     * @return array
     */
    public function refundPayment($captureId, $amount = null, $currency = 'EUR', $note = 'Refund')
    {
        try {
            $accessToken = $this->getAccessToken();

            $payload = [
                'note_to_payer' => $note
            ];

            // If amount is specified, add it to the payload
            if ($amount !== null) {
                $payload['amount'] = [
                    'value' => number_format($amount, 2, '.', ''),
                    'currency_code' => $currency
                ];
            }

            $response = Http::withToken($accessToken)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'PayPal-Request-Id' => uniqid('paypal-refund-')
                ])
                ->post("{$this->baseUrl}/v2/payments/captures/{$captureId}/refund", $payload);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'refundId' => $data['id'],
                    'status' => $data['status'],
                    'data' => $data
                ];
            }

            Log::error('Failed to refund PayPal payment', [
                'status' => $response->status(),
                'response' => $response->json(),
                'capture_id' => $captureId
            ]);

            return [
                'success' => false,
                'error' => 'Failed to refund PayPal payment: ' . $response->body()
            ];
        } catch (\Exception $e) {
            Log::error('Exception refunding PayPal payment', [
                'error' => $e->getMessage(),
                'capture_id' => $captureId
            ]);

            return [
                'success' => false,
                'error' => 'Exception refunding PayPal payment: ' . $e->getMessage()
            ];
        }
    }
}