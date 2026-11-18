<?php
function kontur_np_key_ok($key) {
    return (bool)preg_match('/^[a-f0-9]{32}$/i', (string)$key);
}

function kontur_np_ensure($db) {
    $db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "kontur_np_city` (
        `city_id` int(11) NOT NULL AUTO_INCREMENT,
        `ref` varchar(36) NOT NULL,
        `name` varchar(160) NOT NULL DEFAULT '',
        `name_ru` varchar(160) NOT NULL DEFAULT '',
        `area` varchar(160) NOT NULL DEFAULT '',
        `warehouses` int(11) NOT NULL DEFAULT '0',
        PRIMARY KEY (`city_id`),
        UNIQUE KEY `ref` (`ref`),
        KEY `name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "kontur_np_warehouse` (
        `warehouse_id` int(11) NOT NULL AUTO_INCREMENT,
        `ref` varchar(36) NOT NULL,
        `city_ref` varchar(36) NOT NULL DEFAULT '',
        `city_name` varchar(160) NOT NULL DEFAULT '',
        `number` varchar(16) NOT NULL DEFAULT '',
        `category` varchar(32) NOT NULL DEFAULT '',
        `description` varchar(512) NOT NULL DEFAULT '',
        PRIMARY KEY (`warehouse_id`),
        UNIQUE KEY `ref` (`ref`),
        KEY `city_ref` (`city_ref`),
        KEY `city_name` (`city_name`),
        KEY `number` (`number`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function kontur_np_drop($db) {
    $db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "kontur_np_warehouse`");
    $db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "kontur_np_city`");
}

function kontur_np_ready($db) {
    $query = $db->query("SHOW TABLES LIKE '" . $db->escape(DB_PREFIX . 'kontur_np_warehouse') . "'");
    return $query->num_rows > 0;
}

function kontur_np_counts($db) {
    if (!kontur_np_ready($db)) {
        return array('warehouses' => 0, 'cities' => 0);
    }
    $warehouses = $db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "kontur_np_warehouse`");
    $cities = $db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "kontur_np_city`");
    return array(
        'warehouses' => (int)$warehouses->row['total'],
        'cities' => (int)$cities->row['total']
    );
}

function kontur_np_reset($db) {
    kontur_np_ensure($db);
    $db->query("DELETE FROM `" . DB_PREFIX . "kontur_np_warehouse`");
    $db->query("DELETE FROM `" . DB_PREFIX . "kontur_np_city`");
}

function kontur_np_meta_read($db) {
    $query = $db->query("SELECT `value` FROM `" . DB_PREFIX . "setting` WHERE `key` = 'module_kontur_checkout_np_meta' AND store_id = '0' LIMIT 1");
    if (!$query->num_rows) {
        return array();
    }
    $meta = json_decode($query->row['value'], true);
    return is_array($meta) ? $meta : array();
}

function kontur_np_meta_write($db, $meta) {
    $value = $db->escape(json_encode($meta, JSON_UNESCAPED_UNICODE));
    $exists = $db->query("SELECT setting_id FROM `" . DB_PREFIX . "setting` WHERE `key` = 'module_kontur_checkout_np_meta' AND store_id = '0' LIMIT 1");
    if ($exists->num_rows) {
        $db->query("UPDATE `" . DB_PREFIX . "setting` SET `value` = '" . $value . "', serialized = '0' WHERE `key` = 'module_kontur_checkout_np_meta' AND store_id = '0'");
    } else {
        $db->query("INSERT INTO `" . DB_PREFIX . "setting` SET store_id = '0', `code` = 'module_kontur_checkout', `key` = 'module_kontur_checkout_np_meta', `value` = '" . $value . "', serialized = '0'");
    }
}

