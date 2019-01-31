<?php
class ModelLocalisationZones extends Model {

	public function getGeoAllZones($data = array()) {
			
			$feildExist="false";
			$query=$this->db->query("SHOW COLUMNS FROM " . DB_PREFIX . "geo_zone");
			$geo_zone_data = $query->rows;
			while(list($key,$val)=each($geo_zone_data))
			{
				if($val['Field']=='shipping_price')
				{
					$feildExist="true";
				}
			}
			if($feildExist=='false')
			{
				$this->db->query("ALTER TABLE " . DB_PREFIX . "geo_zone ADD shipping_price FLOAT NULL");
			}

			$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "geo_zone ORDER BY name ASC");
			$geo_zone_data = $query->rows;
			return $geo_zone_data;
	}

	public function saveGeoAllZonesPrice($data) {
			while(list($key,$val)=each($data))
			{
				$query="UPDATE " . DB_PREFIX . "geo_zone SET shipping_price = '" . $val . "' WHERE geo_zone_id = '" . (int)$key . "'";
				$this->db->query($query);
			}
				
		
	}		
}
?>