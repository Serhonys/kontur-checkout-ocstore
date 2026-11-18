<?php
class ControllerExtensionModuleKonturCheckout extends Controller {
    public function checkoutBefore(&$route, &$data) {
        if (!$this->config->get('module_kontur_checkout_status')) {
            return null;
        }

        $html = $this->page();
        if (is_string($html) && $html !== '') {
            $this->response->setOutput($html);
        }

        return $html === null ? '' : $html;
    }

    public function page() {
        if (!$this->config->get('module_kontur_checkout_status')) {
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
            return '';
        }

        $guard = $this->guardCart();
        if ($guard !== '') {
            return $guard;
        }

        require_once(DIR_SYSTEM . 'library/kontur_checkout_data.php');
        $this->load->language('extension/module/kontur_checkout');
        $this->load->language('checkout/checkout');

        $config = $this->moduleConfig();
        $this->document->setTitle($this->language->get('heading_title'));

        $font = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&family=Playfair+Display:wght@500;600;700&display=swap';
        $this->document->addStyle($font);
        $this->document->addStyle('catalog/view/javascript/kontur_checkout/checkout.css?v=2.5.2');

        $data = array();
        $data['heading_title'] = $this->language->get('heading_title');
        $data['home'] = $this->url->link('common/home');
        $data['cart_url'] = $this->url->link('checkout/cart');
        $data['text_home'] = $this->language->get('text_home');
        $data['text_cart'] = $this->language->get('text_cart');

        $checkout_id = (int)$this->config->get('config_checkout_id');
        $account_id = (int)$this->config->get('config_account_id');
        $data['terms_url'] = $checkout_id ? $this->url->link('information/information', 'information_id=' . $checkout_id) : $this->url->link('information/contact');
        $data['privacy_url'] = $account_id ? $this->url->link('information/information', 'information_id=' . $account_id) : $this->url->link('information/contact');
        $data['forgotten'] = $this->url->link('account/forgotten', '', true);
        $data['success_url'] = $this->url->link('checkout/success', '', true);

        $data['config'] = $config;
        $data['fields'] = $this->fieldsForView($config);
        $data['shipping_methods'] = $this->enabledShipping($config);
        $data['payment_methods'] = $config['paymentMethods'];
        $data['has_shipping'] = $this->cart->hasShipping() || !$this->cart->hasProducts();
        if (!$this->cart->hasProducts() && !empty($this->session->data['vouchers'])) {
            $data['has_shipping'] = false;
        } else {
            $data['has_shipping'] = $this->cart->hasShipping();
        }

        $data['customer_logged'] = $this->customer->isLogged();
        $data['theme_class'] = $this->themeClass($config);
        $data['theme_style'] = $this->themeStyle($config);
        $data['custom_css'] = isset($config['theme']['customCss']) ? $config['theme']['customCss'] : '';
        $data['layout'] = isset($config['desktopLayout']) ? $config['desktopLayout'] : '62-38';
        $data['pattern'] = isset($config['mobilePattern']) ? $config['mobilePattern'] : 'accordion_sticky';
        $data['shipping_first'] = !empty($config['shippingMethodFirst']);
        $data['show_customer_tabs'] = !empty($config['showCustomerTabs']) && !$this->customer->isLogged();
        $data['show_promo'] = !empty($config['showPromo']);
        $data['show_gift'] = !empty($config['showGiftWrap']);
        $data['show_nocall'] = !empty($config['showNoCall']);
        $data['show_privacy'] = !empty($config['requirePrivacyPolicy']);
        $data['show_chips'] = !empty($config['showCityChips']);
        $data['show_timer'] = !empty($config['showReserveTimer']);
        $data['show_free_bar'] = !empty($config['showFreeShippingBar']);
        $data['cities'] = isset($config['quickCities']) ? $config['quickCities'] : array();
        $data['np_live'] = !empty($config['integrations']['npLiveSearch']);
        require_once(DIR_SYSTEM . 'library/kontur_np.php');
        $data['np_db'] = kontur_np_ready($this->db) && kontur_np_counts($this->db)['warehouses'] > 0;
        $data['badges'] = $this->integrationBadges($config);
        $data['script_url'] = ($this->request->server['HTTPS'] ? HTTPS_SERVER : HTTP_SERVER) . 'catalog/view/javascript/kontur_checkout/checkout.js?v=2.5.2';
        $data['boot_json'] = json_encode($this->boot($config, $data['has_shipping']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $data['header'] = $this->load->controller('common/header');
        $data['footer'] = $this->load->controller('common/footer');

        return $this->load->view('extension/module/kontur_checkout', $data);
    }

    public function confirm() {
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        require_once(DIR_SYSTEM . 'library/kontur_checkout_data.php');

        if (!$this->config->get('module_kontur_checkout_status')) {
            $this->jsonOut(array('error' => 'Модуль вимкнено'));
            return;
        }

        if ((!$this->cart->hasProducts() && empty($this->session->data['vouchers'])) || (!$this->cart->hasStock() && !$this->config->get('config_stock_checkout'))) {
            $this->jsonOut(array('redirect' => $this->url->link('checkout/cart')));
            return;
        }

        $request = json_decode(file_get_contents('php://input'), true);
        if (!is_array($request)) {
            $request = $this->request->post;
        }

        $config = $this->moduleConfig();
        $errors = array();
        $fieldsIn = isset($request['fields']) && is_array($request['fields']) ? $request['fields'] : array();
        $values = array();

        foreach ($config['fields'] as $field) {
            if (empty($field['enabled'])) {
                continue;
            }
            $id = $field['id'];
            $value = isset($fieldsIn[$id]) ? trim((string)$fieldsIn[$id]) : '';
            $values[$id] = $value;

            if ($id === 'telephone') {
                $digits = preg_replace('/\D/', '', $value);
                if (!empty($field['required']) && strlen($digits) < 12) {
                    $errors[$id] = 'Введіть коректний номер телефону';
                } elseif ($digits && !empty($config['integrations']['validateUaOperator']) && strlen($digits) >= 5) {
                    $op = substr($digits, 2, 3);
                    if (!in_array($op, kontur_checkout_operators(), true)) {
                        $errors[$id] = 'Невідомий код оператора України (' . $op . ')';
                    }
                }
                continue;
            }

            if ($id === 'email' || (isset($field['type']) && $field['type'] === 'email')) {
                if (!empty($field['required']) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$id] = 'Введіть коректний E-mail';
                } elseif ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$id] = 'Введіть коректний E-mail';
                }
                continue;
            }

            if (!empty($field['required']) && $value === '') {
                $errors[$id] = 'Заповніть поле «' . $field['label'] . '»';
            }
        }

