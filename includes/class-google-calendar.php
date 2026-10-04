<?php
defined('ABSPATH') || exit;

class CCR_Google_Calendar {

    public static function init() {
        add_action('admin_post_ccr_google_save_settings', array(__CLASS__, 'save_settings'));
        add_action('admin_post_ccr_google_start_auth', array(__CLASS__, 'start_auth'));
        add_action('admin_post_ccr_google_callback', array(__CLASS__, 'callback'));
    }

    public static function settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав.');
        }

        $client_id = get_option('ccr_google_client_id', '');
        $client_secret = get_option('ccr_google_client_secret', '');
        $calendar_id = get_option('ccr_google_calendar_id', 'primary');
        $token = get_option('ccr_google_token', array());
        ?>
        <div class="wrap">
            <h1>Google Calendar</h1>

            <p>Сначала создайте OAuth Client в Google Cloud Console и укажите этот callback URL:</p>
            <code><?php echo esc_html(admin_url('admin-post.php?action=ccr_google_callback')); ?></code>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ccr_google_save_settings'); ?>
                <input type="hidden" name="action" value="ccr_google_save_settings">

                <table class="form-table">
                    <tr>
                        <th>Client ID</th>
                        <td><input type="text" class="regular-text" name="client_id" value="<?php echo esc_attr($client_id); ?>"></td>
                    </tr>
                    <tr>
                        <th>Client Secret</th>
                        <td><input type="password" class="regular-text" name="client_secret" value="<?php echo esc_attr($client_secret); ?>"></td>
                    </tr>
                    <tr>
                        <th>Calendar ID</th>
                        <td><input type="text" class="regular-text" name="calendar_id" value="<?php echo esc_attr($calendar_id); ?>"></td>
                    </tr>
                </table>

                <?php submit_button('Сохранить настройки'); ?>
            </form>

            <hr>

            <?php if (!empty($token['access_token'])) : ?>
                <p><strong>Google Calendar подключён.</strong></p>
            <?php else : ?>
                <p>Google Calendar пока не подключён.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('ccr_google_start_auth'); ?>
                    <input type="hidden" name="action" value="ccr_google_start_auth">
                    <?php submit_button('Подключить Google Calendar', 'secondary'); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав.');
        }
        check_admin_referer('ccr_google_save_settings');

        update_option('ccr_google_client_id', sanitize_text_field(wp_unslash($_POST['client_id'] ?? '')));
        update_option('ccr_google_client_secret', sanitize_text_field(wp_unslash($_POST['client_secret'] ?? '')));
        update_option('ccr_google_calendar_id', sanitize_text_field(wp_unslash($_POST['calendar_id'] ?? 'primary')));

        wp_safe_redirect(admin_url('admin.php?page=course-crm-calendar'));
        exit;
    }

    public static function start_auth() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав.');
        }
        check_admin_referer('ccr_google_start_auth');

        $client_id = get_option('ccr_google_client_id', '');
        if (!$client_id) {
            wp_die('Сначала сохраните Client ID.');
        }

        $state = wp_generate_password(32, false, false);
        set_transient('ccr_google_oauth_state_' . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS);

        $params = array(
            'client_id' => $client_id,
            'redirect_uri' => admin_url('admin-post.php?action=ccr_google_callback'),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/calendar.events',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        );

        wp_safe_redirect('https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
        exit;
    }

    public static function callback() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав.');
        }

        $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        $saved = get_transient('ccr_google_oauth_state_' . get_current_user_id());

        if (!$state || !$saved || !hash_equals($saved, $state)) {
            wp_die('Неверный OAuth state.');
        }

        delete_transient('ccr_google_oauth_state_' . get_current_user_id());

        $code = sanitize_text_field(wp_unslash($_GET['code'] ?? ''));
        if (!$code) {
            wp_die('Google не вернул authorization code.');
        }

        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'timeout' => 20,
            'body' => array(
                'code' => $code,
                'client_id' => get_option('ccr_google_client_id', ''),
                'client_secret' => get_option('ccr_google_client_secret', ''),
                'redirect_uri' => admin_url('admin-post.php?action=ccr_google_callback'),
                'grant_type' => 'authorization_code',
            ),
        ));

        if (is_wp_error($response)) {
            wp_die(esc_html($response->get_error_message()));
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['access_token'])) {
            wp_die('Google не вернул access token.');
        }

        update_option('ccr_google_token', $body);

        wp_safe_redirect(admin_url('admin.php?page=course-crm-calendar'));
        exit;
    }

    public static function create_event($client_id, $order_id, $contact_id, $start, $end, $title, $description = '') {
        $token = self::get_valid_token();

        if (!$token) {
            return new WP_Error('ccr_google_auth', 'Google Calendar не подключён.');
        }

        $calendar_id = get_option('ccr_google_calendar_id', 'primary');

        $payload = array(
            'summary' => $title,
            'description' => $description,
            'start' => array(
                'dateTime' => gmdate('c', strtotime($start)),
                'timeZone' => wp_timezone_string(),
            ),
            'end' => array(
                'dateTime' => gmdate('c', strtotime($end)),
                'timeZone' => wp_timezone_string(),
            ),
        );

        $response = wp_remote_post(
            'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendar_id) . '/events',
            array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token['access_token'],
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode($payload),
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300 || empty($body['id'])) {
            return new WP_Error('ccr_google_event', 'Не удалось создать событие.', $body);
        }

        global $wpdb;
        $t = CCR_Database::tables();
        $now = CCR_Database::now();

        $wpdb->insert($t['calendar'], array(
            'client_id' => absint($client_id),
            'order_id' => absint($order_id),
            'contact_id' => absint($contact_id),
            'google_event_id' => sanitize_text_field($body['id']),
            'start_datetime' => gmdate('Y-m-d H:i:s', strtotime($start)),
            'end_datetime' => gmdate('Y-m-d H:i:s', strtotime($end)),
            'title' => sanitize_text_field($title),
            'description' => sanitize_textarea_field($description),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ));

        return $body['id'];
    }

    private static function get_valid_token() {
        $token = get_option('ccr_google_token', array());

        if (empty($token['access_token'])) {
            return false;
        }

        if (!empty($token['expires_in']) && !empty($token['created'])) {
            if (time() >= ((int) $token['created'] + (int) $token['expires_in'] - 60)) {
                if (empty($token['refresh_token'])) {
                    return false;
                }

                $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
                    'timeout' => 20,
                    'body' => array(
                        'client_id' => get_option('ccr_google_client_id', ''),
                        'client_secret' => get_option('ccr_google_client_secret', ''),
                        'refresh_token' => $token['refresh_token'],
                        'grant_type' => 'refresh_token',
                    ),
                ));

                if (is_wp_error($response)) {
                    return false;
                }

                $new = json_decode(wp_remote_retrieve_body($response), true);

                if (empty($new['access_token'])) {
                    return false;
                }

                $token['access_token'] = $new['access_token'];
                $token['expires_in'] = $new['expires_in'] ?? 3600;
                $token['created'] = time();

                update_option('ccr_google_token', $token);
            }
        }

        return $token;
    }
}
