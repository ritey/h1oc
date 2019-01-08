<?php
//==============================================================================
// Stripe Payment Gateway Pro v302.2
// 
// Author: Clear Thinking, LLC
// E-mail: johnathan@getclearthinking.com
// Website: http://www.getclearthinking.com
// 
// All code within this file is copyright Clear Thinking, LLC.
// You may not copy or reuse code within this file without written permission.
//==============================================================================

class ControllerExtensionPaymentStripe extends Controller { 
	private $type = 'payment';
	private $name = 'stripe';
	
	public function index() {
		$data = array(
			'type'			=> $this->type,
			'name'			=> $this->name,
			'autobackup'	=> false,
			'save_type'		=> 'keepediting',
			'permission'	=> $this->hasPermission('modify'),
		);
		
		$this->loadSettings($data);
		
		// extension-specific
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "stripe_customer` (
				`customer_id` int(11) NOT NULL,
				`stripe_customer_id` varchar(18) NOT NULL,
				`transaction_mode` varchar(4) NOT NULL DEFAULT 'live',
				PRIMARY KEY (`customer_id`, `stripe_customer_id`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
		");
		
		$transaction_mode_column = false;
		$database_table_query = $this->db->query("DESCRIBE " . DB_PREFIX . "stripe_customer");
		foreach ($database_table_query->rows as $column) {
			if ($column['Field'] == 'transaction_mode') {
				$transaction_mode_column = true;
			}
		}
		if (!$transaction_mode_column) {
			$this->db->query("ALTER TABLE " . DB_PREFIX . "stripe_customer ADD transaction_mode varchar(4) NOT NULL DEFAULT 'live'");
		}
		
		$old_customers_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "setting WHERE `code` = 'stripe_permanent' AND `key` = 'stripe_customers'");
		if ($old_customers_query->num_rows) {
			$stripe_customer_tokens = unserialize($old_customers_query->row['value']);
			foreach ($stripe_customer_tokens as $opencart_id => $stripe_id) {
				$this->db->query("INSERT IGNORE INTO " . DB_PREFIX . "stripe_customer SET customer_id = " . (int)$opencart_id . ", stripe_customer_id = '" . $this->db->escape($stripe_id) . "'");
			}
			$this->db->query("DELETE FROM " . DB_PREFIX . "setting WHERE `code` = 'stripe_permanent'");
		}
		
		//------------------------------------------------------------------------------
		// Data Arrays
		//------------------------------------------------------------------------------
		$data['language_array'] = array($this->config->get('config_language') => '');
		$data['language_flags'] = array();
		$this->load->model('localisation/language');
		foreach ($this->model_localisation_language->getLanguages() as $language) {
			$data['language_array'][$language['code']] = $language['name'];
			$data['language_flags'][$language['code']] = (version_compare(VERSION, '2.2', '<')) ? 'view/image/flags/' . $language['image'] : 'language/' . $language['code'] . '/' . $language['code'] . '.png';
		}
		
		$data['order_status_array'] = array(0 => $data['text_ignore']);
		$this->load->model('localisation/order_status');
		foreach ($this->model_localisation_order_status->getOrderStatuses() as $order_status) {
			$data['order_status_array'][$order_status['order_status_id']] = $order_status['name'];
		}
		
		$data['customer_group_array'] = array(0 => $data['text_guests']);
		$this->load->model((version_compare(VERSION, '2.1', '<') ? 'sale' : 'customer') . '/customer_group');
		foreach ($this->{'model_' . (version_compare(VERSION, '2.1', '<') ? 'sale' : 'customer') . '_customer_group'}->getCustomerGroups() as $customer_group) {
			$data['customer_group_array'][$customer_group['customer_group_id']] = $customer_group['name'];
		}
		
		$data['geo_zone_array'] = array(0 => $data['text_everywhere_else']);
		$this->load->model('localisation/geo_zone');
		foreach ($this->model_localisation_geo_zone->getGeoZones() as $geo_zone) {
			$data['geo_zone_array'][$geo_zone['geo_zone_id']] = $geo_zone['name'];
		}
		
		$data['store_array'] = array(0 => $this->config->get('config_name'));
		$store_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "store ORDER BY name");
		foreach ($store_query->rows as $store) {
			$data['store_array'][$store['store_id']] = $store['name'];
		}
		
		$data['currency_array'] = array($this->config->get('config_currency') => '');
		$this->load->model('localisation/currency');
		foreach ($this->model_localisation_currency->getCurrencies() as $currency) {
			$data['currency_array'][$currency['code']] = $currency['code'];
		}
		
		// Get subscription products
		$data['subscription_products'] = array();
		
