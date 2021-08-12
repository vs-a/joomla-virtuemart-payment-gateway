<?php

class Frisbee
{
    const ORDER_APPROVED = 'approved';
    const ORDER_DECLINED = 'declined';

    const ORDER_SEPARATOR = ':';

    const SIGNATURE_SEPARATOR = '|';

    const URL = "https://api.fondy.eu/api/checkout/url/";


    public static function getSignature($data, $password, $encoded = true)
    {
        if (isset($data['products'])) {
            unset($data['products']);
        }

        $data = array_filter($data, function ($var) {
            return $var !== '' && $var !== null;
        });
        ksort($data);

        $str = $password;
        foreach ($data as $value) {
            if (is_array($value)) {
                $str .= self::SIGNATURE_SEPARATOR . str_replace('"', "'", json_encode($value, JSON_HEX_APOS));
            } else {
                $str .= self::SIGNATURE_SEPARATOR.$value;
            }
        }

        if ($encoded) {
            return sha1($str);
        } else {
            return $str;
        }
    }

    public static function isPaymentValid($frisbeeSettings, $response)
    {
        if ($response['order_status'] == self::ORDER_DECLINED) {
            return 'Order was declined.';
        }
        if ($response['order_status'] != self::ORDER_APPROVED) {
            return 'Order was not approved.';
        }

        if ($frisbeeSettings['merchant_id'] != $response['merchant_id']) {
            return 'An error has occurred during payment. Merchant data is incorrect.';
        }

        $responseSignature = $response['signature'];
        if (isset($response['response_signature_string'])){
            unset($response['response_signature_string']);
        }
        if (isset($response['signature'])){
            unset($response['signature']);
        }
        if (self::getSignature($response, $frisbeeSettings['secret_key']) != $responseSignature) {
            return 'Signature is not valid' ;
        }

        return true;
    }

    public static function getAmount($order)
    {
        return round($order['details']['BT']->order_total * 100);
    }
}
