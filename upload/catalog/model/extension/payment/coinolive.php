<?php
class ModelExtensionPaymentCoinoLive extends Model {
	public function getMethod($address, $total) {
		$this->load->language('extension/payment/coinolive');
		$method_data = array(
			'code'       => 'coinolive',
			'title'      => $this->language->get('text_title'),
			'terms'      => '',
			'sort_order' => $this->config->get('payment_coinolive_sort_order')
		);
		
		return $method_data;
	}
}