        $has_shipping = $this->cart->hasShipping();
        $ship = null;
        if ($has_shipping) {
            $ship = $this->findShipping($config, isset($request['shipping_id']) ? $request['shipping_id'] : '');
            if (!$ship) {
                $errors['shipping'] = 'Оберіть спосіб доставки';
            }
        }

        $city = trim(isset($request['city']) ? (string)$request['city'] : '');
        $branch = trim(isset($request['branch']) ? (string)$request['branch'] : '');
        $street = trim(isset($request['street']) ? (string)$request['street'] : '');
        $house = trim(isset($request['house']) ? (string)$request['house'] : '');
        $apt = trim(isset($request['apt']) ? (string)$request['apt'] : '');
        $ctime = trim(isset($request['courier_time']) ? (string)$request['courier_time'] : '');
        $postcode = preg_replace('/\D/', '', isset($request['postcode']) ? (string)$request['postcode'] : '');
        $up = trim(isset($request['up_branch']) ? (string)$request['up_branch'] : '');
        $slot = trim(isset($request['showroom_slot']) ? (string)$request['showroom_slot'] : '');

        if ($ship) {
            $type = $ship['addressType'];
            if ($type !== 'pickup' && $city === '') {
                $errors['city'] = 'Оберіть або введіть місто доставки';
            }
            if ($type === 'branch' && $branch === '') {
                $errors['branch'] = 'Оберіть відділення або поштомат';
            }
            if ($type === 'courier') {
                if ($street === '') {
                    $errors['street'] = 'Вкажіть назву вулиці';
                }
                if ($house === '') {
                    $errors['house'] = 'Вкажіть номер будинку';
                }
            }
            if ($type === 'postcode') {
                if (strlen($postcode) !== 5) {
                    $errors['postcode'] = 'Введіть 5 цифр індексу';
                }
                if ($up === '') {
                    $errors['up_branch'] = 'Вкажіть відділення Укрпошти';
                }
            }
        }

        $pay = $this->findPayment($config, $ship, isset($request['payment_id']) ? $request['payment_id'] : '');
        if (!$pay) {
            $errors['payment'] = 'Оберіть спосіб оплати';
        }

        $edrpou = preg_replace('/\D/', '', isset($request['edrpou']) ? (string)$request['edrpou'] : '');
        $company = trim(isset($request['company']) ? (string)$request['company'] : '');
        if ($pay && $pay['id'] === 'invoice') {
            if (strlen($edrpou) !== 8 && strlen($edrpou) !== 10) {
                $errors['edrpou'] = 'Введіть 8 або 10 цифр';
            }
            if ($company === '') {
                $errors['company'] = 'Вкажіть назву платника';
            }
        }

        if (!empty($config['requirePrivacyPolicy']) && empty($request['agree'])) {
            $errors['agree'] = 'Необхідно прийняти умови публічної оферти';
        }

        if ($errors) {
            $this->jsonOut(array('errors' => $errors, 'message' => 'Перевірте виділені поля форми'));
            return;
        }

        $firstname = isset($values['firstname']) && $values['firstname'] !== '' ? $values['firstname'] : 'Покупець';
        $lastname = isset($values['lastname']) ? $values['lastname'] : '';
        $email = isset($values['email']) ? $values['email'] : '';
        $telephone = isset($values['telephone']) ? $values['telephone'] : '';

        $promo = $this->resolvePromo(isset($request['promo']) ? $request['promo'] : '', $this->gross());
        $gift = !empty($config['showGiftWrap']) && !empty($request['gift_wrap']);
        $calc = $this->calculate($this->gross(), $ship, $promo, $gift, $has_shipping);

