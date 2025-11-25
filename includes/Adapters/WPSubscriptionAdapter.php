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
        // WPSubscription returns an array of objects (rows from DB)
        $subscriptions = Helper::get_subscriptions_from_order($order_id);
        
        // Return array of subscription IDs
        $subscription_ids = [];
        foreach ($subscriptions as $sub) {
            $subscription_ids[] = (int) $sub->subscription_id;
        }
        
        return $subscription_ids;
    }

    public function get_product_period($product_id)
    {
        return Helper::get_product_period($product_id);
    }

    public function get_product_interval($product_id)
    {
        return Helper::get_product_interval($product_id);
    }

    public function get_product_length($product_id)
    {
        return Helper::get_product_length($product_id);
    }

    public function get_product_trial_length($product_id)
    {
        return Helper::get_product_trial_length($product_id);
    }

    public function get_signup_fee($product_id)
    {
        return Helper::get_product_signup_fee($product_id);
    }

    public function get_first_renewal_payment_time($product_id)
    {
        return Helper::get_first_renewal_payment_time($product_id);
    }

    public function create_renewal_order($subscription, $transaction_id)
    {
        // $subscription is ID (int)
        $subscription_id = $subscription;
        
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
        Helper::update_subscription_next_payment_date($subscription, $new_date);
    }

    public function cancel_subscription($subscription)
    {
        Helper::cancel_subscription($subscription);
    }

    public function pause_subscription($subscription)
    {
        Helper::pause_subscription($subscription);
    }

    public function resume_subscription($subscription)
    {
        Helper::resume_subscription($subscription);
    }

    public function payment_complete($subscription, $payment_id)
    {
        Helper::subscription_payment_complete($subscription, $payment_id);
    }

    public function get_total($subscription)
    {
        return Helper::get_subscription_total($subscription);
    }

    public function get_parent($subscription)
    {
        return Helper::get_parent_order($subscription);
    }

    public function has_status($subscription, $status)
    {
        return Helper::subscription_has_status($subscription, $status);
    }

    public function needs_payment($subscription)
    {
        return Helper::subscription_needs_payment($subscription);
    }
}
