<?php
/**
 * KONTUR / OnePage Checkout — спільні дані конфігурації.
 * Версія модуля збігається з полем version у JSON-експорті адмінки.
 */
function kontur_checkout_version() {
    return '2.5.3';
}

function kontur_checkout_defaults() {
    return array(
        'enabled' => true,
        'desktopLayout' => '62-38',
        'mobilePattern' => 'accordion_sticky',
        'shippingMethodFirst' => true,
        'showCustomerTabs' => true,
        'showPromo' => true,
        'showFreeShippingBar' => true,
        'showReserveTimer' => false,
        'showGiftWrap' => true,
        'showNoCall' => true,
        'requirePrivacyPolicy' => true,
        'showCityChips' => true,
        'quickCities' => array('Київ', 'Львів', 'Одеса', 'Дніпро', 'Харків'),
        'theme' => array(
            'presetId' => 'swiss_thin',
            'presetName' => 'Swiss Thin (1px)',
            'accent' => '#111111',
            'bg' => '#FFFFFF',
            'cardBg' => '#FAFAFA',
            'radius' => 2,
            'borderWidth' => '1px',
            'fontFamily' => 'inter',
            'inputStyle' => 'outlined',
            'sectionStyle' => 'dividers',
            'btnShape' => 'standard',
            'btnWeight' => '500',
            'density' => 'standard',
            'customCss' => ''
        ),
        'fields' => array(
            array('id' => 'firstname', 'label' => "Ім'я", 'placeholder' => 'Олександр', 'type' => 'text', 'enabled' => true, 'required' => true, 'width' => 'half', 'custom' => false),
            array('id' => 'lastname', 'label' => 'Прізвище', 'placeholder' => 'Мельник', 'type' => 'text', 'enabled' => true, 'required' => true, 'width' => 'half', 'custom' => false),
            array('id' => 'telephone', 'label' => 'Телефон', 'placeholder' => '+38 (0__) ___-__-__', 'type' => 'tel', 'enabled' => true, 'required' => true, 'width' => 'half', 'custom' => false),
            array('id' => 'email', 'label' => 'E-mail', 'placeholder' => 'o.melnyk@ukr.net', 'type' => 'email', 'enabled' => true, 'required' => true, 'width' => 'half', 'custom' => false),
            array('id' => 'messenger', 'label' => 'Telegram / Viber', 'placeholder' => '@username або номер', 'type' => 'text', 'enabled' => false, 'required' => false, 'width' => 'full', 'custom' => true)
        ),
        'shippingMethods' => array(
            array(
                'id' => 'np_warehouse',
                'title' => 'Нова Пошта (відділення / поштомат)',
                'subtitle' => '1–2 дні по Україні • Понад 27 000 точок видачі',
                'cost' => 80,
                'freeFrom' => 2500,
                'enabled' => true,
                'addressType' => 'branch'
            ),
            array(
                'id' => 'np_courier',
                'title' => "Нова Пошта (кур'єр)",
                'subtitle' => 'Доставка до дверей кур’єром по Україні',
                'cost' => 120,
                'freeFrom' => 4000,
                'enabled' => true,
                'addressType' => 'courier'
            ),
            array(
                'id' => 'ukrposhta',
                'title' => 'Укрпошта Експрес',
                'subtitle' => '2–4 робочі дні у відділення за індексом',
                'cost' => 55,
                'freeFrom' => 2000,
                'enabled' => true,
                'addressType' => 'postcode'
            ),
            array(
                'id' => 'showroom',
                'title' => 'Самовивіз із шоуруму (Київ)',
                'subtitle' => 'вул. Велика Васильківська, 72 • Примірка сьогодні',
                'cost' => 0,
                'freeFrom' => 0,
                'enabled' => true,
                'addressType' => 'pickup'
            )
        ),
        'paymentMethods' => array(
            array('id' => 'online_card', 'title' => 'Monobank / Apple Pay / Google Pay', 'desc' => 'Миттєва онлайн-оплата карткою без комісії', 'enabled' => true),
            array('id' => 'cod', 'title' => 'Оплата при отриманні (Післяплата)', 'desc' => 'Оплата після огляду посилки (комісія 20 ₴ + 2%)', 'enabled' => true),
            array('id' => 'invoice', 'title' => 'Безготівковий рахунок (ФОП / ТОВ з ЄДРПОУ)', 'desc' => 'Рахунок-фактура IBAN для бізнесу', 'enabled' => true),
            array('id' => 'cash_pickup', 'title' => 'Готівкою при самовивозі', 'desc' => 'Оплата на касі шоуруму в Києві', 'enabled' => true)
        ),
        'matrix' => array(
            'np_warehouse' => array('online_card' => true, 'cod' => true, 'invoice' => true, 'cash_pickup' => false),
            'np_courier' => array('online_card' => true, 'cod' => true, 'invoice' => true, 'cash_pickup' => false),
            'ukrposhta' => array('online_card' => true, 'cod' => false, 'invoice' => true, 'cash_pickup' => false),
            'showroom' => array('online_card' => true, 'cod' => false, 'invoice' => true, 'cash_pickup' => true)
        ),
        'integrations' => array(
            'npApiKey' => 'np_live_98a4f21c8e7b304d91a2',
            'npLiveSearch' => true,
            'upBearer' => '',
            'validateUaOperator' => true,
            'gatewayMonobank' => true,
            'gatewayWayForPay' => true,
            'prroCheckbox' => true,
            'analyticsGA4' => true,
            'analyticsMetaPixel' => true
        )
    );
}