function kontur_np_import_page($db, $key, $page, $reset) {
    $page = max(1, (int)$page);
    $limit = 250;
    if ($page > 200) {
        return array('error' => 'Занадто багато сторінок. Зупинено на всяк випадок.');
    }

    $decoded = kontur_np_call($key, $page, $limit);
    if (isset($decoded['error'])) {
        return $decoded;
    }

    $rows = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : array();
    $total = 0;
    if (!empty($decoded['info']['totalCount'])) {
        $total = (int)$decoded['info']['totalCount'];
    }

    if ($page === 1 && !$rows) {
        return array('error' => 'Нова Пошта не повернула відділення. Перевірте ключ.');
    }

    if ($reset && $page === 1) {
        kontur_np_reset($db);
    } else {
        kontur_np_ensure($db);
    }

    $inserted = kontur_np_store_rows($db, $rows);
    $counts = kontur_np_counts($db);
    $done = !$rows || count($rows) < $limit || ($total && $page * $limit >= $total);

    if ($done) {
        kontur_np_meta_write($db, array(
            'updated' => date('d.m.Y H:i'),
            'warehouses' => $counts['warehouses'],
            'cities' => $counts['cities']
        ));
    }

    return array(
        'success' => true,
        'page' => $page,
        'fetched' => $inserted,
        'warehouses' => $counts['warehouses'],
        'cities' => $counts['cities'],
        'total' => $total,
        'done' => $done
    );
}

function kontur_np_store_rows($db, $rows) {
    $warehouse_sql = array();
    $cities = array();

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $status = isset($row['WarehouseStatus']) ? (string)$row['WarehouseStatus'] : 'Working';
        if ($status !== '' && $status !== 'Working') {
            continue;
        }
        if (!empty($row['DenyToSelect']) && (string)$row['DenyToSelect'] !== '0') {
            continue;
        }
        $ref = isset($row['Ref']) ? trim((string)$row['Ref']) : '';
        $description = isset($row['Description']) ? trim((string)$row['Description']) : '';
        $city_ref = isset($row['CityRef']) ? trim((string)$row['CityRef']) : '';
        $city_name = isset($row['CityDescription']) ? trim((string)$row['CityDescription']) : '';
        if ($ref === '' || $description === '' || $city_name === '') {
            continue;
        }

        $city_ru = isset($row['CityDescriptionRu']) ? trim((string)$row['CityDescriptionRu']) : '';
        $area = isset($row['SettlementAreaDescription']) ? trim((string)$row['SettlementAreaDescription']) : '';
        $number = isset($row['Number']) ? trim((string)$row['Number']) : '';
        $category = isset($row['CategoryOfWarehouse']) ? trim((string)$row['CategoryOfWarehouse']) : '';

        $warehouse_sql[] = "('"
            . $db->escape(kontur_np_cut($ref, 36)) . "', '"
            . $db->escape(kontur_np_cut($city_ref, 36)) . "', '"
            . $db->escape(kontur_np_cut($city_name, 160)) . "', '"
            . $db->escape(kontur_np_cut($number, 16)) . "', '"
            . $db->escape(kontur_np_cut($category, 32)) . "', '"
            . $db->escape(kontur_np_cut($description, 500)) . "')";

        if ($city_ref !== '') {
            if (!isset($cities[$city_ref])) {
                $cities[$city_ref] = array(
                    'name' => $city_name,
                    'name_ru' => $city_ru,
                    'area' => $area,
                    'warehouses' => 0
                );
            }
            $cities[$city_ref]['warehouses']++;
            if ($area !== '') {
                $cities[$city_ref]['area'] = $area;
            }
        }
    }

    foreach (array_chunk($warehouse_sql, 100) as $chunk) {
        $db->query("INSERT INTO `" . DB_PREFIX . "kontur_np_warehouse` (`ref`, `city_ref`, `city_name`, `number`, `category`, `description`) VALUES "
            . implode(',', $chunk)
            . " ON DUPLICATE KEY UPDATE `city_ref` = VALUES(`city_ref`), `city_name` = VALUES(`city_name`), `number` = VALUES(`number`), `category` = VALUES(`category`), `description` = VALUES(`description`)");
    }

    if ($cities) {
        $city_sql = array();
        foreach ($cities as $ref => $city) {
            $city_sql[] = "('"
                . $db->escape(kontur_np_cut($ref, 36)) . "', '"
                . $db->escape(kontur_np_cut($city['name'], 160)) . "', '"
                . $db->escape(kontur_np_cut($city['name_ru'], 160)) . "', '"
                . $db->escape(kontur_np_cut($city['area'], 160)) . "', '"
                . (int)$city['warehouses'] . "')";
        }
        $db->query("INSERT INTO `" . DB_PREFIX . "kontur_np_city` (`ref`, `name`, `name_ru`, `area`, `warehouses`) VALUES "
            . implode(',', $city_sql)
            . " ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `name_ru` = VALUES(`name_ru`), `area` = IF(VALUES(`area`) != '', VALUES(`area`), `area`), `warehouses` = `warehouses` + VALUES(`warehouses`)");
    }

    return count($warehouse_sql);
}

