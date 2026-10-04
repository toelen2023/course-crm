<?php
defined('ABSPATH') || exit;

class CCR_Database {

    public static function tables() {
        global $wpdb;
        $p = $wpdb->prefix . 'coursecrm_';

        return array(
            'clients'   => $p . 'clients',
            'orders'    => $p . 'orders',
            'payments'  => $p . 'payments',
            'contacts'  => $p . 'contacts',
            'calendar'  => $p . 'calendar',
        );
    }

    public static function activate() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $t = self::tables();
        $charset = $wpdb->get_charset_collate();

        $sql = array();

        $sql[] = "CREATE TABLE {$t['clients']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            first_name VARCHAR(100) NOT NULL DEFAULT '',
            last_name VARCHAR(100) NOT NULL DEFAULT '',
            email VARCHAR(190) NOT NULL DEFAULT '',
            phone VARCHAR(50) NOT NULL DEFAULT '',
            manager_id BIGINT UNSIGNED NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'new',
            source VARCHAR(100) NOT NULL DEFAULT '',
            notes LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY email (email),
            KEY phone (phone),
            KEY status (status),
            KEY manager_id (manager_id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$t['orders']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NOT NULL,
            course_id BIGINT UNSIGNED NULL,
            manager_id BIGINT UNSIGNED NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'new',
            price DECIMAL(12,2) NOT NULL DEFAULT 0,
            currency CHAR(3) NOT NULL DEFAULT 'UAH',
            source VARCHAR(100) NOT NULL DEFAULT '',
            comment TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY client_id (client_id),
            KEY course_id (course_id),
            KEY manager_id (manager_id),
            KEY status (status)
        ) $charset;";

        $sql[] = "CREATE TABLE {$t['payments']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            currency CHAR(3) NOT NULL DEFAULT 'UAH',
            payment_date DATE NOT NULL,
            payment_method VARCHAR(50) NOT NULL DEFAULT '',
            comment TEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY payment_date (payment_date)
        ) $charset;";

        $sql[] = "CREATE TABLE {$t['contacts']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NULL,
            manager_id BIGINT UNSIGNED NULL,
            contact_type VARCHAR(30) NOT NULL DEFAULT 'other',
            contact_date DATETIME NOT NULL,
            result VARCHAR(50) NOT NULL DEFAULT '',
            comment LONGTEXT NULL,
            next_contact_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY client_id (client_id),
            KEY order_id (order_id),
            KEY manager_id (manager_id),
            KEY next_contact_at (next_contact_at)
        ) $charset;";

        $sql[] = "CREATE TABLE {$t['calendar']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NULL,
            contact_id BIGINT UNSIGNED NULL,
            google_event_id VARCHAR(255) NOT NULL DEFAULT '',
            start_datetime DATETIME NOT NULL,
            end_datetime DATETIME NOT NULL,
            title VARCHAR(255) NOT NULL DEFAULT '',
            description TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY client_id (client_id),
            KEY order_id (order_id),
            KEY contact_id (contact_id),
            KEY google_event_id (google_event_id),
            KEY start_datetime (start_datetime)
        ) $charset;";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        update_option('ccr_db_version', CCR_VERSION);
    }

    public static function now() {
        return current_time('mysql');
    }
}
