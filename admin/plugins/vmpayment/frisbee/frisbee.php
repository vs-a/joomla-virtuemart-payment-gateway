<?php
if (! defined('_VALID_MOS') && ! defined('_JEXEC')) {
    die('Direct Access to '.basename(__FILE__).' is not allowed.');
}

if (! class_exists('vmPSPlugin')) {
    require(JPATH_VM_PLUGINS.DS.'vmpsplugin.php');
}

class plgVmPaymentFrisbee extends vmPSPlugin
{
    const PRECISION = 2;

    public static $_this = false;
    protected $_type = 'vmpayment';
    protected $_name = 'frisbee';

    public function __construct(&$subject = null, $config = null)
    {
        if ($subject && $config) {
            parent::__construct($subject, $config);
        }

        $filename = 'plg_' . $this->_type . '_' . $this->_name;

        $this->loadJLangThis($filename);

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
            'FRISBEE_MERCHANT' => array('', 'string'),
            'FRISBEE_SECRET_KEY' => array('', 'string'),
            'FRISBEE_CURRENCY' => array('', 'string'),
            'status_pending' => array('', 'string'),
            'status_success' => array('', 'string'),
        );

        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
        VmConfig::loadJLang('com_virtuemart_orders', TRUE);
        VmConfig::loadJLang('com_virtuemart');
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

