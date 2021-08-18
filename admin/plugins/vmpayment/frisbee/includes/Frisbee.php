<?php

class Frisbee
{
    const ORDER_APPROVED = 'approved';
    const ORDER_DECLINED = 'declined';
    const ORDER_REVERSED = 'reversed';
    const ORDER_EXPIRED = 'expired';
    const ORDER_SEPARATOR = ':';
    const SIGNATURE_SEPARATOR = '|';
    const URL = 'https://api.fondy.eu/api/checkout/url/';
    const DEV_URL = 'https://dev2.pay.fondy.eu/api/checkout/url/';
    const DEV_MERCHANT = '1601318';
    const DEV_SECRET_KEY = 'test';
    const SESSION_NAME_CHECKOUT_HASH = 'frisbee_checkout_hash';
    const SESSION_NAME_CHECKOUT_URL = 'frisbee_checkout_url';

    protected $merchantId;
    protected $secretKey;
    protected $requestUrl;
    protected $requestParameters;
    protected $requestResult;
    protected $statusMessage;
    protected $isOrderApproved;
    protected $isOrderDeclined;
    protected $isOrderFullyReversed;
    protected $isOrderPartiallyReversed;
    protected $isOrderExpired;

    public function __construct()
    {
        $this->requestParameters['payment_systems'] = 'frisbee';
    }

    /**
     * @param $merchantId
     * @return void
     */
    public function setMerchantId($merchantId)
    {
        if (empty($merchantId)) {
            $this->merchantId = self::DEV_MERCHANT;
        } else {
            $this->merchantId = $merchantId;
        }

        $this->requestParameters['merchant_id'] = $this->merchantId;
    }

    /**
     * @param $secretKey
     * @return void
     */
    public function setSecretKey($secretKey)
    {
        if (empty($secretKey)) {
            $this->secretKey = self::DEV_SECRET_KEY;
        } else {
            $this->secretKey = $secretKey;
        }
    }

    /**
     * @param $orderId
     * @return void
     */
    public function setRequestParameterOrderId($orderId)
    {
        $this->requestParameters['order_id'] = $orderId . self::ORDER_SEPARATOR . time();
    }

    /**
     * @param $orderDescription
     * @return void
     */
    public function setRequestParameterOrderDescription($orderDescription)
    {
        $this->requestParameters['order_desc'] = $orderDescription;
    }

    /**
     * @param $amount
     * @return void
     */
    public function setRequestParameterAmount($amount)
    {
        $this->requestParameters['amount'] = $this->getAmount($amount);
    }

    /**
     * @param $currency
     * @return void
     */
    public function setRequestParameterCurrency($currency)
    {
        $this->requestParameters['currency'] = $currency;
    }

    /**
     * @param $callbackUrl
     * @return void
     */
    public function setRequestParameterServerCallbackUrl($callbackUrl)
    {
        $this->requestParameters['server_callback_url'] = $callbackUrl;
    }

    /**
     * @param $responseUrl
     * @return void
     */
    public function setRequestParameterResponseUrl($responseUrl)
    {
        $this->requestParameters['response_url'] = $responseUrl;
    }

    /**
     * @param $language
     * @return void
     */
    public function setRequestParameterLanguage($language)
    {
        $this->requestParameters['lang'] = $language;
    }

    /**
     * @param $email
     * @return void
     */
    public function setRequestParameterSenderEmail($email)
    {
        $this->requestParameters['sender_email'] = $email;
    }

    /**
     * @param $reservationData
     * @return void
     */
    public function setRequestParameterReservationData($reservationData)
    {
        $this->requestParameters['reservation_data'] = $reservationData;
    }

    /**
     * @return string
     */
    public function getRequestUrl()
    {
        if ($this->merchantId == self::DEV_MERCHANT) {
            return self::DEV_URL;
        }

        return self::URL;
    }

    /**
     * @return mixed
     */
    public function getRequestParameters()
    {
        return $this->requestParameters;
    }

