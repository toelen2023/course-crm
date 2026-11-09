<?php
defined('ABSPATH') || exit;

class CCR_Admin {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_ccr_lead_status', array(__CLASS__, 'lead_status'));
        add_action('admin_post_ccr_lead_convert', array(__CLASS__, 'lead_convert'));
        add_action('admin_post_ccr_save_client', array(__CLASS__, 'save_client'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
    }

    public static function assets($hook) {
        if (strpos($hook, 'course-crm') !== false) {
            wp_enqueue_style('ccr-admin', CCR_PLUGIN_URL . 'assets/admin.css', array(), CCR_VERSION);
        }
    }

    private static function cap() {
        if (!current_user_can('manage_options')) wp_die('Недостаточно прав.');
    }

    public static function menu() {
        add_menu_page('Course CRM', 'Course CRM', 'manage_options', 'course-crm', array(__CLASS__, 'dashboard'), 'dashicons-groups', 25);
        add_submenu_page('course-crm', 'Обзор', 'Обзор', 'manage_options', 'course-crm', array(__CLASS__, 'dashboard'));
        add_submenu_page('course-crm', 'Новые лиды', 'Новые лиды', 'manage_options', 'course-crm-new', array(__CLASS__, 'leads'));
        add_submenu_page('course-crm', 'В работе', 'В работе', 'manage_options', 'course-crm-work', array(__CLASS__, 'leads'));
        add_submenu_page('course-crm', 'Отказы', 'Отказы', 'manage_options', 'course-crm-refused', array(__CLASS__, 'leads'));
        add_submenu_page('course-crm', 'Все лиды', 'Все лиды', 'manage_options', 'course-crm-leads', array(__CLASS__, 'leads'));
        add_submenu_page('course-crm', 'Клиенты', 'Клиенты', 'manage_options', 'course-crm-clients', array(__CLASS__, 'clients'));
        add_submenu_page('course-crm', 'Повторные клиенты', 'Повторы', 'manage_options', 'course-crm-repeat', array(__CLASS__, 'repeats'));
        add_submenu_page('course-crm', 'Добавить клиента', 'Добавить клиента', 'manage_options', 'course-crm-add', array(__CLASS__, 'add_client'));
        add_submenu_page('course-crm', 'Google Calendar', 'Google Calendar', 'manage_options', 'course-crm-calendar', array('CCR_Google_Calendar', 'settings_page'));
    }

    public static function dashboard() {
        self::cap();
        global $wpdb;
        $t = CCR_Database::tables();
        $counts = array(
            'Новые лиды' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['leads']} WHERE status='new'"),
            'В работе' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['leads']} WHERE status='in_progress'"),
            'Отказы' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['leads']} WHERE status='refused'"),
            'Клиенты' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['clients']}"),
        );
        echo '<div class="wrap"><h1>Course CRM</h1><div class="ccr-cards">';
        foreach ($counts as $label => $count) {
            echo '<div class="ccr-card"><strong>' . esc_html($count) . '</strong><span>' . esc_html($label) . '</span></div>';
        }
        echo '</div><p>Заявки из Contact Form 7 поступают в раздел «Новые лиды».</p></div>';
    }

    private static function current_status() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $map = array('course-crm-new' => 'new', 'course-crm-work' => 'in_progress', 'course-crm-refused' => 'refused');
        return isset($map[$page]) ? $map[$page] : '';
    }

    public static function leads() {
        self::cap();
        global $wpdb;
        $t = CCR_Database::tables();
        $status = self::current_status();
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $where = 'WHERE 1=1';
        $args = array();
        if ($status) { $where .= ' AND l.status=%s'; $args[] = $status; }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $digits = CCR_Database::phone_digits($search);
            $where .= ' AND (l.first_name LIKE %s OR l.email LIKE %s OR l.phone LIKE %s';
            array_push($args, $like, $like, $like);
            if ($digits !== '') { $where .= ' OR l.phone_digits LIKE %s'; $args[] = '%' . $wpdb->esc_like($digits) . '%'; }
            $where .= ')';
        }
        $sql = "SELECT l.*, (SELECT COUNT(*) FROM {$t['lead_requests']} r WHERE r.lead_id=l.id) AS requests_count,
                (SELECT r.course_title FROM {$t['lead_requests']} r WHERE r.lead_id=l.id ORDER BY r.id DESC LIMIT 1) AS latest_course
                FROM {$t['leads']} l {$where} ORDER BY l.updated_at DESC LIMIT 200";
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, $args)) : $wpdb->get_results($sql);

        $title = $status === 'new' ? 'Новые лиды' : ($status === 'in_progress' ? 'Лиды в работе' : ($status === 'refused' ? 'Отказы' : 'Все лиды'));
        echo '<div class="wrap"><h1>' . esc_html($title) . '</h1>';
        self::search_form();
        echo '<table class="widefat striped ccr-table"><thead><tr><th>ID</th><th>Имя</th><th>Телефон</th><th>Email</th><th>Курс</th><th>Заявок</th><th>Статус</th><th>Дата</th><th>Действия</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="9">Лидов не найдено.</td></tr>';
        foreach ($rows as $lead) {
            echo '<tr><td>' . absint($lead->id) . '</td><td>' . esc_html($lead->first_name ?: 'Без имени') . '</td><td>' . esc_html($lead->phone) . '</td><td>' . esc_html($lead->email) . '</td><td>' . esc_html($lead->latest_course) . '</td><td>' . absint($lead->requests_count) . '</td><td>' . esc_html(self::status_label($lead->status)) . '</td><td>' . esc_html($lead->created_at) . '</td><td>';
            if ($lead->status !== 'converted') {
                if ($lead->status !== 'in_progress') self::action_form($lead->id, 'in_progress', 'В работу', 'button');
                if ($lead->status !== 'refused') self::action_form($lead->id, 'refused', 'Отказ', 'button');
                self::convert_form($lead->id);
            } else {
                echo '<span class="ccr-muted">Клиент #' . absint($lead->client_id) . '</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table><p class="description">Показаны последние 200 записей. Поиск работает по имени, email и телефону.</p></div>';
    }

    private static function search_form() {
        $s = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        echo '<form method="get" class="ccr-search"><input type="hidden" name="page" value="' . esc_attr(sanitize_key(wp_unslash($_GET['page'] ?? 'course-crm-leads'))) . '"><label class="screen-reader-text" for="ccr-search">Поиск</label><input id="ccr-search" type="search" name="s" value="' . esc_attr($s) . '" placeholder="Имя, email или телефон"><button class="button">Найти</button> ';
        if ($s !== '') echo '<a class="button" href="' . esc_url(remove_query_arg('s')) . '">Сбросить</a>';
        echo '</form>';
    }

    private static function action_form($id, $status, $label, $class) {
        echo '<form class="ccr-inline-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ccr_lead_status_' . $id);
        echo '<input type="hidden" name="action" value="ccr_lead_status"><input type="hidden" name="lead_id" value="' . absint($id) . '"><input type="hidden" name="status" value="' . esc_attr($status) . '"><button class="button ' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
    }

    private static function convert_form($id) {
        echo '<form class="ccr-inline-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ccr_lead_convert_' . $id);
        echo '<input type="hidden" name="action" value="ccr_lead_convert"><input type="hidden" name="lead_id" value="' . absint($id) . '"><button class="button button-primary">В клиенты</button></form>';
    }

    public static function lead_status() {
        self::cap();
        $id = absint($_POST['lead_id'] ?? 0);
        check_admin_referer('ccr_lead_status_' . $id);
        CCR_Leads::set_status($id, sanitize_key(wp_unslash($_POST['status'] ?? '')));
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=course-crm-leads'));
        exit;
    }

    public static function lead_convert() {
        self::cap();
        $id = absint($_POST['lead_id'] ?? 0);
        check_admin_referer('ccr_lead_convert_' . $id);
        $result = CCR_Leads::convert($id);
        $redirect = wp_get_referer() ?: admin_url('admin.php?page=course-crm-clients');
        if (is_wp_error($result)) $redirect = add_query_arg('ccr_error', rawurlencode($result->get_error_message()), $redirect);
        wp_safe_redirect($redirect);
        exit;
    }

    public static function clients() {
        self::cap();
        global $wpdb;
        $t = CCR_Database::tables();
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $where = '';
        $args = array();
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $digits = CCR_Database::phone_digits($search);
            $where = 'WHERE (first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s';
            array_push($args, $like, $like, $like, $like);
            if ($digits !== '') { $where .= ' OR phone_digits LIKE %s'; $args[] = '%' . $wpdb->esc_like($digits) . '%'; }
            $where .= ')';
        }
        $sql = "SELECT * FROM {$t['clients']} {$where} ORDER BY updated_at DESC LIMIT 200";
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, $args)) : $wpdb->get_results($sql);
        echo '<div class="wrap"><h1>Клиенты</h1>';
        self::search_form();
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Имя</th><th>Телефон</th><th>Email</th><th>Дата</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="5">Клиентов не найдено.</td></tr>';
        foreach ($rows as $row) {
            echo '<tr><td>' . absint($row->id) . '</td><td>' . esc_html($row->first_name . ' ' . $row->last_name) . '</td><td>' . esc_html($row->phone) . '</td><td>' . esc_html($row->email) . '</td><td>' . esc_html($row->created_at) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function repeats() {
        self::cap();
        global $wpdb;
        $t = CCR_Database::tables();
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $where = '';
        $args = array();
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $digits = CCR_Database::phone_digits($search);
            $where = 'WHERE (c.first_name LIKE %s OR c.last_name LIKE %s OR c.email LIKE %s OR c.phone LIKE %s';
            array_push($args, $like, $like, $like, $like);
            if ($digits !== '') { $where .= ' OR c.phone_digits LIKE %s'; $args[] = '%' . $wpdb->esc_like($digits) . '%'; }
            $where .= ')';
        }
        $sql = "SELECT c.*, COUNT(o.id) AS order_count FROM {$t['clients']} c INNER JOIN {$t['orders']} o ON o.client_id=c.id {$where} GROUP BY c.id HAVING order_count>1 ORDER BY c.updated_at DESC LIMIT 200";
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, $args)) : $wpdb->get_results($sql);
        echo '<div class="wrap"><h1>Повторные клиенты</h1>';
        self::search_form();
        echo '<table class="widefat striped"><thead><tr><th>Имя</th><th>Email</th><th>Телефон</th><th>Заказов</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="4">Повторных клиентов пока нет.</td></tr>';
        foreach ($rows as $row) echo '<tr><td>' . esc_html($row->first_name . ' ' . $row->last_name) . '</td><td>' . esc_html($row->email) . '</td><td>' . esc_html($row->phone) . '</td><td>' . absint($row->order_count) . '</td></tr>';
        echo '</tbody></table></div>';
    }

    public static function add_client() {
        self::cap();
        echo '<div class="wrap"><h1>Добавить клиента</h1><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ccr_save_client');
        echo '<input type="hidden" name="action" value="ccr_save_client"><table class="form-table">
        <tr><th>Имя</th><td><input class="regular-text" name="first_name" required></td></tr>
        <tr><th>Фамилия</th><td><input class="regular-text" name="last_name"></td></tr>
        <tr><th>Email</th><td><input class="regular-text" type="email" name="email"></td></tr>
        <tr><th>Телефон</th><td><input class="regular-text" name="phone"></td></tr></table>';
        submit_button('Сохранить клиента');
        echo '</form></div>';
    }

    public static function save_client() {
        self::cap();
        check_admin_referer('ccr_save_client');
        global $wpdb;
        $t = CCR_Database::tables();
        $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $now = CCR_Database::now();
        $wpdb->insert($t['clients'], array(
            'first_name' => sanitize_text_field(wp_unslash($_POST['first_name'] ?? '')),
            'last_name' => sanitize_text_field(wp_unslash($_POST['last_name'] ?? '')),
            'email' => sanitize_email(wp_unslash($_POST['email'] ?? '')),
            'phone' => $phone,
            'phone_digits' => CCR_Database::phone_digits($phone),
            'manager_id' => get_current_user_id(),
            'status' => 'active',
            'source' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
        ));
        wp_safe_redirect(admin_url('admin.php?page=course-crm-clients'));
        exit;
    }

    private static function status_label($status) {
        $labels = array('new' => 'Новый', 'in_progress' => 'В работе', 'refused' => 'Отказ', 'converted' => 'Клиент');
        return isset($labels[$status]) ? $labels[$status] : $status;
    }
}
