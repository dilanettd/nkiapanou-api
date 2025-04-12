<?php

namespace App\Services;

use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Exception\ApiErrorException;

class StripeService
{
    public function __construct()
    {
        Stripe::setApiKey(config('stripe.secret'));
    }

    /**
     * Créer un PaymentIntent pour une commande
     *
     * @param float $amount Montant en centimes
     * @param string $currency Code de la devise (par défaut EUR)
     * @param array $metadata Données supplémentaires
     * @return array
     * @throws ApiErrorException
     */
    public function createPaymentIntent($amount, $currency = 'eur', $metadata = [])
    {
        try {
            $paymentIntent = PaymentIntent::create([
                'amount' => $this->formatAmount($amount, $currency),
                'currency' => $currency,
                'metadata' => $metadata,
                'payment_method_types' => ['card'],
            ]);

            return [
                'success' => true,
                'clientSecret' => $paymentIntent->client_secret,
                'paymentIntentId' => $paymentIntent->id
            ];
        } catch (ApiErrorException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Récupérer un PaymentIntent existant
     *
     * @param string $paymentIntentId
     * @return PaymentIntent
     * @throws ApiErrorException
     */
    public function getPaymentIntent($paymentIntentId)
    {
        return PaymentIntent::retrieve($paymentIntentId);
    }

    /**
     * Mettre à jour un PaymentIntent existant
     *
     * @param string $paymentIntentId
     * @param array $params
     * @return PaymentIntent
     * @throws ApiErrorException
     */
    public function updatePaymentIntent($paymentIntentId, array $params)
    {
        return PaymentIntent::update($paymentIntentId, $params);
    }

    /**
     * Formater le montant selon la devise
     * Certaines devises n'utilisent pas les centimes
     *
     * @param float $amount
     * @param string $currency
     * @return int
     */
    private function formatAmount($amount, $currency)
    {
        // Liste des devises qui n'utilisent pas les centimes
        $zeroDecimalCurrencies = ['jpy', 'krw', 'vnd', 'xaf', 'xof', 'clp', 'pyg', 'rwf'];

        if (in_array(strtolower($currency), $zeroDecimalCurrencies)) {
            return (int) $amount;
        }

        // Convertir en centimes pour les autres devises
        return (int) ($amount * 100);
    }
}