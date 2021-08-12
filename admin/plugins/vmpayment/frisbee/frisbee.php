<?php
if (! defined('_VALID_MOS') && ! defined('_JEXEC')) {
    die('Direct Access to '.basename(__FILE__).' is not allowed.');
}

if (! class_exists('vmPSPlugin')) {
    require(JPATH_VM_PLUGINS.DS.'vmpsplugin.php');
}

class plgVmPaymentFrisbee extends vmPSPlugin
{
    public static $_this = false;

    function __construct(&$subject = null, $config = null)
    {
        if ($subject && $config) {
            parent::__construct($subject, $config);
        }

        $this->_psType = 'payment';
        $this->_loggable = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $this->_tablepkey = 'id';
        $this->_tableId = 'id';
        $this->_psType = 'payment';
        $this->_configTable = '#__virtuemart_' . $this->_psType . 'methods';
        $this->_configTableFieldName = $this->_psType . '_params';
        $this->_configTableFileName = $this->_psType . 'methods';
        $this->_configTableClassName = 'Table' . ucfirst($this->_psType) . 'methods';

        $varsToPush = array(
            'FONDY_MERCHANT' => array('', 'string'),
            'FONDY_SECRET_KEY' => array('', 'string'),
            'FONDY_CURRENCY' => array('', 'string'),
            'status_pending' => array('', 'string'),
            'status_success' => array('', 'string'),
        );

        $res = $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    /**
     * Create the table for this plugin if it does not yet exist.
     */
    protected function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment Frisbee Table');
    }

    /**
     * Fields to create the payment table
     *
     * @return string SQL Fileds
     */
    function getTableSQLFields()
    {
        $SQLfields = [
            'id' => 'tinyint(1) unsigned NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(11) UNSIGNED DEFAULT NULL',
            'order_number' => 'char(32) DEFAULT NULL',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED DEFAULT NULL',
            'payment_name' => 'char(255) NOT NULL DEFAULT \'\' ',
            'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
            'payment_currency' => 'char(3)',
            'cost_per_transaction' => ' decimal(10,2) DEFAULT NULL ',
            'cost_percent_total' => ' decimal(10,2) DEFAULT NULL ',
            'tax_id' => 'smallint(11) DEFAULT NULL',
        ];

        return $SQLfields;
    }

    /**
     * @param $name
     * @param $id
     * @param $data
     * @return bool
     */
    function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    function __getVmPluginMethod($method_id)
    {
        if (! ($method = $this->getVmPluginMethod($method_id))) {
            return null;
        } else {
            return $method;
        }
    }

    function plgVmConfirmedOrder($cart, $order)
    {
        if (! ($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null;
        }
        if (! $this->selectedThisElement($method->payment_element)) {
            return false;
        }

        include_once(dirname(__FILE__).DS."/includes/Frisbee.php");

        if (! class_exists('VirtueMartModelCurrency')) {
            require(JPATH_VM_ADMINISTRATOR.DS.'models'.DS.'currency.php');
        }

        JFactory::getLanguage()->load($filename = 'com_virtuemart', JPATH_ADMINISTRATOR);
        $vendorId = 0;

        $html = "";

        if (! class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR.DS.'models'.DS.'orders.php');
        }

        $this->getPaymentCurrency($method);

        if (empty($method->FRISBEE_CURRENCY)) {
            $currencyModel = new VirtueMartModelCurrency();
            $currencyObj = $currencyModel->getCurrency($order['details']['BT']->order_currency);
            $currency = $currencyObj->currency_code_3;
        } else {
            $currency = $method->FRISBEE_CURRENCY;
        }

        list($lang,$t) = explode('-', JFactory::getLanguage()->getTag());

        $paymentMethodID = $order['details']['BT']->virtuemart_paymentmethod_id;
        $responseUrl = JROUTE::_(JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component&pm='.$paymentMethodID);
        $callbackUrl = JROUTE::_(JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component&pm='.$paymentMethodID);

        if (empty($method->FONDY_MERCHANT) && empty($method->FONDY_SECRET_KEY)) {
            $url = 'https://dev2.pay.fondy.eu/api/checkout/url/';
            $merchantId = '1601318';
            $secretKey = 'test';
        } else {
            $url = Frisbee::URL;
            $merchantId = $method->FONDY_MERCHANT;
            $secretKey = $method->FONDY_SECRET_KEY;
        }

        $user = &$cart->BT;
        $frisbee_args = array(
            'order_id' => $cart->order_number . Frisbee::ORDER_SEPARATOR . time(),
            'merchant_id' => $merchantId,
            'order_desc' => $cart->order_number,
            'amount' => Frisbee::getAmount($order),
            'currency' => $currency,
            'server_callback_url' => $callbackUrl,
            'response_url' => $responseUrl,
            'lang' => strtoupper($lang),
            'sender_email' => $user['email'],
            'payment_systems' => 'frisbee'
        );

        $orderDetails = $order['details']['BT'];
        $this->setProductsParameter($order, $frisbee_args);
        $this->setReservationDataParameter($orderDetails, $frisbee_args);

        $frisbee_args['signature'] = Frisbee::getSignature($frisbee_args, $secretKey);

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-type: application/json',
                'content' => json_encode(['request' => $frisbee_args])
            ]
        ];
        $context = stream_context_create($opts);
        $content = file_get_contents($url, false, $context);
        $result = $this->decodeJson($content);

        if ($result->response->response_status == 'failure') {
            return $this->processConfirmedOrderPaymentResponse(0, $cart, $order, $result->response->error_message, '');
        }

        header("HTTP/1.1 301 Moved Permanently");
        header("Location: " . $result->response->checkout_url);

        return $this->processConfirmedOrderPaymentResponse(2, $cart, $order, $html, '');
    }

