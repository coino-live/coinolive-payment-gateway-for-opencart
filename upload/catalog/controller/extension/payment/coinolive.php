<?php
class ControllerExtensionPaymentCoinoLive extends Controller {
	public function index() {
		$this->load->language('extension/payment/coinolive');

		$data['button_confirm'] = $this->language->get('button_confirm');
		
		$coinolive_adr = "https://coino.live/api/v1/order?data=";

		$this->load->model('checkout/order');

		$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

		if ($order_info) {
			 // coinolive Args
            $coinolive_args = array(
                'dataSource' => "opencart",
                'ipnURL' => $this->url->link('extension/payment/coinolive/callback', '', true),
                'paymentCurrency' => strtoupper($order_info['currency_code']),
                'successURL' => $this->url->link('extension/payment/coinolive/confirm', '', true),
                'cancelURL' => $this->url->link('checkout/checkout', '', true),

                // Order key + ID
                'orderID' => 'OC-' . $order_info['order_id'],
                'apiKey' => $this->config->get('payment_coinolive_api_key'),

                // Billing Address info
                'customerName' => $order_info['payment_firstname'],
                'customerEmail' => $order_info['email'],
				'gateID'=>$this->config->get('payment_coinolive_ipn_secret')
            );
			
			$order_totals = $this->model_checkout_order->getOrderTotals($order_info['order_id']);
			
			$coinolive_args['paymentAmount'] = 0;
			$coinolive_args['shipping'] = 0;
			$coinolive_args['tax'] = 0;
			
			foreach ($order_totals as $order_total) {
				if ($order_total['code'] == 'shipping') {
					$coinolive_args['shipping'] += $order_total['value'];
				} elseif ($order_total['code'] == 'tax') {
					$coinolive_args['tax'] += $order_total['value'];
				} elseif ($order_total['code'] == 'total') {
					$coinolive_args['paymentAmount'] += $order_total['value'];
				} 
			}
			
			$coinolive_args['products'] = array();

			foreach ($this->cart->getProducts() as $product) {
				
				$coinolive_args['products'][] = array(
					'name'     => htmlspecialchars($product['name']),						
					'quantity' => $product['quantity'],
					'price' => $product['quantity'] * $product['price'],
					'pricePerItem'    => $product['price'],
				);
			}

            $coinolive_adr .= urlencode(json_encode($coinolive_args));
			
			$data['coinolive_link'] = $coinolive_adr;

			return $this->load->view('extension/payment/coinolive', $data);
		}
	}

	public function callback() {
		
		if (version_compare(phpversion(), '7.1', '>=')) {
			ini_set( 'serialize_precision', -1 );
		}
		
		$this->load->model('checkout/order');
		
		if ($this->check_ipn_request_is_valid()) {
			$this->successful_request();
		} else {
			exit("Coino Live Request Failure");
		}
	}
	
	function check_ipn_request_is_valid() {

		$order = false;
		$error_msg = "Unknown error";
		$auth_ok = false;
		$request_data = null;
		

		if (isset($_SERVER['HTTP_X_coinolive_SIG']) && !empty($_SERVER['HTTP_X_coinolive_SIG'])) {
			$recived_hmac = $_SERVER['HTTP_X_coinolive_SIG'];

			$request_json = file_get_contents('php://input');
			$request_data = json_decode($request_json, true);
			ksort($request_data);
			$sorted_request_json = json_encode($request_data);

			if ($request_json !== false && !empty($request_json)) {
				$hmac = hash_hmac("sha512", $sorted_request_json, trim($this->config->get('payment_coinolive_ipn_secret')));

				if ($hmac == $recived_hmac) {
					$auth_ok = true;
				} else {
					$error_msg = 'HMAC signature does not match';
				}
			} else {
				$error_msg = 'Error reading POST data';
			}
		} else {
			$error_msg = 'No HMAC signature sent.';
		}

		if ($auth_ok) {
			
			$valid_order_id = str_replace("OC-", "", $request_data["order_id"]);
			$order = $this->model_checkout_order->getOrder($valid_order_id);

			if ($order) {                  
				$order['currency_code'] = strtoupper($order['currency_code']);
				$payment_currency = strtoupper($request_data["pay_currency"]);
				if ($payment_currency == ($order['currency_code'] || $payment_currency)) {
					if ($request_data["price_amount"] >= $order['total']) {
						print "IPN check OK\n";
						return true;
					} else {
						$error_msg = "Amount received is less than the total!";
					}
				} else {
					$error_msg = "Original currency doesn't match!";
				}
			} else {
				$error_msg = "Could not find order info for order ";
			}
		}

		$report = "Error Message: ".$error_msg."\n\n";

		if ($order) {
			$this->update_status($valid_order_id, 'partially_paid', 'Coino Live Error:' . $error_msg);
		}

		if (!empty($this->config->get('payment_coinolive_email'))) { 
			mail($this->config->get('payment_coinolive_email'), "Report", $report); 
		};
		
		$this->log->write('Error: '. $report);
		
		return false;
	}
	
	function successful_request() {
		$request_json = file_get_contents('php://input');
		$request_data = json_decode($request_json, true);

		$valid_order_id = str_replace("OC-", "", $request_data["order_id"]);
		
		$order = $this->model_checkout_order->getOrder($valid_order_id);

		if ($request_data["payment_status"] == "finished") {
			$this->update_status($valid_order_id, 'finished', 'Order has been paid.');
		} else if ($request_data['payment_status'] == "partially_paid") {
			$this->update_status($valid_order_id, 'partially_paid', 'Your payment is partially paid. Please contact support@coino.live Amount received: ' . $request_data["actually_paid"]);
		} else if ($request_data["payment_status"] == "confirming") {
			$this->update_status($valid_order_id, 'confirming', 'Order is processing.');
		} else if ($request_data['payment_status'] == "confirmed") {
			$this->update_status($valid_order_id, 'confirmed', 'Order is processing.');
		} else if ($request_data['payment_status'] == "sending") {
			$this->update_status($valid_order_id, 'sending', 'Order is processing.');
		} else if ($request_data["payment_status"] == "failed") {
			$this->update_status($valid_order_id, 'failed', 'Order is failed. Please contact support@coino.live');
		}
	}
		
	public function confirm() {
		if ($this->session->data['payment_method']['code'] == 'coinolive') {

			$this->load->model('checkout/order');

			$this->model_checkout_order->addOrderHistory($this->session->data['order_id'], $this->config->get('payment_coinolive_confirmed_status_id'), '', '');
		
			$this->response->redirect($this->url->link('checkout/success'));
		}
	}
	
	function update_status($order_id, $status, $comment) {
		$order_status_id = $this->config->get('payment_coinolive_' . $status . '_status_id');
		$this->model_checkout_order->addOrderHistory($order_id, $order_status_id, $comment, true);
	}
}