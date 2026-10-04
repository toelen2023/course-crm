<?php
defined('ABSPATH') || exit;

class CCR_CF7 {

    public static function init() {
        add_action('wpcf7_before_send_mail', array(__CLASS__, 'capture'), 10, 3);
    }

    public static function capture($contact_form, &$abort, $submission) {
        if (!$submission || !class_exists('WPCF7_Submission')) {
            return;
        }

        $data = $submission->get_posted_data();

        /*
         * Expected CF7 field names:
         * first_name, last_name, email, phone, course_id
         *
         * These can be changed later in CRM settings.
         */

        $first_name = self::field($data, 'first_name');
        $last_name  = self::field($data, 'last_name');
        $email      = sanitize_email(self::field($data, 'email'));
        $phone      = self::field($data, 'phone');
        $course_id  = absint(self::field($data, 'course_id'));

        if (!$email && !$phone) {
            return;
        }

        global $wpdb;
        $t = CCR_Database::tables();
        $now = CCR_Database::now();

        $client = null;

        if ($email) {
            $client = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['clients']} WHERE email=%s LIMIT 1", $email));
        }

        if (!$client && $phone) {
            $client = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['clients']} WHERE phone=%s LIMIT 1", $phone));
        }

        if ($client) {
            $client_id = (int) $client->id;

            $wpdb->update(
                $t['clients'],
                array(
                    'first_name' => $first_name ?: $client->first_name,
                    'last_name'  => $last_name ?: $client->last_name,
                    'email'      => $email ?: $client->email,
                    'phone'      => $phone ?: $client->phone,
                    'updated_at' => $now,
                ),
                array('id' => $client_id)
            );
        } else {
            $wpdb->insert($t['clients'], array(
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'email'      => $email,
                'phone'      => $phone,
                'status'     => 'new',
                'source'     => 'contact-form-7',
                'created_at' => $now,
                'updated_at' => $now,
            ));
            $client_id = (int) $wpdb->insert_id;
        }

        if ($course_id) {
            $wpdb->insert($t['orders'], array(
                'client_id'  => $client_id,
                'course_id'  => $course_id,
                'manager_id' => null,
                'status'     => 'new',
                'source'     => 'contact-form-7',
                'created_at' => $now,
                'updated_at' => $now,
            ));
        }
    }

    private static function field($data, $key) {
        if (!isset($data[$key])) {
            return '';
        }

        $value = $data[$key];

        if (is_array($value)) {
            $value = reset($value);
        }

        return sanitize_text_field((string) $value);
    }
}
