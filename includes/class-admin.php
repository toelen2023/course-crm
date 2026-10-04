<?php
defined('ABSPATH') || exit;

class CCR_Admin {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_ccr_save_client', array(__CLASS__, 'save_client'));
        add_action('admin_post_ccr_save_order', array(__CLASS__, 'save_order'));
        add_action('admin_post_ccr_save_payment', array(__CLASS__, 'save_payment'));
        add_action('admin_post_ccr_save_contact', array(__CLASS__, 'save_contact'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
    }

    public static function assets($hook) {
        if (strpos($hook, 'course-crm') === false) {
            return;
        }
        wp_enqueue_style('ccr-admin', CCR_PLUGIN_URL . 'assets/admin.css', array(), CCR_VERSION);
    }

    public static function menu() {
        add_menu_page(
            'Course CRM',
            'Course CRM',
            'manage_options',
            'course-crm',
            array(__CLASS__, 'dashboard'),
            'dashicons-groups',
            25
        );

        add_submenu_page('course-crm', 'Все клиенты', 'Все клиенты', 'manage_options', 'course-crm', array(__CLASS__, 'clients'));
        add_submenu_page('course-crm', 'Новые клиенты', 'Новые', 'manage_options', 'course-crm-new', array(__CLASS__, 'clients'));
        add_submenu_page('course-crm', 'В работе', 'В работе', 'manage_options', 'course-crm-work', array(__CLASS__, 'clients'));
        add_submenu_page('course-crm', 'Повторы', 'Повторы', 'manage_options', 'course-crm-repeat', array(__CLASS__, 'repeats'));
        add_submenu_page('course-crm', 'Отказы', 'Отказы', 'manage_options', 'course-crm-refused', 'CCR_Admin::refused');
        add_submenu_page('course-crm', 'Добавить клиента', 'Добавить клиента', 'manage_options', 'course-crm-add', array(__CLASS__, 'add_client'));
        add_submenu_page('course-crm', 'Google Calendar', 'Google Calendar', 'manage_options', 'course-crm-calendar', array('CCR_Google_Calendar', 'settings_page'));
    }

    private static function require_cap() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав.');
        }
    }

    public static function dashboard() {
        self::require_cap();
        global $wpdb;
        $t = CCR_Database::tables();

        $counts = array(
            'all'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['clients']}"),
            'new'  => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['clients']} WHERE status=%s", 'new')),
            'work' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['clients']} WHERE status=%s", 'in_progress')),
            'ref'  => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['clients']} WHERE status=%s", 'refused')),
        );

        echo '<div class="wrap"><h1>Course CRM</h1><div class="ccr-cards">';
        foreach ($counts as $key => $value) {
            echo '<div class="ccr-card"><strong>' . esc_html($value) . '</strong><span>' . esc_html(ucfirst($key)) . '</span></div>';
        }
        echo '</div></div>';
    }

    private static function status_for_page() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page === 'course-crm-new') return 'new';
        if ($page === 'course-crm-work') return 'in_progress';
        if ($page === 'course-crm-refused') return 'refused';
        return '';
    }

    public static function clients() {
        self::require_cap();
        global $wpdb;
        $t = CCR_Database::tables();

        $status = self::status_for_page();
        $where = '';
        $args = array();

        if ($status) {
            $where = 'WHERE status = %s';
            $args[] = $status;
        }

        $sql = "SELECT * FROM {$t['clients']} $where ORDER BY updated_at DESC LIMIT 200";
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, $args)) : $wpdb->get_results($sql);

        echo '<div class="wrap"><h1>Клиенты</h1>';
        echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=course-crm-add')) . '">Добавить клиента</a></p>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Клиент</th><th>Телефон</th><th>E-mail</th><th>Статус</th><th>Менеджер</th><th>Дата</th></tr></thead><tbody>';

        if (!$rows) {
            echo '<tr><td colspan="7">Клиентов пока нет.</td></tr>';
        }

        foreach ($rows as $row) {
            $name = trim($row->last_name . ' ' . $row->first_name);
            $url = admin_url('admin.php?page=course-crm-client&client_id=' . absint($row->id));
            echo '<tr>';
            echo '<td>' . absint($row->id) . '</td>';
            echo '<td><a href="' . esc_url($url) . '">' . esc_html($name ?: 'Без имени') . '</a></td>';
            echo '<td>' . esc_html($row->phone) . '</td>';
            echo '<td>' . esc_html($row->email) . '</td>';
            echo '<td>' . esc_html($row->status) . '</td>';
            echo '<td>' . absint($row->manager_id) . '</td>';
            echo '<td>' . esc_html($row->updated_at) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    public static function repeats() {
        self::require_cap();
        global $wpdb;
        $t = CCR_Database::tables();

        $rows = $wpdb->get_results(
            "SELECT c.*, COUNT(o.id) AS order_count
             FROM {$t['clients']} c
             INNER JOIN {$t['orders']} o ON o.client_id = c.id
             GROUP BY c.id
             HAVING order_count > 1
             ORDER BY c.updated_at DESC"
        );

        echo '<div class="wrap"><h1>Повторные клиенты</h1><table class="widefat striped"><thead><tr><th>Клиент</th><th>Заказов</th><th>E-mail</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $name = trim($row->last_name . ' ' . $row->first_name);
            echo '<tr><td>' . esc_html($name) . '</td><td>' . absint($row->order_count) . '</td><td>' . esc_html($row->email) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function refused() {
        self::clients();
    }

    public static function add_client() {
        self::require_cap();
        ?>
        <div class="wrap">
            <h1>Добавить клиента</h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ccr_save_client'); ?>
                <input type="hidden" name="action" value="ccr_save_client">
                <table class="form-table">
                    <tr><th><label for="first_name">Имя</label></th><td><input name="first_name" id="first_name" class="regular-text"></td></tr>
                    <tr><th><label for="last_name">Фамилия</label></th><td><input name="last_name" id="last_name" class="regular-text"></td></tr>
                    <tr><th><label for="email">E-mail</label></th><td><input type="email" name="email" id="email" class="regular-text"></td></tr>
                    <tr><th><label for="phone">Телефон</label></th><td><input name="phone" id="phone" class="regular-text"></td></tr>
                </table>
                <?php submit_button('Сохранить клиента'); ?>
            </form>
        </div>
        <?php
    }

    public static function client_page() {
        self::require_cap();
        global $wpdb;
        $t = CCR_Database::tables();
        $id = isset($_GET['client_id']) ? absint($_GET['client_id']) : 0;

        $client = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['clients']} WHERE id=%d", $id));
        if (!$client) {
            wp_die('Клиент не найден.');
        }

        $orders = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['orders']} WHERE client_id=%d ORDER BY created_at DESC", $id));
        $contacts = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['contacts']} WHERE client_id=%d ORDER BY contact_date DESC", $id));

        echo '<div class="wrap"><h1>' . esc_html(trim($client->last_name . ' ' . $client->first_name)) . '</h1>';
        echo '<p><strong>Телефон:</strong> ' . esc_html($client->phone) . ' &nbsp; <strong>E-mail:</strong> ' . esc_html($client->email) . '</p>';
        echo '<p><strong>Статус:</strong> ' . esc_html($client->status) . '</p>';

        echo '<h2>Заказы</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Курс</th><th>Статус</th><th>Цена</th><th>Валюта</th></tr></thead><tbody>';
        foreach ($orders as $order) {
            $course = $order->course_id ? get_the_title($order->course_id) : '';
            echo '<tr><td>' . absint($order->id) . '</td><td>' . esc_html($course) . '</td><td>' . esc_html($order->status) . '</td><td>' . esc_html($order->price) . '</td><td>' . esc_html($order->currency) . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2>История общения</h2><table class="widefat striped"><thead><tr><th>Дата</th><th>Тип</th><th>Результат</th><th>Комментарий</th><th>Следующий контакт</th></tr></thead><tbody>';
        foreach ($contacts as $contact) {
            echo '<tr><td>' . esc_html($contact->contact_date) . '</td><td>' . esc_html($contact->contact_type) . '</td><td>' . esc_html($contact->result) . '</td><td>' . esc_html($contact->comment) . '</td><td>' . esc_html($contact->next_contact_at) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function save_client() {
        self::require_cap();
        check_admin_referer('ccr_save_client');

        global $wpdb;
        $t = CCR_Database::tables();
        $now = CCR_Database::now();

        $wpdb->insert($t['clients'], array(
            'first_name' => sanitize_text_field(wp_unslash($_POST['first_name'] ?? '')),
            'last_name'  => sanitize_text_field(wp_unslash($_POST['last_name'] ?? '')),
            'email'      => sanitize_email(wp_unslash($_POST['email'] ?? '')),
            'phone'      => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
            'manager_id' => get_current_user_id(),
            'status'     => 'new',
            'created_at' => $now,
            'updated_at' => $now,
        ));

        wp_safe_redirect(admin_url('admin.php?page=course-crm'));
        exit;
    }

    public static function save_order() {
        self::require_cap();
        check_admin_referer('ccr_save_order');
        // Reserved for the CRM UI phase.
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=course-crm'));
        exit;
    }

    public static function save_payment() {
        self::require_cap();
        check_admin_referer('ccr_save_payment');
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=course-crm'));
        exit;
    }

    public static function save_contact() {
        self::require_cap();
        check_admin_referer('ccr_save_contact');
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=course-crm'));
        exit;
    }
}
add_action('admin_menu', function () {
    add_submenu_page('course-crm', 'Карточка клиента', 'Карточка клиента', 'manage_options', 'course-crm-client', array('CCR_Admin', 'client_page'));
});