		if (!empty($data['saved']['subscriptions']) &&
			!empty($data['saved']['transaction_mode']) &&
			!empty($data['saved'][$data['saved']['transaction_mode'].'_secret_key'])
		) {
			$plan_response = $this->curlRequest('GET', 'plans', array('count' => 100));
			
			if (!empty($plan_response['error'])) {
				$this->log->write('STRIPE ERROR: ' . $plan_response['error']['message']);
			} else {
				$plans = $plan_response['data'];
				
				while (!empty($plan_response['has_more'])) {
					$plan_response = $this->curlRequest('GET', 'plans', array('count' => 100, 'starting_after' => $plans[count($plans) - 1]['id']));
					if (empty($plan_response['error'])) {
						$plans = array_merge($plans, $plan_response['data']);
					}
				}
				
				foreach ($plans as $plan) {
					$decimal_factor = (in_array(strtoupper($plan['currency']), array('BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','VND','VUV','XAF','XOF','XPF'))) ? 1 : 100;
					$product_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "product p LEFT JOIN " . DB_PREFIX . "product_description pd ON (p.product_id = pd.product_id AND pd.language_id = " . (int)$this->config->get('config_language_id') . ") WHERE p.location = '" . $this->db->escape($plan['id']) . "'");
					
					foreach ($product_query->rows as $product) {
						$data['subscription_products'][] = array(
							'product_id'	=> $product['product_id'],
							'name'			=> $product['name'],
							'price'			=> $this->currency->format($product['price'], $this->config->get('config_currency')),
							'location'		=> $product['location'],
							'plan'			=> $plan['name'],
							'interval'		=> $plan['interval_count'] . ' ' . $plan['interval'] . ($plan['interval_count'] > 1 ? 's' : ''),
							'charge'		=> $this->currency->format($plan['amount'] / $decimal_factor, strtoupper($plan['currency']), 1, strtoupper($plan['currency'])),
						);
					}
				}
			}
		}
		
		// Pro-specific
		$data['typeaheads'] = array('customer');
		
		//------------------------------------------------------------------------------
		// Extensions Settings
		//------------------------------------------------------------------------------
		$data['settings'] = array();
		
		$data['settings'][] = array(
			'type'		=> 'tabs',
			'tabs'		=> array('extension_settings', 'order_statuses', 'restrictions', 'stripe_settings', 'stripe_checkout', 'subscription_products', 'create_a_charge'), // Pro-specific
		);
		$data['settings'][] = array(
			'key'		=> 'extension_settings',
			'type'		=> 'heading',
		);
		$data['settings'][] = array(
			'key'		=> 'status',
			'type'		=> 'select',
			'options'	=> array(1 => $data['text_enabled'], 0 => $data['text_disabled']),
			'default'	=> 1,
		);
		$data['settings'][] = array(
			'key'		=> 'sort_order',
			'type'		=> 'text',
			'default'	=> 1,
			'class'		=> 'short',
		);
		$data['settings'][] = array(
			'key'		=> 'title',
			'type'		=> 'multilingual_text',
			'default'	=> 'Credit / Debit Card',
		);
		$data['settings'][] = array(
			'key'		=> 'button_text',
			'type'		=> 'multilingual_text',
			'default'	=> 'Confirm Order',
		);
		$data['settings'][] = array(
			'key'		=> 'button_class',
			'type'		=> 'text',
			'default'	=> 'btn btn-primary',
		);
		$data['settings'][] = array(
			'key'		=> 'button_styling',
			'type'		=> 'text',
		);
		
		// Payment Page Text
		$data['settings'][] = array(
			'key'		=> 'payment_page_text',
			'type'		=> 'heading',
		);
		$data['settings'][] = array(
			'key'		=> 'text_card_details',
			'type'		=> 'multilingual_text',
			'default'	=> 'Card Details',
		);
		$data['settings'][] = array(
			'key'		=> 'text_use_your_stored_card',
			'type'		=> 'multilingual_text',
			'default'	=> 'Use Your Stored Card:',
		);
		$data['settings'][] = array(
			'key'		=> 'text_ending_in',
			'type'		=> 'multilingual_text',
			'default'	=> 'ending in',
		);
		$data['settings'][] = array(
			'key'		=> 'text_use_a_new_card',
			'type'		=> 'multilingual_text',
			'default'	=> 'Use a New Card:',
		);
		$data['settings'][] = array(
			'key'		=> 'text_store_card',
			'type'		=> 'multilingual_text',
			'default'	=> 'Store Card for Future Use:',
		);
		$data['settings'][] = array(
			'key'		=> 'text_please_wait',
			'type'		=> 'multilingual_text',
			'default'	=> 'Please wait...',
		);
		$data['settings'][] = array(
			'key'		=> 'text_to_be_charged',
			'type'		=> 'multilingual_text',
			'default'	=> 'To Be Charged Later',
		);
		
		// Errors
		$data['settings'][] = array(
			'key'		=> 'errors',
			'type'		=> 'heading',
		);
		$data['settings'][] = array(
			'key'		=> 'error_customer_required',
			'type'		=> 'multilingual_text',
			'default'	=> 'Error: You must create a customer account to purchase a subscription product.',
			'class'		=> 'long',
		);
		$data['settings'][] = array(
			'key'		=> 'error_shipping_required',
			'type'		=> 'multilingual_text',
			'default'	=> 'Please apply a shipping method to your cart before confirming your order.',
			'class'		=> 'long',
		);
		$data['settings'][] = array(
			'key'		=> 'error_shipping_mismatch',
			'type'		=> 'multilingual_text',
			'default'	=> 'Error: You must use the same shipping address as the one you used for your shipping quote. Please either use the same address when entering your credit card details, or re-estimate your shipping using the correct shipping location.',
			'class'		=> 'long',
		);
		
		// Stripe Error Codes
		$data['settings'][] = array(
			'key'		=> 'stripe_error_codes',
			'type'		=> 'heading',
		);
		$data['settings'][] = array(
			'type'		=> 'html',
			'content'	=> '<div class="text-info text-center pad-bottom-sm">' . $data['help_stripe_error_codes'] . '</div>',
		);
		$stripe_errors = array(
			'card_declined',
			'expired_card',
			'incorrect_cvc',
			'incorrect_number',
			'incorrect_zip',
			'invalid_cvc',
			'invalid_expiry_month',
			'invalid_expiry_year',
			'invalid_number',
			'missing',
			'processing_error',
		);
		foreach ($stripe_errors as $stripe_error) {
			$data['settings'][] = array(
				'key'		=> 'error_' . $stripe_error,
				'type'		=> 'multilingual_text',
				'class'		=> 'long',
			);
		}
		
		// Cards Page Text (Pro-specific)
		$data['settings'][] = array(
			'key'		=> 'cards_page_text',
			'type'		=> 'heading',
		);
		$data['settings'][] = array(
			'key'		=> 'cards_page_heading',
			'type'		=> 'multilingual_text',
			'default'	=> 'Your Stored Cards',
		);
		$data['settings'][] = array(
			'key'		=> 'cards_page_none',
			'type'		=> 'multilingual_text',
			'default'	=> 'You have no stored cards.',
		);
		$data['settings'][] = array(
			'key'		=> 'cards_page_default_card',
			'type'		=> 'multilingual_text',
			'default'	=> 'Default Card',
		);
		$data['settings'][] = array(
			'key'		=> 'cards_page_make_default',
			'type'		=> 'multilingual_text',
			'default'	=> 'Make Default',
		);
		$data['settings'][] = array(
			'key'		=> 'cards_page_delete',
			'type'		=> 'multilingual_text',
			'default'	=> 'Delete',
		);
		$data['settings'][] = array(
			'key'		=> 'cards_page_confirm',
			'type'		=> 'multilingual_text',
			'default'	=> 'Are you sure you want to delete this card?',
		);
		$data['settings'][] = array(
			'key'		=> 'cards_page_add_card',
			'type'		=> 'multilingual_text',
			'default'	=> 'Add New Card',
		);
		$data['settings'][] = array(
			'key'		=> 'cards_page_card_name',
			'type'		=> 'multilingual_text',
			'default'	=> 'Name on Card:',
		);
		$data['settings'][] = array(
			'key'		=> 'cards_page_card_details',
			'type'		=> 'multilingual_text',
			'default'	=> 'Card Details:',
		);
		$data['settings'][] = array(
			'key'		=> 'cards_page_card_address',
			'type'		=> 'multilingual_text',
			'default'	=> 'Card Address:',
		);
		$data['settings'][] = array(
			'key'		=> 'cards_page_success',
			'type'		=> 'multilingual_text',
			'default'	=> 'Success!',
		);
		
		// Subscriptions Page Text (Pro-specific)
		$data['settings'][] = array(
			'key'		=> 'subscriptions_page_text',
			'type'		=> 'heading',
		);
		$data['settings'][] = array(
			'key'		=> 'subscriptions_page_heading',
			'type'		=> 'multilingual_text',
			'default'	=> 'Your Subscriptions',
		);
		$data['settings'][] = array(
			'key'		=> 'subscriptions_page_message',
			'type'		=> 'multilingual_text',
			'default'	=> '<h4>Subscriptions will be charged using your default card.</h4>',
		);
		$data['settings'][] = array(
			'key'		=> 'subscriptions_page_none',
			'type'		=> 'multilingual_text',
			'default'	=> 'You have no subscriptions.',
		);
		$data['settings'][] = array(
			'key'		=> 'subscriptions_page_trial',
			'type'		=> 'multilingual_text',
			'default'	=> 'Trial End:',
		);
		$data['settings'][] = array(
			'key'		=> 'subscriptions_page_last',
			'type'		=> 'multilingual_text',
			'default'	=> 'Last Charge:',
		);
		$data['settings'][] = array(
			'key'		=> 'subscriptions_page_next',
			'type'		=> 'multilingual_text',
			'default'	=> 'Next Charge:',
		);
		$data['settings'][] = array(
			'key'		=> 'subscriptions_page_charge',
			'type'		=> 'multilingual_text',
			'default'	=> 'Additional Charge:',
		);
		$data['settings'][] = array(
			'key'		=> 'subscriptions_page_cancel',
			'type'		=> 'multilingual_text',
			'default'	=> 'Cancel',
		);
		$data['settings'][] = array(
			'key'		=> 'subscriptions_page_confirm',
			'type'		=> 'multilingual_text',
			'default'	=> 'Please type CANCEL to confirm that you want to cancel this subscription.',
		);
		
		//------------------------------------------------------------------------------
		// Order Statuses
		//------------------------------------------------------------------------------
		$data['settings'][] = array(
			'key'		=> 'order_statuses',
			'type'		=> 'tab',
		);
		$data['settings'][] = array(
			'type'		=> 'html',
			'content'	=> '<div class="text-info text-center pad-bottom-sm">' . $data['help_order_statuses'] . '</div>',
		);
		$data['settings'][] = array(
			'key'		=> 'order_statuses',
			'type'		=> 'heading',
		);
		
		$processing_status_id = $this->config->get('config_processing_status');
		$processing_status_id = $processing_status_id[0];
		
		$data['settings'][] = array(
			'key'		=> 'success_status_id',
			'type'		=> 'select',
			'options'	=> $data['order_status_array'],
			'default'	=> $processing_status_id,
		);
		$data['settings'][] = array(
			'key'		=> 'authorize_status_id',
			'type'		=> 'select',
			'options'	=> $data['order_status_array'],
			'default'	=> $processing_status_id,
		);
		
		foreach (array('error', 'street', 'zip', 'cvc', 'refund', 'partial') as $order_status) {
			$default_status = ($order_status == 'error') ? 10 : 0;
			$data['settings'][] = array(
				'key'		=> $order_status . '_status_id',
				'type'		=> 'select',
				'options'	=> $data['order_status_array'],
				'default'	=> $default_status,
			);
		}
		
		//------------------------------------------------------------------------------
		// Restrictions
		//------------------------------------------------------------------------------
		$data['settings'][] = array(
			'key'		=> 'restrictions',
			'type'		=> 'tab',
		);
		$data['settings'][] = array(
			'type'		=> 'html',
			'content'	=> '<div class="text-info text-center pad-bottom-sm">' . $data['help_restrictions'] . '</div>',
		);
		$data['settings'][] = array(
			'key'		=> 'restrictions',
			'type'		=> 'heading',
		);
		$data['settings'][] = array(
			'key'		=> 'min_total',
			'type'		=> 'text',
			'attributes'=> array('style' => 'width: 50px !important'),
			'default'	=> '0.50',
		);
		$data['settings'][] = array(
			'key'		=> 'max_total',
			'type'		=> 'text',
			'attributes'=> array('style' => 'width: 50px !important'),
		);
		$data['settings'][] = array(
			'key'		=> 'stores',
			'type'		=> 'checkboxes',
			'options'	=> $data['store_array'],
			'default'	=> array_keys($data['store_array']),
		);
		$data['settings'][] = array(
			'key'		=> 'geo_zones',
			'type'		=> 'checkboxes',
			'options'	=> $data['geo_zone_array'],
			'default'	=> array_keys($data['geo_zone_array']),
		);
		$data['settings'][] = array(
			'key'		=> 'customer_groups',
			'type'		=> 'checkboxes',
			'options'	=> $data['customer_group_array'],
			'default'	=> array_keys($data['customer_group_array']),
		);
		
		// Currency Settings
		$data['settings'][] = array(
			'key'		=> 'currency_settings',
			'type'		=> 'heading',
		);
		$data['settings'][] = array(
			'type'		=> 'html',
			'content'	=> '<div class="text-info text-center pad-bottom">' . $data['help_currency_settings'] . '</div>',
		);
		foreach ($data['currency_array'] as $code => $title) {
			$data['settings'][] = array(
				'key'		=> 'currencies_' . $code,
				'title'		=> str_replace('[currency]', $code, $data['entry_currencies']),
				'type'		=> 'select',
				'options'	=> array_merge(array(0 => $data['text_currency_disabled']), $data['currency_array']),
				'default'	=> $this->config->get('config_currency'),
			);
		}
		
		//------------------------------------------------------------------------------
		// Stripe Settings
		//------------------------------------------------------------------------------
		$data['settings'][] = array(
			'key'		=> 'stripe_settings',
			'type'		=> 'tab',
		);
		$data['settings'][] = array(
			'type'		=> 'html',
			'content'	=> '<div class="text-info text-center pad-bottom-sm">' . $data['help_stripe_settings'] . '</div>',
		);
		
		// API Keys
		$data['settings'][] = array(
			'key'		=> 'api_keys',
			'type'		=> 'heading',
		);
		$data['settings'][] = array(
			'key'		=> 'test_publishable_key',
			'type'		=> 'text',
			'attributes'=> array('onchange' => '$(this).val($(this).val().trim())', 'style' => 'width: 350px !important'),
		);
		$data['settings'][] = array(
			'key'		=> 'test_secret_key',
			'type'		=> 'text',
			'attributes'=> array('onchange' => '$(this).val($(this).val().trim())', 'style' => 'width: 350px !important'),
		);
		$data['settings'][] = array(
			'key'		=> 'live_publishable_key',
			'type'		=> 'text',
			'attributes'=> array('onchange' => '$(this).val($(this).val().trim())', 'style' => 'width: 350px !important'),
		);
		$data['settings'][] = array(
			'key'		=> 'live_secret_key',
			'type'		=> 'text',
			'attributes'=> array('onchange' => '$(this).val($(this).val().trim())', 'style' => 'width: 350px !important'),
		);
		
		// Stripe Settings
		$data['settings'][] = array(
			'key'		=> 'stripe_settings',
			'type'		=> 'heading',
		);
		unset($data['saved']['webhook_url']);
		$data['settings'][] = array(
			'key'		=> 'webhook_url',
			'type'		=> 'text',
			'default'	=> str_replace('http:', 'https:', HTTP_CATALOG) . 'index.php?route=extension/' . $this->type . '/' . $this->name . '/webhook&key=' . md5($this->config->get('config_encryption')),
			'attributes'=> array('readonly' => 'readonly', 'onclick' => 'this.select()', 'style' => 'background: #EEE; cursor: pointer; font-family: monospace; width: 100% !important;'),
		);
		$data['settings'][] = array(
			'key'		=> 'transaction_mode',
			'type'		=> 'select',
			'options'	=> array('test' => $data['text_test'], 'live' => $data['text_live']),
		);
		$data['settings'][] = array(
			'key'		=> 'charge_mode',
			'type'		=> 'select',
			'options'	=> array('authorize' => $data['text_authorize'], 'capture' => $data['text_capture'], 'fraud' => $data['text_fraud_authorize']),
			'default'	=> 'capture',
		);
		$data['settings'][] = array(
			'key'		=> 'transaction_description',
			'type'		=> 'text',
			'default'	=> '[store]: Order #[order_id] ([amount], [email])',
		);
		$data['settings'][] = array(
			'key'		=> 'send_customer_data',
			'type'		=> 'select',
			'options'	=> array('never' => $data['text_never'], 'choice' => $data['text_customers_choice'], 'always' => $data['text_always']),
		);
		$data['settings'][] = array(
			'key'		=> 'allow_stored_cards',
			'type'		=> 'select',
			'options'	=> array(0 => $data['text_no'], 1 => $data['text_yes']),
		);
		$data['settings'][] = array(
			'key'		=> 'always_send_receipts',
			'type'		=> 'select',
			'options'	=> array(0 => $data['text_no'], 1 => $data['text_yes']),
		);
		
		// Payment Request Button (Pro-specific)
		$data['settings'][] = array(
			'key'		=> 'payment_request_button',
			'type'		=> 'heading',
		);
		$data['settings'][] = array(
			'key'		=> 'payment_request_button',
			'type'		=> 'select',
			'options'	=> array(0 => $data['text_no'], 1 => $data['text_yes']),
			'default'	=> 0,
		);
		$data['settings'][] = array(
			'key'		=> 'payment_request_label',
			'type'		=> 'multilingual_text',
			'default'	=> $this->request->server['HTTP_HOST'],
		);
		$data['settings'][] = array(
			'key'		=> 'payment_request_applepay',
			'type'		=> 'multilingual_text',
			'default'	=> 'Apple Pay',
		);
		$data['settings'][] = array(
			'key'		=> 'payment_request_browserandroid',
			'type'		=> 'multilingual_text',
			'default'	=> 'Browser/Android Pay',
		);
		
		// 3D Secure Settings (Pro-specific)
		$data['settings'][] = array(
			'key'		=> 'three_d_secure_settings',
			'type'		=> 'heading',
		);
		$data['settings'][] = array(
			'key'		=> 'three_d_secure',
			'type'		=> 'select',
			'options'	=> array(0 => $data['text_no'], 'allow' => $data['text_yes_allow_ineligible_cards'], 'deny' => $data['text_yes_deny_ineligible_cards']),
		);
		$data['settings'][] = array(
			'key'		=> 'error_three_d_ineligible',
			'type'		=> 'multilingual_text',
			'attributes'=> array('style' => 'width: 600px !important'),
			'default'	=> 'Your card has is not eligibile for 3D Secure, which is required. Please use a different card for payment.',
		);
		$data['settings'][] = array(
			'key'		=> 'three_d_error_page',
			'type'		=> 'multilingual_textarea',
			'attributes'=> array('style' => 'font-family: monospace; height: 180px; width: 600px !important'),
			'default'	=> '
[header]
<div class="container" style="font-size: 18px; min-height: 600px; text-align: center;">
	<div style="color: red; margin: 20px">
		<b>Error:</b> [error]
	</div>
	<a href="' . HTTPS_CATALOG . 'index.php?route=checkout/checkout">
		Return to checkout
	</a>
</div>
[footer]
			',
		);
		
		//------------------------------------------------------------------------------
		// Stripe Checkout
		//------------------------------------------------------------------------------
		$data['settings'][] = array(
			'key'		=> 'stripe_checkout',
			'type'		=> 'tab',
		);
		$data['settings'][] = array(
			'type'		=> 'html',
			'content'	=> '<div class="text-info text-center pad-bottom-sm">' . $data['help_stripe_checkout'] . '</div>',
		);
		$data['settings'][] = array(
			'key'		=> 'stripe_checkout',
			'type'		=> 'heading',
		);
		$data['settings'][] = array(
			'key'		=> 'use_checkout',
			'type'		=> 'select',
			'options'	=> array(0 => $data['text_no'], 'all' => $data['text_yes'], 'desktop' => $data['text_yes_for_desktop_devices']),
			'default'	=> '0',
		);
		$data['settings'][] = array(
			'key'		=> 'checkout_remember_me',
			'type'		=> 'select',
			'options'	=> array(1 => $data['text_yes'], 0 => $data['text_no']),
			'default'	=> 1,
		);
		$data['settings'][] = array(
			'key'		=> 'checkout_alipay',
			'type'		=> 'select',
			'options'	=> array(1 => $data['text_yes'], 0 => $data['text_no']),
			'default'	=> 0,
		);
		$data['settings'][] = array(
			'key'		=> 'checkout_bitcoin',
			'type'		=> 'select',
			'options'	=> array(1 => $data['text_yes'], 0 => $data['text_no']),
			'default'	=> 0,
		);
		$data['settings'][] = array(
			'key'		=> 'checkout_billing',
			'type'		=> 'select',
			'options'	=> array(1 => $data['text_yes'], 0 => $data['text_no']),
			'default'	=> 1,
		);
		$data['settings'][] = array(
			'key'		=> 'checkout_shipping',
			'type'		=> 'select',
			'options'	=> array(1 => $data['text_yes'], 0 => $data['text_no']),
			'default'	=> 0,
		);
		$data['settings'][] = array(
			'key'		=> 'checkout_image',
			'type'		=> 'text',
			'before'	=> HTTP_CATALOG . 'image/',
			'default'	=> 'no_image.png',
		);
		$data['settings'][] = array(
			'key'		=> 'checkout_title',
			'type'		=> 'multilingual_text',
			'default'	=> '[store]',
		);
		$data['settings'][] = array(
			'key'		=> 'checkout_description',
			'type'		=> 'multilingual_text',
			'default'	=> 'Order #[order_id] ([amount])',
		);
		$data['settings'][] = array(
			'key'		=> 'checkout_button',
			'type'		=> 'multilingual_text',
			'default'	=> 'Pay [amount]',
		);
		$data['settings'][] = array(
			'key'		=> 'quick_checkout',
			'type'		=> 'html',
			'content'	=> '
				<div class="well" style="padding: 10px">
					You can add a "quick checkout" feature to your store by placing the Stripe Checkout button on other pages besides the checkout confirm page. The customer can enter their e-mail, payment address, shipping address, and credit card details all through the pop-up, and an order will be properly created in OpenCart.<br /><br /><strong>You must only use this on SSL-enabled (https) pages.</strong> To see an example of how to do this on the cart page, <a href="#quick-checkout-modal" data-toggle="modal">click here</a>.
					<div id="quick-checkout-modal" class="modal fade" style="text-align: left">
						<div class="modal-dialog">
							<div class="modal-content">
								<div class="modal-header">
									<a class="close" data-dismiss="modal">&times;</a>
									<h4 class="modal-title">Quick Checkout Example</h4>
								</div>
								<div class="modal-body">
									In the default theme, you would use these edits to add a Quick Checkout button in place of the regular "Checkout" button on the cart page:<br /><br />
									<pre style="white-space: pre-line">
									IN:
									/catalog/controller/checkout/cart.php

									AFTER:
									public function index() {
										
									ADD:
									$data[\'quick_checkout_button\'] = $this->load->controller(\'payment/stripe/embed\');
									</pre>
									<br />
									<pre style="white-space: pre-line">
									IN:
									/catalog/view/theme/default/template/checkout/cart.tpl or .twig

									REPLACE THE CODE BLOCK:
									&lt;div class="buttons"&gt;
									   ...
									&lt;/div&gt;

									WITH:
									&lt;?php echo $quick_checkout_button; ?&gt;
									
									OR FOR TWIG FILES:
									{{ quick_checkout_button }}
									</pre>
								</div>
								<div class="modal-footer">
									<a href="#" class="btn btn-default" data-dismiss="modal"><i class="fa fa-times"></i> Close</a>
								</div>
							</div>
						</div>
					</div>
				</div>
			',
		);
				
		//------------------------------------------------------------------------------
		// Subscription Products
		//------------------------------------------------------------------------------
		$data['settings'][] = array(
			'key'		=> 'subscription_products',
			'type'		=> 'tab',
		);
		$data['settings'][] = array(
			'type'		=> 'html',
			'content'	=> '<div class="text-info pad-left pad-bottom-sm">' . $data['help_subscription_products'] . '</div>',
		);
		$data['settings'][] = array(
			'key'		=> 'subscription_products',
			'type'		=> 'heading',
		);
		$data['settings'][] = array(
			'key'		=> 'subscriptions',
			'type'		=> 'select',
			'options'	=> array(1 => $data['text_yes'], 0 => $data['text_no']),
			'default'	=> 0,
		);
		$data['settings'][] = array(
			'key'		=> 'prevent_guests',
			'type'		=> 'select',
			'options'	=> array(1 => $data['text_yes'], 0 => $data['text_no']),
			'default'	=> 0,
		);
		
		// Pro-specific
		$data['settings'][] = array(
			'key'		=> 'include_shipping',
			'type'		=> 'select',
			'options'	=> array(1 => $data['text_yes'], 0 => $data['text_no']),
			'default'	=> 0,
		);
		$data['settings'][] = array(
			'key'		=> 'allow_customers_to_cancel',
			'type'		=> 'select',
			'options'	=> array(1 => $data['text_yes'], 0 => $data['text_no']),
			'default'	=> 1,
		);
		
		// Current Subscription Products
		$data['settings'][] = array(
			'key'		=> 'current_subscriptions',
			'type'		=> 'heading',
		);
		$subscription_products_table = '
			<div class="form-group">
				<label class="control-label col-sm-3">' . str_replace('[transaction_mode]', ucwords(isset($data['saved']['transaction_mode']) ? $data['saved']['transaction_mode'] : 'test'), $data['entry_current_subscriptions']) . '</label>
				<div class="col-sm-9">
					<br />
					<table class="table table-stripe table-bordered">
						<thead>
							<tr>
								<td colspan="3" style="text-align: center">' . $data['text_thead_opencart'] . '</td>
								<td colspan="3" style="text-align: center">' . $data['text_thead_stripe'] . '</td>
							</tr>
							<tr>
								<td class="left">' . $data['text_product_name'] . '</td>
								<td class="left">' . $data['text_product_price'] . '</td>
								<td class="left">' . $data['text_location_plan_id'] . '</td>
								<td class="left">' . $data['text_plan_name'] . '</td>
								<td class="left">' . $data['text_plan_interval'] . '</td>
								<td class="left">' . $data['text_plan_charge'] . '</td>
							</tr>
						</thead>
		';
		if (empty($data['subscription_products'])) {
			$subscription_products_table .= '
				<tr><td class="center" colspan="6">' . $data['text_no_subscription_products'] . '</td></tr>
				<tr><td class="center" colspan="6">' . $data['text_create_one_by_entering'] . '</td></tr>
			';
		}
		foreach ($data['subscription_products'] as $product) {
			$highlight = ($product['price'] == $product['charge']) ? '' : 'style="background: #FDD"';
			$subscription_products_table .= '
				<tr>
					<td class="left"><a target="_blank" href="index.php?route=catalog/product/edit&amp;product_id=' . $product['product_id'] . '&amp;token=' . $data['token'] . '">' . $product['name'] . '</a></td>
					<td class="left" ' . $highlight . '>' . $product['price'] . '</td>
					<td class="left">' . $product['location'] . '</td>
					<td class="left">' . $product['plan'] . '</td>
					<td class="left">' . $product['interval'] . '</td>
					<td class="left" ' . $highlight . '>' . $product['charge'] . '</td>
				</tr>
			';
		}
		$subscription_products_table .= '</table></div></div><br />';
		
		$data['settings'][] = array(
			'type'		=> 'html',
			'content'	=> $subscription_products_table,
		);
		
		// Map Options to Subscriptions (Pro-specific)
		$data['settings'][] = array(
			'key'		=> 'map_options',
			'type'		=> 'heading',
		);
		$data['settings'][] = array(
			'type'		=> 'html',
			'content'	=> '<div class="text-info text-center" style="margin-bottom: 30px">' . $data['help_map_options'] . '</div>',
		);
		
		$table = 'subscription_options';
		$sortby = 'option_name';
		$data['settings'][] = array(
			'key'		=> $table,
			'type'		=> 'table_start',
			'columns'	=> array('action', 'option_name', 'option_value', 'plan_id'),
		);
		foreach ($this->getTableRowNumbers($data, $table, $sortby) as $num => $rules) {
			$prefix = $table . '_' . $num . '_';
			$data['settings'][] = array(
				'type'		=> 'row_start',
			);
			$data['settings'][] = array(
				'key'		=> 'delete',
				'type'		=> 'button',
			);
			$data['settings'][] = array(
				'type'		=> 'column',
			);
			$data['settings'][] = array(
				'key'		=> $prefix . 'option_name',
				'type'		=> 'text',
			);
			$data['settings'][] = array(
				'type'		=> 'column',
			);
			$data['settings'][] = array(
				'key'		=> $prefix . 'option_value',
				'type'		=> 'text',
			);
			$data['settings'][] = array(
				'type'		=> 'column',
			);
			$data['settings'][] = array(
				'key'		=> $prefix . 'plan_id',
				'type'		=> 'text',
			);
			$data['settings'][] = array(
				'type'		=> 'row_end',
			);
		}
		$data['settings'][] = array(
			'type'		=> 'table_end',
			'buttons'	=> 'add_row',
			'text'		=> 'button_add_mapping',
		);
		$data['settings'][] = array(
			'type'		=> 'html',
			'content'	=> '<br />',
		);
		
		// Map Recurring Profiles to Subscriptions (Pro-specific)
		$data['settings'][] = array(
			'key'		=> 'map_recurring_profiles',
			'type'		=> 'heading',
		);
		$data['settings'][] = array(
			'type'		=> 'html',
			'content'	=> '<div class="text-info text-center" style="margin-bottom: 30px">' . $data['help_map_recurring_profiles'] . '</div>',
		);
		
		$table = 'subscription_profiles';
		$sortby = 'profile_name';
		$data['settings'][] = array(
			'key'		=> $table,
			'type'		=> 'table_start',
			'columns'	=> array('action', 'profile_name', 'plan_id'),
		);
		foreach ($this->getTableRowNumbers($data, $table, $sortby) as $num => $rules) {
			$prefix = $table . '_' . $num . '_';
			$data['settings'][] = array(
				'type'		=> 'row_start',
			);
			$data['settings'][] = array(
				'key'		=> 'delete',
				'type'		=> 'button',
			);
			$data['settings'][] = array(
				'type'		=> 'column',
			);
			$data['settings'][] = array(
				'key'		=> $prefix . 'profile_name',
				'type'		=> 'text',
			);
			$data['settings'][] = array(
				'type'		=> 'column',
			);
			$data['settings'][] = array(
				'key'		=> $prefix . 'plan_id',
				'type'		=> 'text',
			);
			$data['settings'][] = array(
				'type'		=> 'row_end',
			);
		}
		$data['settings'][] = array(
			'type'		=> 'table_end',
			'buttons'	=> 'add_row',
			'text'		=> 'button_add_mapping',
		);
		
		//------------------------------------------------------------------------------
		// Create a Charge
		//------------------------------------------------------------------------------
		// Pro-specific
		$data['settings'][] = array(
			'key'		=> 'create_a_charge',
			'type'		=> 'tab',
		);
		
		$settings = $data['saved'];
		$language = $this->config->get('config_language');
		
		ob_start();
		$filepath = DIR_APPLICATION . 'view/template/extension/payment/' . $this->name . '_card_form.twig';
		include_once(class_exists('VQMod') ? VQMod::modCheck(modification($filepath)) : modification($filepath));
		$tpl_contents = ob_get_contents();
		ob_end_clean();
		
		$data['settings'][] = array(
			'type'		=> 'html',
			'content'	=> $tpl_contents,
		);
		
		//------------------------------------------------------------------------------
		// end settings
		//------------------------------------------------------------------------------
		
		$this->document->setTitle($data['heading_title']);
		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');
		
		$template_file = DIR_TEMPLATE . 'extension/' . $this->type . '/' . $this->name . '.twig';
		
		if (is_file($template_file)) {
			extract($data);
			
			ob_start();
			require(class_exists('VQMod') ? VQMod::modCheck(modification($template_file)) : modification($template_file));
			$output = ob_get_clean();
			
			if (version_compare(VERSION, '3.0', '>=')) {
				$output = str_replace('&token=', '&user_token=', $output);
			}
			
			echo $output;
		} else {
			echo 'Error loading template file';
		}
	}
	
	//==============================================================================
	// Helper functions
	//==============================================================================
	private function hasPermission($permission) {
		return ($this->user->hasPermission($permission, $this->type . '/' . $this->name) || $this->user->hasPermission($permission, 'extension/' . $this->type . '/' . $this->name));
	}
	
	private function loadLanguage($path) {
		$_ = array();
		$language = array();
		$admin_language = (version_compare(VERSION, '2.2', '<')) ? $this->db->query("SELECT * FROM " . DB_PREFIX . "language WHERE `code` = '" . $this->db->escape($this->config->get('config_admin_language')) . "'")->row['directory'] : $this->config->get('config_admin_language');
		foreach (array('english', 'en-gb', $admin_language) as $directory) {
			$file = DIR_LANGUAGE . $directory . '/' . $directory . '.php';
			if (file_exists($file)) require($file);
			$file = DIR_LANGUAGE . $directory . '/default.php';
			if (file_exists($file)) require($file);
			$file = DIR_LANGUAGE . $directory . '/' . $path . '.php';
			if (file_exists($file)) require($file);
			$file = DIR_LANGUAGE . $directory . '/extension/' . $path . '.php';
			if (file_exists($file)) require($file);
			$language = array_merge($language, $_);
		}
		return $language;
	}
	
	private function getTableRowNumbers(&$data, $table, $sorting) {
		$groups = array();
		$rules = array();
		
		foreach ($data['saved'] as $key => $setting) {
			if (preg_match('/' . $table . '_(\d+)_' . $sorting . '/', $key, $matches)) {
				$groups[$setting][] = $matches[1];
			}
			if (preg_match('/' . $table . '_(\d+)_rule_(\d+)_type/', $key, $matches)) {
				$rules[$matches[1]][] = $matches[2];
			}
		}
		
		if (empty($groups)) $groups = array('' => array('1'));
		ksort($groups, defined('SORT_NATURAL') ? SORT_NATURAL : SORT_REGULAR);
		
		foreach ($rules as $key => $rule) {
			ksort($rules[$key], defined('SORT_NATURAL') ? SORT_NATURAL : SORT_REGULAR);
		}
		
		$data['used_rows'][$table] = array();
		$rows = array();
		foreach ($groups as $group) {
			foreach ($group as $num) {
				$data['used_rows'][preg_replace('/module_(\d+)_/', '', $table)][] = $num;
				$rows[$num] = (empty($rules[$num])) ? array() : $rules[$num];
			}
		}
		sort($data['used_rows'][$table]);
		
		return $rows;
	}
	
	//==============================================================================
	// Setting functions
	//==============================================================================
	private $encryption_key = '';
	
	public function loadSettings(&$data) {
		$backup_type = (empty($data)) ? 'manual' : 'auto';
		if ($backup_type == 'manual' && !$this->hasPermission('modify')) {
			return;
		}
		
		$this->cache->delete($this->name);
		unset($this->session->data[$this->name]);
		$code = (version_compare(VERSION, '3.0', '<') ? '' : $this->type . '_') . $this->name;
		
		// Set exit URL
		$data['token'] = $this->session->data[version_compare(VERSION, '3.0', '<') ? 'token' : 'user_token'];
		$data['exit'] = $this->url->link((version_compare(VERSION, '3.0', '<') ? 'extension' : 'marketplace') . '/' . (version_compare(VERSION, '2.3', '<') ? '' : 'extension&type=') . $this->type . '&token=' . $data['token'], '', 'SSL');
		
		// Load saved settings
		$data['saved'] = array();
		$settings_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "setting WHERE `code` = '" . $this->db->escape($code) . "' ORDER BY `key` ASC");
		
		foreach ($settings_query->rows as $setting) {
			$key = str_replace($code . '_', '', $setting['key']);
			$value = $setting['value'];
			if ($setting['serialized']) {
				$value = (version_compare(VERSION, '2.1', '<')) ? unserialize($setting['value']) : json_decode($setting['value'], true);
			}
			
			$data['saved'][$key] = $value;
			
			if (is_array($value)) {
				foreach ($value as $num => $value_array) {
					foreach ($value_array as $k => $v) {
						$data['saved'][$key . '_' . $num . '_' . $k] = $v;
					}
				}
			}
		}
		
		// Load language and run standard checks
		$data = array_merge($data, $this->loadLanguage($this->type . '/' . $this->name));
		
		if (ini_get('max_input_vars') && ((ini_get('max_input_vars') - count($data['saved'])) < 50)) {
			$data['warning'] = $data['standard_max_input_vars'];
		}
		
		// Modify files according to OpenCart version
		if ($this->type == 'total' && version_compare(VERSION, '2.2', '<')) {
			file_put_contents(DIR_CATALOG . 'model/' . $this->type . '/' . $this->name . '.php', str_replace('public function getTotal($total) {', 'public function getTotal(&$total_data, &$order_total, &$taxes) {' . "\n\t\t" . '$total = array("totals" => &$total_data, "total" => &$order_total, "taxes" => &$taxes);', file_get_contents(DIR_CATALOG . 'model/' . $this->type . '/' . $this->name . '.php')));
		}
		
		if (version_compare(VERSION, '2.3', '>=')) {
			$filepaths = array(
				DIR_APPLICATION . 'controller/' . $this->type . '/' . $this->name . '.php',
				DIR_CATALOG . 'controller/' . $this->type . '/' . $this->name . '.php',
				DIR_CATALOG . 'model/' . $this->type . '/' . $this->name . '.php',
			);
			foreach ($filepaths as $filepath) {
				if (file_exists($filepath)) {
					rename($filepath, str_replace('.php', '.php-OLD', $filepath));
				}
			}
		}
		
		// Set save type and skip auto-backup if not needed
		if (!empty($data['saved']['autosave'])) {
			$data['save_type'] = 'auto';
		}
		
		if ($backup_type == 'auto' && empty($data['autobackup'])) {
			return;
		}
		
		// Create settings auto-backup file
		$manual_filepath = DIR_LOGS . $this->name . $this->encryption_key . '.backup';
		$auto_filepath = DIR_LOGS . $this->name . $this->encryption_key . '.autobackup';
		$filepath = ($backup_type == 'auto') ? $auto_filepath : $manual_filepath;
		if (file_exists($filepath)) unlink($filepath);
		
		file_put_contents($filepath, 'SETTING	NUMBER	SUB-SETTING	SUB-NUMBER	SUB-SUB-SETTING	VALUE' . "\n", FILE_APPEND|LOCK_EX);
		
		foreach ($data['saved'] as $key => $value) {
			if (is_array($value)) continue;
			
			$parts = explode('|', preg_replace(array('/_(\d+)_/', '/_(\d+)/'), array('|$1|', '|$1'), $key));
			
			$line = '';
			for ($i = 0; $i < 5; $i++) {
				$line .= (isset($parts[$i]) ? $parts[$i] : '') . "\t";
			}
			$line .= str_replace(array("\t", "\n"), array('    ', '\n'), $value) . "\n";
			
			file_put_contents($filepath, $line, FILE_APPEND|LOCK_EX);
		}
		
		$data['autobackup_time'] = date('Y-M-d @ g:i a');
		$data['backup_time'] = (file_exists($manual_filepath)) ? date('Y-M-d @ g:i a', filemtime($manual_filepath)) : '';
		
		if ($backup_type == 'manual') {
			echo $data['autobackup_time'];
		}
	}
	
	public function saveSettings() {
		if (!$this->hasPermission('modify')) {
			echo 'PermissionError';
			return;
		}
		
		$this->cache->delete($this->name);
		unset($this->session->data[$this->name]);
		$code = (version_compare(VERSION, '3.0', '<') ? '' : $this->type . '_') . $this->name;
		
		if ($this->request->get['saving'] == 'manual') {
			$this->db->query("DELETE FROM " . DB_PREFIX . "setting WHERE `code` = '" . $this->db->escape($code) . "' AND `key` != '" . $this->db->escape($this->name . '_module') . "'");
		}
		
		$module_id = 0;
		$modules = array();
		$module_instance = false;
		
		foreach ($this->request->post as $key => $value) {
			if (strpos($key, 'module_') === 0) {
				$parts = explode('_', $key, 3);
				$module_id = $parts[1];
				$modules[$parts[1]][$parts[2]] = $value;
				if ($parts[2] == 'module_id') $module_instance = true;
			} else {
				$key = (version_compare(VERSION, '3.0', '<') ? '' : $this->type . '_') . $this->name . '_' . $key;
				
				if ($this->request->get['saving'] == 'auto') {
					$this->db->query("DELETE FROM " . DB_PREFIX . "setting WHERE `code` = '" . $this->db->escape($code) . "' AND `key` = '" . $this->db->escape($key) . "'");
				}
				
				$this->db->query("
					INSERT INTO " . DB_PREFIX . "setting SET
					`store_id` = 0,
					`code` = '" . $this->db->escape($code) . "',
					`key` = '" . $this->db->escape($key) . "',
					`value` = '" . $this->db->escape(stripslashes(is_array($value) ? implode(';', $value) : $value)) . "',
					`serialized` = 0
				");
			}
		}
		
		foreach ($modules as $module_id => $module) {
			if (!$module_id) {
				$this->db->query("
					INSERT INTO " . DB_PREFIX . "module SET
					`name` = '" . $this->db->escape($module['name']) . "',
					`code` = '" . $this->db->escape($this->name) . "',
					`setting` = ''
				");
				$module_id = $this->db->getLastId();
				$module['module_id'] = $module_id;
			}
			$module_settings = (version_compare(VERSION, '2.1', '<')) ? serialize($module) : json_encode($module);
			$this->db->query("
				UPDATE " . DB_PREFIX . "module SET
				`name` = '" . $this->db->escape($module['name']) . "',
				`code` = '" . $this->db->escape($this->name) . "',
				`setting` = '" . $this->db->escape($module_settings) . "'
				WHERE module_id = " . (int)$module_id . "
			");
		}
	}
	
	public function deleteSetting() {
		if (!$this->hasPermission('modify')) {
			echo 'PermissionError';
			return;
		}
		$this->db->query("DELETE FROM " . DB_PREFIX . "setting WHERE `code` = '" . $this->db->escape($this->name) . "' AND `key` = '" . $this->db->escape($this->name . '_' . str_replace('[]', '', $this->request->get['setting'])) . "'");
	}
	
	//==============================================================================
	// Custom functions
	//==============================================================================
	private function curlRequest($request, $api, $data = array()) {
		$settings = array('autobackup' => false);
		$this->loadSettings($settings);
		$settings = $settings['saved'];
		
		$url = 'https://api.stripe.com/v1/';
		
		if ($request == 'GET') {
			$curl = curl_init($url . $api . '?' . http_build_query($data));
		} else {
			$curl = curl_init($url . $api);
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
			if ($request != 'POST') {
				curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $request);
			}
		}
		
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($curl, CURLOPT_FORBID_REUSE, true);
		curl_setopt($curl, CURLOPT_FRESH_CONNECT, true);
		curl_setopt($curl, CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array('Stripe-Version: 2016-07-06'));
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		if ($settings['transaction_mode'] == 'live') {
			//curl_setopt($curl, CURLOPT_SSLVERSION, 6);
		}
		curl_setopt($curl, CURLOPT_TIMEOUT, 30);
		curl_setopt($curl, CURLOPT_USERPWD, $settings[$settings['transaction_mode'] . '_secret_key'] . ':');
		
		$response = json_decode(curl_exec($curl), true);
		
		if (curl_error($curl)) {
			$response = array('error' => array('message' => 'CURL ERROR: ' . curl_errno($curl) . '::' . curl_error($curl)));
			$this->log->write('STRIPE CURL ERROR: ' . curl_errno($curl) . '::' . curl_error($curl));	
		} elseif (empty($response)) {
			$response = array('error' => array('message' => 'CURL ERROR: Empty Gateway Response'));
			$this->log->write('STRIPE CURL ERROR: Empty Gateway Response');
		}
		curl_close($curl);
		
		if (!empty($response['error']['code']) && !empty($settings['error_' . $response['error']['code']])) {
			$response['error']['message'] = html_entity_decode($settings['error_' . $response['error']['code']], ENT_QUOTES, 'UTF-8');
		}
		
		return $response;
	}
	
	public function capture() {
		$capture_response = $this->curlRequest('POST', 'charges/' . $this->request->get['charge_id'] . '/capture');
		if (!empty($capture_response['error'])) {
			$this->log->write('STRIPE ERROR: ' . $capture_response['error']['message']);
			echo 'Error: ' . $capture_response['error']['message'];
		}
		if (empty($capture_response['error']) || strpos($capture_response['error']['message'], 'has already been captured')) {
			$this->db->query("UPDATE " . DB_PREFIX . "order_history SET `comment` = REPLACE(`comment`, '<span>No &nbsp;</span> <a onclick=\"capture($(this), \'" . $this->db->escape($this->request->get['charge_id']) . "\')\">(Capture)</a>', 'Yes') WHERE `comment` LIKE '%capture($(this), \'" . $this->db->escape($this->request->get['charge_id']) . "\')%'");
		}
	}
	
	public function refund() {
		$refund_response = $this->curlRequest('POST', 'charges/' . $this->request->get['charge_id'] . '/refunds', array('amount' => $this->request->get['amount'] * 100));
		if (!empty($refund_response['error'])) {
			$this->log->write('STRIPE ERROR: ' . $refund_response['error']['message']);
			echo 'Error: ' . $refund_response['error']['message'];
		}
	}
	
	public function getCustomerCards() {
		$settings = array('autobackup' => false);
		$this->loadSettings($settings);
		$settings = $settings['saved'];
		
		$customer_id_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "stripe_customer WHERE customer_id = " . (int)$this->request->get['id'] . " AND transaction_mode = '" . $this->db->escape($settings['transaction_mode']) . "'");
		if (!$customer_id_query->num_rows) {
			echo '(no stored cards)';
			return;
		}
		
		$stripe_customer_id = $customer_id_query->row['stripe_customer_id'];
		$customer_response = $this->curlRequest('GET', 'customers/' . $stripe_customer_id);
		
		if (!empty($customer_response['deleted'])) {
			$this->db->query("DELETE FROM " . DB_PREFIX . "stripe_customer WHERE stripe_customer_id = '" . $this->db->escape($stripe_customer_id) . "'");
		} elseif (!empty($customer_response['error'])) {
			echo $customer_response['error']['message'];
			$this->log->write('STRIPE ERROR: ' . $customer_response['error']['message']);
			return;
		} else {
			$sources = $customer_response['sources']['data'];
		}
		
		if (empty($sources)) {
			echo '(no stored cards)';
		} else {
			foreach ($sources as $source) {
				if ($source['object'] == 'card' && $source['id'] == $customer_response['default_source']) {
					echo '<input type="hidden" value="' . $customer_response['id'] . '" />';
					echo '<b>' . $source['brand'] . ' ' . $settings['text_ending_in_' . $this->config->get('config_language')] . ' ' . $source['last4'] . ' (' . str_pad($source['exp_month'], 2, '0', STR_PAD_LEFT) . '/' . substr($source['exp_year'], 2) . ')</b>';
				}
			}
		}
	}
	
	public function chargeCard() {
		$settings = array('autobackup' => false);
		$this->loadSettings($settings);
		$settings = $settings['saved'];
		
		$currency = $this->request->post['currency'];
		$main_currency = $this->db->query("SELECT * FROM " . DB_PREFIX . "setting WHERE `key` = 'config_currency' AND store_id = 0")->row['value'];
		$decimal_factor = (in_array($settings['currencies_' . $currency], array('BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','VND','VUV','XAF','XOF','XPF'))) ? 1 : 100;
		
		$data = array(
			'amount'		=> round($decimal_factor * $this->currency->convert($this->request->post['amount'], $main_currency, $settings['currencies_' . $currency])),
			'currency'		=> $settings['currencies_' . $currency],
			'description'	=> $this->request->post['description'],
			'metadata'		=> array(
				'Store'			=> $this->config->get('config_name'),
				'Order ID'		=> $this->request->post['order_id'],
			),
		);
		
		if (!empty($this->request->post['statement_descriptor'])) {
			$data['statement_descriptor'] = $this->request->post['statement_descriptor'];
		}
		
		if (!empty($this->request->post['token'])) {
			$data['source'] = $this->request->post['token'];
		} elseif (!empty($this->request->post['customer'])) {
			$data['customer'] = $this->request->post['customer'];
		}
		
		foreach ($data['metadata'] as &$metadata) {
			if (strlen($metadata) > 197) {
				$metadata = mb_substr($metadata, 0, 197, 'UTF-8') . '...';
			}
		}
		
		$charge_response = $this->curlRequest('POST', 'charges', $data);
		
		if (!empty($charge_response['error'])) {
			echo 'Error: ' . $charge_response['error']['message'];
		} else {
			$order_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE order_id = " . (int)$this->request->post['order_id']);
			
			if ($order_query->num_rows) {
				$comment = 'Paid ' . $this->currency->format($this->request->post['amount'], $currency) . ' via Stripe (charge ID ' . $charge_response['id'] . ')';
				$order_status_id = (!empty($this->request->post['order_status'])) ? $this->request->post['order_status'] : $order_query->row['order_status_id'];
				
				$this->db->query("UPDATE `" . DB_PREFIX . "order` SET order_status_id = " . (int)$order_status_id . ", date_modified = NOW() WHERE order_id = " . (int)$this->request->post['order_id']);
				$this->db->query("INSERT INTO " . DB_PREFIX . "order_history SET order_id = " . (int)$this->request->post['order_id'] . ", order_status_id = " . (int)$order_status_id . ", notify = 0, comment = '" . $this->db->escape($comment) . "', date_added = NOW()");
			}
			
			echo $charge_response['id'];
		}
	}
	
	//==============================================================================
	// Typeahead
	//==============================================================================
	public function typeahead() {
		$search = (strpos($this->request->get['q'], '[')) ? substr($this->request->get['q'], 0, strpos($this->request->get['q'], ' [')) : $this->request->get['q'];
		
		if ($this->request->get['type'] == 'all') {
			if (strpos($this->name, 'ultimate') === 0) {
				$tables = array('attribute_group_description', 'attribute_description', 'category_description', 'manufacturer', 'option_description', 'option_value_description', 'product_description');
			} else {
				$tables = array('category_description', 'manufacturer', 'product_description');
			}
		} elseif (in_array($this->request->get['type'], array('customer', 'manufacturer', 'zone'))) {
			$tables = array($this->request->get['type']);
		} else {
			$tables = array($this->request->get['type'] . '_description');
		}
		
		$results = array();
		foreach ($tables as $table) {
			if ($table == 'customer') {
				$query = $this->db->query("SELECT customer_id, CONCAT(firstname, ' ', lastname, ' (', email, ')') as name FROM " . DB_PREFIX . $table . " WHERE CONCAT(firstname, ' ', lastname, ' (', email, ')') LIKE '%" . $this->db->escape($search) . "%' ORDER BY name ASC LIMIT 0,100");
			} else {
				$query = $this->db->query("SELECT * FROM " . DB_PREFIX . $table . " WHERE name LIKE '%" . $this->db->escape($search) . "%' ORDER BY name ASC LIMIT 0,100");
			}
			$results = array_merge($results, $query->rows);
		}
		
		if (empty($results)) {
			$variations = array();
			for ($i = 0; $i < strlen($search); $i++) {
				$variations[] = $this->db->escape(substr_replace($search, '_', $i, 1));
				$variations[] = $this->db->escape(substr_replace($search, '', $i, 1));
				if ($i != strlen($search)-1) {
					$transpose = $search;
					$transpose[$i] = $search[$i+1];
					$transpose[$i+1] = $search[$i];
					$variations[] = $this->db->escape($transpose);
				}
			}
			foreach ($tables as $table) {
				if ($table == 'customer') {
					$query = $this->db->query("SELECT customer_id, CONCAT(firstname, ' ', lastname, ' (', email, ')') as name FROM " . DB_PREFIX . $table . " WHERE CONCAT(firstname, ' ', lastname, ' (', email, ')') LIKE '%" . implode("%' OR CONCAT(firstname, ' ', lastname, ' (', email, ')') LIKE '%", $variations) . "%' ORDER BY name ASC LIMIT 0,100");
				} else {
					$query = $this->db->query("SELECT * FROM " . DB_PREFIX . $table . " WHERE name LIKE '%" . implode("%' OR name LIKE '%", $variations) . "%' ORDER BY name ASC LIMIT 0,100");
				}
				$results = array_merge($results, $query->rows);
			}
		}
		
		$items = array();
		foreach ($results as $result) {
			if (key($result) == 'category_id') {
				$category_id = reset($result);
				$parent_exists = true;
				while ($parent_exists) {
					$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "category_description WHERE category_id = (SELECT parent_id FROM " . DB_PREFIX . "category WHERE category_id = " . (int)$category_id . " AND parent_id != " . (int)$category_id . ")");
					if (!empty($query->row['name'])) {
						$category_id = $query->row['category_id'];
						$result['name'] = $query->row['name'] . ' > ' . $result['name'];
					} else {
						$parent_exists = false;
					}
				}
			}
			$items[] = html_entity_decode($result['name'], ENT_NOQUOTES, 'UTF-8') . ' [' . key($result) . ':' . reset($result) . ']';
		}
		
		natcasesort($items);
		echo '["' . implode('","', str_replace(array('"', '_id'), array('&quot;', ''), $items)) . '"]';
	}
}
?>