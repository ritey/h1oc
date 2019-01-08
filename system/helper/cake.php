<?php
	class Cake {
		
		public function __construct($registry) {
			$this->db = $registry->get('db');
	        $this->session = $registry->get('session');
	        $this->config = $registry->get('config');
	        $this->log = $registry->get('log');
	        $this->registry = $registry;			
		}
		
		public function findUser($email) {
			
		}
		
		private function register($data) {
			$customer_group_id = 1;
			
			$sql = "INSERT INTO " . DB_PREFIX . "customer SET customer_group_id = '" . (int)$customer_group_id . "', store_id = '" . (int)$this->config->get('config_store_id') . "', language_id = '" . (int)$this->config->get('config_language_id') . "', firstname = '" . $this->db->escape($data['firstname']) . "', lastname = '" . $this->db->escape($data['lastname']) . "', email = '" . $this->db->escape($data['email']) . "', telephone = '" . $this->db->escape($data['telephone']) . "', custom_field = '" . $this->db->escape(isset($data['custom_field']['account']) ? json_encode($data['custom_field']['account']) : '') . "', salt = '" . $this->db->escape($salt = token(9)) . "', password = '" . $this->db->escape(sha1($salt . sha1($salt . sha1($data['password'])))) . "', newsletter = '" . (isset($data['newsletter']) ? (int)$data['newsletter'] : 0) . "', ip = '" . $this->db->escape($this->request->server['REMOTE_ADDR']) . "', status = '0', date_added = NOW()";
		}
		
	}	
	