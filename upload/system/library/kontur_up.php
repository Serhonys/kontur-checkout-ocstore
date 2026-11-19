<?php
function kontur_up_bearer_ok($bearer) {
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim((string)$bearer));
}

function kontur_up_ensure($db) {
    $db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "kontur_up_city` (
        `city_id` int(11) NOT NULL AUTO_INCREMENT,
        `ref` varchar(32) NOT NULL,
        `name` varchar(160) NOT NULL DEFAULT '',
        `region` varchar(160) NOT NULL DEFAULT '',
        `offices` int(11) NOT NULL DEFAULT '0',
        PRIMARY KEY (`city_id`),
        UNIQUE KEY `ref` (`ref`),
        KEY `name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "kontur_up_office` (
        `office_id` int(11) NOT NULL AUTO_INCREMENT,
        `ref` varchar(32) NOT NULL,
        `postcode` varchar(5) NOT NULL DEFAULT '',
        `city_ref` varchar(32) NOT NULL DEFAULT '',
        `city_name` varchar(160) NOT NULL DEFAULT '',
        `region` varchar(160) NOT NULL DEFAULT '',
        `label` varchar(512) NOT NULL DEFAULT '',
        PRIMARY KEY (`office_id`),
        UNIQUE KEY `ref` (`ref`),
        KEY `city_name` (`city_name`),
        KEY `city_ref` (`city_ref`),
        KEY `postcode` (`postcode`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function kontur_up_drop($db) {
    $db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "kontur_up_office`");
    $db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "kontur_up_city`");
}

function kontur_up_ready($db) {
    $query = $db->query("SHOW TABLES LIKE '" . $db->escape(DB_PREFIX . 'kontur_up_office') . "'");
    return $query->num_rows > 0;
}

function kontur_up_counts($db) {
    if (!kontur_up_ready($db)) {
        return array('offices' => 0, 'cities' => 0);
    }
    $offices = $db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "kontur_up_office`");
    $cities = $db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "kontur_up_city`");
    return array(
        'offices' => (int)$offices->row['total'],
        'cities' => (int)$cities->row['total']
    );
}

function kontur_up_reset($db) {
    kontur_up_ensure($db);
    $db->query("DELETE FROM `" . DB_PREFIX . "kontur_up_office`");
    $db->query("DELETE FROM `" . DB_PREFIX . "kontur_up_city`");
}

function kontur_up_meta_read($db) {
    $query = $db->query("SELECT `value` FROM `" . DB_PREFIX . "setting` WHERE `key` = 'module_kontur_checkout_up_meta' AND store_id = '0' LIMIT 1");
    if (!$query->num_rows) {
        return array();
    }
    $meta = json_decode($query->row['value'], true);
    return is_array($meta) ? $meta : array();
}

function kontur_up_meta_write($db, $meta) {
    $value = $db->escape(json_encode($meta, JSON_UNESCAPED_UNICODE));
    $exists = $db->query("SELECT setting_id FROM `" . DB_PREFIX . "setting` WHERE `key` = 'module_kontur_checkout_up_meta' AND store_id = '0' LIMIT 1");
    if ($exists->num_rows) {
        $db->query("UPDATE `" . DB_PREFIX . "setting` SET `value` = '" . $value . "', serialized = '0' WHERE `key` = 'module_kontur_checkout_up_meta' AND store_id = '0'");
    } else {
        $db->query("INSERT INTO `" . DB_PREFIX . "setting` SET store_id = '0', `code` = 'module_kontur_checkout', `key` = 'module_kontur_checkout_up_meta', `value` = '" . $value . "', serialized = '0'");
    }
}

function kontur_up_regions($bearer) {
    $decoded = kontur_up_get($bearer, 'get_regions_by_region_ua');
    if (isset($decoded['error'])) {
        return $decoded;
    }
    $regions = array();
    foreach (kontur_up_entries($decoded) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = isset($row['REGION_ID']) ? trim((string)$row['REGION_ID']) : '';
        $name = isset($row['REGION_UA']) ? trim((string)$row['REGION_UA']) : '';
        if ($id === '' || $name === '') {
            continue;
        }
        $regions[] = array('id' => $id, 'name' => $name);
    }
    if (!$regions) {
        return array('error' => 'Укрпошта не повернула список областей. Перевірте Bearer адресного класифікатора.');
    }
    return array('success' => true, 'regions' => $regions);
}

function kontur_up_import_region($db, $bearer, $region_id, $reset) {
    $region_id = trim((string)$region_id);
    if ($region_id === '' || !preg_match('/^\d+$/', $region_id)) {
        return array('error' => 'Невідома область Укрпошти.');
    }
    if ($reset) {
        kontur_up_reset($db);
    } else {
        kontur_up_ensure($db);
    }

    $decoded = kontur_up_get($bearer, 'get_postoffices_by_postindex?poRegionId=' . rawurlencode($region_id));
    if (isset($decoded['error'])) {
        return $decoded;
    }
    $rows = kontur_up_entries($decoded);
    if (!$rows) {
        $decoded = kontur_up_get($bearer, 'get_postoffices_by_city_id?region_id=' . rawurlencode($region_id));
        if (isset($decoded['error'])) {
            return $decoded;
        }
        $rows = kontur_up_entries($decoded);
    }

    $fetched = kontur_up_store($db, $rows);
    $counts = kontur_up_counts($db);
    return array(
        'success' => true,
        'fetched' => $fetched,
        'offices' => $counts['offices'],
        'cities' => $counts['cities']
    );
}