    function plgVmOnPaymentResponseReceived(&$html)
    {
        $method = $this->getVmPluginMethod(JRequest::getInt('pm', 0));
        if (! $this->selectedThisElement($method->payment_element)) {
            return false;
        }

        if (! class_exists('VirtueMartCart')) {
            require(JPATH_VM_SITE.DS.'helpers'.DS.'cart.php');
        }

        // get the correct cart / session
        $cart = VirtueMartCart::getCart();
        $cart->emptyCart();

        return true;
    }

    function plgVmOnUserPaymentCancel()
    {
        $data = JRequest::get('get');

        list($order_id, $time) = explode(Frisbee::ORDER_SEPARATOR, $data['order_id']);
        $order = new VirtueMartModelOrders();

        $order_s_id = $order->getOrderIdByOrderNumber($order_id);
        $orderitems = $order->getOrder($order_s_id);

        $method = $this->getVmPluginMethod($orderitems['details']['BT']->virtuemart_paymentmethod_id);
        if (! $this->selectedThisElement($method->payment_element)) {
            return false;
        }

        if (! class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR.DS.'models'.DS.'orders.php');
        }

        $this->handlePaymentUserCancel($data['oid']);

        return true;
    }

    function plgVmOnPaymentNotification()
    {
        $data = $this->getCallbackData();

        $_SERVER['REQUEST_URI'] = '';
        $_SERVER['SCRIPT_NAME'] = '';
        $_SERVER['QUERY_STRING'] = '';
        $option = 'com_virtuemart';
        $my_path = dirname(__FILE__);
        $my_path = explode(DS.'plugins', $my_path);
        $my_path = $my_path[0];
        if (file_exists($my_path.'/defines.php')) {
            include_once $my_path.'/defines.php';
        }
        if (! defined('_JDEFINES')) {
            define('JPATH_BASE', $my_path);
            require_once JPATH_BASE.'/includes/defines.php';
        }
        define('JPATH_COMPONENT', JPATH_BASE.'/components/'.$option);
        define('JPATH_COMPONENT_SITE', JPATH_SITE.'/components/'.$option);
        define('JPATH_COMPONENT_ADMINISTRATOR', JPATH_ADMINISTRATOR.'/components/'.$option);
        require_once JPATH_BASE.'/includes/framework.php';
        $app = JFactory::getApplication('site');
        $app->initialise();
        if (! class_exists('VmConfig')) {
            require(JPATH_ADMINISTRATOR.DS.'components'.DS.'com_virtuemart'.DS.'helpers'.DS.'config.php');
        }
        VmConfig::loadConfig();
        if (! class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR.DS.'models'.DS.'orders.php');
        }
        if (! class_exists('plgVmPaymentFrisbee')) {
            require(dirname(__FILE__).DS.'frisbee.php');
        }

        require(dirname(__FILE__).DS.'includes/Frisbee.php');
        list($order_id,$time) = explode(Frisbee::ORDER_SEPARATOR, $data['order_id']);
        $order = new VirtueMartModelOrders();

        $method = new plgVmPaymentFrisbee();
        $order_s_id = $order->getOrderIdByOrderNumber($order_id);
        $orderitems = $order->getOrder($order_s_id);
        $paymentMethodId = $orderitems['details']['BT']->virtuemart_paymentmethod_id;

        $methoditems = $method->__getVmPluginMethod($paymentMethodId);

        $option = [
            'merchant_id' => $methoditems->FRISBEE_MERCHANT,
            'secret_key' => $methoditems->FRISBEE_SECRET_KEY,
        ];

        $response = Frisbee::isPaymentValid($option, $data);

        if ($response === true) {
            $red = JROUTE::_(JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm='.$paymentMethodId);
            header('Location:'.$red);
            $datetime = date("YmdHis");
            echo "OK";
        } else {
            echo "<!-- {$response} -->";
        }

        $orderitems['order_status'] = $methoditems->status_success;
        $orderitems['customer_notified'] = 0;
        $orderitems['virtuemart_order_id'] = $order_s_id;
        $orderitems['comments'] = 'Frisbee ID: '.$order_id." Ref ID : ".$data['payment_id'];
        $order->updateStatusForOneOrder($order_s_id, $orderitems, true);
    }

    /**
     * @return array
     */
    protected function getCallbackData()
    {
        $content = file_get_contents('php://input');

        if (isset($_SERVER['CONTENT_TYPE'])) {
            switch ($_SERVER['CONTENT_TYPE']) {
                case 'application/json':
                    return json_decode($content, true);
                case 'application/xml':
                    return (array) simplexml_load_string($content, "SimpleXMLElement", LIBXML_NOCDATA);
                default:
                    return $_REQUEST;
            }
        }

        return $_REQUEST;
    }

    protected function setProductsParameter($order, &$parameters)
    {
        $parameters['products'] = [];

        foreach ($order['items'] as $key => $item) {
            $parameters['products'][] = [
                'id' => $key+1,
                'name' => $item->order_item_name,
                'price' => number_format(floatval($item->product_item_price), 2),
                'total_amount' => number_format(floatval($item->product_quantity * $item->product_item_price), 2),
                'quantity' => number_format(floatval($item->product_quantity), 2),
            ];
        }
    }

    protected function setReservationDataParameter($order, &$parameters)
    {
        $db = JFactory::getDBO();

        $query = 'SELECT *';
        $query .= ' FROM `#__virtuemart_countries`';
        $query .= ' WHERE virtuemart_country_id = ' . $order->virtuemart_country_id;
        $db->setQuery($query);
        $countryObject = $db->loadObject();

        if ($order->virtuemart_state_id) {
            $query = 'SELECT *';
            $query .= ' FROM `#__virtuemart_states`';
            $query .= ' WHERE virtuemart_state_id = '.$order->virtuemart_state_id;
            $db->setQuery($query);
            $stateObject = $db->loadObject();
            $state = $stateObject->state_name;
        } else {
            $state = '';
        }

        $reservationData = array(
            'phonemobile' => $order->phone_2,
            'customer_address' => $order->address_1 . ' ' . $order->address_2,
            'customer_country' => $countryObject->country_code_2,
            'customer_state' => $state,
            'customer_name' => $order->first_name . ' ' . $order->last_name,
            'customer_city' => $order->city,
            'customer_zip' => $order->zip,
            'account' => $order->virtuemart_user_id,
        );

        $parameters['reservation_data'] = base64_encode(json_encode($reservationData));
    }

    protected function decodeJson($data)
    {
        $data = json_decode($data);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Unable to parse string into JSON');
        }

        return $data;
    }

    /**
     * Check if the payment conditions are fulfilled for this payment method
     *
     * @param $cart_prices : cart prices
     * @param $payment
     * @return true: if the conditions are fulfilled, false otherwise
     *
     * @author: Valerie Isaksen
     *
     */
    protected function checkConditions($cart, $method, $cart_prices)
    {
        return true;
    }

    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the standard method to create the tables
     *
     * @author Valérie Isaksen
     *
     */
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    /**
     * This event is fired after the payment method has been selected. It can be used to store
     * additional payment info in the cart.
     *
     * @param VirtueMartCart $cart : the actual cart
     * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
     *
     * @author Max Milbers
     * @author Valérie isaksen
     *
     */
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart)
    {
        return $this->OnSelectCheck($cart);
    }

    /**
     * plgVmDisplayListFEPayment
     * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
     *
     * @param object $cart Cart object
     * @param integer $selected ID of the method selected
     * @return boolean True on succes, false on failures, null when this plugin was not selected.
     * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
     *
     * @author Valerie Isaksen
     * @author Max Milbers
     */
    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {

        if (! ($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        if (! $this->selectedThisElement($method->payment_element)) {
            return false;
        }
        $this->getPaymentCurrency($method);

        $paymentCurrencyId = $method->payment_currency;
    }

    /**
     * plgVmOnCheckAutomaticSelectedPayment
     * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type
     *
     * @param VirtueMartCart cart: the cart object
     * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
     *
     * @author Valerie Isaksen
     */
    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = [])
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param integer $order_id The order ID
     * @return mixed Null for methods that aren't active, text (HTML) otherwise
     * @author Max Milbers
     * @author Valerie Isaksen
     */
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    /**
     * This method is fired when showing when priting an Order
     * It displays the the payment method-specific data.
     *
     * @param integer $_virtuemart_order_id The order ID
     * @param integer $method_id method used for this order
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     * @author Valerie Isaksen
     */
    function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPayment($name, $id, &$data)
    {
        return $this->declarePluginParams('payment', $name, $id, $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }
}

?>
