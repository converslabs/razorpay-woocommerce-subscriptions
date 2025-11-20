<?php

use Razorpay\Api\Api;
use Razorpay\Api\Errors;
use Razorpay\Woocommerce\Errors as WooErrors;

class RZP_Subscriptions
{
    /**
     * @var WC_Razorpay
     */
    protected $razorpay;

    /**
     * @var Api
     */
    protected $api;

    /**
     * @var string
     */
    protected $keyId;

    /**
     * @var string
     */
    protected $keySecret;

    /**
     * @var SubscriptionPluginInterface
     */
    protected $adapter;

    const RAZORPAY_SUBSCRIPTION_ID       = 'razorpay_subscription_id';
    const RAZORPAY_PLAN_ID               = 'razorpay_wc_plan_id';
    const INR                            = 'INR';

    public function __construct($keyId, $keySecret, $adapter)
    {
        $this->razorpay = new WC_Razorpay(false);

        $this->api = $this->razorpay->getRazorpayApiInstance();

        $this->adapter = $adapter;
    }

    /**
     * Create subscription for customer.
     *
     * @param $orderId
     * @return mixed
     * @throws Exception
     */
    public function createSubscription($orderId)
    {
        global $woocommerce;

        $subscriptionData = $this->getSubscriptionCreateData($orderId);

        try
        {
            $subscription = $this->api->subscription->create($subscriptionData);
        }
        catch (Exception $e)
        {
            $message = $e->getMessage();
            rzpSubscriptionErrorLog("Woocommerce orderId: $orderId Subscription creation failed with the following message: $message");

            throw new Errors\Error(
                $message,
                WooErrors\SubscriptionErrorCode::API_SUBSCRIPTION_CREATION_FAILED,
                400
            );
        }

        // Setting the subscription id as the session variable
        $sessionKey = $this->getSubscriptionSessionKey($orderId);

        $woocommerce->session->set($sessionKey, $subscription['id']);

        return $subscription['id'];
    }

    /**
     * Method for cancel subscription of client.
     *
     * @param $subscriptionId
     * @throws Errors\Error
     */
    public function cancelSubscription($subscriptionId, $subscriptionCycleEndAt)
    {
        try
        {
            $subscription = $this->api->subscription->fetch($subscriptionId);
            
            if ($subscription->status !== 'cancelled')
            {
                $subscription->cancel($subscriptionCycleEndAt);
            }
        }
        catch (Exception $e)
        {
            $message = $e->getMessage();
            rzpSubscriptionErrorLog("Razorpay subscriptionId: $subscriptionId Subscription cancel failed with the following message: $message");

            throw new Errors\Error(
                $message,
                WooErrors\SubscriptionErrorCode::API_SUBSCRIPTION_CANCELLATION_FAILED,
                400
            );
        }
    }

    private function getWooCommerceSubscriptionFromOrderId($orderId)
    {
        $subscriptions = $this->adapter->get_subscriptions_for_order($orderId);

        return end($subscriptions);
    }

    protected function getSubscriptionCreateData($orderId)
    {
        $order = new WC_Order($orderId);

        $product = $this->getProductFromOrder($order);

        $planId = $this->getProductPlanId($product, $order);

        $customerId = $this->getCustomerId($order);

        $length = (int) $this->adapter->get_product_length($product['product_id']);

        //Subscription will not work in case of never expire case.

        if ($length == 0)
        {
            rzpSubscriptionErrorLog("Woocommerce orderId: $orderId Perpetual subscriptions are not supported");

            throw new Exception('Perpetual subscriptions are not supported.');
        }

        //The first payment is always set as an upfront amount to support woocommerce discounts like fixed
        // cart discounts which is only for the first payment.

        $subscriptionData = array(
            'customer_id'     => $customerId,
            'plan_id'         => $planId,
            'quantity'        => (int) $product['qty'],
            'total_count'     => $length,
            'customer_notify' => 0,
            'notes'           => array(
                'woocommerce_order_id'   => $orderId,
                'woocommerce_product_id' => $product['product_id']
            ),
            'source'          => 'WooCommerce'
        );

        // We add the signup fee as an addon
        $signUpFee = wcs_get_price_including_tax(wc_get_product($product['product_id']),
            array("price" => $this->adapter->get_signup_fee($product['product_id']))
        );

        if ($signUpFee)
        {
            $subscriptionData['addons'] = array(array('item' => $this->getUpfrontAmount($signUpFee, $order, $product)));
        }

        $trial_length     = $this->adapter->get_product_trial_length( $product['product_id'] );

        $renewalDate = $this->adapter->get_first_renewal_payment_time($product['product_id']);

        // if the first payment after applying discount is zero, create subscription without initial addon
        //so that token amount would be auto refunded.

        if ($trial_length > 0)
        {
            $subscriptionData['start_at'] = $renewalDate;
        }

        return $subscriptionData;
    }