        $address = $this->composeAddress($ship, $city, $branch, $street, $house, $apt, $ctime, $postcode, $up, $slot);
        $comment = trim(isset($request['comment']) ? (string)$request['comment'] : '');
        $extra = array();

        if (!empty($request['no_call']) && !empty($config['showNoCall'])) {
            $extra[] = 'Не телефонувати для підтвердження. Надіслати ТТН у Viber / Telegram / SMS.';
        }
        if ($company !== '') {
            $extra[] = 'Платник: ' . $company . ($edrpou ? ', ЄДРПОУ/ІПН ' . $edrpou : '');
        }
        foreach ($config['fields'] as $field) {
            if (empty($field['enabled']) || empty($field['custom'])) {
                continue;
            }
            if (!empty($values[$field['id']])) {
                $extra[] = $field['label'] . ': ' . $values[$field['id']];
            }
        }
        if ($slot && $ship && $ship['addressType'] === 'pickup') {
            $extra[] = 'Самовивіз: ' . $slot;
        }
        if ($extra) {
            $comment = trim($comment . "\n" . implode("\n", $extra));
        }

        $order_id = $this->createOrder($config, $firstname, $lastname, $email, $telephone, $ship, $pay, $address, $city, $postcode, $company, $comment, $calc, $promo);

        if (!$order_id) {
            $this->jsonOut(array('message' => 'Не вдалося створити замовлення. Спробуйте ще раз.'));
            return;
        }

