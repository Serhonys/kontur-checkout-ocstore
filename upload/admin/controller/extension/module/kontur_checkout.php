<?php
class ControllerExtensionModuleKonturCheckout extends Controller {
    public function index() {
        $this->load->language('extension/module/kontur_checkout');

        if (!$this->user->hasPermission('access', 'extension/module/kontur_checkout')) {
            $this->response->setOutput($this->language->get('error_permission'));
            return;
        }

        require_once(DIR_SYSTEM . 'library/kontur_checkout_data.php');

        $token = '';
        $token_key = 'user_token';
        if (isset($this->session->data['user_token'])) {
            $token = $this->session->data['user_token'];
        } elseif (isset($this->session->data['token'])) {
            $token = $this->session->data['token'];
            $token_key = 'token';
        }

        $html = file_get_contents(DIR_APPLICATION . 'view/template/extension/module/kontur_checkout.html');

        $saved = $this->config->get('module_kontur_checkout_config');
        if (is_string($saved)) {
            $saved = json_decode($saved, true);
        }

        $config = kontur_checkout_merge($saved);
        $status = $this->config->get('module_kontur_checkout_status');
        if ($status !== null && $status !== '') {
            $config['enabled'] = (bool)$status;
        }

        $save = html_entity_decode($this->url->link('extension/module/kontur_checkout/save', $token_key . '=' . $token, true), ENT_QUOTES, 'UTF-8');
        $back = html_entity_decode($this->url->link('marketplace/extension', $token_key . '=' . $token . '&type=module', true), ENT_QUOTES, 'UTF-8');
        $np_sync = html_entity_decode($this->url->link('extension/module/kontur_checkout/npSync', $token_key . '=' . $token, true), ENT_QUOTES, 'UTF-8');
        $np_status = html_entity_decode($this->url->link('extension/module/kontur_checkout/npStatus', $token_key . '=' . $token, true), ENT_QUOTES, 'UTF-8');

        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $html = str_replace('__KONTUR_CONFIG_JSON__', $json, $html);
        $html = str_replace('__KONTUR_SAVE_URL__', $save, $html);
        $html = str_replace('__KONTUR_BACK_URL__', $back, $html);
        $html = str_replace('__KONTUR_NP_SYNC_URL__', $np_sync, $html);
        $html = str_replace('__KONTUR_NP_STATUS_URL__', $np_status, $html);

        $this->response->setOutput($html);
    }

    public function save() {
        $this->load->language('extension/module/kontur_checkout');
        $json = array();

        if (!$this->user->hasPermission('modify', 'extension/module/kontur_checkout')) {
            $json['error'] = $this->language->get('error_permission');
        } else {
            require_once(DIR_SYSTEM . 'library/kontur_checkout_data.php');

            $payload = json_decode(file_get_contents('php://input'), true);
            if (!is_array($payload) || empty($payload['config']) || !is_array($payload['config'])) {
                $json['error'] = 'Порожня конфігурація';
            } else {
                $config = kontur_checkout_merge($payload['config']);
                $config['enabled'] = !empty($payload['config']['enabled']);

                $this->load->model('setting/setting');
                $this->model_setting_setting->editSetting('module_kontur_checkout', array(
                    'module_kontur_checkout_status' => $config['enabled'] ? 1 : 0,
                    'module_kontur_checkout_config' => json_encode($config, JSON_UNESCAPED_UNICODE)
                ));

                $json['success'] = $this->language->get('text_success');
            }
        }

        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $this->response->setOutput(json_encode($json));
    }

    public function npStatus() {
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        if (!$this->user->hasPermission('access', 'extension/module/kontur_checkout')) {
            $this->response->setOutput(json_encode(array('error' => 'Немає доступу')));
            return;
        }
        require_once(DIR_SYSTEM . 'library/kontur_np.php');
        $counts = kontur_np_ready($this->db) ? kontur_np_counts($this->db) : array('warehouses' => 0, 'cities' => 0);
        $meta = kontur_np_meta_read($this->db);
        $this->response->setOutput(json_encode(array(
            'warehouses' => $counts['warehouses'],
            'cities' => $counts['cities'],
            'updated' => isset($meta['updated']) ? $meta['updated'] : ''
        )));
    }

    public function npSync() {
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        if (!$this->user->hasPermission('modify', 'extension/module/kontur_checkout')) {
            $this->response->setOutput(json_encode(array('error' => 'Немає прав на зміну модуля')));
            return;
        }
        require_once(DIR_SYSTEM . 'library/kontur_np.php');
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $payload = array();
        }
        $key = isset($payload['key']) ? trim((string)$payload['key']) : '';
        if (!kontur_np_key_ok($key)) {
            $this->response->setOutput(json_encode(array('error' => 'Потрібен API-ключ Нової Пошти: 32 символи з кабінету. ТТН не створюються.')));
            return;
        }
        $page = isset($payload['page']) ? (int)$payload['page'] : 1;
        $reset = !empty($payload['reset']);
        $result = kontur_np_import_page($this->db, $key, $page, $reset);
        $this->response->setOutput(json_encode($result, JSON_UNESCAPED_UNICODE));
    }

    public function install() {
        require_once(DIR_SYSTEM . 'library/kontur_checkout_data.php');

        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode('kontur_checkout');
        $this->model_setting_event->addEvent(
            'kontur_checkout',
            'catalog/controller/checkout/checkout/before',
            'extension/module/kontur_checkout/checkoutBefore'
        );

        $this->load->model('setting/setting');
        $config = kontur_checkout_defaults();
        $this->model_setting_setting->editSetting('module_kontur_checkout', array(
            'module_kontur_checkout_status' => 1,
            'module_kontur_checkout_config' => json_encode($config, JSON_UNESCAPED_UNICODE)
        ));

        $this->load->model('user/user_group');
        $this->model_user_user_group->addPermission($this->user->getGroupId(), 'access', 'extension/module/kontur_checkout');
        $this->model_user_user_group->addPermission($this->user->getGroupId(), 'modify', 'extension/module/kontur_checkout');

        require_once(DIR_SYSTEM . 'library/kontur_np.php');
        kontur_np_ensure($this->db);
    }

    public function uninstall() {
        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode('kontur_checkout');

        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting('module_kontur_checkout');

        require_once(DIR_SYSTEM . 'library/kontur_np.php');
        kontur_np_drop($this->db);
    }
}
