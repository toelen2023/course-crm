<?php
defined('ABSPATH') || exit;

/**
 * Lead detail screen for Course CRM.
 */
class CCR_Lead_Card {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_page'));
        add_action('admin_post_ccr_save_lead_card', array(__CLASS__, 'save'));
        add_action('admin_footer', array(__CLASS__, 'lead_table_links'));
    }

    public static function register_page() {
        add_submenu_page(
            null,
            'Карточка лида',
            'Карточка лида',
            'manage_options',
            'course-crm-lead',
            array(__CLASS__, 'render')
        );
    }

    private static function can_manage() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав.');
        }
    }

    public static function render() {
        self::can_manage();

        global $wpdb;
        $tables = CCR_Database::tables();
        $lead_id = isset($_GET['lead_id']) ? absint($_GET['lead_id']) : 0;

        if (!$lead_id) {
            wp_die('Не указан ID лида.');
        }

        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tables['leads']} WHERE id = %d",
            $lead_id
        ));

        if (!$lead) {
            wp_die('Лид не найден.');
        }

        $requests = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tables['lead_requests']} WHERE lead_id = %d ORDER BY created_at DESC, id DESC",
            $lead_id
        ));

        $users = get_users(array(
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'fields'  => array('ID', 'display_name', 'user_email'),
        ));

        $statuses = array(
            'new'         => 'Новый',
            'in_progress' => 'В работе',
            'refused'     => 'Отказ',
            'converted'   => 'Клиент',
        );

        echo '<div class="wrap ccr-lead-card">';
        echo '<h1>Карточка лида #' . absint($lead->id) . '</h1>';

        if (isset($_GET['updated']) && '1' === $_GET['updated']) {
            echo '<div class="notice notice-success is-dismissible"><p>Изменения сохранены.</p></div>';
        }

        echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=course-crm-leads')) . '">&larr; К списку лидов</a></p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ccr_save_lead_card_' . $lead_id);
        echo '<input type="hidden" name="action" value="ccr_save_lead_card">';
        echo '<input type="hidden" name="lead_id" value="' . absint($lead_id) . '">';

        echo '<h2>Контактные данные</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="ccr-first-name">Имя</label></th><td>';
        echo '<input id="ccr-first-name" class="regular-text" type="text" name="first_name" value="' . esc_attr($lead->first_name) . '">';
        echo '<p class="description">Пока сохраняем имя целиком — так, как оно пришло из формы.</p></td></tr>';

        echo '<tr><th scope="row"><label for="ccr-email">Email</label></th><td>';
        echo '<input id="ccr-email" class="regular-text" type="email" name="email" value="' . esc_attr($lead->email) . '"></td></tr>';

        echo '<tr><th scope="row"><label for="ccr-phone">Телефон</label></th><td>';
        echo '<input id="ccr-phone" class="regular-text" type="text" name="phone" value="' . esc_attr($lead->phone) . '"></td></tr>';

        echo '<tr><th scope="row"><label for="ccr-manager">Ответственный менеджер</label></th><td>';
        echo '<select id="ccr-manager" name="manager_id"><option value="0">— Не назначен —</option>';
        foreach ($users as $user) {
            echo '<option value="' . absint($user->ID) . '"' . selected((int) $lead->manager_id, (int) $user->ID, false) . '>';
            echo esc_html($user->display_name . ($user->user_email ? ' (' . $user->user_email . ')' : ''));
            echo '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th scope="row"><label for="ccr-status">Статус</label></th><td>';
        echo '<select id="ccr-status" name="status">';
        foreach ($statuses as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($lead->status, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th scope="row"><label for="ccr-notes">Заметка</label></th><td>';
        echo '<textarea id="ccr-notes" class="large-text" rows="5" name="notes">' . esc_textarea($lead->notes) . '</textarea></td></tr>';

        echo '</tbody></table>';
        submit_button('Сохранить изменения');
        echo '</form>';

        echo '<hr><h2>Заявки (' . count($requests) . ')</h2>';

        if (!$requests) {
            echo '<p>Заявок пока нет.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr>';
            echo '<th>Дата</th><th>Курс</th><th>Преподаватель</th><th>Поток</th><th>Страница</th><th>Источник</th>';
            echo '</tr></thead><tbody>';

            foreach ($requests as $request) {
                echo '<tr>';
                echo '<td>' . esc_html($request->created_at) . '</td>';
                echo '<td>' . esc_html($request->course_title ?: '—') . '</td>';
                echo '<td>' . esc_html($request->teacher ?: '—') . '</td>';
                echo '<td>' . esc_html($request->course_stream ?: '—') . '</td>';

                echo '<td>';
                if (!empty($request->page_url)) {
                    echo '<a href="' . esc_url($request->page_url) . '" target="_blank" rel="noopener noreferrer">';
                    echo esc_html($request->page_title ?: $request->page_url) . '</a>';
                } else {
                    echo esc_html($request->page_title ?: '—');
                }
                echo '</td>';

                echo '<td>' . esc_html($request->source ?: '—') . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '<hr><h2>Сведения</h2><table class="widefat" style="max-width:700px"><tbody>';
        echo '<tr><th style="width:220px">Первая заявка</th><td>' . esc_html($lead->created_at) . '</td></tr>';
        echo '<tr><th>Последнее обновление</th><td>' . esc_html($lead->updated_at) . '</td></tr>';
        if (!empty($lead->converted_at)) {
            echo '<tr><th>Переведён в клиенты</th><td>' . esc_html($lead->converted_at) . '</td></tr>';
        }
        if (!empty($lead->client_id)) {
            echo '<tr><th>ID карточки клиента</th><td>' . absint($lead->client_id) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    public static function save() {
        self::can_manage();

        $lead_id = isset($_POST['lead_id']) ? absint($_POST['lead_id']) : 0;
        check_admin_referer('ccr_save_lead_card_' . $lead_id);

        global $wpdb;
        $tables = CCR_Database::tables();

        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tables['leads']} WHERE id = %d",
            $lead_id
        ));

        if (!$lead) {
            wp_die('Лид не найден.');
        }

        $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $status = sanitize_key(wp_unslash($_POST['status'] ?? $lead->status));
        $allowed_statuses = array('new', 'in_progress', 'refused', 'converted');

        if (!in_array($status, $allowed_statuses, true)) {
            $status = $lead->status;
        }

        // A converted lead cannot be moved back by editing the card.
        if ('converted' === $lead->status) {
            $status = 'converted';
        }

        $manager_id = absint($_POST['manager_id'] ?? 0);
        if ($manager_id && !get_user_by('id', $manager_id)) {
            $manager_id = 0;
        }

        $wpdb->update(
            $tables['leads'],
            array(
                'first_name'  => sanitize_text_field(wp_unslash($_POST['first_name'] ?? '')),
                'email'       => sanitize_email(wp_unslash($_POST['email'] ?? '')),
                'phone'       => $phone,
                'phone_digits'=> CCR_Database::phone_digits($phone),
                'manager_id'  => $manager_id ?: null,
                'status'      => $status,
                'notes'       => sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')),
                'updated_at'  => CCR_Database::now(),
            ),
            array('id' => $lead_id),
            array('%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s'),
            array('%d')
        );

        wp_safe_redirect(add_query_arg(
            array(
                'page'    => 'course-crm-lead',
                'lead_id' => $lead_id,
                'updated' => 1,
            ),
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Makes lead names clickable in the existing leads table.
     */
    public static function lead_table_links() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $lead_pages = array('course-crm-new', 'course-crm-work', 'course-crm-refused', 'course-crm-leads');

        if (!in_array($page, $lead_pages, true)) {
            return;
        }
        ?>
        <script>
        (function () {
            var table = document.querySelector('table.ccr-table');
            if (!table) return;

            table.querySelectorAll('tbody tr').forEach(function (row) {
                var idCell = row.cells[0];
                var nameCell = row.cells[1];

                if (!idCell || !nameCell || !/^\d+$/.test(idCell.textContent.trim())) {
                    return;
                }

                var id = idCell.textContent.trim();
                var name = nameCell.textContent.trim();

                if (!name || nameCell.querySelector('a')) return;

                var link = document.createElement('a');
                link.href = 'admin.php?page=course-crm-lead&lead_id=' + encodeURIComponent(id);
                link.textContent = name;
                nameCell.textContent = '';
                nameCell.appendChild(link);
            });
        }());
        </script>
        <?php
    }
}
