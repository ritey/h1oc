<?php
class ModelExtensionShippingZones extends Model {
	function getQuote($address) {
		$this->load->language('extension/shipping/zones');
		
		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get('shipping_flat_geo_zone_id') . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");
	
		if (!$this->config->get('shipping_flat_geo_zone_id')) {
			$status = true;
		} elseif ($query->num_rows) {
			$status = true;
		} else {
			$status = false;
		}

		$method_data = array();
	
		if ($status) {
			$quote_data = array();
			$getShippingPrice=$this->model_extension_shipping_zones->getShippingPrice($address['zone_id']);
      		$quote_data['zones'] = array(
        		'code'         => 'zones.zones',
        		'title'        => $this->language->get('text_title'),
        		'cost'         => $getShippingPrice,
        		'tax_class_id' => $this->config->get('shipping_flat_tax_class_id'),
				'text'         => $this->currency->format($this->tax->calculate($getShippingPrice, $this->config->get('shipping_flat_tax_class_id'), $this->config->get('config_tax')), $this->session->data['currency'])
      		);

      		$method_data = array(
        		'code'       => 'zones',
        		'title'      => $this->language->get('text_title'),
        		'quote'      => $quote_data,
				'sort_order' => $this->config->get('shipping_flat_sort_order'),
        		'error'      => false
      		);
		}
	
		return $method_data;
	}
	function getShippingPrice($zone_id='')
	{
		$query = $this->db->query("SELECT " . DB_PREFIX . "geo_zone.shipping_price FROM " . DB_PREFIX . "geo_zone," . DB_PREFIX . "zone_to_geo_zone where " . DB_PREFIX . "zone_to_geo_zone.zone_id='".$zone_id."' AND " . DB_PREFIX . "zone_to_geo_zone.geo_zone_id=" . DB_PREFIX . "geo_zone.geo_zone_id");
			$zone_data = $query->rows;
			if($zone_data)
			{
				return $zone_data[0]['shipping_price'];
			}
			else
			{
				$qQuery="SELECT " . DB_PREFIX . "geo_zone.shipping_price FROM " . DB_PREFIX . "geo_zone," . DB_PREFIX . "zone," . DB_PREFIX . "zone_to_geo_zone where " . DB_PREFIX . "zone_to_geo_zone.zone_id='0' AND " . DB_PREFIX . "zone_to_geo_zone.geo_zone_id=" . DB_PREFIX . "geo_zone.geo_zone_id AND " . DB_PREFIX . "zone.zone_id=" . $zone_id . " AND " . DB_PREFIX . "zone.country_id=" . DB_PREFIX . "zone_to_geo_zone.country_id";
				$query = $this->db->query($qQuery);
				$zone_data = $query->rows;
				if($zone_data)
				{
					return $zone_data[0]['shipping_price'];
				}
				else
				{
					return 0;
				}
			}
	}
}
?>