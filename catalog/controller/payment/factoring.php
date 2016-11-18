<?php
if (!defined('DIR_APPLICATION')) {
    die();
}
require_once DIR_SYSTEM . '../vendor/payex/php-api/src/PayEx/Px.php';
require_once DIR_SYSTEM . 'Payex/Payex.php';
require_once DIR_SYSTEM . 'Payex/OcRoute.php';

class ControllerPaymentFactoring extends Controller
{
    protected $_module_name = 'factoring';

    protected static $_px;

    /**
     * Index Action
     */
    public function index()
    {

        $this->language->load( OcRoute::getPaymentRoute('payment/') . 'factoring');

        $data['text_title'] = $this->language->get('text_title');
        $data['text_description'] = $this->language->get('text_description');
        $data['text_social_security_number'] = $this->language->get('text_social_security_number');
        $data['text_select_payment_method'] = $this->language->get('text_select_payment_method');
        $data['text_financing_invoice'] = $this->language->get('text_financing_invoice');
        $data['text_factoring'] = $this->language->get('text_factoring');
        $data['text_part_payment'] = $this->language->get('text_part_payment');
        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['continue'] = $this->url->link('checkout/success');
        $data['action'] = $this->url->link( OcRoute::getPaymentRoute('payment/') . 'factoring/validate');
        $data['type'] = $this->config->get('factoring_type');

        if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/payment/factoring.tpl')) {
            return $this->load->view($this->config->get('config_template') . '/template/payment/factoring.tpl', $data);
        } else {
	        return $this->load->view( OcRoute::getTemplate('payment/') . 'factoring.tpl', $data);
        }
    }

    /**
     * Validate Action
     */
    public function validate()
    {
        $this->load->model('checkout/order');
        $this->load->language( OcRoute::getPaymentRoute('payment/') . 'factoring');
        $this->load->language( OcRoute::getPaymentRoute('payment/') . 'payex_error');

        $order_id = $this->session->data['order_id'];
        $ssn = trim($this->request->post['social-security-number']);
        $order = $this->model_checkout_order->getOrder($order_id);

        if (empty($ssn)) {
            $json = array(
                'status' => 'error',
                'message' => $this->language->get('error_invalid_ssn')
            );
            $this->response->setOutput(json_encode($json));
            return;
        }

        if (!in_array(mb_strtoupper($order['payment_iso_code_2'], 'UTF-8'), array('SE', 'NO', 'FI'))) {
            $json = array(
                'status' => 'error',
                'message' => 'This country is not supported by the payment system.'
            );
            $this->response->setOutput(json_encode($json));
            return;
        }

        if (empty($order['payment_postcode'])) {
            $json = array(
                'status' => 'error',
                'message' => 'Please fill Zip Code'
            );
            $this->response->setOutput(json_encode($json));
            return;
        }

        $json = array(
            'status' => 'ok',
            'redirect' => $this->url->link( OcRoute::getPaymentRoute('payment/') . 'factoring/confirm'),
        );
        $this->response->setOutput(json_encode($json));
        return;
    }

    /**
     * Confirm Action
     */
    public function confirm()
    {
        $this->language->load( OcRoute::getPaymentRoute('payment/') . 'payex_error');
        $this->load->model('checkout/order');
        $this->load->model('module/factoring');

        $order_id = $this->session->data['order_id'];
        if (empty($order_id)) {
            $this->session->data['payex_error'] = $this->language->get('error_invalid_order');
            $this->response->redirect($this->url->link( OcRoute::getPaymentRoute('payment/') . '' . $this->_module_name . '/error', '', 'SSL'));
        }

        $ssn = $this->request->post['social-security-number'];
        if (empty($ssn)) {
            $this->session->data['payex_error'] = $this->language->get('error_invalid_ssn');
            $this->response->redirect($this->url->link( OcRoute::getPaymentRoute('payment/') . '' . $this->_module_name . '/error', '', 'SSL'));
        }

        // Selected Payment Mode
        $view = $this->config->get('factoring_type') ? $this->config->get('factoring_type') : 'FINANCING';
        if ($view === 'SELECT') {
            $view = $this->request->post['factoring-menu'];
        }

        $order = $this->model_checkout_order->getOrder($order_id);

        // Call PxOrder.Initialize8
        $params = array(
            'accountNumber' => '',
            'purchaseOperation' => 'AUTHORIZATION',
            'price' => round($order['total'] * 100),
            'priceArgList' => '',
            'currency' => strtoupper($order['currency_code']),
            'vat' => 0,
            'orderID' => $order['order_id'],
            'productNumber' => $order['customer_id'],
            'description' => html_entity_decode($order['store_name'], ENT_QUOTES, 'UTF-8'),
            'clientIPAddress' => $order['ip'],
            'clientIdentifier' => '',
            'additionalValues' => '',
            'externalID' => '',
            'returnUrl' => 'http://localhost.no/return',
            'view' => $view === 'CREDITACCOUNT' ? 'FINANCING' : $view,
            'agreementRef' => '',
            'cancelUrl' => 'http://localhost.no/cancel',
            'clientLanguage' => 'en-US'
        );
        $result = $this->getPx()->Initialize8($params);
        if ($result['code'] !== 'OK' || $result['description'] !== 'OK' || $result['errorCode'] !== 'OK') {
            $this->session->data['payex_error'] = $result['errorCode'] . ' (' . $result['description'] . ')';
            $this->response->redirect($this->url->link( OcRoute::getPaymentRoute('payment/') . '' . $this->_module_name . '/error', '', 'SSL'));
        }
        $orderRef = $result['orderRef'];

        // Perform Payment
        switch ($view) {
            case 'FINANCING':
                // Call PxOrder.PurchaseFinancingInvoice
                $params = array(
                    'accountNumber' => '',
                    'orderRef' => $orderRef,
                    'socialSecurityNumber' => $ssn,
                    'legalName' => trim($order['payment_firstname'] . ' ' . $order['payment_lastname']),
                    'streetAddress' => trim($order['payment_address_1'] . ' ' . $order['payment_address_2']),
                    'coAddress' => '',
                    'zipCode' => $order['payment_postcode'],
                    'city' => $order['payment_city'],
                    'countryCode' => $order['payment_iso_code_2'],
                    'paymentMethod' => $order['payment_iso_code_2'] === 'SE' ? 'PXFINANCINGINVOICESE' : 'PXFINANCINGINVOICENO',
                    'email' => $order['email'],
                    'msisdn' => ( substr( $order['telephone'], 0, 1 ) === '+' ) ? $order['telephone'] : '+' . $order['telephone'],
                    'ipAddress' => $order['ip']
                );
                $result = $this->getPx()->PurchaseFinancingInvoice($params);
                break;
            case 'CREDITACCOUNT':
                // Call PxOrder.PurchaseCreditAccount
                $params = array(
                    'accountNumber' => '',
                    'orderRef' => $orderRef,
                    'socialSecurityNumber' => $ssn,
                    'legalName' => trim($order['payment_firstname'] . ' ' . $order['payment_lastname']),
                    'streetAddress' => trim($order['payment_address_1'] . ' ' . $order['payment_address_2']),
                    'coAddress' => '',
                    'zipCode' => $order['payment_postcode'],
                    'city' => $order['payment_city'],
                    'countryCode' => $order['payment_iso_code_2'],
                    'paymentMethod' => $order['payment_iso_code_2'] === 'SE' ? 'PXCREDITACCOUNTSE' : 'PXCREDITACCOUNTNO',
                    'email' => $order['email'],
                    'msisdn' => (mb_substr($order['telephone'], 0, 1) === '+') ? $order['telephone'] : '+' . $order['telephone'],
                    'ipAddress' => $order['ip']
                );
                $result = $this->getPx()->PurchaseCreditAccount($params);
                break;
            default:
                $this->session->data['payex_error'] = 'Invalid payment mode';
                $this->response->redirect($this->url->link( OcRoute::getPaymentRoute('payment/') . '' . $this->_module_name . '/error', '', 'SSL'));
        }
        if ($result['code'] !== 'OK' || $result['description'] !== 'OK') {
            $this->session->data['payex_error'] = $result['errorCode'] . ' (' . $result['description'] . ')';
            if (preg_match('/\bInvalid parameter:msisdn\b/i', $result['description'])) {
                $this->session->data['payex_error'] = $this->language->get('error_invalid_msisdn');
            }
			else if (preg_match('/\bCreditCheckNotApproved\b/i', $result['errorCode'])) {
				$this->session->data['payex_error'] = $this->language->get('error_creditCheckNotApproved');
			}

            $this->response->redirect($this->url->link( OcRoute::getPaymentRoute('payment/') . '' . $this->_module_name . '/error', '', 'SSL'));
        }

        // Save Transaction
        $this->model_module_factoring->addTransaction($order_id, $result['transactionNumber'], $result['transactionStatus'], $result, isset($result['date']) ? strtotime($result['date']) : time());

        // Save Order Lines for Capture
        $order_xml = $this->getInvoiceExtraPrintBlocksXML($order['order_id'], $this->cart->getProducts(), $this->session->data['shipping_method']);
        $this->save_order_lines($order['order_id'], $order_xml);

        $transaction_status = (int)$result['transactionStatus'];
        switch ($transaction_status) {
            case 0:
            case 6:
                $this->model_checkout_order->addOrderHistory($this->session->data['order_id'], $this->config->get('factoring_completed_status_id'), '', true);
                $this->response->redirect($this->url->link('checkout/success', '', 'SSL'));
                break;
            case 3:
                $this->model_checkout_order->addOrderHistory($this->session->data['order_id'], $this->config->get('factoring_pending_status_id'), '', true);
                $this->response->redirect($this->url->link('checkout/success', '', 'SSL'));
                break;
            case 5;
            default:
                $error_message = '';
                if (!empty($message['thirdPartyError'])) {
                    $error_message .= $this->language->get('error_third_party') . ': ' . $message['thirdPartyError'];
                }

                if (!empty($message['transactionErrorCode']) && !empty($message['transactionErrorDescription'])) {
                    $error_message .= $this->language->get('error_transaction') . ': ' . $message['transactionErrorCode'] . ' (' . $message['transactionErrorDescription'] . ')';
                }

                if (empty($error_message)) {
                    $error_message = $this->language->get('error_unknown');
                }

                $this->session->data['payex_error'] = $error_message;
                $this->response->redirect($this->url->link( OcRoute::getPaymentRoute('payment/') . '' . $this->_module_name . '/error', '', 'SSL'));
        }
    }

    /**
     * Error Action
     */
    public function error()
    {
        $this->load->language( OcRoute::getPaymentRoute('payment/') . 'payex_error');

        $data['heading_title'] = $this->language->get('heading_title');
        if (!empty($this->session->data['payex_error'])) {
            $data['description'] = $this->session->data['payex_error'];
        } else {
            $data['description'] = $this->language->get('text_error');
        }
        $data['link_text'] = $this->language->get('link_text');
        $data['link'] = $this->url->link('checkout/checkout', '', 'SSL');
	    $data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['column_right'] = $this->load->controller('common/column_right');
		$data['content_top'] = $this->load->controller('common/content_top');
		$data['content_bottom'] = $this->load->controller('common/content_bottom');
		$data['footer'] = $this->load->controller('common/footer');
      
        if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/payment/payex_error.tpl')) {
	        $this->response->setOutput($this->load->view($this->config->get('config_template') . '/template/payment/payex_error.tpl', $data));
        } else {
	        $this->response->setOutput($this->load->view(OcRoute::getTemplate('payment/') . 'payex_error.tpl', $data));
        }
    }

    /**
     * Get PayEx Handler
     * @return \PayEx\Px
     */
    protected function getPx()
    {
        if (is_null(self::$_px)) {
            $account_number = $this->config->get('factoring_account_number');
            $encryption_key = $this->config->get('factoring_encryption_key');
            $mode = $this->config->get('factoring_mode');
            self::$_px = new \PayEx\Px();
            self::$_px->setEnvironment($account_number, $encryption_key, ($mode !== 'LIVE'));
            self::$_px->setUserAgent(sprintf("PayEx.Ecommerce.Php/%s PHP/%s OpenCart/%s PayEx.OpenCart/%s",
                \PayEx\Px::VERSION,
                phpversion(),
                VERSION,
                Payex::getVersion()
            ));
        }

        return self::$_px;
    }

    /**
     * Generate Invoice Print XML
     * @param $order_id
     * @param $products
     * @param $shipping_method
     * @return mixed
     */
    protected function getInvoiceExtraPrintBlocksXML($order_id, $products, $shipping_method)
    {
        $dom = new DOMDocument('1.0', 'utf-8');
        $OnlineInvoice = $dom->createElement('OnlineInvoice');
        $dom->appendChild($OnlineInvoice);
        $OnlineInvoice->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $OnlineInvoice->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsd', 'http://www.w3.org/2001/XMLSchema');

        $OrderLines = $dom->createElement('OrderLines');
        $OnlineInvoice->appendChild($OrderLines);

        $averageTax = array();
        foreach ($products as $key => $product) {
            $qty = $product['quantity'];
            $price = $product['price'] * $qty;
            $priceWithTax = $this->tax->calculate($price, $product['tax_class_id'], 1);
            $taxPrice = $priceWithTax - $price;
            $taxPercent = ($taxPrice > 0) ? round(100 / (($priceWithTax - $taxPrice) / $taxPrice)) : 0;
            $averageTax[] = $taxPercent;

            $OrderLine = $dom->createElement('OrderLine');
            $OrderLine->appendChild($dom->createElement('Product', $product['name']));
            $OrderLine->appendChild($dom->createElement('Qty', $qty));
            $OrderLine->appendChild($dom->createElement('UnitPrice', $product['price']));
            $OrderLine->appendChild($dom->createElement('VatRate', $taxPercent));
            $OrderLine->appendChild($dom->createElement('VatAmount', $taxPrice));
            $OrderLine->appendChild($dom->createElement('Amount', $priceWithTax));
            $OrderLines->appendChild($OrderLine);
        }

        // Add Shipping Line
        if (isset($shipping_method['cost']) && (float)$shipping_method['cost'] > 0) {
            $shipping = $shipping_method['cost'];
            $shippingWithTax = $this->tax->calculate($shipping, $shipping_method['tax_class_id'], 1);
            $shippingTax = $shippingWithTax - $shipping;
            $shippingTaxPercent = $shipping != 0 ? (int)((100 * ($shippingTax) / $shipping)) : 0;
            $averageTax[] = $shippingTaxPercent;

            $OrderLine = $dom->createElement('OrderLine');
            $OrderLine->appendChild($dom->createElement('Product', $shipping_method['title']));
            $OrderLine->appendChild($dom->createElement('Qty', 1));
            $OrderLine->appendChild($dom->createElement('UnitPrice', $shipping));
            $OrderLine->appendChild($dom->createElement('VatRate', $shippingTaxPercent));
            $OrderLine->appendChild($dom->createElement('VatAmount', $shippingTax));
            $OrderLine->appendChild($dom->createElement('Amount', $shippingWithTax));
            $OrderLines->appendChild($OrderLine);
        }

        // Add Coupon Line
        $order_info = $this->model_checkout_order->getOrder($order_id);
        $order_total_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order_total` WHERE code = 'coupon'  AND order_id = '" . (int)$order_id . "' ORDER BY sort_order ASC");
        if ($order_total_query && $order_total_query->rows > 0) {
            $coupon = array_shift($order_total_query->rows);
            $coupon['value'] = $this->currency->format($coupon['value'], $order_info['currency_code'], $order_info['currency_value'], false);

            if (abs($coupon['value']) > 0) {
                // Use average tax as discount tax for workaround
                $couponTaxPercent = round(array_sum($averageTax) / count($averageTax));
                $couponTax = round($coupon['value'] / 100 * $couponTaxPercent, 2);
                $couponWithTax = $coupon['value'] + $couponTax;

                $OrderLine = $dom->createElement('OrderLine');
                $OrderLine->appendChild($dom->createElement('Product', $coupon['title']));
                $OrderLine->appendChild($dom->createElement('Qty', 1));
                $OrderLine->appendChild($dom->createElement('UnitPrice', $coupon['value']));
                $OrderLine->appendChild($dom->createElement('VatRate', $couponTaxPercent));
                $OrderLine->appendChild($dom->createElement('VatAmount', $couponTax));
                $OrderLine->appendChild($dom->createElement('Amount', $couponWithTax));
                $OrderLines->appendChild($OrderLine);
            }
        }

        // Add Factoring fee
        if ($this->config->get('factoring_fee_fee') > 0) {
            $fee = (float)$this->config->get('factoring_fee_fee');
            $fee_tax_class_id = (int)$this->config->get('factoring_fee_tax_class_id');
            $feeWithTax = $this->tax->calculate($fee, $fee_tax_class_id, 1);
            $feeTax = $feeWithTax - $fee;
            $feeTaxPercent = $fee != 0 ? (int)((100 * ($feeTax) / $fee)) : 0;

            $OrderLine = $dom->createElement('OrderLine');
            $OrderLine->appendChild($dom->createElement('Product', $this->language->get('text_factoring_fee')));
            $OrderLine->appendChild($dom->createElement('Qty', 1));
            $OrderLine->appendChild($dom->createElement('UnitPrice', $fee));
            $OrderLine->appendChild($dom->createElement('VatRate', $feeTaxPercent));
            $OrderLine->appendChild($dom->createElement('VatAmount', $feeTax));
            $OrderLine->appendChild($dom->createElement('Amount', $fee + $feeTax));
            $OrderLines->appendChild($OrderLine);
        }

        return str_replace("\n", '', $dom->saveXML());
    }

    /**
     * Save Order lines in Database
     * @param int $order_id Order ID
     * @param string $xml XML content generated using getInvoiceExtraPrintBlocksXML()
     * @return void
     */
    public function save_order_lines($order_id, $xml) {
        $products = array();

        // Parse order lines
        $dom = new DOMDocument('1.0', 'utf-8');
        $dom->loadXML($xml);
        $order_lines = $dom->getElementsByTagName('OrderLine');
        foreach ($order_lines as $order_line) {
            if ($order_line->childNodes->length) {
                $tmp = array();
                foreach ($order_line->childNodes as $i) {
                    $tmp[$i->nodeName] = $i->nodeValue;
                }

                $products[] = $tmp;
            }
        }

        if (count($products) > 0) {
            // Clean up
            $this->db->query(sprintf("DELETE FROM " . DB_PREFIX . "payex_factoring_items WHERE order_id = '%s';", (int)$order_id));

            // Insert Order lines to table
            foreach ($products as $product) {
                $name = $this->db->escape($product['Product']);
                $qty = (float) $product['Qty'];
                $unit_price = (float) $product['UnitPrice'];
                $vat_rate = (float) $product['VatRate'];
                $vat_amount = (float) $product['VatAmount'];
                $amount = (float) $product['Amount'];

                $this->db->query(sprintf("INSERT INTO " . DB_PREFIX . "payex_factoring_items SET order_id = '%s', name = '%s', qty = '%s', unit_price = '%s', vat_rate = '%s', vat_amount='%s', amount='%s';", (int)$order_id, $name, $qty, $unit_price, $vat_rate, $vat_amount, $amount));
            }
        }
    }
}