    /**
     * @return mixed
     */
    public function getRequestResult()
    {
        return $this->requestResult;
    }

    /**
     * @return string
     */
    public function getRequestResultErrorMessage()
    {
        if (isset($this->requestResult->response->error_message)) {
            return $this->requestResult->response->error_message;
        }

        return 'Frisbee response error';
    }

    /**
     * @return array
     */
    public function getCallbackData()
    {
        $content = file_get_contents('php://input');

        if (strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
            return json_decode($content, true);
        } elseif (strpos($_SERVER['CONTENT_TYPE'], 'application/xml') !== false) {
            return (array) simplexml_load_string($content, "SimpleXMLElement", LIBXML_NOCDATA);
        }

        return $_REQUEST;
    }

    /**
     * @param $value
     * @return float
     */
    public function getAmount($value)
    {
        return round(floatval($value) * 100);
    }

    /**
     * @param $callbackData
     * @return mixed|string
     */
    public function parseFrisbeeOrderId($callbackData)
    {
        list($orderId, $time) = explode(Frisbee::ORDER_SEPARATOR, $callbackData['order_id']);

        return $orderId;
    }

    /**
     * @param $orderId
     * @return false|mixed
     * @throws \Exception
     */
    public function retrieveCheckoutUrl($orderId)
    {
        $uniqueHash = $this->generateUniqueHash($orderId);

        if ($this->isSessionUnique($uniqueHash)) {
            $checkoutUrl = $this->requestCheckoutUrl();
        } else {
            $checkoutUrl = $this->getSessionCheckoutUrl();
        }

        if (!empty($checkoutUrl)) {
            $this->setSessionCheckoutHash($uniqueHash);
            $this->setSessionCheckoutUrl($checkoutUrl);

            return $checkoutUrl;
        }

        return $this->requestCheckoutUrl();
    }

    /**
     * @param $data
     * @return bool
     * @throws \Exception
     */
    public function handleCallbackData($data)
    {
        if ($this->isCallbackDataValid($data)) {
            $orderStatus = strtolower($data['order_status']);
            if ($orderStatus == self::ORDER_DECLINED) {
                $this->isOrderDeclined = true;
                $this->setStatusMessage('Order was declined.');

                return false;
            }

            if ($orderStatus == self::ORDER_EXPIRED) {
                $this->isOrderExpired = true;
                $this->setStatusMessage('Order was expired.');

                return false;
            }

            if ($orderStatus == self::ORDER_REVERSED) {
                $this->isOrderFullyReversed = true;
                $this->setStatusMessage('Order was fully reversed.');

                return true;
            }

            if (isset($data['reversal_amount']) && $data['reversal_amount'] > 0) {
                $this->isOrderPartiallyReversed = true;
                $this->setStatusMessage('Order was partially reversed.');

                return true;
            }

            if ($orderStatus != self::ORDER_APPROVED) {
                $this->isOrderApproved = false;
                $this->setStatusMessage('Order was not approved.');

                return false;
            } else {
                $this->isOrderApproved = true;
            }
        }

        return true;
    }

    /**
     * @return mixed
     */
    public function isOrderDeclined()
    {
        return $this->isOrderDeclined;
    }

    /**
     * @return mixed
     */
    public function isOrderFullyReversed()
    {
        return $this->isOrderFullyReversed;
    }

    /**
     * @return mixed
     */
    public function isOrderPartiallyReversed()
    {
        return $this->isOrderPartiallyReversed;
    }

    /**
     * @return mixed
     */
    public function isOrderApproved()
    {
        return $this->isOrderApproved;
    }

    /**
     * @return mixed
     */
    public function isOrderExpired()
    {
        return $this->isOrderExpired;
    }

