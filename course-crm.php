<?php
/**
 * Plugin Name: Course CRM
 * Description: CRM for course applications, clients, payments, communication history and Google Calendar.
 * Version: 0.1.0
 * Author: OLVIA
 * License: GPL-2.0-or-later
 * Text Domain: course-crm
 */

defined('ABSPATH') || exit;

define('CCR_PLUGIN_FILE', __FILE__);
define('CCR_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('CCR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CCR_VERSION', '0.1.0');

require_once CCR_PLUGIN_PATH . 'includes/class-database.php';
require_once CCR_PLUGIN_PATH . 'includes/class-admin.php';
require_once CCR_PLUGIN_PATH . 'includes/class-cf7.php';
require_once CCR_PLUGIN_PATH . 'includes/class-google-calendar.php';

register_activation_hook(__FILE__, array('CCR_Database', 'activate'));

add_action('plugins_loaded', function () {
    CCR_Admin::init();
    CCR_CF7::init();
    CCR_Google_Calendar::init();
});