        $user = &$cart->BT;
        $orderDetails = $order['details']['BT'];
        $paymentMethodID = $orderDetails->virtuemart_paymentmethod_id;
        $responseUrl = JROUTE::_(JURI::root().'index.php?option=com_virtuemart&view=cart&layout=order_done');
        $callbackUrl = JROUTE::_(JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component&pm='.$paymentMethodID);

        $orderStatusPending = !empty($method->status_pending) ? $method->status_pending : 'P';
        try {
            $orderModel = new VirtueMartModelOrders();
            $orderitems = ['order_status' => $orderStatusPending];
            $orderModel->updateStatusForOneOrder($orderDetails->virtuemart_order_id, $orderitems, true);
        } catch (\Exception $exception) {}

        $frisbeeService = new Frisbee();
        $frisbeeService->setMerchantId($method->FRISBEE_MERCHANT);
        $frisbeeService->setSecretKey($method->FRISBEE_SECRET_KEY);
        $frisbeeService->setRequestParameterOrderId($cart->order_number);
        $frisbeeService->setRequestParameterOrderDescription($this->generateOrderDescriptionParameter($order));
        $frisbeeService->setRequestParameterAmount($orderDetails->order_total);
        $frisbeeService->setRequestParameterCurrency($currency);
        $frisbeeService->setRequestParameterServerCallbackUrl($callbackUrl);
        $frisbeeService->setRequestParameterResponseUrl($responseUrl);
        $frisbeeService->setRequestParameterLanguage($lang);
        $frisbeeService->setRequestParameterSenderEmail($user['email']);
        $frisbeeService->setRequestParameterReservationData($this->generateReservationDataParameter($order));

        $checkoutUrl = $frisbeeService->retrieveCheckoutUrl($cart->order_number);

        if (!$checkoutUrl) {
            return $this->processConfirmedOrderPaymentResponse(0, $cart, $order, $frisbeeService->getRequestResultErrorMessage(), '');
        }

        header("HTTP/1.1 301 Moved Permanently");
        header("Location: " . $checkoutUrl);

        return $this->processConfirmedOrderPaymentResponse(2, $cart, $order, $html, '', $orderStatusPending);
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

        $frisbeeService = new Frisbee();
        $data = $frisbeeService->getCallbackData();
        $order_id = $frisbeeService->parseFrisbeeOrderId($data);

        $order = new VirtueMartModelOrders();

        $method = new plgVmPaymentFrisbee();
        $order_s_id = $order->getOrderIdByOrderNumber($order_id);
        $orderitems = $order->getOrder($order_s_id);
        $orderdetails = $orderitems['details']['BT'];
        $paymentMethodId = $orderdetails->virtuemart_paymentmethod_id;

        $methoditems = $method->__getVmPluginMethod($paymentMethodId);
        $orderStatusPending = !empty($methoditems->status_pending) ? $methoditems->status_pending : 'P';
        $orderStatusSuccess = !empty($methoditems->status_success) ? $methoditems->status_success : 'C';

        try {
            $frisbeeService->setMerchantId($methoditems->FRISBEE_MERCHANT);
            $frisbeeService->setSecretKey($methoditems->FRISBEE_SECRET_KEY);

            $response = $frisbeeService->handleCallbackData($data);

            if ($frisbeeService->isOrderDeclined()) {
                $orderitems['order_status'] = 'D';
            } elseif ($frisbeeService->isOrderExpired()) {
                if ($orderdetails->order_status == $orderStatusPending) {
                    $orderitems['order_status'] = 'X';
                } else {
                    die();
                }
            } elseif ($frisbeeService->isOrderApproved()) {
                $orderitems['order_status'] = $orderStatusSuccess;
            } elseif ($frisbeeService->isOrderFullyReversed() || $frisbeeService->isOrderPartiallyReversed()) {
                $orderitems['order_status'] = 'R';
            }

            $orderitems['comments'] = 'Frisbee ID: '.$data['order_id'].' Payment ID: '.$data['payment_id'] . ' Message: ' . $frisbeeService->getStatusMessage();
        } catch (\Exception $exception) {
            $orderitems['order_status'] = $orderStatusPending;
            $orderitems['comments'] = $exception->getMessage();
            http_response_code(500);
        }

        if ($response === true) {
            $redirect = JROUTE::_(JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm='.$paymentMethodId);
            header('Location:'.$redirect);
            $datetime = date("YmdHis");
            echo "OK";
        } else {
            echo sprintf("<!-- {%s} -->", $orderitems['comments']);
        }

        $orderitems['customer_notified'] = 0;
        $orderitems['virtuemart_order_id'] = $order_s_id;
        $order->updateStatusForOneOrder($order_s_id, $orderitems, true);
    }

    /**
     * @param $order
     * @return string
     */
    protected function generateReservationDataParameter($order)
    {
        $db = JFactory::getDBO();
        $orderDetails = $order['details']['BT'];

        $query = 'SELECT *';
        $query .= ' FROM `#__virtuemart_countries`';
        $query .= ' WHERE virtuemart_country_id = ' . $orderDetails->virtuemart_country_id;
        $db->setQuery($query);
        $countryObject = $db->loadObject();

        if ($orderDetails->virtuemart_state_id) {
            $query = 'SELECT *';
            $query .= ' FROM `#__virtuemart_states`';
            $query .= ' WHERE virtuemart_state_id = '.$orderDetails->virtuemart_state_id;
            $db->setQuery($query);
            $stateObject = $db->loadObject();
            $state = $stateObject->state_name;
        } else {
            $state = '';
        }

        $reservationData = array(
            'phonemobile' => $orderDetails->phone_2,
            'customer_address' => $orderDetails->address_1 . ' ' . $orderDetails->address_2,
            'customer_country' => $countryObject->country_2_code,
            'customer_state' => $state,
            'customer_name' => $orderDetails->first_name . ' ' . $orderDetails->last_name,
            'customer_city' => $orderDetails->city,
            'customer_zip' => $orderDetails->zip,
            'account' => ($orderDetails->virtuemart_user_id > 0 ? $orderDetails->virtuemart_user_id : time()),
            'products' => $this->generateProductsParameter($order),
            'cms_name' => 'Joomla',
            'cms_version' => defined('JVERSION') ? JVERSION : '',
            'shop_domain' => $_SERVER['SERVER_NAME'] ?: $_SERVER['HTTP_HOST'],
            'path' => $_SERVER['REQUEST_URI'],
            'uuid' => (isset($_SERVER['HTTP_USER_AGENT']) ? base64_encode($_SERVER['HTTP_USER_AGENT']) : time())
        );

        return base64_encode(json_encode($reservationData));
    }

    /**
     * @param $orderDetails
     * @return string
     */
    protected function generateOrderDescriptionParameter($orderDetails)
    {
        $description = '';
        foreach ($orderDetails['items'] as $item) {
            $amount = number_format($this->calculateItemTotalAmount($item), self::PRECISION);
            $description .= "Name: $item->order_item_name ";
            $description .= "Price: $item->product_item_price ";
            $description .= "Qty: $item->product_quantity ";
            $description .= "Amount: $amount\n";
        }

        return $description;
    }

    /**
     * @param $orderDetails
     * @return array
     */
    protected function generateProductsParameter($orderDetails)
    {
        $products = [];

        foreach ($orderDetails['items'] as $key => $item) {
            $products[] = [
                'id' => $key+1,
                'name' => $item->order_item_name,
                'price' => number_format(floatval($item->product_item_price), self::PRECISION),
                'total_amount' => number_format($this->calculateItemTotalAmount($item), self::PRECISION),
                'quantity' => number_format(floatval($item->product_quantity), self::PRECISION),
            ];
        }

        return $products;
    }

    /**
     * @param $item
     * @return float
     */
    protected function calculateItemTotalAmount($item)
    {
        return floatval($item->product_quantity * $item->product_item_price);
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
            return null;
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
