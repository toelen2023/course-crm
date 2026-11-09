<?php
defined('ABSPATH') || exit;

class CCR_CF7 {
    public static function init() {
        add_action('wpcf7_before_send_mail', array(__CLASS__, 'capture'), 10, 3);
    }

    public static function capture($contact_form, &$abort, $submission) {
        if (!$submission || !method_exists($submission, 'get_posted_data')) return;

        $meta = array();
        if (method_exists($submission, 'get_meta')) {
            $post_id = absint($submission->get_meta('container_post_id'));
            if ($post_id) {
                $meta['page_title'] = get_the_title($post_id);
                $meta['page_url'] = get_permalink($post_id);
            }
        }
        CCR_Leads::capture($submission->get_posted_data(), $meta);
    }
}