    /**
     * @param $data
     * @return bool
     * @throws \Exception
     */
    public function isCallbackDataValid($data)
    {
        if (!isset($data['order_status'])) {
            throw new \Exception('Callback data order_status is empty.');
        }

        if (!isset($data['merchant_id'])) {
            throw new \Exception('Callback data merchant_id is empty.');
        }

        if (!isset($data['signature'])) {
            throw new \Exception('Callback data signature is empty.');
        }

        if ($this->merchantId != $data['merchant_id']) {
            throw new \Exception('An error has occurred during payment. Merchant data is incorrect.');
        }

        $responseSignature = $data['signature'];
        if (isset($data['response_signature_string'])) {
            unset($data['response_signature_string']);
        }

        if (isset($data['signature'])) {
            unset($data['signature']);
        }

        if ($this->getSignature($data) != $responseSignature) {
            throw new \Exception('Signature is not valid.');
        }

        return true;
    }

    /**
     * @return mixed
     */
    public function getStatusMessage()
    {
        return $this->statusMessage;
    }

    /**
     * @param $message
     * @return void
     */
    protected function setStatusMessage($message)
    {
        $this->statusMessage = $message;
    }

    /**
     * @param $data
     * @return mixed
     * @throws \Exception
     */
    protected function decodeJson($data)
    {
        $data = json_decode($data);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Unable to parse string into JSON');
        }

        return $data;
    }

    /**
     * @return false
     * @throws \Exception
     */
    protected function requestCheckoutUrl()
    {
        $frisbeeParameters = $this->getRequestParameters();
        $frisbeeParameters['signature'] = $this->getSignature($frisbeeParameters, $this->secretKey);

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-type: application/json',
                'content' => json_encode(['request' => $frisbeeParameters])
            ]
        ];
        $context = stream_context_create($opts);
        $content = file_get_contents($this->getRequestUrl(), false, $context);
        $this->requestResult = $this->decodeJson($content);

        if (!isset($this->requestResult->response->response_status) || $this->requestResult->response->response_status != 'success') {
            return false;
        }

        return $this->requestResult->response->checkout_url;
    }

    /**
     * @param $orderId
     * @return string
     */
    protected function generateUniqueHash($orderId)
    {
        $uniqueParameters = [
            'merchant_id',
            'order_desc',
            'amount',
            'currency',
            'server_callback_url',
            'response_url',
            'lang',
            'sender_email',
            'payment_systems',
            'reservation_data'
        ];

        $uniqueHash = $orderId;
        foreach ($uniqueParameters as $parameter) {
            $uniqueHash .= $this->requestParameters[$parameter];
        }

        return md5($uniqueHash);
    }

    /**
     * @param $hash
     * @return void
     */
    protected function setSessionCheckoutHash($hash)
    {
        $_SESSION[self::SESSION_NAME_CHECKOUT_HASH] = $hash;
    }

    /**
     * @param $url
     * @return void
     */
    protected function setSessionCheckoutUrl($url)
    {
        $_SESSION[self::SESSION_NAME_CHECKOUT_URL] = $url;
    }

    /**
     * @return mixed|null
     */
    protected function getSessionCheckoutHash()
    {
        if (isset($_SESSION[self::SESSION_NAME_CHECKOUT_HASH])) {
            return $_SESSION[self::SESSION_NAME_CHECKOUT_HASH];
        }

        return null;
    }

    /**
     * @return mixed|null
     */
    protected function getSessionCheckoutUrl()
    {
        if (isset($_SESSION[self::SESSION_NAME_CHECKOUT_URL])) {
            return $_SESSION[self::SESSION_NAME_CHECKOUT_URL];
        }

        return null;
    }

    /**
     * @param $hash
     * @return bool
     */
    protected function isSessionUnique($hash)
    {
        return (isset($_SESSION[self::SESSION_NAME_CHECKOUT_HASH]) && $_SESSION[self::SESSION_NAME_CHECKOUT_HASH] != $hash);
    }

    /**
     * @param $data
     * @param bool $encoded
     * @return string
     */
    protected function getSignature($data, $encoded = true)
    {
        $data = array_filter($data, function ($var) {
            return $var !== '' && $var !== null;
        });
        ksort($data);

        $str = $this->secretKey;
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
}