        $this->jsonOut(array(
            'success' => true,
            'order_id' => (int)$order_id,
            'order_number' => '#OC-' . (int)$order_id,
            'firstname' => $firstname,
            'email' => $email,
            'shipping' => $ship ? $ship['title'] : 'Без доставки',
            'address' => $address,
            'payment' => $pay['title'],
            'total' => $this->formatMoney($calc['total']),
            'success_url' => $this->url->link('checkout/success', '', true),
            'analytics' => array(
                'ga4' => !empty($config['integrations']['analyticsGA4']),
                'meta' => !empty($config['integrations']['analyticsMetaPixel']),
                'prro' => !empty($config['integrations']['prroCheckbox']),
                'value' => round($calc['total'], 2),
                'currency' => $this->session->data['currency']
            )
        ));
    }

    public function login() {
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $request = json_decode(file_get_contents('php://input'), true);
        if (!is_array($request)) {
            $request = $this->request->post;
        }

        $email = isset($request['email']) ? trim($request['email']) : '';
        $password = isset($request['password']) ? $request['password'] : '';

        if (!$this->customer->login($email, $password)) {
            $this->jsonOut(array('message' => 'Невірний E-mail / телефон або пароль'));
            return;
        }

        $this->jsonOut(array(
            'success' => true,
            'customer' => array(
                'firstname' => $this->customer->getFirstName(),
                'lastname' => $this->customer->getLastName(),
                'email' => $this->customer->getEmail(),
                'telephone' => $this->customer->getTelephone()
            )
        ));
    }

    public function cart() {
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $request = json_decode(file_get_contents('php://input'), true);
        if (!is_array($request)) {
            $request = $this->request->post;
        }

        $key = isset($request['key']) ? (string)$request['key'] : '';
        $qty = isset($request['qty']) ? (int)$request['qty'] : 1;

        if (strpos($key, 'voucher:') === 0) {
            $vkey = substr($key, 8);
            unset($this->session->data['vouchers'][$vkey]);
        } elseif ($key !== '') {
            if ($qty < 1) {
                $this->cart->remove($key);
            } else {
                $this->cart->update($key, $qty);
            }
        }

        require_once(DIR_SYSTEM . 'library/kontur_checkout_data.php');
        $config = $this->moduleConfig();
        $this->jsonOut(array(
            'cart' => $this->cartLines(),
            'gross' => $this->gross(),
            'tax' => array_sum($this->cart->getTaxes()),
            'pricesIncludeTax' => (bool)$this->config->get('config_tax'),
            'count' => $this->cartCount(),
            'empty' => (!$this->cart->hasProducts() && empty($this->session->data['vouchers'])),
            'cart_url' => $this->url->link('checkout/cart')
        ));
    }

    public function coupon() {
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        require_once(DIR_SYSTEM . 'library/kontur_checkout_data.php');
        $request = json_decode(file_get_contents('php://input'), true);
        if (!is_array($request)) {
            $request = $this->request->post;
        }

        $code = isset($request['code']) ? trim($request['code']) : '';
        $promo = $this->resolvePromo($code, $this->gross());
        if (!$promo) {
            $this->jsonOut(array('message' => 'Промокод не знайдено (спробуйте SALE10 або GIFT1000)'));
            return;
        }

        $this->jsonOut(array(
            'success' => true,
            'promo' => $promo,
            'message' => $promo['type'] === 'percent'
                ? ('Промокод ' . $promo['code'] . ' застосовано: −' . (int)$promo['value'] . '%')
                : ('Сертифікат ' . $promo['code'] . ' активовано')
        ));
    }

    public function branches() {
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        require_once(DIR_SYSTEM . 'library/kontur_checkout_data.php');
        require_once(DIR_SYSTEM . 'library/kontur_np.php');

        $city = isset($this->request->get['city']) ? trim($this->request->get['city']) : '';
        $q = isset($this->request->get['q']) ? trim($this->request->get['q']) : '';
        $ref = isset($this->request->get['ref']) ? trim($this->request->get['ref']) : '';

        if (kontur_np_ready($this->db) && kontur_np_counts($this->db)['warehouses'] > 0) {
            $list = kontur_np_warehouses($this->db, $city, $ref, $q, 100);
            $this->jsonOut(array('branches' => $list, 'source' => 'db'));
            return;
        }

        $config = $this->moduleConfig();
        $list = $this->lookupBranches($config, $city, $q);
        $this->jsonOut(array('branches' => $list, 'live' => $this->npKeyIsReal($config), 'source' => 'fallback'));
    }

    public function cities() {
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        require_once(DIR_SYSTEM . 'library/kontur_np.php');
        $q = isset($this->request->get['q']) ? trim($this->request->get['q']) : '';
        if (function_exists('mb_substr')) {
            $q = mb_substr($q, 0, 64, 'UTF-8');
        } else {
            $q = substr($q, 0, 64);
        }
        $this->jsonOut(array('cities' => kontur_np_cities($this->db, $q, 12)));
    }

    private function guardCart() {
        if ((!$this->cart->hasProducts() && empty($this->session->data['vouchers'])) || (!$this->cart->hasStock() && !$this->config->get('config_stock_checkout'))) {
            $this->response->redirect($this->url->link('checkout/cart'));
            return ' ';
        }

        $products = $this->cart->getProducts();
        foreach ($products as $product) {
            $min = $this->db->query("SELECT minimum FROM " . DB_PREFIX . "product WHERE product_id = '" . (int)$product['product_id'] . "'");
            if ($min->num_rows && (int)$product['quantity'] < (int)$min->row['minimum']) {
                $this->response->redirect($this->url->link('checkout/cart'));
                return ' ';
            }
        }

        return '';
    }

    private function moduleConfig() {
        require_once(DIR_SYSTEM . 'library/kontur_checkout_data.php');
        $saved = $this->config->get('module_kontur_checkout_config');
        if (is_string($saved)) {
            $saved = json_decode($saved, true);
        }
        $config = kontur_checkout_merge($saved);
        if (!$this->config->get('module_kontur_checkout_status')) {
            $config['enabled'] = false;
        }
        return $config;
    }

    private function fieldsForView($config) {
        $out = array();
        foreach ($config['fields'] as $field) {
            if (empty($field['enabled'])) {
                continue;
            }
            $id = $field['id'];
            $value = '';
            if ($this->customer->isLogged()) {
                if ($id === 'firstname') $value = $this->customer->getFirstName();
                if ($id === 'lastname') $value = $this->customer->getLastName();
                if ($id === 'email') $value = $this->customer->getEmail();
                if ($id === 'telephone') $value = $this->customer->getTelephone();
            }
            $field['value'] = $value;
            $out[] = $field;
        }
        return $out;
    }

    private function enabledShipping($config) {
        $list = array();
        foreach ($config['shippingMethods'] as $ship) {
            if (!empty($ship['enabled'])) {
                $list[] = $ship;
            }
        }
        return $list;
    }

    private function findShipping($config, $id) {
        $fallback = null;
        foreach ($config['shippingMethods'] as $ship) {
            if (empty($ship['enabled'])) {
                continue;
            }
            if ($fallback === null) {
                $fallback = $ship;
            }
            if ($ship['id'] === $id) {
                return $ship;
            }
        }
        return $fallback;
    }

    private function findPayment($config, $ship, $id) {
        $allowed = array();
        foreach ($config['paymentMethods'] as $pay) {
            if (empty($pay['enabled'])) {
                continue;
            }
            if ($ship) {
                $matrix = isset($config['matrix'][$ship['id']]) ? $config['matrix'][$ship['id']] : array();
                if (empty($matrix[$pay['id']])) {
                    continue;
                }
            }
            $allowed[] = $pay;
        }
        foreach ($allowed as $pay) {
            if ($pay['id'] === $id) {
                return $pay;
            }
        }
        return isset($allowed[0]) ? $allowed[0] : null;
    }

    private function gross() {
        $sum = (float)$this->cart->getSubTotal();
        foreach ($this->cart->getTaxes() as $value) {
            $sum += (float)$value;
        }
        if (!empty($this->session->data['vouchers'])) {
            foreach ($this->session->data['vouchers'] as $voucher) {
                $sum += (float)$voucher['amount'];
            }
        }
        return $sum;
    }

    private function cartCount() {
        $n = 0;
        foreach ($this->cart->getProducts() as $product) {
            $n += (int)$product['quantity'];
        }
        if (!empty($this->session->data['vouchers'])) {
            $n += count($this->session->data['vouchers']);
        }
        return $n;
    }

    private function cartLines() {
        $this->load->model('tool/image');
        $lines = array();
        foreach ($this->cart->getProducts() as $product) {
            if ($product['image']) {
                $image = $this->model_tool_image->resize($product['image'], 120, 150);
            } else {
                $image = $this->model_tool_image->resize('placeholder.png', 120, 150);
            }
            $opts = array();
            foreach ($product['option'] as $option) {
                $opts[] = $option['name'] . ': ' . $option['value'];
            }
            $unit = $this->config->get('config_tax')
                ? $this->tax->calculate($product['price'], $product['tax_class_id'], true)
                : $product['price'];
            $lines[] = array(
                'key' => $product['cart_id'],
                'name' => $product['name'],
                'model' => $product['model'],
                'options' => implode(' • ', $opts),
                'price' => (float)$unit,
                'qty' => (int)$product['quantity'],
                'image' => $image,
                'voucher' => false
            );
        }
        if (!empty($this->session->data['vouchers'])) {
            foreach ($this->session->data['vouchers'] as $key => $voucher) {
                $lines[] = array(
                    'key' => 'voucher:' . $key,
                    'name' => $voucher['description'],
                    'model' => 'GIFT',
                    'options' => isset($voucher['to_name']) ? $voucher['to_name'] : '',
                    'price' => (float)$voucher['amount'],
                    'qty' => 1,
                    'image' => '',
                    'voucher' => true
                );
            }
        }
        return $lines;
    }

    private function calculate($gross, $ship, $promo, $gift, $has_shipping) {
        $discount = 0;
        if ($promo && $gross > 0) {
            if ($promo['type'] === 'percent') {
                $discount = round($gross * ($promo['value'] / 100), 2);
            } else {
                $discount = min($gross, (float)$promo['value']);
            }
        }
        $after = max(0, $gross - $discount);
        $gift_cost = $gift ? kontur_checkout_gift_price() : 0;
        $shipping = 0;
        if ($has_shipping && $ship && $gross > 0) {
            $free = (float)$ship['freeFrom'];
            $shipping = ($free > 0 && $after >= $free) ? 0 : (float)$ship['cost'];
        }
        return array(
            'gross' => $gross,
            'discount' => $discount,
            'after' => $after,
            'gift' => $gift_cost,
            'shipping' => $shipping,
            'total' => $after + $gift_cost + $shipping
        );
    }

    private function resolvePromo($code, $gross) {
        $code = mb_strtoupper(trim((string)$code), 'UTF-8');
        if ($code === '') {
            return null;
        }
        if ($code === 'SALE10' || $code === 'UA10') {
            return array('code' => $code, 'type' => 'percent', 'value' => 10, 'source' => 'builtin');
        }
        if ($code === 'GIFT1000') {
            return array('code' => $code, 'type' => 'fixed', 'value' => 1000, 'source' => 'builtin');
        }

        $this->load->model('extension/total/coupon');
        $coupon = $this->model_extension_total_coupon->getCoupon($code);
        if ($coupon) {
            if ($coupon['type'] === 'P') {
                return array('code' => $code, 'type' => 'percent', 'value' => (float)$coupon['discount'], 'source' => 'coupon', 'coupon_id' => (int)$coupon['coupon_id']);
            }
            return array('code' => $code, 'type' => 'fixed', 'value' => (float)$coupon['discount'], 'source' => 'coupon', 'coupon_id' => (int)$coupon['coupon_id']);
        }

        $this->load->model('extension/total/voucher');
        $voucher = $this->model_extension_total_voucher->getVoucher($code);
        if ($voucher) {
            return array(
                'code' => $code,
                'type' => 'fixed',
                'value' => (float)$voucher['amount'],
                'source' => 'voucher',
                'voucher_id' => (int)$voucher['voucher_id']
            );
        }

        return null;
    }

    private function composeAddress($ship, $city, $branch, $street, $house, $apt, $ctime, $postcode, $up, $slot) {
        if (!$ship) {
            return '';
        }
        if ($ship['addressType'] === 'pickup') {
            return 'м. Київ, вул. Велика Васильківська, 72' . ($slot ? ' (' . $slot . ')' : '');
        }
        if ($ship['addressType'] === 'branch') {
            return 'м. ' . $city . ', ' . $branch;
        }
        if ($ship['addressType'] === 'courier') {
            return 'м. ' . $city . ', ' . $street . ', буд. ' . $house . ($apt ? ', ' . $apt : '') . ($ctime ? ' • ' . $ctime : '');
        }
        return $postcode . ', м. ' . $city . ', ' . $up;
    }

    private function createOrder($config, $firstname, $lastname, $email, $telephone, $ship, $pay, $address, $city, $postcode, $company, $comment, $calc, $promo) {
        $this->load->model('checkout/order');
        $this->load->model('localisation/country');
        $this->load->model('localisation/zone');

        $country_id = (int)$this->config->get('config_country_id');
        $zone_id = (int)$this->config->get('config_zone_id');
        $country = $this->model_localisation_country->getCountry($country_id);
        $zone = $this->model_localisation_zone->getZone($zone_id);
        $country_name = $country ? $country['name'] : 'Україна';
        $zone_name = $zone ? $zone['name'] : '';
        $address_format = $country ? $country['address_format'] : '';

        $currency = $this->session->data['currency'];
        $products = array();
        foreach ($this->cart->getProducts() as $product) {
            $products[] = array(
                'product_id' => $product['product_id'],
                'name' => $product['name'],
                'model' => $product['model'],
                'quantity' => $product['quantity'],
                'price' => $product['price'],
                'total' => $product['total'],
                'tax' => $this->tax->getTax($product['price'], $product['tax_class_id']),
                'reward' => isset($product['reward']) ? $product['reward'] : 0,
                'option' => $product['option']
            );
        }

        $vouchers = array();
        if (!empty($this->session->data['vouchers'])) {
            foreach ($this->session->data['vouchers'] as $voucher) {
                $vouchers[] = array(
                    'description' => $voucher['description'],
                    'code' => token(10),
                    'to_name' => $voucher['to_name'],
                    'to_email' => $voucher['to_email'],
                    'from_name' => $voucher['from_name'],
                    'from_email' => $voucher['from_email'],
                    'voucher_theme_id' => $voucher['voucher_theme_id'],
                    'message' => $voucher['message'],
                    'amount' => $voucher['amount']
                );
            }
        }

        $totals = array();
        $subtotal = $this->cart->getSubTotal();
        $totals[] = array('code' => 'sub_total', 'title' => 'Сума товарів', 'value' => $subtotal, 'sort_order' => 1);
        $sort = 2;
        foreach ($this->cart->getTaxes() as $value) {
            if ((float)$value == 0.0) {
                continue;
            }
            $totals[] = array('code' => 'tax', 'title' => 'Податок', 'value' => $value, 'sort_order' => $sort++);
        }
        if (!empty($vouchers)) {
            foreach ($vouchers as $voucher) {
                $totals[] = array('code' => 'voucher', 'title' => $voucher['description'], 'value' => $voucher['amount'], 'sort_order' => 3);
            }
        }
        if ($calc['discount'] > 0 && $promo) {
            $totals[] = array(
                'code' => ($promo['source'] === 'voucher' ? 'voucher' : 'coupon'),
                'title' => 'Знижка (' . $promo['code'] . ')',
                'value' => -$calc['discount'],
                'sort_order' => 5
            );
        }
        if ($calc['gift'] > 0) {
            $totals[] = array('code' => 'handling', 'title' => 'Подарункове пакування', 'value' => $calc['gift'], 'sort_order' => 6);
        }
        if ($ship) {
            $totals[] = array(
                'code' => 'shipping',
                'title' => $ship['title'],
                'value' => $calc['shipping'],
                'sort_order' => 7
            );
        }
        $totals[] = array('code' => 'total', 'title' => 'Разом до сплати', 'value' => $calc['total'], 'sort_order' => 9);

        $order_data = array(
            'invoice_prefix' => $this->config->get('config_invoice_prefix'),
            'store_id' => $this->config->get('config_store_id'),
            'store_name' => $this->config->get('config_name'),
            'store_url' => $this->request->server['HTTPS'] ? HTTPS_SERVER : HTTP_SERVER,
            'customer_id' => $this->customer->isLogged() ? $this->customer->getId() : 0,
            'customer_group_id' => $this->customer->isLogged() ? $this->customer->getGroupId() : $this->config->get('config_customer_group_id'),
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => $email,
            'telephone' => $telephone,
            'custom_field' => array(),
            'payment_firstname' => $firstname,
            'payment_lastname' => $lastname,
            'payment_company' => $company,
            'payment_address_1' => $address,
            'payment_address_2' => '',
            'payment_city' => $ship && $ship['addressType'] === 'pickup' ? 'Київ' : $city,
            'payment_postcode' => $postcode,
            'payment_country' => $country_name,
            'payment_country_id' => $country_id,
            'payment_zone' => $zone_name,
            'payment_zone_id' => $zone_id,
            'payment_address_format' => $address_format,
            'payment_custom_field' => array(),
            'payment_method' => $pay['title'],
            'payment_code' => 'kontur_checkout.' . $pay['id'],
            'shipping_firstname' => $firstname,
            'shipping_lastname' => $lastname,
            'shipping_company' => '',
            'shipping_address_1' => $address,
            'shipping_address_2' => '',
            'shipping_city' => $ship && $ship['addressType'] === 'pickup' ? 'Київ' : $city,
            'shipping_postcode' => $postcode,
            'shipping_country' => $country_name,
            'shipping_country_id' => $country_id,
            'shipping_zone' => $zone_name,
            'shipping_zone_id' => $zone_id,
            'shipping_address_format' => $address_format,
            'shipping_custom_field' => array(),
            'shipping_method' => $ship ? $ship['title'] : '',
            'shipping_code' => $ship ? ('kontur_checkout.' . $ship['id']) : '',
            'comment' => $comment,
            'total' => $calc['total'],
            'affiliate_id' => 0,
            'commission' => 0,
            'marketing_id' => 0,
            'tracking' => isset($this->session->data['tracking']) ? $this->session->data['tracking'] : '',
            'language_id' => $this->config->get('config_language_id'),
            'currency_id' => $this->currency->getId($currency),
            'currency_code' => $currency,
            'currency_value' => $this->currency->getValue($currency),
            'ip' => $this->request->server['REMOTE_ADDR'],
            'forwarded_ip' => isset($this->request->server['HTTP_X_FORWARDED_FOR']) ? $this->request->server['HTTP_X_FORWARDED_FOR'] : '',
            'user_agent' => isset($this->request->server['HTTP_USER_AGENT']) ? $this->request->server['HTTP_USER_AGENT'] : '',
            'accept_language' => isset($this->request->server['HTTP_ACCEPT_LANGUAGE']) ? $this->request->server['HTTP_ACCEPT_LANGUAGE'] : '',
            'products' => $products,
            'vouchers' => $vouchers,
            'totals' => $totals
        );

        $order_id = $this->model_checkout_order->addOrder($order_data);
        $status_id = (int)$this->config->get('config_order_status_id');
        if ($status_id < 1) {
            $status_id = 1;
        }
        $this->session->data['order_id'] = $order_id;
        $notify = ($email && filter_var($email, FILTER_VALIDATE_EMAIL));
        $this->model_checkout_order->addOrderHistory($order_id, $status_id, $comment, $notify);

        if ($promo && $promo['source'] === 'coupon' && !empty($promo['coupon_id'])) {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "coupon_history` SET coupon_id = '" . (int)$promo['coupon_id'] . "', order_id = '" . (int)$order_id . "', customer_id = '" . (int)$this->customer->getId() . "', amount = '" . (float)$calc['discount'] . "', date_added = NOW()");
        }
        if ($promo && $promo['source'] === 'voucher' && !empty($promo['voucher_id'])) {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "voucher_history` SET voucher_id = '" . (int)$promo['voucher_id'] . "', order_id = '" . (int)$order_id . "', amount = '" . (float)$calc['discount'] . "', date_added = NOW()");
        }

        $this->cart->clear();
        unset($this->session->data['shipping_method']);
        unset($this->session->data['shipping_methods']);
        unset($this->session->data['payment_method']);
        unset($this->session->data['payment_methods']);
        unset($this->session->data['guest']);
        unset($this->session->data['comment']);
        unset($this->session->data['coupon']);
        unset($this->session->data['reward']);
        unset($this->session->data['voucher']);
        unset($this->session->data['vouchers']);
        unset($this->session->data['totals']);

        return $order_id;
    }

    private function boot($config, $has_shipping) {
        require_once(DIR_SYSTEM . 'library/kontur_np.php');
        $currency = $this->session->data['currency'];
        $symbol = $this->currency->getSymbolRight($currency);
        if (!$symbol) {
            $symbol = $this->currency->getSymbolLeft($currency);
        }
        if (!$symbol) {
            $symbol = '₴';
        }

        return array(
            'cart' => $this->cartLines(),
            'gross' => $this->gross(),
            'tax' => array_sum($this->cart->getTaxes()),
            'pricesIncludeTax' => (bool)$this->config->get('config_tax'),
            'count' => $this->cartCount(),
            'shipping' => $this->enabledShipping($config),
            'payments' => $config['paymentMethods'],
            'matrix' => $config['matrix'],
            'cities' => kontur_checkout_cities(),
            'chips' => $config['quickCities'],
            'branches' => kontur_checkout_branches(),
            'operators' => kontur_checkout_operators(),
            'validateOperator' => !empty($config['integrations']['validateUaOperator']),
            'npLive' => !empty($config['integrations']['npLiveSearch']),
            'npReal' => $this->npKeyIsReal($config),
            'npDb' => kontur_np_ready($this->db) && kontur_np_counts($this->db)['warehouses'] > 0,
            'giftPrice' => kontur_checkout_gift_price(),
            'showGift' => !empty($config['showGiftWrap']),
            'showPromo' => !empty($config['showPromo']),
            'showFreeBar' => !empty($config['showFreeShippingBar']),
            'showTimer' => !empty($config['showReserveTimer']),
            'showNoCall' => !empty($config['showNoCall']),
            'requirePrivacy' => !empty($config['requirePrivacyPolicy']),
            'hasShipping' => $has_shipping,
            'symbol' => trim($symbol),
            'urls' => array(
                'confirm' => $this->url->link('extension/module/kontur_checkout/confirm', '', true),
                'login' => $this->url->link('extension/module/kontur_checkout/login', '', true),
                'cart' => $this->url->link('extension/module/kontur_checkout/cart', '', true),
                'coupon' => $this->url->link('extension/module/kontur_checkout/coupon', '', true),
                'branches' => $this->url->link('extension/module/kontur_checkout/branches', '', true),
                'cities' => $this->url->link('extension/module/kontur_checkout/cities', '', true),
                'cartPage' => $this->url->link('checkout/cart'),
                'success' => $this->url->link('checkout/success', '', true)
            ),
            'analytics' => array(
                'ga4' => !empty($config['integrations']['analyticsGA4']),
                'meta' => !empty($config['integrations']['analyticsMetaPixel'])
            ),
            'logged' => $this->customer->isLogged()
        );
    }

    private function npKeyIsReal($config) {
        $key = isset($config['integrations']['npApiKey']) ? $config['integrations']['npApiKey'] : '';
        return (bool)preg_match('/^[a-f0-9]{32}$/i', $key);
    }

    private function lookupBranches($config, $city, $q) {
        $local = $this->localBranches($city);
        if ($this->npKeyIsReal($config) && $city !== '') {
            $api = $this->npWarehouses($config['integrations']['npApiKey'], $city, $q);
            if ($api) {
                $local = $api;
            }
        }
        if ($q !== '') {
            $filtered = array();
            foreach ($local as $row) {
                if (mb_stripos($row, $q) !== false) {
                    $filtered[] = $row;
                }
            }
            return $filtered;
        }
        return $local;
    }

    private function localBranches($city) {
        $map = kontur_checkout_branches();
        foreach ($map as $name => $rows) {
            if (mb_strtolower($name) === mb_strtolower(trim($city))) {
                return $rows;
            }
        }
        $label = $city !== '' ? $city : 'ваше місто';
        return array(
            'Відділення №1 (до 30 кг): м. ' . $label . ', центральне відділення',
            'Відділення №2: м. ' . $label . ', вул. Соборна, 12',
            'Поштомат №1024: м. ' . $label . ', цілодобовий доступ'
        );
    }

    private function npWarehouses($key, $city, $q) {
        $body = array(
            'apiKey' => $key,
            'modelName' => 'Address',
            'calledMethod' => 'getWarehouses',
            'methodProperties' => array(
                'CityName' => $city,
                'FindByString' => $q,
                'Limit' => 30,
                'Language' => 'UA'
            )
        );
        $raw = $this->httpJson('https://api.novaposhta.ua/v2.0/json/', $body);
        if (!$raw) {
            return array();
        }
        $decoded = json_decode($raw, true);
        if (empty($decoded['success']) || empty($decoded['data'])) {
            return array();
        }
        $out = array();
        foreach ($decoded['data'] as $row) {
            if (!empty($row['Description'])) {
                $out[] = $row['Description'];
            }
        }
        return $out;
    }

    private function httpJson($url, $body) {
        $payload = json_encode($body);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            $result = curl_exec($ch);
            curl_close($ch);
            return $result ? $result : '';
        }
        return '';
    }

    private function integrationBadges($config) {
        $badges = array();
        if (!empty($config['integrations']['gatewayMonobank'])) $badges[] = 'mono pay';
        if (!empty($config['integrations']['gatewayWayForPay'])) $badges[] = 'WayForPay';
        if (!empty($config['integrations']['prroCheckbox'])) $badges[] = 'Checkbox ПРРО';
        return $badges;
    }

    private function themeClass($config) {
        $t = $config['theme'];
        $layout = isset($config['desktopLayout']) ? $config['desktopLayout'] : '62-38';
        $pattern = isset($config['mobilePattern']) ? $config['mobilePattern'] : 'accordion_sticky';
        $classes = array(
            'inputs-' . (isset($t['inputStyle']) ? $t['inputStyle'] : 'outlined'),
            'section-' . (isset($t['sectionStyle']) ? $t['sectionStyle'] : 'dividers'),
            'density-' . (isset($t['density']) ? $t['density'] : 'standard'),
            'layout-' . $layout,
            'pattern-' . $pattern
        );
        if (empty($config['shippingMethodFirst'])) {
            $classes[] = 'address-first';
        }
        return implode(' ', $classes);
    }

    private function themeStyle($config) {
        $t = $config['theme'];
        $accent = isset($t['accent']) ? $t['accent'] : '#111111';
        $bg = isset($t['bg']) ? $t['bg'] : '#FFFFFF';
        $card = isset($t['cardBg']) ? $t['cardBg'] : '#FAFAFA';
        $radius = isset($t['radius']) ? (int)$t['radius'] : 2;
        $bw = isset($t['borderWidth']) ? $t['borderWidth'] : '1px';
        $shape = isset($t['btnShape']) ? $t['btnShape'] : 'standard';
        $weight = isset($t['btnWeight']) ? $t['btnWeight'] : '500';
        $font = isset($t['fontFamily']) ? $t['fontFamily'] : 'inter';
        $btn = $radius . 'px';
        if ($shape === 'sharp') $btn = '0px';
        if ($shape === 'pill') $btn = '9999px';
        $body = "'Inter', -apple-system, BlinkMacSystemFont, sans-serif";
        $head = $body;
        if ($font === 'mono') {
            $body = "'JetBrains Mono', monospace";
            $head = $body;
        }
        if ($font === 'serif') {
            $head = "'Playfair Display', Georgia, serif";
        }
        $height = '44px';
        if (isset($t['density']) && $t['density'] === 'compact') $height = '38px';
        if (isset($t['density']) && $t['density'] === 'spacious') $height = '50px';

        return '--oc-accent:' . $accent . ';--oc-bg:' . $bg . ';--oc-card-bg:' . $card
            . ';--oc-radius:' . $radius . 'px;--oc-border-w:' . $bw . ';--oc-btn-radius:' . $btn
            . ';--oc-btn-weight:' . $weight . ';--oc-font-body:' . $body . ';--oc-font-heading:' . $head
            . ';--oc-input-height:' . $height . ';background:' . $bg . ';';
    }

    private function formatMoney($number) {
        $currency = $this->session->data['currency'];
        return $this->currency->format($number, $currency);
    }

    private function jsonOut($data) {
        $this->response->setOutput(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
