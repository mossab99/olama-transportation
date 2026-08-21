<?php
/**
 * Plugin Name: Olama Transportation
 * Plugin URI: https://olama.online
 * Description: School transportation planning, stops, enrollment, fleet allocation, route optimization, and tracking integrations.
 * Version: 2.9.1
 * Author: Olama
 * Text Domain: olama-transportation
 * Domain Path: /languages
 * Requires Plugins: olama-core, olama-school
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OLAMA_TRANSPORTATION_VERSION', '2.9.1');
define('OLAMA_TRANSPORTATION_FILE', __FILE__);
define('OLAMA_TRANSPORTATION_PATH', plugin_dir_path(__FILE__));
define('OLAMA_TRANSPORTATION_URL', plugin_dir_url(__FILE__));

require_once OLAMA_TRANSPORTATION_PATH . 'includes/class-db.php';
require_once OLAMA_TRANSPORTATION_PATH . 'includes/class-plugin.php';

register_activation_hook(__FILE__, array('Olama_Transportation_Plugin', 'activate'));
add_action('plugins_loaded', array('Olama_Transportation_Plugin', 'instance'), 20);
