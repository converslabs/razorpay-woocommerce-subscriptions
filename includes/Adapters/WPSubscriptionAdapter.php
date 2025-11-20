<?php

namespace Razorpay\Subscriptions\Adapters;

use Razorpay\Subscriptions\Contracts\SubscriptionPluginInterface;
use SpringDevs\Subscription\Illuminate\Helper;

require_once __DIR__ . '/WPSubscriptionWrapper.php';

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
        $product = wc_get_product($product_id);
        // Return the numeric trial length (e.g., 3 for "3 days")
        return (int) $product->get_meta('_subscrpt_trial_timing_per');
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
        
        // We need to calculate the actual timestamp.
        // WPSubscription stores trial period and option.
        $trial_period = $product->get_meta('_subscrpt_trial_timing_per');
        $trial_option = $product->get_meta('_subscrpt_trial_timing_option'); // e.g., 'days', 'weeks'
        
        if (!empty($trial_period) && !empty($trial_option)) {
            return strtotime("+{$trial_period} {$trial_option}");
        }
        
        return 0;
    }

    public function create_renewal_order($subscription, $transaction_id)
    {
        // $subscription is our Wrapper
        $subscription_id = $subscription->get_id();
        
        // Helper::create_renewal_order now returns the order object (after our modification)
        $order = Helper::create_renewal_order($subscription_id);
        
        if ($order && $order instanceof \WC_Order) {
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
        // 1. Activate subscription if not active
        if (!$subscription->has_status('active')) {
            wp_update_post([
                'ID' => $subscription->get_id(),
                'post_status' => 'active'
            ]);
        }

        // 2. Add a note to the subscription
        $comment_id = wp_insert_comment([
            'comment_author'  => 'Razorpay',
            'comment_content' => sprintf('Payment received via Razorpay. Transaction ID: %s', $payment_id),
            'comment_post_ID' => $subscription->get_id(),
            'comment_type'    => 'order_note',
        ]);
        update_comment_meta($comment_id, '_subscrpt_activity', 'Payment Received');
        update_comment_meta($comment_id, '_subscrpt_activity_type', 'payment_received');
    }
}
