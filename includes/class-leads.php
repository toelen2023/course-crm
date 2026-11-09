<?php
defined('ABSPATH') || exit;

class CCR_Leads {
    public static function capture($data, $meta = array()) {
        global $wpdb;
        $t = CCR_Database::tables();

        $name = self::value($data, 'your-name');
        $email = sanitize_email(self::value($data, 'your-email'));
        $phone = self::value($data, 'phone_full');
        if (!$phone) {
            $phone = self::value($data, 'your-tel');
        }
        $phone = sanitize_text_field($phone);
        if (!$email && !$phone) {
            return 0;
        }

        $digits = CCR_Database::phone_digits($phone);
        $lead = null;

        if ($email) {
            $lead = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$t['leads']} WHERE LOWER(email) = LOWER(%s) ORDER BY id ASC LIMIT 1",
                $email
            ));
        }
        if (!$lead && $digits) {
            $lead = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$t['leads']} WHERE phone_digits = %s ORDER BY id ASC LIMIT 1",
                $digits
            ));
        }

        $now = CCR_Database::now();
        if ($lead) {
            $lead_id = (int) $lead->id;
            $update = array('updated_at' => $now);
            if ($name && !$lead->first_name) $update['first_name'] = $name;
            if ($email && !$lead->email) $update['email'] = $email;
            if ($phone && !$lead->phone) {
                $update['phone'] = $phone;
                $update['phone_digits'] = $digits;
            }
            // A new submission reopens the lead even if this person was a client before.
            // The existing client_id is retained; conversion will reuse that client.
            $update['status'] = 'new';
            $wpdb->update($t['leads'], $update, array('id' => $lead_id));
        } else {
            $wpdb->insert($t['leads'], array(
                'first_name' => $name,
                'email' => $email,
                'phone' => $phone,
                'phone_digits' => $digits,
                'status' => 'new',
                'source' => 'contact-form-7',
                'created_at' => $now,
                'updated_at' => $now,
            ));
            $lead_id = (int) $wpdb->insert_id;
        }

        if (!$lead_id) return 0;

        $course_id = absint(self::value($data, 'course_id'));
        $course_title = self::value($data, 'course');
        if (!$course_title && $course_id) {
            $course_title = get_the_title($course_id);
        }

        $page_title = self::value($data, '_post_title');
        $page_url = self::value($data, '_url');
        if (!$page_title && !empty($meta['page_title'])) $page_title = $meta['page_title'];
        if (!$page_url && !empty($meta['page_url'])) $page_url = $meta['page_url'];

        $wpdb->insert($t['lead_requests'], array(
            'lead_id' => $lead_id,
            'course_id' => $course_id ?: null,
            'course_title' => $course_title,
            'teacher' => self::value($data, 'teacher'),
            'course_stream' => self::value($data, 'course_stream'),
            'page_title' => $page_title,
            'page_url' => esc_url_raw($page_url),
            'source' => 'contact-form-7',
            'created_at' => $now,
        ));

        return $lead_id;
    }

    public static function set_status($lead_id, $status) {
        global $wpdb;
        $allowed = array('new', 'in_progress', 'refused');
        if (!in_array($status, $allowed, true)) return false;
        $t = CCR_Database::tables();
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$t['leads']} SET status=%s, updated_at=%s WHERE id=%d AND status <> 'converted'",
            $status,
            CCR_Database::now(),
            absint($lead_id)
        ));
        return false !== $result;
    }

    public static function convert($lead_id) {
        global $wpdb;
        $t = CCR_Database::tables();
        $lead_id = absint($lead_id);
        $lead = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['leads']} WHERE id=%d", $lead_id));
        if (!$lead) return new WP_Error('ccr_lead_missing', 'Лид не найден.');

        $client = null;
        if (!empty($lead->client_id)) {
            $client = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$t['clients']} WHERE id=%d", absint($lead->client_id)
            ));
        }
        if (!$client && $lead->email) {
            $client = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$t['clients']} WHERE LOWER(email)=LOWER(%s) LIMIT 1", $lead->email
            ));
        }
        if (!$client && $lead->phone_digits) {
            $client = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$t['clients']} WHERE phone_digits=%s LIMIT 1", $lead->phone_digits
            ));
        }

        $now = CCR_Database::now();
        if ($client) {
            $client_id = (int) $client->id;
        } else {
            $wpdb->insert($t['clients'], array(
                'first_name' => $lead->first_name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'phone_digits' => $lead->phone_digits,
                'manager_id' => get_current_user_id(),
                'status' => 'active',
                'source' => 'lead',
                'created_at' => $now,
                'updated_at' => $now,
            ));
            $client_id = (int) $wpdb->insert_id;
        }
        if (!$client_id) return new WP_Error('ccr_client_create', 'Не удалось создать клиента.');

        $wpdb->update($t['leads'], array(
            'client_id' => $client_id,
            'status' => 'converted',
            'converted_at' => $now,
            'updated_at' => $now,
            'manager_id' => get_current_user_id(),
        ), array('id' => $lead_id));

        $request = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t['lead_requests']} WHERE lead_id=%d ORDER BY id DESC LIMIT 1", $lead_id
        ));
        if ($request && empty($request->order_id)) {
            $wpdb->insert($t['orders'], array(
                'client_id' => $client_id,
                'course_id' => $request->course_id ?: null,
                'manager_id' => get_current_user_id(),
                'status' => 'new',
                'source' => 'lead',
                'comment' => $request->course_title ? 'Заявка на курс: ' . $request->course_title : '',
                'created_at' => $now,
                'updated_at' => $now,
            ));
            $order_id = (int) $wpdb->insert_id;
            if ($order_id) {
                $wpdb->update($t['lead_requests'], array('order_id' => $order_id), array('id' => $request->id));
            }
        }
        return $client_id;
    }

    private static function value($data, $key) {
        if (!isset($data[$key])) return '';
        $value = $data[$key];
        if (is_array($value)) $value = reset($value);
        return sanitize_text_field((string) $value);
    }
}
