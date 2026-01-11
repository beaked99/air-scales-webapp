<?php

namespace App\Service;

use Stripe\Stripe;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\BillingPortal\Session as BillingPortalSession;
use Stripe\Customer;
use Stripe\Subscription;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;

class StripeService
{
    private string $secretKey;
    private string $publishableKey;
    private EntityManagerInterface $em;

    public function __construct(
        ParameterBagInterface $params,
        EntityManagerInterface $em
    ) {
        $this->secretKey = $_ENV['STRIPE_SECRET_KEY'] ?? '';
        $this->publishableKey = $_ENV['STRIPE_PUBLISHABLE_KEY'] ?? '';
        $this->em = $em;

        Stripe::setApiKey($this->secretKey);
    }

    /**
     * Get or create Stripe customer for user
     */
    public function getOrCreateCustomer(User $user): Customer
    {
        // If user already has a Stripe customer ID, retrieve it
        if ($user->getStripeCustomerId()) {
            try {
                return Customer::retrieve($user->getStripeCustomerId());
            } catch (ApiErrorException $e) {
                // Customer not found, create new one
            }
        }

        // Create new Stripe customer
        $customer = Customer::create([
            'email' => $user->getEmail(),
            'name' => $user->getFullName(),
            'metadata' => [
                'user_id' => $user->getId()
            ]
        ]);

        // Save customer ID to user
        $user->setStripeCustomerId($customer->id);
        $this->em->flush();

        return $customer;
    }

    /**
     * Create Stripe Checkout Session for subscription
     */
    public function createSubscriptionCheckout(
        User $user,
        string $priceId,
        string $successUrl,
        string $cancelUrl
    ): CheckoutSession {
        $customer = $this->getOrCreateCustomer($user);

        return CheckoutSession::create([
            'customer' => $customer->id,
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price' => $priceId,
                'quantity' => 1,
            ]],
            'mode' => 'subscription',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'user_id' => $user->getId()
            ]
        ]);
    }

    /**
     * Create Stripe Checkout Session for one-time device purchase
     */
    public function createDeviceCheckout(
        User $user,
        string $priceId,
        int $quantity,
        string $successUrl,
        string $cancelUrl
    ): CheckoutSession {
        $customer = $this->getOrCreateCustomer($user);

        return CheckoutSession::create([
            'customer' => $customer->id,
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price' => $priceId,
                'quantity' => $quantity,
            ]],
            'mode' => 'payment',
            'shipping_address_collection' => [
                'allowed_countries' => ['US', 'CA'], // Adjust based on where you ship
            ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'user_id' => $user->getId(),
                'device_quantity' => $quantity
            ]
        ]);
    }

    /**
     * Create Stripe Customer Portal session
     */
    public function createPortalSession(User $user, string $returnUrl): BillingPortalSession
    {
        $customer = $this->getOrCreateCustomer($user);

        return BillingPortalSession::create([
            'customer' => $customer->id,
            'return_url' => $returnUrl,
        ]);
    }

    /**
     * Cancel subscription at period end
     */
    public function cancelSubscriptionAtPeriodEnd(string $subscriptionId): Subscription
    {
        return Subscription::update($subscriptionId, [
            'cancel_at_period_end' => true
        ]);
    }

    /**
     * Reactivate a canceled subscription
     */
    public function reactivateSubscription(string $subscriptionId): Subscription
    {
        return Subscription::update($subscriptionId, [
            'cancel_at_period_end' => false
        ]);
    }

    /**
     * Update subscription to new price (upgrade/downgrade)
     */
    public function updateSubscriptionPrice(string $subscriptionId, string $newPriceId): Subscription
    {
        $subscription = Subscription::retrieve($subscriptionId);

        return Subscription::update($subscriptionId, [
            'items' => [[
                'id' => $subscription->items->data[0]->id,
                'price' => $newPriceId,
            ]],
            'proration_behavior' => 'always_invoice',
        ]);
    }

    /**
     * Schedule subscription price change at next renewal (no proration)
     */
    public function scheduleSubscriptionPriceChange(string $subscriptionId, string $newPriceId): Subscription
    {
        $subscription = Subscription::retrieve($subscriptionId);

        return Subscription::update($subscriptionId, [
            'items' => [[
                'id' => $subscription->items->data[0]->id,
                'price' => $newPriceId,
            ]],
            'proration_behavior' => 'none',
            'billing_cycle_anchor' => 'unchanged',
        ]);
    }

    /**
     * Get publishable key for frontend
     */
    public function getPublishableKey(): string
    {
        return $this->publishableKey;
    }
}
