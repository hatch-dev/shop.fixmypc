<?php

namespace App\Models;

use Iyzipay\Model\CheckoutFormInitialize;

class IyzicoPayment
{

    public static function options()
    {

        $payment =Payment::first();
        $options = new \Iyzipay\Options();
        $options->setApiKey($payment->ip_api_key);
        $options->setSecretKey($payment->ip_secret_key);
        $options->setBaseUrl($payment->ip_base_url);
        return $options;
    }


    public static function initIyzico($request, $orderId){

        $order = Order::with('ordered_products.product.product_categories')
            ->with('address')
            ->find($orderId);


        if(!$order){
            return $orderId;
        }


        $requestIyzico = new \Iyzipay\Request\CreateCheckoutFormInitializeRequest();
        $requestIyzico->setLocale(app()->getLocale());
        $requestIyzico->setConversationId(rand());
        $requestIyzico->setPrice($order->total_amount);
        $requestIyzico->setPaidPrice($order->total_amount);
        $requestIyzico->setCurrency($order->currency);
        $requestIyzico->setBasketId("B67832");


        $requestIyzico->setPaymentGroup(\Iyzipay\Model\PaymentGroup::PRODUCT);
        $requestIyzico->setCallbackUrl(route('iyzico.callback', ["order_id" => $orderId] ));
        $requestIyzico->setEnabledInstallments(array(2, 3, 6, 9));


        /*
         * A collection order has no delivery address, so every one of these
         * used to be an unguarded dereference on null. Iyzico still wants a
         * postal address on the buyer and on both address blocks, so a pickup
         * falls back to the store the customer is collecting from - which is
         * the address the transaction actually relates to - and to the contact
         * details captured in place of a delivery address.
         */
        $contact = $order->contactDetails();
        $postal = self::postalAddress($order);

        $buyer = new \Iyzipay\Model\Buyer();
        $buyer->setId($order->user_address_id ?: $order->id);
        $buyer->setName($contact['name']);
        $buyer->setSurname($contact['name']);
        $buyer->setGsmNumber($contact['phone']);
        $buyer->setEmail($contact['email']);
        $buyer->setIdentityNumber(rand());
        $buyer->setRegistrationAddress($postal['street']);
        $buyer->setIp($request->ip());
        $buyer->setCity($postal['city']);
        $buyer->setCountry($postal['country']);
        $buyer->setZipCode($postal['zip']);

        $requestIyzico->setBuyer($buyer);


        $shippingAddress = new \Iyzipay\Model\Address();
        $shippingAddress->setContactName($contact['name']);
        $shippingAddress->setCity($postal['city']);
        $shippingAddress->setCountry($postal['country']);
        $shippingAddress->setAddress($postal['street']);
        $shippingAddress->setZipCode($postal['zip']);
        $requestIyzico->setShippingAddress($shippingAddress);

        $billingAddress = new \Iyzipay\Model\Address();
        $billingAddress->setContactName($contact['name']);
        $billingAddress->setCity($postal['city']);
        $billingAddress->setCountry($postal['country']);
        $billingAddress->setAddress($postal['street']);
        $billingAddress->setZipCode($postal['zip']);
        $requestIyzico->setBillingAddress($billingAddress);

        $totalAmount = $order->total_amount;

        $basketItems = [];

        foreach ($order->ordered_products as $op) {
            $BasketItem = new \Iyzipay\Model\BasketItem();
            $BasketItem->setId($op->product_id);
            $BasketItem->setName($op->product->title);

            if(count($op->product->product_categories) > 0) {

                $BasketItem->setCategory1($op->product->product_categories[0]->id);

            } else {
                $BasketItem->setCategory1("No category");
            }

            $price = ($op->selling * $op->quantity) + $op->tax_price + $op->shipping_price;

            $totalAmount -= $price;


            $BasketItem->setItemType(\Iyzipay\Model\BasketItemType::PHYSICAL);
            $BasketItem->setPrice($price);

            array_push($basketItems, $BasketItem);
        }

        if($totalAmount > 0){
            $BasketItem = new \Iyzipay\Model\BasketItem();
            $BasketItem->setId("Product");
            $BasketItem->setName("Product");
            $BasketItem->setCategory1("Product");

            $BasketItem->setItemType(\Iyzipay\Model\BasketItemType::PHYSICAL);
            $BasketItem->setPrice($totalAmount);

            array_push($basketItems, $BasketItem);
        }

        $requestIyzico->setBasketItems($basketItems);

       //$paymentForm = $checkoutFormInitialize->getCheckoutFormContent();


        $checkoutFormInitialize = \Iyzipay\Model\CheckoutFormInitialize::create($requestIyzico, IyzicoPayment::options());
        // $paymentForm = $checkoutFormInitialize->getCheckoutFormContent();

        $paymentResponse = (array)$checkoutFormInitialize;

        $responseDecode = null;

        foreach ($paymentResponse as $key => $value){
            $responseDecode = json_decode($value);
        }

        return $responseDecode;
    }

    /**
     * The postal address a payment relates to.
     *
     * Delivery orders use the customer's address. A collection order has none,
     * so it uses the pickup point the customer is collecting from.
     */
    private static function postalAddress($order)
    {
        if ($order->address) {
            return [
                'street' => $order->address->address_1 . $order->address->address_2,
                'city' => $order->address->city,
                'country' => $order->address->country,
                'zip' => $order->address->zip,
            ];
        }

        $pickup = optional($order->ordered_products->first())->shipping_place;

        return [
            'street' => optional($pickup)->pickup_address_line_1 . optional($pickup)->pickup_address_line_2,
            'city' => optional($pickup)->pickup_city,
            'country' => optional($pickup)->pickup_country,
            'zip' => optional($pickup)->pickup_zip,
        ];
    }

}
