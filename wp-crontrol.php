<?php
/**
 * Plugin Name:  WP Crontrol
 * Plugin URI:   https://wp-crontrol.com
 * Description:  Take control of the cron events on your WordPress website or WooCommerce store.
 * Author:       Duplicator
 * Author URI:   https://duplicator.com
 * Version:      2.0.0
 * Text Domain:  wp-crontrol
 * Domain Path:  /languages/
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * License URI:  https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * License:      GPL v2 or later
 *
 * LICENSE
 * This file is part of WP Crontrol.
 *
 * WP Crontrol is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * @package    wp-crontrol
 * @author     John Blackbourn & Edward Dale
 * @copyright  Copyright 2008 Edward Dale, 2012-2026 John Blackbourn
 * @license    https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt GPL 2.0
 * @link       https://github.com/awesomemotive/wp-crontrol/
 */

namespace Crontrol;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.1 403 Forbidden' );
	exit;
}

// @phpstan-ignore booleanNot.alwaysFalse
if ( ! version_compare( PHP_VERSION, '7.4', '>=' ) ) {
	return;
}

// The old WP Crontrol Pro plugin can't run alongside WP Crontrol, which now includes its features.
// If it's active, it's deactivated and WP Crontrol starts on the next request.
require_once __DIR__ . '/src/retire-pro.php';

if ( retired_pro_is_active() ) {
	retire_pro( plugin_basename( __FILE__ ) );
	return;
}

// A WP Crontrol Pro that was already inactive keeps an uninstall hook that would clash with
// WP Crontrol when it's deleted, from the admin or WP-CLI. The option is autoloaded, so this is cheap.
if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	forget_retired_pro_uninstall_hook();
}

add_action( 'admin_notices', __NAMESPACE__ . '\show_retired_pro_notice' );
add_action( 'network_admin_notices', __NAMESPACE__ . '\show_retired_pro_notice' );

const PLUGIN_FILE = __FILE__;
const WP_CRONTROL_VERSION = '2.0.0';

if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/event.php';
require_once __DIR__ . '/src/schedule.php';
require_once __DIR__ . '/src/Pro/bootstrap.php';
require_once __DIR__ . '/src/promos.php';
require_once __DIR__ . '/src/alerts.php';
require_once __DIR__ . '/src/abilities.php';
require_once __DIR__ . '/src/ai.php';

// Get this show on the road.
init_hooks();
Pro\init_hooks();
