<?php

namespace Razorpay\Subscriptions\Adapters;

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