function kontur_up_finish($db) {
    $counts = kontur_up_counts($db);
    kontur_up_meta_write($db, array(
        'updated' => date('d.m.Y H:i'),
        'offices' => $counts['offices'],
        'cities' => $counts['cities']
    ));
    return $counts;
}

function kontur_up_store($db, $rows) {
    $office_sql = array();
    $cities = array();
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $lock = isset($row['LOCK_CODE']) ? trim((string)$row['LOCK_CODE']) : '0';
        if ($lock !== '' && $lock !== '0') {
            continue;
        }
        if (isset($row['AVALIBLE']) && (string)$row['AVALIBLE'] === '0') {
            continue;
        }
        $postcode = isset($row['POSTINDEX']) ? preg_replace('/\D/', '', (string)$row['POSTINDEX']) : '';
        if (strlen($postcode) !== 5) {
            continue;
        }
        $ref = isset($row['ID']) ? trim((string)$row['ID']) : '';
        if ($ref === '') {
            $ref = $postcode;
        }
        $city = isset($row['PDCITY_UA']) ? trim((string)$row['PDCITY_UA']) : '';
        if ($city === '' && isset($row['CITY_UA'])) {
            $city = trim((string)$row['CITY_UA']);
        }
        $city_ref = isset($row['PDCITY_ID']) ? trim((string)$row['PDCITY_ID']) : '';
        $region = isset($row['REGION_UA']) ? trim((string)$row['REGION_UA']) : '';
        $label = kontur_up_label($row, $postcode, $city);
        if ($label === '') {
            continue;
        }

        $office_sql[] = "('"
            . $db->escape(kontur_up_cut($ref, 32)) . "', '"
            . $db->escape($postcode) . "', '"
            . $db->escape(kontur_up_cut($city_ref, 32)) . "', '"
            . $db->escape(kontur_up_cut($city, 160)) . "', '"
            . $db->escape(kontur_up_cut($region, 160)) . "', '"
            . $db->escape(kontur_up_cut($label, 500)) . "')";

        if ($city_ref !== '' && $city !== '') {
            if (!isset($cities[$city_ref])) {
                $cities[$city_ref] = array('name' => $city, 'region' => $region, 'offices' => 0);
            }
            $cities[$city_ref]['offices']++;
            if ($region !== '') {
                $cities[$city_ref]['region'] = $region;
            }
        }
    }

    foreach (array_chunk($office_sql, 80) as $chunk) {
        $db->query("INSERT INTO `" . DB_PREFIX . "kontur_up_office` (`ref`, `postcode`, `city_ref`, `city_name`, `region`, `label`) VALUES "
            . implode(',', $chunk)
            . " ON DUPLICATE KEY UPDATE `postcode` = VALUES(`postcode`), `city_ref` = VALUES(`city_ref`), `city_name` = VALUES(`city_name`), `region` = VALUES(`region`), `label` = VALUES(`label`)");
    }

    if ($cities) {
        $city_sql = array();
        foreach ($cities as $ref => $city) {
            $city_sql[] = "('"
                . $db->escape(kontur_up_cut($ref, 32)) . "', '"
                . $db->escape(kontur_up_cut($city['name'], 160)) . "', '"
                . $db->escape(kontur_up_cut($city['region'], 160)) . "', '"
                . (int)$city['offices'] . "')";
        }
        foreach (array_chunk($city_sql, 80) as $chunk) {
            $db->query("INSERT INTO `" . DB_PREFIX . "kontur_up_city` (`ref`, `name`, `region`, `offices`) VALUES "
                . implode(',', $chunk)
                . " ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `region` = IF(VALUES(`region`) != '', VALUES(`region`), `region`), `offices` = `offices` + VALUES(`offices`)");
        }
    }

    return count($office_sql);
}

function kontur_up_label($row, $postcode, $city) {
    $short = isset($row['PO_SHORT']) ? trim((string)$row['PO_SHORT']) : '';
    $long = isset($row['PO_LONG']) ? trim((string)$row['PO_LONG']) : '';
    $name = $short !== '' ? $short : $long;
    $street = isset($row['STREET_UA']) ? trim((string)$row['STREET_UA']) : '';
    $type = isset($row['STREETTYPE_UA']) ? trim((string)$row['STREETTYPE_UA']) : '';
    $house = isset($row['HOUSENUMBER']) ? trim((string)$row['HOUSENUMBER']) : '';
    $address = isset($row['ADDRESS']) ? trim((string)$row['ADDRESS']) : '';
    $line = trim($type . ' ' . $street);
    if ($house !== '') {
        $line = trim($line . ($line !== '' ? ', ' : '') . $house);
    }
    if ($line === '') {
        $line = $address;
    }
    $parts = array();
    if ($postcode !== '') {
        $parts[] = $postcode;
    }
    if ($name !== '') {
        $parts[] = $name;
    } elseif ($city !== '') {
        $parts[] = $city;
    }
    if ($line !== '') {
        $parts[] = $line;
    }
    return implode(' · ', $parts);
}