function kontur_checkout_merge($saved) {
    $base = kontur_checkout_defaults();

    if (!is_array($saved)) {
        return $base;
    }

    $config = array_merge($base, $saved);
    $config['theme'] = array_merge($base['theme'], isset($saved['theme']) && is_array($saved['theme']) ? $saved['theme'] : array());
    $config['integrations'] = array_merge($base['integrations'], isset($saved['integrations']) && is_array($saved['integrations']) ? $saved['integrations'] : array());

    if (empty($saved['fields']) || !is_array($saved['fields'])) {
        $config['fields'] = $base['fields'];
    }

    if (empty($saved['shippingMethods']) || !is_array($saved['shippingMethods'])) {
        $config['shippingMethods'] = $base['shippingMethods'];
    }

    if (empty($saved['paymentMethods']) || !is_array($saved['paymentMethods'])) {
        $config['paymentMethods'] = $base['paymentMethods'];
    }

    if (empty($saved['matrix']) || !is_array($saved['matrix'])) {
        $config['matrix'] = $base['matrix'];
    }

    if (empty($saved['quickCities']) || !is_array($saved['quickCities'])) {
        $config['quickCities'] = $base['quickCities'];
    }

    return $config;
}

function kontur_checkout_cities() {
    return array(
        'Київ', 'Львів', 'Одеса', 'Дніпро', 'Харків',
        'Запоріжжя', 'Вінниця', 'Івано-Франківськ', 'Полтава', 'Черкаси',
        'Хмельницький', 'Тернопіль', 'Рівне', 'Луцьк', 'Чернівці', 'Ужгород'
    );
}

function kontur_checkout_branches() {
    return array(
        'Київ' => array(
            'Відділення №1 (до 30 кг): вул. Пирогівський шлях, 135',
            'Відділення №15: вул. Велика Васильківська, 63',
            'Відділення №42: вул. Хрещатик, 22',
            'Поштомат №5421: вул. Саксаганського, 44',
            'Поштомат №7810: просп. Берестейський, 24'
        ),
        'Львів' => array(
            'Відділення №1: вул. Городоцька, 359',
            'Відділення №12: просп. Свободи, 27',
            'Поштомат №3104: вул. Під Дубом, 7Б (ТРЦ Forum Lviv)'
        ),
        'Одеса' => array(
            'Відділення №14: вул. Дерибасівська, 19',
            'Відділення №35: просп. Шевченка, 8А',
            'Поштомат №4412: вул. Катерининська, 27'
        ),
        'Дніпро' => array(
            'Відділення №9: просп. Дмитра Яворницького, 54',
            'Поштомат №2918: вул. Короленка, 3'
        ),
        'Харків' => array(
            'Відділення №18: вул. Сумська, 48',
            'Поштомат №6102: просп. Науки, 14'
        )
    );
}

function kontur_checkout_operators() {
    return array('039', '050', '063', '066', '067', '068', '073', '075', '077', '089', '091', '092', '093', '094', '095', '096', '097', '098', '099');
}

function kontur_checkout_gift_price() {
    return 95;
}
