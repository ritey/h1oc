<?php
class ControllerExtensionModuleRoyalDrop extends Controller {
	private $error = array();

	public function index() {
		$this->load->language('extension/module/royal_drop');

		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('setting/setting');
		
		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$this->model_setting_setting->editSetting('module_royal_drop', $this->request->post);

			$this->session->data['success'] = $this->language->get('text_success');

			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true));
		}
		
		if (isset($this->error['warning'])) {
			$data['error_warning'] = $this->error['warning'];
		} else {
			$data['error_warning'] = '';
		}

		if (isset($this->error['token'])) {
			$data['error_token'] = $this->error['token'];
		} else {
			$data['error_token'] = '';
		}

		if (isset($this->error['path'])) {
			$data['error_path'] = $this->error['path'];
		} else {
			$data['error_path'] = '';
		}

		$data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/module/royal_drop', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['action'] = $this->url->link('extension/module/royal_drop', 'user_token=' . $this->session->data['user_token'], true);

		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);

		if (isset($this->request->post['module_royal_drop_token'])) {
			$data['module_royal_drop_token'] = $this->request->post['module_royal_drop_token'];
		} else {
			$data['module_royal_drop_token'] = $this->config->get('module_royal_drop_token');
		}

		if (isset($this->request->post['module_royal_drop_path'])) {
			$data['module_royal_drop_path'] = $this->request->post['module_royal_drop_path'];
		} else {
			$data['module_royal_drop_path'] = $this->config->get('module_royal_drop_path');
		}

		if (isset($this->request->post['module_royal_drop_status'])) {
			$data['module_royal_drop_status'] = $this->request->post['module_royal_drop_status'];
		} else {
			$data['module_royal_drop_status'] = $this->config->get('module_royal_drop_status');
		}

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/module/royal_drop', $data));
	}

	protected function validate() {
		if (!$this->user->hasPermission('modify', 'extension/module/royal_drop')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		if (!$this->request->post['module_royal_drop_token']) {
			$this->error['token'] = $this->language->get('error_token');
		}

		if (!$this->request->post['module_royal_drop_path']) {
			$this->error['path'] = $this->language->get('error_path');
		}

		return !$this->error;
	}
}