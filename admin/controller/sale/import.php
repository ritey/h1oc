<?php
class ControllerSaleImport extends Controller {
	private $error = array();

	public function index() {

		$data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['breadcrumbs'][] = array(
			'text' => 'Import order data',
			'href' => $this->url->link('sale/import', 'user_token=' . $this->session->data['user_token'] . $url, true)
		);

		$data['user_token'] = $this->session->data['user_token'];
	    $data['action_url'] = $this->url->link('sale/import/upload', 'user_token=' . $this->session->data['user_token'] . $url, true);

		if (isset($this->error['warning'])) {
			$data['error_warning'] = $this->error['warning'];
		} else {
			$data['error_warning'] = '';
		}

		if (isset($this->session->data['success'])) {
			$data['success'] = $this->session->data['success'];

			unset($this->session->data['success']);
		} else {
			$data['success'] = '';
		}

        $data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('sale/order_import', $data));
    }

    public function upload()
    {
        $allowed = ['csv'];

		$filename = html_entity_decode($this->request->files['file']['name'], ENT_QUOTES, 'UTF-8');
        if (!in_array(strtolower(substr(strrchr($filename, '.'), 1)), $allowed)) {
            $this->session->data['success'] = 'No data imported. CSV file format is required!!';
            $this->response->redirect($this->url->link('sale/order', 'user_token=' . $this->session->data['user_token'] . $url, true));
        }

		$file = $filename . '.' . token(32);
        move_uploaded_file($this->request->files['file']['tmp_name'], DIR_UPLOAD . $file);
        
        $fileHandle = fopen(DIR_UPLOAD . $file, "r");

        //Loop through the CSV rows.
        while (($row = fgetcsv($fileHandle, 0, ",")) !== FALSE) {
            if ($row[0] != 'Order number') {
                $data = [
                    'order_id' => trim(str_replace('Web ','',$row[4])),
                    'shipping' => $row[15],
                    'tracking' => $row[17],
                ];

                $this->load->model('sale/order');
                $order = $this->model_sale_order->getOrder($data['order_id']);;

                if (is_numeric($data['order_id']) && isset($order['store_name'])) { // && $order['order_status_id'] != 3) {
                     
                    $comment = 'Order shipped via: ' . $data['shipping'].'. Tracking ref:' . $data['tracking'];

                    // Update the DB with the new statuses
                    $this->db->query("UPDATE `" . DB_PREFIX . "order` SET order_status_id = '3', date_modified = NOW() WHERE order_id = '" . (int)$data['order_id'] . "'");

                    $this->db->query("INSERT INTO " . DB_PREFIX . "order_history SET order_id = '" . (int)$data['order_id'] . "', order_status_id = '3', notify = '0', comment = '" . $this->db->escape($comment) . "', date_added = NOW()");

                }
            }
        }

        $this->session->data['success'] = 'Data imported';
		$this->response->redirect($this->url->link('sale/order', 'user_token=' . $this->session->data['user_token'] . $url, true));
    }
}