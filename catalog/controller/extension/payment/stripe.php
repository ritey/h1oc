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
	private $embed = false;
	
	public function embed() {
		$this->embed = true;
		return $this->index();
	}
	
	public function logFatalErrors() {
		$error = error_get_last();
		if ($error['type'] === E_ERROR) { 
			$this->log->write('STRIPE PAYMENT GATEWAY: Order could not be completed due to the following fatal error:');
			$this->log->write('PHP Fatal Error:  ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line']);
		}
	}
	
	//==============================================================================
	// index()
	//==============================================================================
	public function index() {
		register_shutdown_function(array($this, 'logFatalErrors'));
		
		$data['type'] = $this->type;
		$data['name'] = $this->name;
		$data['embed'] = $this->embed;
		
		$data['settings'] = $settings = $this->getSettings();
		$data['language'] = $this->session->data['language'];
		$data['currency'] = $this->session->data['currency'];
		
		// Get order info, or build order if necessary
		$this->load->model('checkout/order');
		
		if (isset($this->session->data['order_id'])) {
			$data['order_info'] = $this->model_checkout_order->getOrder($this->session->data['order_id']);
		} else {
			$order_totals_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "extension WHERE `type` = 'total' ORDER BY `code` ASC");
			$order_totals = $order_totals_query->rows;
			
			$sort_order = array();
			foreach ($order_totals as $key => $value) {
				$prefix = (version_compare(VERSION, '3.0', '<')) ? '' : 'total_';
				$sort_order[$key] = $this->config->get($prefix . $value['code'] . '_sort_order');
			}
			array_multisort($sort_order, SORT_ASC, $order_totals);
			
			$total_data = array();
			$order_total = 0;
			$taxes = $this->cart->getTaxes();
			
			foreach ($order_totals as $ot) {
				if (!$this->config->get($ot['code'] . '_status')) continue;
				if (version_compare(VERSION, '2.2', '<')) {
					$this->load->model('total/' . $ot['code']);
					$this->{'model_total_' . $ot['code']}->getTotal($total_data, $order_total, $taxes);
				} elseif (version_compare(VERSION, '2.3', '<')) {
					$this->load->model('total/' . $ot['code']);
					$this->{'model_total_' . $ot['code']}->getTotal(array('totals' => &$total_data, 'total' => &$order_total, 'taxes' => &$taxes));
				} else {
					$this->load->model('extension/total/' . $ot['code']);
					$this->{'model_extension_total_' . $ot['code']}->getTotal(array('totals' => &$total_data, 'total' => &$order_total, 'taxes' => &$taxes));
				}
			}
			
			$data['order_info'] = array(
				'order_id'	=> '',
				'total'		=> $order_total,
				'email'		=> $this->customer->getEmail(),
				'comment'	=> '',
			);
		}
		
		// Find stripe_customer_id
		$data['customer'] = array();
		$data['logged_in'] = $this->customer->isLogged();
		
		if ($data['logged_in']) {
			$customer_id_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "stripe_customer WHERE customer_id = " . (int)$this->customer->getId() . " AND transaction_mode = '" . $this->db->escape($settings['transaction_mode']) . "'");
			if ($customer_id_query->num_rows) {
				$customer_response = $this->curlRequest('GET', 'customers/' . $customer_id_query->row['stripe_customer_id'], array('expand' => array('' => 'default_source')));
				if (!empty($customer_response['deleted'])) {
					$this->db->query("DELETE FROM " . DB_PREFIX . "stripe_customer WHERE stripe_customer_id = '" . $this->db->escape($customer_id_query->row['stripe_customer_id']) . "'");
				} elseif (!empty($customer_response['error'])) {
					$this->log->write('STRIPE PAYMENT GATEWAY: ' . $customer_response['error']['message']);
				} elseif ($data['settings']['allow_stored_cards']) {
					$data['customer'] = $customer_response;
				}
			}
		}
		
		$data['no_shipping_method'] = empty($this->session->data['shipping_method']);
		$data['checkout_success'] = $this->url->link('checkout/success', '', 'SSL');
		$data['stripe_errors'] = array(
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
		
		// Stripe Checkout
		$main_currency = $this->db->query("SELECT * FROM " . DB_PREFIX . "setting WHERE `key` = 'config_currency' AND store_id = 0")->row['value'];
		$decimal_factor = (in_array($data['currency'], array('BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','VND','VUV','XAF','XOF','XPF'))) ? 1 : 100;
		
		$data['checkout_image'] = (!empty($settings['checkout_image'])) ? HTTPS_SERVER . 'image/' . $settings['checkout_image'] : '';
		$data['checkout_title'] = (!empty($settings['checkout_title_' . $data['language']])) ? $this->replaceShortcodes($settings['checkout_title_' . $data['language']], $data['order_info']) : $this->config->get('config_name');
		$data['checkout_description'] = (!empty($settings['checkout_description_' . $data['language']])) ? $this->replaceShortcodes($settings['checkout_description_' . $data['language']], $data['order_info']) : '';
		$data['checkout_amount'] = round($decimal_factor * $this->currency->convert($data['order_info']['total'], $main_currency, $data['currency']));
		$data['checkout_button'] = (!empty($settings['checkout_button_' . $data['language']])) ? $settings['checkout_button_' . $data['language']] : '';
		$data['is_mobile'] = preg_match("/(android|avantgo|blackberry|bolt|boost|cricket|docomo|fone|hiptop|mini|mobi|palm|phone|pie|tablet|up\.browser|up\.link|webos|wos)/i", $this->request->server['HTTP_USER_AGENT']);
		
		// Payment Request button (Pro-specific)
		$data['country_code'] = $this->db->query("SELECT * FROM " . DB_PREFIX . "country WHERE country_id = " . (int)$this->config->get('config_country_id'))->row['iso_code_2'];
		
		// Render
		$theme = (version_compare(VERSION, '2.2', '<')) ? $this->config->get('config_template') : str_replace('theme_', '', $this->config->get('config_theme'));
		$template = (file_exists(DIR_TEMPLATE . $theme . '/template/extension/' . $this->type . '/' . $this->name . '.twig')) ? $theme : 'default';
		$template_file = DIR_TEMPLATE . $template . '/template/extension/' . $this->type . '/' . $this->name . '.twig';
		
		if (version_compare(VERSION, '3.0', '>=')) {
			$override_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "theme WHERE theme = '" . $this->db->escape($theme) . "' AND route = 'extension/" . $this->type . "/" . $this->name . "'");
			if ($override_query->num_rows) {
				$cache_file = DIR_CACHE . $this->name . '.twig.' . strtotime($override_query->row['date_added']);
				
				if (!file_exists($cache_file)) {
					$old_files = glob(DIR_CACHE . $this->name . '.twig.*');
					foreach ($old_files as $old_file) unlink($old_file);
					file_put_contents($cache_file, html_entity_decode($override_query->row['code'], ENT_QUOTES, 'UTF-8'));
				}
				
				$template_file = $cache_file;
			}
		}
		
		if (is_file($template_file)) {
			extract($data);
			
			ob_start();
			require(class_exists('VQMod') ? VQMod::modCheck(modification($template_file)) : modification($template_file));
			$output = ob_get_clean();
			
			return $output;
		} else {
			return 'Error loading template file';
		}
	}
	
	//==============================================================================
	// chargeSource()
	//==============================================================================
	public function chargeSource() {
		register_shutdown_function(array($this, 'logFatalErrors'));
		unset($this->session->data[$this->name . '_order_error']);
		
		$settings = $this->getSettings();
		
		$language_data = $this->load->language(version_compare(VERSION, '2.3', '<') ? 'total/total' : 'extension/total/total');
		$language = (isset($this->session->data['language'])) ? $this->session->data['language'] : $this->config->get('config_language');
		$currency = $this->session->data['currency'];
		$main_currency = $this->db->query("SELECT * FROM " . DB_PREFIX . "setting WHERE `key` = 'config_currency' AND store_id = 0")->row['value'];
		$decimal_factor = (in_array($settings['currencies_' . $currency], array('BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','VND','VUV','XAF','XOF','XPF'))) ? 1 : 100;

		$this->load->model('checkout/order');
		
		// Create order if necessary
		if (!empty($this->request->post['embed'])) {
			$data = array();
			
			$data['customer_id'] = (int)$this->customer->getId();
			$data['email'] = $this->request->post['email'];
			
			if ($settings['checkout_billing']) {
				$payment_name = explode(' ', $this->request->post['addresses']['billing_name'], 2);
				$payment_country = $this->db->query("SELECT * FROM " . DB_PREFIX . "country WHERE iso_code_2 = '" . $this->db->escape($this->request->post['addresses']['billing_address_country_code']) . "'");
				$payment_zone = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone WHERE `name` = '" . $this->db->escape($this->request->post['addresses']['billing_address_state']) . "' AND country_id = " . (int)$payment_country->row['country_id']);
				
				$data['firstname'] = (isset($payment_name[0])) ? $payment_name[0] : '';
				$data['lastname'] = (isset($payment_name[1])) ? $payment_name[1] : '';
				
				$data['payment_firstname'] = (isset($payment_name[0])) ? $payment_name[0] : '';
				$data['payment_lastname'] = (isset($payment_name[1])) ? $payment_name[1] : '';
				$data['payment_company'] = '';
				$data['payment_company_id'] = '';
				$data['payment_tax_id'] = '';
				$data['payment_address_1'] = $this->request->post['addresses']['billing_address_line1'];
				$data['payment_address_2'] = '';
				$data['payment_city'] = $this->request->post['addresses']['billing_address_city'];
				$data['payment_postcode'] = $this->request->post['addresses']['billing_address_zip'];
				$data['payment_zone'] = $this->request->post['addresses']['billing_address_state'];
				$data['payment_zone_id'] = (isset($payment_zone->row['zone_id'])) ? $payment_zone->row['zone_id'] : '';
				$data['payment_country'] = $this->request->post['addresses']['billing_address_country'];
				$data['payment_country_id'] = (isset($payment_country->row['country_id'])) ? $payment_country->row['country_id'] : '';
			}
			
			if ($settings['checkout_shipping']) {
				if (isset($this->session->data['country_id'])) {
					$shipping_quote = array(
						'country_id'	=> $this->session->data['country_id'],
						'zone_id'		=> $this->session->data['zone_id'],
						'postcode'		=> $this->session->data['postcode'],
					);
				} elseif (isset($this->session->data['guest']['shipping']['country_id'])) {
					$shipping_quote = array(
						'country_id'	=> $this->session->data['guest']['shipping']['country_id'],
						'zone_id'		=> $this->session->data['guest']['shipping']['zone_id'],
						'postcode'		=> $this->session->data['guest']['shipping']['postcode'],
					);
				} elseif (isset($this->session->data['shipping_country_id'])) {
					$shipping_quote = array(
						'country_id'	=> $this->session->data['shipping_country_id'],
						'zone_id'		=> $this->session->data['shipping_zone_id'],
						'postcode'		=> $this->session->data['shipping_postcode'],
					);
				} else {
					$shipping_quote = array(
						'country_id'	=> $this->session->data['shipping_address']['country_id'],
						'zone_id'		=> $this->session->data['shipping_address']['zone_id'],
						'postcode'		=> $this->session->data['shipping_address']['postcode'],
					);
				}
				
				$shipping_name = explode(' ', $this->request->post['addresses']['shipping_name'], 2);
				$shipping_country = $this->db->query("SELECT * FROM " . DB_PREFIX . "country WHERE iso_code_2 = '" . $this->db->escape($this->request->post['addresses']['shipping_address_country_code']) . "'");
				$shipping_zone = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone WHERE `code` = '" . $this->db->escape($this->request->post['addresses']['shipping_address_state']) . "' AND country_id = " . (int)$shipping_country->row['country_id']);
				
				if ($shipping_quote['country_id'] != $shipping_country->row['country_id'] || 
					//$shipping_quote['zone_id'] != $shipping_zone->row['zone_id'] || 
					str_replace(' ', '', strtolower($shipping_quote['postcode'])) != str_replace(' ', '', strtolower($this->request->post['addresses']['shipping_address_zip']))
				) {
					$this->displayError($settings['error_shipping_mismatch_' . $language]);
					return;
				}
				
				$data['shipping_firstname'] = (isset($shipping_name[0])) ? $shipping_name[0] : '';
				$data['shipping_lastname'] = (isset($shipping_name[1])) ? $shipping_name[1] : '';
				$data['shipping_company'] = '';
				$data['shipping_company_id'] = '';
				$data['shipping_tax_id'] = '';
				$data['shipping_address_1'] = $this->request->post['addresses']['shipping_address_line1'];
				$data['shipping_address_2'] = '';
				$data['shipping_city'] = $this->request->post['addresses']['shipping_address_city'];
				$data['shipping_postcode'] = $this->request->post['addresses']['shipping_address_zip'];
				$data['shipping_zone'] = $this->request->post['addresses']['shipping_address_state'];
				$data['shipping_zone_id'] = (isset($shipping_zone->row['zone_id'])) ? $shipping_zone->row['zone_id'] : '';
				$data['shipping_country'] = $this->request->post['addresses']['shipping_address_country'];
				$data['shipping_country_id'] = (isset($shipping_country->row['country_id'])) ? $shipping_country->row['country_id'] : '';
			}
			
			$this->load->model('extension/' . $this->type . '/' . $this->name);
			$this->session->data['order_id'] = $this->{'model_extension_'.$this->type.'_'.$this->name}->createOrder($data);
		}
		
		$order_id = $this->session->data['order_id'];
		$order_info = $this->model_checkout_order->getOrder($order_id);
		
		// Get source data
		$data = array();
		
		if (!empty($this->request->post['source'])) {
			$data = array('source' => $this->request->post['source']);
		} elseif (!empty($this->request->get['source'])) {
			if (empty($_COOKIE['client_secret']) || $_COOKIE['client_secret'] != $this->request->get['client_secret']) {
				$this->displayError('Mismatching client_secret');
				return;
			}
			$data = array('source' => $this->request->get['source']);
		}
		
		// Check for subscription products
		$customer_id = $this->customer->getId();
		$plans = array();
		
		if (!empty($settings['subscriptions'])) {
			foreach ($this->cart->getProducts() as $product) {
				$plan_ids = array();
				
				if (!empty($settings['subscription_options'])) {
					foreach ($settings['subscription_options'] as $row) {
						foreach ($product['option'] as $option) {
							if ($option['name'] == $row['option_name'] && $option['value'] == $row['option_value']) {
								$plan_ids[] = trim($row['plan_id']);
							}
						}
					}
				}
				
				if (!empty($product['recurring']) && !empty($settings['subscription_profiles'])) {
					foreach ($settings['subscription_profiles'] as $row) {
						if ($product['recurring']['name'] == $row['profile_name']) {
							$plan_ids[] = trim($row['plan_id']);
						}
					}
				}
				
				if (empty($plan_ids)) {
					$product_info = $this->db->query("SELECT * FROM " . DB_PREFIX . "product WHERE product_id = " . (int)$product['product_id'])->row;
					if (!empty($product_info['location'])) {
						$plan_ids[] = trim($product_info['location']);
					}
				}
				
				if (empty($plan_ids)) continue;
				
				foreach ($plan_ids as $plan_id) {
					$plan_response = $this->curlRequest('GET', 'plans/' . $plan_id);
					
					if (!empty($plan_response['error'])) {
						continue;
					} elseif ($settings['prevent_guests'] && !$customer_id) {
						$this->displayError($settings['error_customer_required_' . $language]);
						return;
					}
					
					$plan_tax_rate = $this->tax->getTax($product['total'], $product['tax_class_id']) / $product['total'];
					$plans[] = array(
						'cost'			=> $plan_response['amount'] / 100,
						'taxed_cost'	=> $plan_response['amount'] / 100 * (1 + $plan_tax_rate),
						'tax_percent'	=> $plan_tax_rate * 100,
						'id'			=> $plan_response['id'],
						'key'			=> $product[version_compare(VERSION, '2.1', '<') ? 'key' : 'cart_id'],
						'name'			=> $plan_response['name'],
						'quantity'		=> $product['quantity'],
						'trial'			=> $plan_response['trial_period_days'],
						'product_id'	=> $product['product_id'],
					);
				}
			}
		}
		
		// Create or update customer
		if (!empty($plans) || (isset($this->request->post['store_card']) && $this->request->post['store_card'] == 'true') || $settings['send_customer_data'] == 'always' || empty($data)) {
			$stripe_customer_id = '';
			if ($this->customer->isLogged()) {
				$customer_id_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "stripe_customer WHERE customer_id = " . (int)$this->customer->getId() . " AND transaction_mode = '" . $this->db->escape($settings['transaction_mode']) . "'");
				if ($customer_id_query->num_rows) {
					$stripe_customer_id = $customer_id_query->row['stripe_customer_id'];
				}
			}
			
			$data['description'] = $order_info['firstname'] . ' ' . $order_info['lastname'] . ' (' . 'customer_id: ' . $order_info['customer_id'] . ')';
			$data['email'] = $order_info['email'];
			
			$customer_response = $this->curlRequest('POST', 'customers' . ($stripe_customer_id ? '/' . $stripe_customer_id : ''), $data);
			
			if (empty($customer_response['error'])) {
				$data = array('customer' => $customer_response['id']);
				if (!$stripe_customer_id) {
					$stripe_customer_id = $customer_response['id'];
					$this->db->query("INSERT INTO " . DB_PREFIX . "stripe_customer SET customer_id = " . (int)$this->customer->getId() . ", stripe_customer_id = '" . $this->db->escape($customer_response['id']) . "', transaction_mode = '" . $this->db->escape($settings['transaction_mode']) . "'");
				}
			} else {
				$this->displayError($customer_response['error']['message']);
				return;
			}
		}
		
		// Subscribe customer to plan
		if (!empty($plans)) {
			foreach ($plans as &$plan) {
				// Check for current subscriptions
				$current_quantity = 0;
				
				if (!empty($customer_response['subscriptions'])) {
				 	foreach ($customer_response['subscriptions']['data'] as $subscription) {
						if ($subscription['id'] == $plan['id']) $current_quantity += $subscription['quantity'];
					}
				}
				
				$subscription_id = '';
				$total_plan_cost = $plan['quantity'] * $plan['taxed_cost'];
				
				// Subscribe customer BEFORE adding shipping if there IS a trial period
				if ($plan['trial']) {
					$subscription_data = array(
						'plan'			=> $plan['id'],
						'quantity'		=> $current_quantity + $plan['quantity'],
						'tax_percent'	=> $plan['tax_percent'],
						'metadata'		=> array(
							'order_id'		=> $order_id,
							'product_id'	=> $plan['product_id'],
						),
					);
					
					if (isset($this->session->data['coupon'])) {
						$coupon_response = $this->curlRequest('GET', 'coupons/' . $this->session->data['coupon']);
						if (empty($coupon_response['error'])) {
							$subscription_data['coupon'] = $coupon_response['id'];
						}
					}
					
					$subscription_response = $this->curlRequest('POST', 'customers/' . $data['customer'] . '/subscriptions', $subscription_data);
					if (!empty($subscription_response['error'])) {
						$this->displayError($subscription_response['error']['message']);
						return;
					}
					$subscription_id = $subscription_response['id'];
				}
				
				// Add invoice item for shipping
				if (isset($this->session->data['shipping_method']) && $stripe_customer_id && !empty($settings['include_shipping'])) {
					$country_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "country WHERE country_id = " . (int)$order_info['shipping_country_id']);
					$shipping_address = array(
						'firstname'		=> $order_info['shipping_firstname'],
						'lastname'		=> $order_info['shipping_lastname'],
						'company'		=> $order_info['shipping_company'],
						'address_1'		=> $order_info['shipping_address_1'],
						'address_2'		=> $order_info['shipping_address_2'],
						'city'			=> $order_info['shipping_city'],
						'postcode'		=> $order_info['shipping_postcode'],
						'zone'			=> $order_info['shipping_zone'],
						'zone_id'		=> $order_info['shipping_zone_id'],
						'zone_code'		=> $order_info['shipping_zone_code'],
						'country'		=> $order_info['shipping_country'],
						'country_id'	=> $order_info['shipping_country_id'],
						'iso_code_2'	=> $order_info['shipping_iso_code_2'],
					);
					
					
					// Save cart and remove ineligible products
					$cart_products = $this->cart->getProducts();
					
					foreach ($cart_products as $product) {
						$key = $product[version_compare(VERSION, '2.1', '<') ? 'key' : 'cart_id'];
						if ($key != $plan['key']) {
							$this->cart->remove($key);
						}
					}
					
					// Get shipping rates
					if ($this->cart->hasShipping()) {
						$shipping_methods = $this->db->query("SELECT * FROM " . DB_PREFIX . "extension WHERE `type` = 'shipping' ORDER BY `code` ASC")->rows;
						$prefix = (version_compare(VERSION, '3.0', '<')) ? '' : 'shipping_';
						
						foreach ($shipping_methods as $shipping_method) {
							if (!$this->config->get($prefix . $shipping_method['code'] . '_status')) continue;
							
							if (version_compare(VERSION, '2.3', '<')) {
								$this->load->model('shipping/' . $shipping_method['code']);
								$quote = $this->{'model_shipping_' . $shipping_method['code']}->getQuote($shipping_address);
							} else {
								$this->load->model('extension/shipping/' . $shipping_method['code']);
								$quote = $this->{'model_extension_shipping_' . $shipping_method['code']}->getQuote($shipping_address);
							}
							
							if (empty($quote)) continue;
							
							foreach ($quote['quote'] as $q) {
								if ($q['code'] == $order_info['shipping_code']) {
									// Create invoice item
									$shipping_cost = $this->currency->convert($q['cost'], $main_currency, $settings['currencies_' . $currency]);
									$shipping_amount = round($decimal_factor * $shipping_cost);
									$taxed_shipping_amount = $this->tax->calculate($shipping_amount, $q['tax_class_id']);
									
									if (!$shipping_amount) continue;
									
									$invoice_item_data = array(
										'amount'		=> $shipping_amount,
										'currency'		=> $settings['currencies_' . $currency],
										'customer'		=> $stripe_customer_id,
										'description'	=> 'Shipping for ' . $plan['name'],
									);
									
									if ($subscription_id) {
										$invoice_item_data['subscription'] = $subscription_id;
									}
									
									$invoice_item_response = $this->curlRequest('POST', 'invoiceitems', $invoice_item_data);
									if (!empty($invoice_item_response['error'])) {
										$this->displayError($invoice_item_response['error']['message']);
										return;
									}
									
									$total_plan_cost += $taxed_shipping_amount / 100;
									$plan['shipping_cost'] = $this->currency->format($taxed_shipping_amount / 100, $settings['currencies_' . $currency]);
									
									break;
								}
							}
						}
					}
					
					// Restore cart
					$this->cart->clear();
					foreach ($cart_products as $product) {
						$options = array();
						foreach ($product['option'] as $option) {
							if (isset($options[$option['product_option_id']])) {
								if (!is_array($options[$option['product_option_id']])) $options[$option['product_option_id']] = array($options[$option['product_option_id']]);
								$options[$option['product_option_id']][] = $option['product_option_value_id'];
							} else {
								$options[$option['product_option_id']] = (!empty($option['product_option_value_id'])) ? $option['product_option_value_id'] : $option['value'];
							}
						}
						$this->cart->add($product['product_id'], $product['quantity'], $options, $product['recurring']['recurring_id']);
					}
				}
				
				// Subscribe customer AFTER adding shipping if there is NOT a trial period
				if (!$plan['trial']) {
					$subscription_data = array(
						'plan'			=> $plan['id'],
						'quantity'		=> $current_quantity + $plan['quantity'],
						'tax_percent'	=> $plan['tax_percent'],
						'metadata'		=> array(
							'order_id'		=> $order_id,
							'product_id'	=> $plan['product_id'],
						),
					);
					
					if (isset($this->session->data['coupon'])) {
						$coupon_response = $this->curlRequest('GET', 'coupons/' . $this->session->data['coupon']);
						if (empty($coupon_response['error'])) {
							$subscription_data['coupon'] = $coupon_response['id'];
						}
					}
					
					$subscription_response = $this->curlRequest('POST', 'customers/' . $data['customer'] . '/subscriptions', $subscription_data);
					if (!empty($subscription_response['error'])) {
						$this->displayError($subscription_response['error']['message']);
						return;
					}
				}
				
				// Adjust order line items
				$order_info['total'] -= $total_plan_cost;
				$prefix = (version_compare(VERSION, '3.0', '<')) ? '' : 'total_';
				
				if (!empty($plan['trial'])) {
					$this->db->query("UPDATE `" . DB_PREFIX . "order` SET total = " . (float)$order_info['total'] . " WHERE order_id = " . (int)$order_info['order_id']);
					$this->db->query("UPDATE " . DB_PREFIX . "order_total SET value = " . (float)$order_info['total'] . " WHERE order_id = " . (int)$order_info['order_id'] . " AND title = '" . $this->db->escape($language_data['text_total']) . "'");
					$this->db->query("INSERT INTO " . DB_PREFIX . "order_total SET order_id = " . (int)$order_info['order_id'] . ", code = 'total', title = '" . $this->db->escape($settings['text_to_be_charged_' . $language] . ' (' . $plan['name'] . ')') . "', value = " . (float)-$total_plan_cost . ", sort_order = " . ((int)$this->config->get($prefix . 'total_sort_order')-1));
				}
			}
		}
		
		// Charge card
		$order_status_id = $settings['success_status_id'];
		
		if ($order_info['total'] >= 0.5) {
			// Check fraud data
			$data['capture'] = ($settings['charge_mode'] == 'authorize') ? 'false' : 'true';
			
			if ($settings['charge_mode'] == 'fraud') {
				if (version_compare(VERSION, '2.0.3', '<')) {
					if ($this->config->get('config_fraud_detection')) {
						$this->load->model('checkout/fraud');
						if ($this->model_checkout_fraud->getFraudScore($order_info) > $this->config->get('config_fraud_score')) {
							$data['capture'] = 'false';
						}
					}
				} else {
					$this->load->model('account/customer');
					$customer_info = $this->model_account_customer->getCustomer($order_info['customer_id']);
					
					if (empty($customer_info['safe'])) {
						$fraud_extensions = $this->db->query("SELECT * FROM " . DB_PREFIX . "extension WHERE `type` = 'fraud' ORDER BY `code` ASC")->rows;
						
						foreach ($fraud_extensions as $extension) {
							$prefix = (version_compare(VERSION, '3.0', '<')) ? '' : 'fraud_';
							if (!$this->config->get($prefix . $extension['code'] . '_status')) continue;
							
							if (version_compare(VERSION, '2.3', '<')) {
								$this->load->model('fraud/' . $extension['code']);
								$fraud_status_id = $this->{'model_fraud_' . $extension['code']}->check($order_info);
							} else {
								$this->load->model('extension/fraud/' . $extension['code']);
								$fraud_status_id = $this->{'model_extension_fraud_' . $extension['code']}->check($order_info);
							}
							
							if ($fraud_status_id) {
								$data['capture'] = 'false';
							}
						}
					}
				}
			}
			
			if ($data['capture'] == 'false') {
				$order_status_id = $settings['authorize_status_id'];
			}
			
			// Set up other charge data
			$data['amount'] = round($decimal_factor * $this->currency->convert($order_info['total'], $main_currency, $settings['currencies_' . $currency]));
			$data['currency'] = $settings['currencies_' . $currency];
			$data['description'] = $this->replaceShortcodes($settings['transaction_description'], $order_info);
			
			if ($settings['always_send_receipts']) {
				$data['receipt_email'] = $order_info['email'];
			}
			
			$data['metadata']['Store'] = $this->config->get('config_name');
			$data['metadata']['Order ID'] = $order_info['order_id'];
			$data['metadata']['Customer Info'] = $order_info['firstname'] . ' ' . $order_info['lastname'] . ', ' . $order_info['email'] . ', ' . $order_info['telephone'] . ', customer_id: ' . $order_info['customer_id'];
			$data['metadata']['Products'] = $this->replaceShortcodes('[products]', $order_info);
			if (!empty($order_info['shipping_method'])) {
				$data['shipping'] = array(
					'address'		=> array(
						'line1'			=> $order_info['shipping_address_1'],
						'line2'			=> $order_info['shipping_address_2'],
						'city'			=> $order_info['shipping_city'],
						'state'			=> $order_info['shipping_zone'],
						'postal_code'	=> $order_info['shipping_postcode'],
						'country'		=> $order_info['shipping_iso_code_2'],
					),
					'carrier'		=> (isset($order_info['shipping_code']) ? substr($order_info['shipping_code'], 0, strpos($order_info['shipping_code'], '.')) : $order_info['shipping_method']),
					'name'			=> $order_info['shipping_firstname'] . ' ' . $order_info['shipping_lastname'] . ($order_info['shipping_company'] ? ' (' . $order_info['shipping_company'] . ')' : ''),
					'phone'			=> $order_info['telephone'],
				);
				$data['metadata']['Shipping Method'] = $order_info['shipping_method'] . (isset($this->session->data['shipping_method']) ? ' (' . $this->currency->format($this->session->data['shipping_method']['cost'], $currency) . ')' : '');
				$data['metadata']['Shipping Address'] = $order_info['shipping_firstname'] . ' ' . $order_info['shipping_lastname'] . ($order_info['shipping_company'] ? ', ' . $order_info['shipping_company'] : '');
				$data['metadata']['Shipping Address'] .= ', ' . $order_info['shipping_address_1'] . ($order_info['shipping_address_2'] ? ', ' . $order_info['shipping_address_2'] : '');
				$data['metadata']['Shipping Address'] .= ', ' . $order_info['shipping_city'] . ', ' . $order_info['shipping_zone'] . ', ' . $order_info['shipping_postcode'] . ', ' . $order_info['shipping_country'];
			}
			$data['metadata']['Order Comment'] = $order_info['comment'];
			$data['metadata']['IP Address'] = $order_info['ip'];
			foreach ($data['metadata'] as &$metadata) {
				if (strlen($metadata) > 197) {
					$metadata = mb_substr($metadata, 0, 197, 'UTF-8') . '...';
				}
			}
			
			$charge_response = $this->curlRequest('POST', 'charges', $data);
			
			if (empty($charge_response['error'])) {
				/*
				if ($charge_response['outcome']['risk_level'] == 'elevated') $order_status_id = 15;
				if ($charge_response['outcome']['risk_level'] == 'highest') $order_status_id = 15;
				*/
				if ($settings['street_status_id'] && isset($charge_response['source']['address_line1_check']) && $charge_response['source']['address_line1_check'] == 'fail')	$order_status_id = $settings['street_status_id'];
				if ($settings['zip_status_id'] && isset($charge_response['source']['address_zip_check']) && $charge_response['source']['address_zip_check'] == 'fail')			$order_status_id = $settings['zip_status_id'];
				if ($settings['cvc_status_id'] && isset($charge_response['source']['cvc_check']) && $charge_response['source']['cvc_check'] == 'fail')							$order_status_id = $settings['cvc_status_id'];
			} else {
				if (!empty($charge_response['error']['code']) && !empty($settings['error_' . $charge_response['error']['code'] . '_' . $language])) {
					$this->displayError($settings['error_' . $charge_response['error']['code'] . '_' . $language]);
				} else {
					$this->displayError($charge_response['error']['message']);
				}
				return;
			}
		}
		
		// Create comment data
		$strong = '<strong style="display: inline-block; width: 180px; padding: 2px 5px">';
		
		$comment = '';
		if (!empty($plans)) {
			foreach ($plans as $plan) {
				$comment .= $strong . 'Subscribed to Plan:</strong>' . $plan['name'] . '<br>';
				$comment .= $strong . 'Subscription Charge:</strong>' . $this->currency->format($plan['cost'], strtoupper($subscription_response['plan']['currency']), 1);
				if ($plan['taxed_cost'] != $plan['cost']) {
					$comment .= ' (Including Tax: ' . $this->currency->format($plan['taxed_cost'], strtoupper($subscription_response['plan']['currency']), 1) . ')';
				}
				if (!empty($plan['shipping_cost'])) {
					$comment .= '<br>' . $strong . 'Shipping Cost:</strong>' . $plan['shipping_cost'];
				}
				if (!empty($plan['trial'])) {
					$comment .= '<br>' . $strong . 'Trial Days:</strong>' . $plan['trial'];
				}
				$comment .= '<hr>';
			}
		}
		if (!empty($charge_response)) {
			$charge_amount = $charge_response['amount'] / $decimal_factor;
			$comment .= '<script type="text/javascript" src="view/javascript/stripe.js"></script>';
			$comment .= $strong . 'Stripe Charge ID:</strong>' . $charge_response['id'] . '<br>';
			$comment .= $strong . 'Charge Amount:</strong>' . $this->currency->format($charge_amount, strtoupper($charge_response['currency']), 1) . '<br>';
			$comment .= $strong . 'Captured:</strong>' . (!empty($charge_response['captured']) ? 'Yes' : '<span>No &nbsp;</span> <a onclick="stripeCapture($(this), \'' . $charge_response['id'] . '\')">(Capture)</a>') . '<br>';
			
			// Card fields (token)
			if (isset($charge_response['source']['name']))					$comment .= $strong . 'Card Name:</strong>' . $charge_response['source']['name'] . '<br>';
			if (isset($charge_response['source']['last4']))					$comment .= $strong . 'Card Number:</strong>**** **** **** ' . $charge_response['source']['last4'] . '<br>';
			if (isset($charge_response['source']['fingerprint']))			$comment .= $strong . 'Card Fingerprint:</strong>' . $charge_response['source']['fingerprint'] . '<br>';
			if (isset($charge_response['source']['exp_month']))				$comment .= $strong . 'Card Expiry:</strong>' . $charge_response['source']['exp_month'] . ' / ' . $charge_response['source']['exp_year'] . '<br>';
			if (isset($charge_response['source']['brand']))					$comment .= $strong . 'Card Type:</strong>' . $charge_response['source']['brand'] . '<br>';
			if (isset($charge_response['source']['address_line1']))			$comment .= $strong . 'Card Address:</strong>' . $charge_response['source']['address_line1'] . '<br>';
			if (isset($charge_response['source']['address_line2']))			$comment .= (!empty($charge_response['source']['address_line2'])) ? $strong . '&nbsp;</strong>' . $charge_response['source']['address_line2'] . '<br>' : '';
			if (isset($charge_response['source']['address_city']))			$comment .= $strong . '&nbsp;</strong>' . $charge_response['source']['address_city'] . ', ' . $charge_response['source']['address_state'] . ' ' . $charge_response['source']['address_zip'] . '<br>';
			if (isset($charge_response['source']['address_country']))		$comment .= $strong . '&nbsp;</strong>' . $charge_response['source']['address_country'] . '<br>';
			if (isset($charge_response['source']['country']))				$comment .= $strong . 'Origin:</strong>' . $charge_response['source']['country'] . '<br>';
			if (isset($charge_response['source']['cvc_check']))				$comment .= $strong . 'CVC Check:</strong>' . $charge_response['source']['cvc_check'] . '<br>';
			if (isset($charge_response['source']['address_line1_check']))	$comment .= $strong . 'Street Check:</strong>' . $charge_response['source']['address_line1_check'] . '<br>';
			if (isset($charge_response['source']['address_zip_check']))		$comment .= $strong . 'Zip Check:</strong>' . $charge_response['source']['address_zip_check'] . '<br>';
			
			// Owner fields
			if (!empty($charge_response['source']['owner'])) {
				$comment .= $strong . 'Owner:</strong>' . $charge_response['source']['owner']['name'] . '<br>';
				if (!empty($charge_response['source']['owner']['address'])) {
					$owner_address = $charge_response['source']['owner']['address'];
					$comment .= $strong . '&nbsp;</strong>' . $owner_address['line1'] . '<br>';
					if (!empty($card_address['line2'])) {
						$comment .= $strong . '&nbsp;</strong>' . $owner_address['line2'] . '<br>';
					}
					$comment .= $strong . '&nbsp;</strong>' . $owner_address['city']. ', ' .$owner_address['state'] . ' ' . $owner_address['postal_code'] . '<br>';
					$comment .= $strong . '&nbsp;</strong>' . $owner_address['country'] . '<br>';
				}
			}
			
			// Card fields (source)
			$card = array();
			
			if (!empty($charge_response['source']['card'])) {
				$card = $charge_response['source']['card'];
			} elseif (!empty($charge_response['source']['three_d_secure']['card'])) {
				$source_response = $this->curlRequest('GET', 'sources/' . $charge_response['source']['three_d_secure']['card']);
				
				if (empty($source_response['error']) && !empty($source_response['card'])) {
					$card = $source_response['card'];
				}
				
				// Create or update customer
				if (isset($this->request->get['store_card']) && $this->request->get['store_card'] == 'true') {
					$stripe_customer_id = '';
					if ($this->customer->isLogged()) {
						$customer_id_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "stripe_customer WHERE customer_id = " . (int)$this->customer->getId() . " AND transaction_mode = '" . $this->db->escape($settings['transaction_mode']) . "'");
						if ($customer_id_query->num_rows) {
							$stripe_customer_id = $customer_id_query->row['stripe_customer_id'];
						}
					}
					
					$customer_response = $this->curlRequest('POST', 'customers' . ($stripe_customer_id ? '/' . $stripe_customer_id : ''), array('source' => $charge_response['source']['three_d_secure']['card']));
					
					if (empty($customer_response['error'])) {
						if (!$stripe_customer_id) {
							$stripe_customer_id = $customer_response['id'];
							$this->db->query("INSERT INTO " . DB_PREFIX . "stripe_customer SET customer_id = " . (int)$this->customer->getId() . ", stripe_customer_id = '" . $this->db->escape($customer_response['id']) . "', transaction_mode = '" . $this->db->escape($settings['transaction_mode']) . "'");
						}
					} else {
						$this->log->write($customer_response['error']['message']);
					}
				}
			}
			
			if (!empty($card)) {
				$comment .= $strong . 'Card Number:</strong>**** **** **** ' . $card['last4'] . '<br>';
				$comment .= $strong . 'Card Fingerprint:</strong>' . (isset($card['fingerprint']) ? $card['fingerprint'] : '') . '<br>';
				$comment .= $strong . 'Card Expiry:</strong>' . $card['exp_month'] . ' / ' . $card['exp_year'] . '<br>';
				$comment .= $strong . 'Card Type:</strong>' . $card['brand'] . '<br>';
				$comment .= $strong . 'Card Origin:</strong>' . $card['country'] . '<br>';
				$comment .= $strong . 'CVC Check:</strong>' . $card['cvc_check'] . '<br>';
				$comment .= $strong . 'Street Check:</strong>' . $card['address_line1_check'] . '<br>';
				$comment .= $strong . 'Zip Check:</strong>' . $card['address_zip_check'] . '<br>';
			}
			
			// Apple Pay fields
			if (!empty($charge_response['source']['card']['tokenization_method'])) {
				$comment .= $strong . 'Payment Method:</strong>' . ucwords(str_replace('_', ' ', $charge_response['source']['card']['tokenization_method'])) . '<br>';
			}
			if (!empty($charge_response['source']['card']['dynamic_last4'])) {
				$comment .= $strong . 'Device Number:</strong>**** **** **** ' . $charge_response['source']['card']['dynamic_last4'] . '<br>';
			}
			
			// 3D Secure fields
			if (isset($charge_response['source']['three_d_secure'])) {
				$authenticated = ($charge_response['source']['three_d_secure']['authenticated']) ? 'true' : 'false';
				$comment .= $strong . '3D Secure Authenticated:</strong>' . $authenticated . '<br>';
			}
			
			// Bitcoin fields
			if (isset($charge_response['source']['email'])) {
				$comment .= $strong . 'Bitcoin E-mail:</strong>' . $charge_response['source']['email'] . '<br>';
			}
			
			// Refund link
			$comment .= $strong . 'Refund:</strong><a onclick="stripeRefund($(this), ' . number_format($charge_response['amount'] / 100, 2, '.', '') . ', \'' . $charge_response['id'] . '\')">(Refund)</a>';
		}
		
		// Add order history
		$this->db->query("INSERT INTO " . DB_PREFIX . "order_history SET order_id = " . (int)$order_id . ", order_status_id = " . (int)$order_status_id . ", notify = 0, comment = '" . $this->db->escape($comment) . "', date_added = NOW()");
		
		if (isset($this->request->get['source'])) {
			$this->load->model('checkout/order');
			$this->model_checkout_order->addOrderHistory($order_id, $order_status_id);
			$this->response->redirect($this->url->link('checkout/success', '', 'SSL'));
		} else {
			$this->session->data[$this->name . '_order_id'] = $order_id;
			$this->session->data[$this->name . '_order_status_id'] = $order_status_id;
		}
	}
	
	//==============================================================================
	// displayError()
	//==============================================================================
	public function displayError($message) {
		if (isset($this->request->get['source'])) {
			$settings = $this->getSettings();
			$language = (isset($this->session->data['language'])) ? $this->session->data['language'] : $this->config->get('config_language');
			
			$header = $this->load->controller('common/header');
			$footer = $this->load->controller('common/footer');
			
			$error_page = html_entity_decode($settings['three_d_error_page_' . $language], ENT_QUOTES, 'UTF-8');
			$error_page = str_replace(array('[header]', '[error]', '[footer]'), array($header, $message, $footer), $error_page);
			
			echo $error_page;
		} else {
			//$this->log->write('STRIPE PAYMENT GATEWAY: ' . $message);
			echo $message;
		}
	}
	
	//==============================================================================
	// completeOrder()
	//==============================================================================
	public function completeOrder() {
		if (empty($this->session->data[$this->name . '_order_id'])) {
			echo 'No order data';
			return;
		}
		
		$order_id = $this->session->data[$this->name . '_order_id'];
		$order_status_id = $this->session->data[$this->name . '_order_status_id'];
		
		unset($this->session->data[$this->name . '_order_id']);
		unset($this->session->data[$this->name . '_order_status_id']);
		
		$this->session->data[$this->name . '_order_error'] = $order_id;
		
		$this->load->model('checkout/order');
		$this->model_checkout_order->addOrderHistory($order_id, $order_status_id);
	}
	
	//==============================================================================
	// completeWithError()
	//==============================================================================
	public function completeWithError() {
		if (empty($this->session->data[$this->name . '_order_error'])) {
			echo 'Payment was not processed';
			return;
		}
		
		$settings = $this->getSettings();
		
		$this->db->query("UPDATE `" . DB_PREFIX . "order` SET order_status_id = " . (int)$settings['error_status_id'] . ", date_modified = NOW() WHERE order_id = " . (int)$this->session->data[$this->name . '_order_error']);
		$this->db->query("INSERT INTO " . DB_PREFIX . "order_history SET order_id = " . (int)$this->session->data[$this->name . '_order_error'] . ", order_status_id = " . (int)$settings['error_status_id'] . ", notify = 0, comment = 'The order could not be completed normally due to the following error:<br><br><em>" . $this->db->escape($this->request->post['error_message']) . "</em><br><br>Double-check your SMTP settings in System > Settings > Mail, and then try disabling or uninstalling any modifications that affect customer orders (i.e. the /catalog/model/checkout/order.php file). One of those is usually the cause of errors like this.', date_added = NOW()");
		
		unset($this->session->data[$this->name . '_order_error']);
	}
	
	//==============================================================================
	// Payment link functions
	//==============================================================================
	public function link() {
		$data['type'] = $this->type;
		$data['name'] = $this->name;
		$data['embed'] = true;
		
		$data['settings'] = $settings = $this->getSettings();
		
		$data['link_data'] = $this->request->get['data'];
		parse_str(base64_decode($this->request->get['data']), $link_data);
		
		$data['language'] = $this->session->data['language'];
		$data['currency'] = $link_data['currency'];
		$main_currency = $this->db->query("SELECT * FROM " . DB_PREFIX . "setting WHERE `key` = 'config_currency' AND store_id = 0")->row['value'];
		$decimal_factor = (in_array($data['currency'], array('BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','VND','VUV','XAF','XOF','XPF'))) ? 1 : 100;
		
		$data['order_info'] = array(
			'order_id'	=> $link_data['order_id'],
			'total'		=> $link_data['amount'],
			'email'		=> '',
			'comment'	=> '',
		);
		
		$data['no_shipping_method'] = 0;
		$data['checkout_success'] = $this->url->link('common/home');
		$data['stripe_errors'] = array(
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
		
		$data['checkout_image'] = (!empty($settings['checkout_image'])) ? HTTPS_SERVER . 'image/' . $settings['checkout_image'] : '';
		$data['checkout_title'] = (!empty($settings['checkout_title_' . $data['language']])) ? $this->replaceShortcodes($settings['checkout_title_' . $data['language']], $data['order_info']) : $this->config->get('config_name');
		$data['checkout_description'] = (!empty($settings['checkout_description_' . $data['language']])) ? $this->replaceShortcodes($settings['checkout_description_' . $data['language']], $data['order_info']) : '';
		$data['checkout_amount'] = round($decimal_factor * $this->currency->convert($data['order_info']['total'], $main_currency, $data['currency']));
		$data['checkout_button'] = (!empty($settings['checkout_button_' . $data['language']])) ? $settings['checkout_button_' . $data['language']] : '';
		
		$data['settings']['button_text_' . $data['language']] = '<span id="payment-link-button">' . str_replace('[amount]', $this->currency->format($link_data['amount'], $data['currency']), $data['checkout_button']) . '</span>';
		$data['settings']['checkout_billing'] = true;
		$data['settings']['checkout_shipping'] = false;
		
		// Render
		$theme = (version_compare(VERSION, '2.2', '<')) ? $this->config->get('config_template') : str_replace('theme_', '', $this->config->get('config_theme'));
		$template = (file_exists(DIR_TEMPLATE . $theme . '/template/extension/' . $this->type . '/' . $this->name . '.twig')) ? $theme : 'default';
		$template_file = DIR_TEMPLATE . $template . '/template/extension/' . $this->type . '/' . $this->name . '.twig';
		
		if (version_compare(VERSION, '3.0', '>=')) {
			$override_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "theme WHERE theme = '" . $this->db->escape($theme) . "' AND route = 'extension/" . $this->type . "/" . $this->name . "'");
			if ($override_query->num_rows) {
				$cache_file = DIR_CACHE . $this->name . '.twig.' . strtotime($override_query->row['date_added']);
				
				if (!file_exists($cache_file)) {
					$old_files = glob(DIR_CACHE . $this->name . '.twig.*');
					foreach ($old_files as $old_file) unlink($old_file);
					file_put_contents($cache_file, html_entity_decode($override_query->row['code'], ENT_QUOTES, 'UTF-8'));
				}
				
				$template_file = $cache_file;
			}
		}
		
		if (is_file($template_file)) {
			extract($data);
			
			ob_start();
			require(class_exists('VQMod') ? VQMod::modCheck(modification($template_file)) : modification($template_file));
			$output = ob_get_clean();
			
			echo $output;
		} else {
			echo 'Error loading template file';
		}
	}
	
	public function chargeLink() {
		$settings = $this->getSettings();
		parse_str(base64_decode($this->request->get['link_data']), $link_data);
		
		$currency = $link_data['currency'];
		$main_currency = $this->db->query("SELECT * FROM " . DB_PREFIX . "setting WHERE `key` = 'config_currency' AND store_id = 0")->row['value'];
		$decimal_factor = (in_array($settings['currencies_' . $currency], array('BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','VND','VUV','XAF','XOF','XPF'))) ? 1 : 100;
		
		$data = array(
			'amount'		=> round($decimal_factor * $this->currency->convert($link_data['amount'], $main_currency, $settings['currencies_' . $currency])),
			'currency'		=> $settings['currencies_' . $currency],
			'description'	=> $link_data['description'],
			'metadata'		=> array(
				'Store'			=> $this->config->get('config_name'),
				'Order ID'		=> $link_data['order_id'],
			),
			'source'		=> $this->request->post['token'],
		);
		
		if (!empty($link_data['statement_descriptor'])) {
			$data['statement_descriptor'] = $link_data['statement_descriptor'];
		}
		
		foreach ($data['metadata'] as &$metadata) {
			if (strlen($metadata) > 197) {
				$metadata = mb_substr($metadata, 0, 197, 'UTF-8') . '...';
			}
		}
		
		$charge_response = $this->curlRequest('POST', 'charges', $data);
		
		if (!empty($charge_response['error'])) {
			echo $charge_response['error']['message'];
		} else {
			$order_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE order_id = " . (int)$link_data['order_id']);
			
			if ($order_query->num_rows) {
				$comment = 'Paid ' . $this->currency->format($link_data['amount'], $currency) . ' via Stripe (charge ID ' . $charge_response['id'] . ')';
				$order_status_id = (!empty($link_data['order_status'])) ? $link_data['order_status'] : $order_query->row['order_status_id'];
				
				$this->db->query("UPDATE `" . DB_PREFIX . "order` SET order_status_id = " . (int)$order_status_id . ", date_modified = NOW() WHERE order_id = " . (int)$link_data['order_id']);
				$this->db->query("INSERT INTO " . DB_PREFIX . "order_history SET order_id = " . (int)$link_data['order_id'] . ", order_status_id = " . (int)$order_status_id . ", notify = 0, comment = '" . $this->db->escape($comment) . "', date_added = NOW()");
			}
		}
	}
	
	//==============================================================================
	// Webhook functions
	//==============================================================================
	public function webhook() {
		register_shutdown_function(array($this, 'logFatalErrors'));
		
		$settings = $this->getSettings();
		$event = @json_decode(file_get_contents('php://input'));
		
		if (empty($event->type)) {
			echo 'Stripe Payment Gateway webhook is working.';
			return;
		}
		
		if (!isset($this->request->get['key']) || $this->request->get['key'] != md5($this->config->get('config_encryption'))) {
			echo 'Wrong key';
			$this->log->write('STRIPE WEBHOOK ERROR: webhook URL key ' . $this->request->get['key'] . ' does not match the encryption key hash ' . md5($this->config->get('config_encryption')));
			return;
		}
		
		$this->load->model('checkout/order');
		
		if ($event->type == 'charge.refunded') {
			
			$order_history_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_history WHERE `comment` LIKE '%" . $this->db->escape($event->data->object->id) . "%' ORDER BY order_history_id DESC");
			if (!$order_history_query->num_rows) return;
			
			$refund = array_pop($event->data->object->refunds->data);
			$refund_currency = strtoupper($refund->currency);
			$decimal_factor = (in_array($refund_currency, array('BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','VND','VUV','XAF','XOF','XPF'))) ? 1 : 100;
			
			$strong = '<strong style="display: inline-block; width: 140px; padding: 3px">';
			$comment = $strong . 'Stripe Event:</strong>' . $event->type . '<br>';
			$comment .= $strong . 'Refund Amount:</strong>' . $this->currency->format($refund->amount / $decimal_factor, $refund_currency, 1) . '<br>';
			$comment .= $strong . 'Total Amount Refunded:</strong>' . $this->currency->format($event->data->object->amount_refunded / $decimal_factor, $refund_currency, 1);
			
			$order_id = $order_history_query->row['order_id'];
			$order_info = $this->model_checkout_order->getOrder($order_id);
			$refund_type = ($event->data->object->amount_refunded == $event->data->object->amount) ? 'refund' : 'partial';
			$order_status_id = ($settings[$refund_type . '_status_id']) ? $settings[$refund_type . '_status_id'] : $order_history_query->row['order_status_id'];
			
			$this->model_checkout_order->addOrderHistory($order_id, $order_status_id, $comment, false);
		
		} elseif ($event->type == 'customer.subscription.deleted') {
			
			/*
			$order_id = $event->data->object->metadata->order_id;
			$this->db->query("UPDATE `" . DB_PREFIX . "order` SET order_status_id = 7 WHERE order_id = " . (int)$order_id);
			$this->db->query("INSERT INTO " . DB_PREFIX . "order_history SET order_id = " . (int)$order_id . ", order_status_id = 7, notify = 0, comment = 'customer.subscription.deleted', date_added = NOW()");
			*/
			
		} elseif ($event->type == 'invoice.payment_succeeded') {
			
			if (empty($settings['subscriptions'])) return;
			
			// Check for Stripe errors
			$customer_response = $this->curlRequest('GET', 'customers/' . $event->data->object->customer, array('expand' => array('' => 'default_source')));
			if (!empty($customer_response['deleted']) || !empty($customer_response['error'])) {
				$this->log->write('STRIPE WEBHOOK ERROR: ' . $customer_response['error']['message']);
				return;
			}
			
			// Put together order data
			$data = array();
			
			$name = (isset($customer_response['default_source']['name'])) ? explode(' ', $customer_response['default_source']['name'], 2) : array();
			$firstname = (isset($name[0])) ? $name[0] : '';
			$lastname = (isset($name[1])) ? $name[1] : '';
			
			$customer_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "customer c LEFT JOIN " . DB_PREFIX . "stripe_customer sc ON (c.customer_id = sc.customer_id) WHERE sc.stripe_customer_id = '" . $this->db->escape($customer_response['id']) . "' AND sc.transaction_mode = '" . $this->db->escape($settings['transaction_mode']) . "'");
			
			$data['customer_id'] = (isset($customer_query->row['customer_id'])) ? $customer_query->row['customer_id'] : 0;
			$data['firstname'] = $firstname;
			$data['lastname'] = $lastname;
			$data['email'] = $customer_response['email'];
			
			$plan_name = '';
			$product_data = array();
			$total_data = array();
			$subtotal = 0;
			$shipping = false;
			
			foreach ($event->data->object->lines->data as $line) {
				$line_currency = strtoupper($line->currency);
				$line_decimal_factor = (in_array($line_currency, array('BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','VND','VUV','XAF','XOF','XPF'))) ? 1 : 100;
				
				if (empty($line->plan)) {
					
					// Add non-product line items
					$total_data[] = array(
						'code'			=> 'total',
						'title'			=> $line->description,
						'text'			=> $this->currency->format($line->amount / $line_decimal_factor, $line_currency, 1),
						'value'			=> $line->amount / $line_decimal_factor,
						'sort_order'	=> 2
					);
					
					// Add invoice item for shipping
					if (strpos($line->description, 'Shipping for') === 0) {
						$invoice_item_data = array(
							'amount'		=> $line->amount,
							'currency'		=> $line->currency,
							'customer'		=> $event->data->object->customer,
							'description'	=> $line->description,
						);
						
						$invoice_item_response = $this->curlRequest('POST', 'invoiceitems', $invoice_item_data);
						if (!empty($invoice_item_response['error'])) {
							$this->log->write($invoice_item_response['error']['message']);
						}
					}
					
				} else {
					
					$plan_name = $line->plan->name;
					$charge = $line->amount / $line_decimal_factor;
					$subtotal += $charge;
					
					$product_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "product p LEFT JOIN " . DB_PREFIX . "product_description pd ON (p.product_id = pd.product_id AND pd.language_id = " . (int)$this->config->get('config_language_id') . ") WHERE p.location = '" . $this->db->escape($line->plan->id) . "'");
					
					if ($product_query->num_rows) {
						$product = $product_query->row;
					} else {
						$product = array(
							'product_id'	=> 0,
							'name'			=> $plan_name,
							'model'			=> '',
							'subtract'		=> 0,
							'tax_class_id'	=> 0,
							'shipping'		=> 1,
						);
					}
					
					$shipping = !empty($product['shipping']);
					
					$product_data[] = array(
						'product_id'	=> $product['product_id'],
						'name'			=> $product['name'],
						'model'			=> $product['model'],
						'option'		=> array(),
						'download'		=> array(),
						'quantity'		=> $line->quantity,
						'subtract'		=> $product['subtract'],
						'price'			=> ($charge / $line->quantity),
						'total'			=> $charge,
						'tax'			=> $this->tax->getTax($charge, $product['tax_class_id']),
						'reward'		=> isset($product['reward']) ? $product['reward'] : 0
					);
				}
				
			}
			
			// Check for immediate subscriptions
			$now_query = $this->db->query("SELECT NOW()");
			$last_order_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE email = '" . $this->db->escape($customer_response['email']) . "' ORDER BY date_added DESC");
			if ($last_order_query->num_rows && (strtotime($now_query->row['NOW()']) - strtotime($last_order_query->row['date_added'])) < 600) {
				// Customer's last order is within 10 minutes, so it most likely was an immediate subscription and is already shown on their last order
				return;
			}
			
			// Set address data
			$country = (isset($customer_response['default_source']['address_country'])) ? $this->db->query("SELECT * FROM " . DB_PREFIX . "country WHERE `name` = '" . $this->db->escape($customer_response['default_source']['address_country']) . "'") : '';
			$country_id = (isset($country->row['country_id'])) ? $country->row['country_id'] : 0;
			
			$zone_id = 0;
			if (isset($customer_response['default_source']['address_state'])) {
				$zone_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone WHERE `name` = '" . $this->db->escape($customer_response['default_source']['address_state']) . "' AND country_id = " . (int)$country_id);
				if ($zone_query->num_rows) {
					$zone_id = $zone_query->row['zone_id'];
				} else {
					$zone_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone WHERE `code` = '" . $this->db->escape($customer_response['default_source']['address_state']) . "' AND country_id = " . (int)$country_id);
					if ($zone_query->num_rows) {
						$zone_id = $zone_query->row['zone_id'];
					}
				}
			}
			
			$data['payment_firstname'] = $firstname;
			$data['payment_lastname'] = $lastname;
			$data['payment_company'] = '';
			$data['payment_company_id'] = '';
			$data['payment_tax_id'] = '';
			$data['payment_address_1'] = $customer_response['default_source']['address_line1'];
			$data['payment_address_2'] = $customer_response['default_source']['address_line2'];
			$data['payment_city'] = $customer_response['default_source']['address_city'];
			$data['payment_postcode'] = $customer_response['default_source']['address_zip'];
			$data['payment_zone'] = $customer_response['default_source']['address_state'];
			$data['payment_zone_id'] = $zone_id;
			$data['payment_country'] = $customer_response['default_source']['address_country'];
			$data['payment_country_id'] = $country_id;
			
			if ($shipping) {
				$data['shipping_firstname'] = $firstname;
				$data['shipping_lastname'] = $lastname;
				$data['shipping_company'] = '';
				$data['shipping_company_id'] = '';
				$data['shipping_tax_id'] = '';
				$data['shipping_address_1'] = $customer_response['default_source']['address_line1'];
				$data['shipping_address_2'] = $customer_response['default_source']['address_line2'];
				$data['shipping_city'] = $customer_response['default_source']['address_city'];
				$data['shipping_postcode'] = $customer_response['default_source']['address_zip'];
				$data['shipping_zone'] = $customer_response['default_source']['address_state'];
				$data['shipping_zone_id'] = $zone_id;
				$data['shipping_country'] = $customer_response['default_source']['address_country'];
				$data['shipping_country_id'] = $country_id;
			}
			
			// Set order totals
			$data['currency_code'] = strtoupper($event->data->object->currency);
			$data['currency_id'] = $this->currency->getId($data['currency_code']);
			$data['currency_value'] = $this->currency->getValue($data['currency_code']);
			
			$decimal_factor = (in_array($data['currency_code'], array('BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','VND','VUV','XAF','XOF','XPF'))) ? 1 : 100;
			
			$total_data[] = array(
				'code'			=> 'sub_total',
				'title'			=> 'Sub-Total',
				'text'			=> $this->currency->format($subtotal, $data['currency_code'], 1),
				'value'			=> $subtotal,
				'sort_order'	=> 1
			);
			if (!empty($event->data->object->tax)) {
				$total_data[] = array(
					'code'			=> 'tax',
					'title'			=> 'Tax',
					'text'			=> $this->currency->format($event->data->object->tax / $decimal_factor, $data['currency_code'], 1),
					'value'			=> $event->data->object->tax / $decimal_factor,
					'sort_order'	=> 2
				);
			}
			$total_data[] = array(
				'code'			=> 'total',
				'title'			=> 'Total',
				'text'			=> $this->currency->format($event->data->object->total / $decimal_factor, $data['currency_code'], 1),
				'value'			=> $event->data->object->total / $decimal_factor,
				'sort_order'	=> 3
			);
			
			$data['products'] = $product_data;
			$data['totals'] = $total_data;
			$data['total'] = $event->data->object->total / $decimal_factor;
			
			// Create order in database
			$this->load->model('extension/' . $this->type . '/' . $this->name);
			$order_id = $this->{'model_extension_'.$this->type.'_'.$this->name}->createOrder($data);
			$order_status_id = $settings['success_status_id'];
			
			$strong = '<strong style="display: inline-block; width: 140px; padding: 3px">';
			$comment = $strong . 'Charged for Plan:</strong>' . $plan_name . '<br>';
			$comment .= $strong . 'Stripe Event ID:</strong>' . $event->id . '<br>';
			if (!empty($event->data->object->charge)) {
				$comment .= $strong . 'Stripe Charge ID:</strong>' . $event->data->object->charge . '<br>';
			}
			
			$this->model_checkout_order->addOrderHistory($order_id, $order_status_id, $comment, false);
		}
		
	}
	
	//==============================================================================
	// Private functions
	//==============================================================================
	private function getSettings() {
		$code = (version_compare(VERSION, '3.0', '<') ? '' : $this->type . '_') . $this->name;
		
		$settings = array();
		$settings_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "setting WHERE `code` = '" . $this->db->escape($code) . "' ORDER BY `key` ASC");
		
		foreach ($settings_query->rows as $setting) {
			$value = $setting['value'];
			if ($setting['serialized']) {
				$value = (version_compare(VERSION, '2.1', '<')) ? unserialize($setting['value']) : json_decode($setting['value'], true);
			}
			$split_key = preg_split('/_(\d+)_?/', str_replace($code . '_', '', $setting['key']), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
			
				if (count($split_key) == 1)	$settings[$split_key[0]] = $value;
			elseif (count($split_key) == 2)	$settings[$split_key[0]][$split_key[1]] = $value;
			elseif (count($split_key) == 3)	$settings[$split_key[0]][$split_key[1]][$split_key[2]] = $value;
			elseif (count($split_key) == 4)	$settings[$split_key[0]][$split_key[1]][$split_key[2]][$split_key[3]] = $value;
			else 							$settings[$split_key[0]][$split_key[1]][$split_key[2]][$split_key[3]][$split_key[4]] = $value;
		}
		
		return $settings;
	}
	
	private function replaceShortcodes($text, $order_info) {
		$product_names = array();
		foreach ($this->cart->getProducts() as $product) {
			$options = array();
			foreach ($product['option'] as $option) {
				$options[] = $option['name'] . ': ' . $option['value'];
			}
			$product_name = $product['name'] . ($options ? ' (' . implode(', ', $options) . ')' : '');
			$product_names[] = html_entity_decode($product_name, ENT_QUOTES, 'UTF-8');
		}
		
		$replace = array(
			'[store]',
			'[order_id]',
			'[amount]',
			'[email]',
			'[comment]',
			'[products]'
		);
		$with = array(
			$this->config->get('config_name'),
			$order_info['order_id'],
			$this->currency->format($order_info['total'], $this->session->data['currency']),
			$order_info['email'],
			$order_info['comment'],
			implode(', ', $product_names)
		);
		
		return str_replace($replace, $with, $text);
	}
	
	private function curlRequest($request, $api, $data = array()) {
		$settings = $this->getSettings();
		
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
}
?>