function kontur_up_cities($db, $q, $limit) {
    if (!kontur_up_ready($db)) {
        return array();
    }
    $q = str_replace(array('%', '_'), '', trim((string)$q));
    if ($q === '' || (function_exists('mb_strlen') ? mb_strlen($q, 'UTF-8') : strlen($q)) < 2) {
        return array();
    }
    $limit = max(1, min(20, (int)$limit));
    $like = $db->escape($q) . '%';
    $query = $db->query("SELECT `ref`, `name`, `region`, `offices` FROM `" . DB_PREFIX . "kontur_up_city` WHERE `name` LIKE '" . $like . "' ORDER BY `offices` DESC, `name` ASC LIMIT " . $limit);
    if (!$query->num_rows) {
        $like = '%' . $db->escape($q) . '%';
        $query = $db->query("SELECT `ref`, `name`, `region`, `offices` FROM `" . DB_PREFIX . "kontur_up_city` WHERE `name` LIKE '" . $like . "' ORDER BY `offices` DESC, `name` ASC LIMIT " . $limit);
    }
    $out = array();
    foreach ($query->rows as $row) {
        $out[] = array(
            'ref' => $row['ref'],
            'name' => $row['name'],
            'area' => $row['region'],
            'offices' => (int)$row['offices']
        );
    }
    return $out;
}

function kontur_up_offices($db, $city, $city_ref, $q, $limit) {
    if (!kontur_up_ready($db)) {
        return array();
    }
    $limit = max(1, min(150, (int)$limit));
    $q = str_replace(array('%', '_'), '', trim((string)$q));
    $where = array();
    if ($q !== '' && preg_match('/^\d{3,5}$/', $q)) {
        $where[] = "`postcode` LIKE '" . $db->escape($q) . "%'";
    } else {
        $city_ref = trim((string)$city_ref);
        $city = trim((string)$city);
        if ($city_ref !== '') {
            $where[] = "`city_ref` = '" . $db->escape($city_ref) . "'";
        } elseif ($city !== '') {
            $where[] = "`city_name` = '" . $db->escape($city) . "'";
        } else {
            return array();
        }
        if ($q !== '') {
            $like = '%' . $db->escape($q) . '%';
            $where[] = "(`label` LIKE '" . $like . "' OR `postcode` LIKE '" . $db->escape($q) . "%')";
        }
    }
    $query = $db->query("SELECT `label`, `postcode` FROM `" . DB_PREFIX . "kontur_up_office` WHERE " . implode(' AND ', $where) . " ORDER BY `postcode` ASC, `label` ASC LIMIT " . $limit);
    $out = array();
    foreach ($query->rows as $row) {
        $out[] = array(
            'label' => $row['label'],
            'postcode' => $row['postcode']
        );
    }
    return $out;
}

function kontur_up_get($bearer, $path) {
    if (!function_exists('curl_init')) {
        return array('error' => 'На сервері немає cURL, базу Укрпошти завантажити не можна.');
    }
    $url = 'https://www.ukrposhta.ua/address-classifier-ws/' . ltrim($path, '/');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' . trim($bearer)
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, 40);
    $result = curl_exec($ch);
    $errno = curl_errno($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 401 || $code === 403) {
        return array('error' => 'Укрпошта не прийняла Bearer. Потрібен ключ адресного класифікатора з кабінету Укрпошти.');
    }
    if ($errno || !$result) {
        return array('error' => 'Немає відповіді від Укрпошти. Спробуйте ще раз.');
    }
    $decoded = json_decode($result, true);
    if (!is_array($decoded)) {
        return array('error' => 'Укрпошта повернула незрозумілу відповідь.');
    }
    if ($code >= 400) {
        $message = 'Укрпошта відхилила запит.';
        if (!empty($decoded['message'])) {
            $message = (string)$decoded['message'];
        }
        return array('error' => $message);
    }
    return $decoded;
}

function kontur_up_entries($decoded) {
    if (!is_array($decoded)) {
        return array();
    }
    $entry = null;
    if (isset($decoded['Entries']['Entry'])) {
        $entry = $decoded['Entries']['Entry'];
    } elseif (isset($decoded['Entry'])) {
        $entry = $decoded['Entry'];
    }
    if (!is_array($entry) || !$entry) {
        return array();
    }
    if (isset($entry['REGION_ID']) || isset($entry['POSTINDEX']) || isset($entry['ID'])) {
        return array($entry);
    }
    return $entry;
}

function kontur_up_cut($value, $length) {
    $value = (string)$value;
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $length, 'UTF-8');
    }
    return substr($value, 0, $length);
}
