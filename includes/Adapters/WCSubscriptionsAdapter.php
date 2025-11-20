<?php

namespace Razorpay\Subscriptions\Adapters;

use Razorpay\Subscriptions\Contracts\SubscriptionPluginInterface;
use WC_Subscriptions_Cart;
use WC_Subscriptions_Product;
use WC_Subscriptions_Manager;

class WCSubscriptionsAdapter implements SubscriptionPluginInterface
{
    public function is_active()
    {
        return class_exists('WC_Subscriptions');
    }

    public function cart_contains_subscription()
    {
        return class_exists('WC_Subscriptions_Cart') && WC_Subscriptions_Cart::cart_contains_subscription();
    }

    public function get_subscriptions_for_order($order_id)
    {
        if (!function_exists('wcs_get_subscriptions_for_order')) {
            return [];
        }
        return wcs_get_subscriptions_for_order($order_id);
    }

    public function get_product_period($product_id)
    {
        return WC_Subscriptions_Product::get_period($product_id);
    }

    public function get_product_interval($product_id)
    {
        return WC_Subscriptions_Product::get_interval($product_id);
    }

    public function get_product_length($product_id)
    {
        return WC_Subscriptions_Product::get_length($product_id);
    }

    public function get_product_trial_length($product_id)
    {
        return WC_Subscriptions_Product::get_trial_length($product_id);
    }

    public function get_signup_fee($product_id)
    {
        return WC_Subscriptions_Product::get_sign_up_fee($product_id);
    }

    public function get_first_renewal_payment_time($product_id)
    {
        return WC_Subscriptions_Product::get_first_renewal_payment_time($product_id);
    }

    public function create_renewal_order($subscription, $transaction_id)
    {
        // $subscription is expected to be a WC_Subscription object
        $renewal_order = wcs_create_renewal_order($subscription);
        if ($renewal_order) {
            $renewal_order->set_payment_method('razorpay_subscriptions');
            $renewal_order->set_transaction_id($transaction_id);
            $renewal_order->save();
        }
        return $renewal_order;
    }

    public function update_next_payment_date($subscription, $new_date)
    {
        $subscription->update_dates(['next_payment_date' => $new_date]);
    }

    public function cancel_subscription($subscription)
    {
        $subscription->update_status('cancelled');
    }

    public function pause_subscription($subscription)
    {
        $subscription->update_status('on-hold');
    }

    public function resume_subscription($subscription)
    {
        $subscription->update_status('active');
    }

    public function payment_complete($subscription, $payment_id)
    {
        $subscription->payment_complete($paymentId);
    }

    public function get_total($subscription)
    {
        return $subscription->get_total();
    }

    public function get_parent($subscription)
    {
        return $subscription->get_parent();
    }

    public function has_status($subscription, $status)
    {
        return $subscription->has_status($status);
    }

    public function needs_payment($subscription)
    {
        return $subscription->needs_payment();
    }
}
