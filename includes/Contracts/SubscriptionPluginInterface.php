<?php

namespace Razorpay\Subscriptions\Contracts;

interface SubscriptionPluginInterface
{
    /**
     * Check if the plugin is active and ready.
     *
     * @return bool
     */
    public function is_active();

    /**
     * Check if the cart contains a subscription product.
     *
     * @return bool
     */
    public function cart_contains_subscription();

    /**
     * Get subscriptions associated with an order.
     *
     * @param int $order_id
     * @return array
     */
    public function get_subscriptions_for_order($order_id);

    /**
     * Get the billing period for a product (e.g., 'month', 'year').
     *
     * @param int $product_id
     * @return string
     */
    public function get_product_period($product_id);

    /**
     * Get the billing interval for a product (e.g., 1, 3).
     *
     * @param int $product_id
     * @return int
     */
    public function get_product_interval($product_id);

    /**
     * Get the subscription length (total number of payments).
     *
     * @param int $product_id
     * @return int
     */
    public function get_product_length($product_id);

    /**
     * Get the trial length for a product.
     *
     * @param int $product_id
     * @return int
     */
    public function get_product_trial_length($product_id);

    /**
     * Get the sign-up fee for a product.
     *
     * @param int $product_id
     * @return float
     */
    public function get_signup_fee($product_id);

    /**
     * Get the first renewal payment date.
     *
     * @param int $product_id
     * @return string|int
     */
    public function get_first_renewal_payment_time($product_id);

    /**
     * Create a renewal order for a subscription.
     *
     * @param mixed $subscription The subscription object/ID.
     * @param string $transaction_id The payment transaction ID.
     * @return \WC_Order|null
     */
    public function create_renewal_order($subscription, $transaction_id);

    /**
     * Update the next payment date for a subscription.
     *
     * @param mixed $subscription The subscription object/ID.
     * @param string $new_date The new date (Y-m-d H:i:s).
     * @return void
     */
    public function update_next_payment_date($subscription, $new_date);

    /**
     * Cancel a subscription.
     *
     * @param mixed $subscription The subscription object/ID.
     * @return void
     */
    public function cancel_subscription($subscription);

    /**
     * Pause a subscription.
     *
     * @param mixed $subscription The subscription object/ID.
     * @return void
     */
    public function pause_subscription($subscription);

    /**
     * Resume a subscription.
     *
     * @param mixed $subscription The subscription object/ID.
     * @return void
     */
    public function resume_subscription($subscription);
    
    /**
     * Mark payment as complete for a subscription.
     * 
     * @param mixed $subscription
     * @param string $payment_id
     * @return void
     */
    public function payment_complete($subscription, $payment_id);

    /**
     * Get the total amount for the subscription.
     *
     * @param mixed $subscription
     * @return float
     */
    public function get_total($subscription);

    /**
     * Get the parent order of the subscription.
     *
     * @param mixed $subscription
     * @return \WC_Order|bool
     */
    public function get_parent($subscription);

    /**
     * Check if the subscription has a specific status.
     *
     * @param mixed $subscription
     * @param string $status
     * @return bool
     */
    public function has_status($subscription, $status);

    /**
     * Check if the subscription needs payment.
     *
     * @param mixed $subscription
     * @return bool
     */
    public function needs_payment($subscription);
}