function kontur_np_cities($db, $q, $limit) {
    if (!kontur_np_ready($db)) {
        return array();
    }
    $q = trim((string)$q);
    if (function_exists('mb_strlen') && mb_strlen($q, 'UTF-8') < 2) {
        return array();
    }
    if (!function_exists('mb_strlen') && strlen($q) < 2) {
        return array();
    }
    $q = str_replace(array('%', '_'), '', $q);
    $like = $db->escape($q) . '%';
    $limit = max(1, min(20, (int)$limit));
    $query = $db->query("SELECT `ref`, `name`, `area`, `warehouses` FROM `" . DB_PREFIX . "kontur_np_city` WHERE `name` LIKE '" . $like . "' OR `name_ru` LIKE '" . $like . "' ORDER BY `warehouses` DESC, `name` ASC LIMIT " . $limit);
    if (!$query->num_rows) {
        $like = '%' . $db->escape($q) . '%';
        $query = $db->query("SELECT `ref`, `name`, `area`, `warehouses` FROM `" . DB_PREFIX . "kontur_np_city` WHERE `name` LIKE '" . $like . "' OR `name_ru` LIKE '" . $like . "' ORDER BY `warehouses` DESC, `name` ASC LIMIT " . $limit);
    }
    $out = array();
    foreach ($query->rows as $row) {
        $out[] = array(
            'ref' => $row['ref'],
            'name' => $row['name'],
            'area' => $row['area'],
            'warehouses' => (int)$row['warehouses']
        );
    }
    return $out;
}

function kontur_np_warehouses($db, $city, $city_ref, $q, $limit) {
    if (!kontur_np_ready($db)) {
        return array();
    }
    $limit = max(1, min(150, (int)$limit));
    $where = array();
    $city_ref = trim((string)$city_ref);
    $city = trim((string)$city);
    if ($city_ref !== '') {
        $where[] = "`city_ref` = '" . $db->escape($city_ref) . "'";
    } elseif ($city !== '') {
        $where[] = "`city_name` = '" . $db->escape($city) . "'";
    } else {
        return array();
    }
    $q = trim(str_replace(array('%', '_'), '', (string)$q));
    if ($q !== '') {
        $like = '%' . $db->escape($q) . '%';
        $where[] = "(`description` LIKE '" . $like . "' OR `number` LIKE '" . $db->escape($q) . "%')";
    }
    $query = $db->query("SELECT `description` FROM `" . DB_PREFIX . "kontur_np_warehouse` WHERE " . implode(' AND ', $where) . " ORDER BY CAST(`number` AS UNSIGNED) ASC, `description` ASC LIMIT " . $limit);
    $out = array();
    foreach ($query->rows as $row) {
        $out[] = $row['description'];
    }
    return $out;
}

function kontur_np_call($key, $page, $limit) {
    $body = array(
        'apiKey' => $key,
        'modelName' => 'Address',
        'calledMethod' => 'getWarehouses',
        'methodProperties' => array(
            'Page' => (string)$page,
            'Limit' => (string)$limit,
            'Language' => 'UA'
        )
    );
    $payload = json_encode($body);
    if (!function_exists('curl_init')) {
        return array('error' => 'На сервері немає cURL, базу відділень завантажити не можна.');
    }
    $ch = curl_init('https://api.novaposhta.ua/v2.0/json/');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    $result = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($errno || !$result) {
        return array('error' => 'Немає відповіді від Нової Пошти. Спробуйте ще раз.');
    }
    $decoded = json_decode($result, true);
    if (!is_array($decoded)) {
        return array('error' => 'Нова Пошта повернула незрозумілу відповідь.');
    }
    if (empty($decoded['success'])) {
        $message = 'Нова Пошта відхилила запит.';
        if (!empty($decoded['errors']) && is_array($decoded['errors'])) {
            $message = implode(' ', $decoded['errors']);
        }
        return array('error' => $message);
    }
    return $decoded;
}

function kontur_np_cut($value, $length) {
    $value = (string)$value;
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $length, 'UTF-8');
    }
    return substr($value, 0, $length);
}