    protected function getUpfrontAmount($signUpFee, $order, $product)
    {
        $amount = (int) round($signUpFee * 100);

        $item = array(
            'amount'   => $amount,
            'currency' => get_woocommerce_currency(),
            'name'     => $product['name'],
            'description'  => 'wocoommerce_order_id: ' . $order->get_id(),
        );

        return $item;
    }

    protected function getProductPlanId($product, $order)
    {
        $productId = $product['product_id'];

        $metadata = get_post_meta($productId);

        list($planId, $created, $key) = $this->createOrGetPlanId($metadata, $product, $order);

        //
        // If new plan was created, we delete the old plan id
        // If we created a new planId, we have to store it as post metadata
        //
        if ($created === true)
        {
            delete_post_meta($productId, $key);

            add_post_meta($productId, $key, $planId, true);
        }

        return $planId;
    }

    /**
     * Takes in product metadata and product
     * Creates or gets created plan
     *
     * @param $metadata
     * @param $product
     * @param $order
     * @return array
     * @throws Errors\Error
     */
    protected function createOrGetPlanId($metadata, $product, $order)
    {
        list($key, $planArgs) = $this->getPlanArguments($product, $order);

        //
        // If razorpay_plan_id is set in the metadata,
        // we check if the amounts match and return the plan id
        //
        if (isset($metadata[$key]) === true)
        {
            $create = false;

            $planId = $metadata[$key][0];

            try
            {
                $plan = $this->api->plan->fetch($planId);
            }
            catch (Exception $e)
            {
                //
                // If plan id fetch causes an error, we re-create the plan
                //
                $create = true;
            }

            if (($create === false) and
                ($plan['item']['amount'] === $planArgs['item']['amount']))
            {
                return array($plan['id'], false, $key);
            }
        }

        //
        // By default we create a new plan
        // if metadata doesn't have plan id set
        //
        $planId = $this->createPlan($planArgs);

        return array($planId, true, $key);
    }

    protected function createPlan($planArgs)
    {
        try
        {
            $plan = $this->api->plan->create($planArgs);
        }
        catch (Exception $e)
        {
            $message = $e->getMessage();

            throw new Errors\Error(
                $message,
                WooErrors\SubscriptionErrorCode::API_PLAN_CREATION_FAILED,
                400
            );
        }

        // Storing the plan id as product metadata, unique set to true
        return $plan['id'];
    }

    protected function getPlanArguments($product, $order)
    {
        $sub          = $this->getWooCommerceSubscriptionFromOrderId($order->get_id());

        // Use adapter methods if available on the subscription object wrapper, or fetch from product
        // The adapter returns a wrapper or object.
        // If we are using the wrapper, we might need to fetch product ID from it.
        // But getPlanArguments takes $product and $order.
        // Wait, the original code used $sub->get_billing_period().
        // If $sub is our wrapper, we need to ensure it has these methods or we use the adapter directly with product ID.
        // Let's check getPlanArguments context. It has $product array (from getProductFromOrder).
        
        $productId = $product['product_id'];
        
        $period       = $this->adapter->get_product_period($productId);

        $interval     = $this->adapter->get_product_interval($productId);

        $recurringFee = $sub->get_total();

        $planArgs = array(
            'period'   => $this->getProductPeriod($period),
            'interval' => $interval
        );

        $item = array(
            'name'     => $product['name'],
            'amount'   => (int) round($recurringFee * 100),
            'currency' => get_woocommerce_currency(),
        );

        $planArgs['item'] = $item;

        return array($this->getKeyFromPlanArgs($planArgs), $planArgs);
    }

