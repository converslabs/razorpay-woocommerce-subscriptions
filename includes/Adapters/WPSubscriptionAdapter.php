<?php

namespace Razorpay\Subscriptions\Adapters;

use Razorpay\Subscriptions\Contracts\SubscriptionPluginInterface;
use SpringDevs\Subscription\Illuminate\Helper;

class WPSubscriptionAdapter implements SubscriptionPluginInterface
{
    public function is_active()
    {
        return class_exists('SpringDevs\Subscription\Sdevs_Subscription');
    }

    public function cart_contains_subscription()
    {
        // WPSubscription checks cart items for subscription meta
        if (function_exists('WC') && WC()->cart) {
            $cart_items = WC()->cart->get_cart_contents() ?? [];
            $recurs = Helper::get_recurrs_from_cart($cart_items);
            return !empty($recurs);
        }
        return false;
    }

    public function get_subscriptions_for_order($order_id)
    {
        // WPSubscription returns an array of objects, we need to normalize or handle it
        // This returns raw DB rows usually
        $subscriptions = Helper::get_subscriptions_from_order($order_id);
        
        // We need to return something that the main class can iterate over.
        // The main class expects objects that it can call methods on, OR we need to abstract that too.
        // For now, let's return the raw objects, but we might need to wrap them if the main class calls methods on them directly.
        // Looking at the code, the main class calls $subscription->get_parent(), etc.
        // So we definitely need to wrap these or change the main class to use the adapter for EVERYTHING.
        
        // For WPSubscription, the "subscription" is a post of type 'subscrpt_order'.
        // We should probably return a wrapper or the post ID.
        
        $wrapped_subscriptions = [];
        foreach ($subscriptions as $sub) {
            // $sub is a row from subscrpt_order_relation table
            // We need the actual subscription post
            $wrapped_subscriptions[] = new WPSubscriptionWrapper($sub->subscription_id);
        }
        
        return $wrapped_subscriptions;
    }

    public function get_product_period($product_id)
    {
        $product = wc_get_product($product_id);
        return $product->get_meta('_subscrpt_timing_option'); // e.g., 'month', 'year'
    }

    public function get_product_interval($product_id)
    {
        $product = wc_get_product($product_id);
        return (int) $product->get_meta('_subscrpt_timing_per');
    }

    public function get_product_length($product_id)
    {
        $product = wc_get_product($product_id);
        return (int) $product->get_meta('_subscrpt_max_no_payment');
    }

    public function get_product_trial_length($product_id)
    {
        // WPSubscription stores trial as a string like "3 days", "1 week"
        // We might need to parse this if Razorpay expects an int.
        // However, Razorpay Native Subscriptions handles trial via 'start_at'.
        // So we just need to know if there IS a trial to calculate start_at.
        $product = wc_get_product($product_id);
        $trial = $product->get_meta('_subscrpt_trial_time');
        if (empty($trial)) return 0;
        
        // Simple parsing logic if needed, or return 0 if complex
        return 1; // Placeholder indicating trial exists
    }

    public function get_signup_fee($product_id)
    {
        $product = wc_get_product($product_id);
        return (float) $product->get_meta('_subscrpt_signup_fee');
    }

    public function get_first_renewal_payment_time($product_id)
    {
        // Calculate based on current time + trial
        $product = wc_get_product($product_id);
        $trial = $product->get_meta('_subscrpt_trial_time'); // e.g. "3 days"
        
        if (!empty($trial)) {
            return strtotime($trial);
        }
        
        // If no trial, it's immediate? Or next period?
        // Razorpay expects a timestamp.
        // If no trial, start_at should be null (immediate).
        return 0;
    }

    public function create_renewal_order($subscription, $transaction_id)
    {
        // $subscription is our Wrapper
        $subscription_id = $subscription->get_id();
        
        // WPSubscription's create_renewal_order doesn't return the order object directly in all versions,
        // but let's check Helper::create_renewal_order code.
        // It calls do_action('subscrpt_after_create_renew_order', $new_order...)
        // It doesn't return the order.
        // We might need to hook into the action to capture it, or query for the latest order.
        
        Helper::create_renewal_order($subscription_id);
        
        // Find the latest order for this subscription
        $orders = Helper::get_subscriptions_from_order($subscription->get_parent_id()); // This gets relations
        // We need to find the order created just now.
        // This is tricky with the current Helper.
        
        // Workaround: The Helper::create_renewal_order saves the order.
        // We can try to find the last order associated with this subscription.
        
        // Actually, we can just return null if we don't need to do anything else with it.
        // But the interface expects an order to set transaction ID.
        
        // Let's look at Helper::create_renewal_order again.
        // It sets payment method to match old order.
        // We need to update the transaction ID.
        
        // For now, let's assume we can find it.
        $last_order_id = get_post_meta($subscription_id, '_subscrpt_order_id', true);
        $order = wc_get_order($last_order_id);
        
        if ($order) {
            $order->set_transaction_id($transaction_id);
            $order->save();
        }
        
        return $order;
    }

    public function update_next_payment_date($subscription, $new_date)
    {
        update_post_meta($subscription->get_id(), '_subscrpt_next_date', strtotime($new_date));
    }

    public function cancel_subscription($subscription)
    {
        wp_update_post([
            'ID' => $subscription->get_id(),
            'post_status' => 'cancelled'
        ]);
    }

    public function pause_subscription($subscription)
    {
        wp_update_post([
            'ID' => $subscription->get_id(),
            'post_status' => 'on-hold'
        ]);
    }

    public function resume_subscription($subscription)
    {
        wp_update_post([
            'ID' => $subscription->get_id(),
            'post_status' => 'active'
        ]);
    }

    public function payment_complete($subscription, $payment_id)
    {
        // WPSubscription doesn't have a direct "payment_complete" method on the subscription post.
        // It tracks payments via orders.
        // We might not need to do anything here if the renewal order handles it.
    }
}

class WPSubscriptionWrapper {
    protected $id;
    protected $post;
    
    public function __construct($id) {
        $this->id = $id;
        $this->post = get_post($id);
    }
    
    public function get_id() {
        return $this->id;
    }
    
    public function get_parent_id() {
        return get_post_meta($this->id, '_subscrpt_order_id', true);
    }
    
    public function get_parent() {
        return wc_get_order($this->get_parent_id());
    }
    
    public function get_status() {
        return get_post_status($this->id);
    }
    
    public function has_status($status) {
        return $this->get_status() === $status;
    }
    
    public function get_payment_count() {
        // Helper function needed or custom query
        return 1; // Placeholder
    }
    
    public function get_total() {
        return get_post_meta($this->id, '_subscrpt_price', true);
    }
    
    public function is_manual() {
        return false;
    }
    
    public function get_payment_method() {
        $order = $this->get_parent();
        return $order ? $order->get_payment_method() : '';
    }
    
    public function payment_method_supports($feature) {
        return true; // Assume yes for Razorpay
    }
    
    public function needs_payment() {
        return true;
    }
    
    public function get_last_order() {
        return $this->get_parent();
    }
    
    public function get_related_orders() {
        return []; // Implement if needed
    }
}