    private function getKeyFromPlanArgs(array $planArgs)
    {
        $item = $planArgs['item'];

        $hashInput = implode('|', [
            $item['amount'],
            $item['currency'],
            $planArgs['period'],
            $planArgs['interval']
        ]);

        return self::RAZORPAY_PLAN_ID . sha1($hashInput); // nosemgrep : php.lang.security.weak-crypto.weak-crypto
    }

    // TODO: Take care of trial period here
    public function getDisplayAmount($order)
    {
        if ((int) $order->get_total() !==0 )
        {
            return $order->get_total();
        }
    }

    /**
     * @param $period
     * @return mixed
     */
    private function getProductPeriod($period)
    {
        $periodMap = array(
            'day'   => 'daily',
            'week'  => 'weekly',
            'month' => 'monthly',
            'year'  => 'yearly'
        );

        return $periodMap[$period];
    }

    /**
     * @param $order
     * @return mixed
     * @throws Errors\Error
     */
    protected function getCustomerId($order)
    {
        $data = $this->razorpay->getCustomerInfo($order);

        //
        // This line of code tells api that if a customer is already created,
        // return the created customer instead of throwing an exception
        // https://docs.razorpay.com/v1/page/customers-api
        //
        $data['fail_existing'] = '0';

        try
        {
            $customer = $this->api->customer->create($data);
        }
        catch (Exception $e)
        {
            $message = $e->getMessage();

            throw new Errors\Error(
                $message,
                WooErrors\SubscriptionErrorCode::API_CUSTOMER_CREATION_FAILED,
                400
            );
        }

        return $customer['id'];
    }

    /**
     * @param $order
     * @return mixed
     * @throws Exception
     */
    public function getProductFromOrder($order)
    {
        $products = $order->get_items();

        $count = $order->get_item_count();

        //
        // Technically, subscriptions work only if there's one array in the cart
        //
        if ($count > 1)
        {
            $orderId = $order->get_id();
            rzpSubscriptionErrorLog("Woocommerce orderId: $orderId Currently Razorpay does not support more than one product in the cart if one of the products is a subscription");

            throw new Exception('Currently Razorpay does not support more than'
                . ' one product in the cart if one of the products'
                . ' is a subscription.');
        }

        return array_values($products)[0];
    }

    /**
     * @param $orderId
     * @return string
     */
    protected function getSubscriptionSessionKey($orderId)
    {
        return self::RAZORPAY_SUBSCRIPTION_ID . $orderId;
    }

    /**
     * Method to pause subscription of client.
     *
     * @param $subscriptionId
     * @throws Errors\Error
     */
    public function pauseSubscription($subscriptionId, $subscriptionPauseAt)
    {
        try
        {
            $subscription = $this->api->subscription->fetch($subscriptionId);

            if ($subscription->status === 'active')
            {
                $subscription->pause($subscriptionPauseAt);
            }
        }
        catch (Exception $e)
        {
            $message = $e->getMessage();
            rzpSubscriptionErrorLog("Razorpay subscriptionId: $subscriptionId Subscription pause failed with the following message: $message");

            throw new Errors\Error(
                $message,
                WooErrors\SubscriptionErrorCode::API_SUBSCRIPTION_PAUSE_FAILED,
                400
            );
        }
    }

    /**
     * Method to resume subscription of client.
     *
     * @param $subscriptionId
     * @throws Errors\Error
     */
    public function resumeSubscription($subscriptionId, $subscriptionResumeAt)
    {
        try
        {
            $subscription = $this->api->subscription->fetch($subscriptionId);

            if ($subscription->status === 'paused')
            {
                $subscription->resume($subscriptionResumeAt);
            }
        }
        catch (Exception $e)
        {
            $message = $e->getMessage();
            rzpSubscriptionErrorLog("Razorpay subscriptionId: $subscriptionId Subscription resume failed with the following message: $message");

            throw new Errors\Error(
                $message,
                WooErrors\SubscriptionErrorCode::API_SUBSCRIPTION_RESUME_FAILED,
                400
            );
        }
    }

}
