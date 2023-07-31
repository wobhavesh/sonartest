<?php
/*
Plugin Name: ShipTime: Discount Shipping
Plugin URI:  https://app.shiptime.com/index.html?locale=en#nav.SalesChannels
Description: ShipTime shipping plugin. Provide discounted shipping rates from the top national couriers, and sync products and orders.
Version: 1.0
Author: ShipTime
Author URI: https://shiptime.com
License:      GPL2
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Domain Path: /lang
Text Domain: shiptime_ls
*/

/*
Shiptime Live rates Woocommerce is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.

Shiptime Live rates Woocommerce is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with woocommerce webhook helper. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SHIPTIME_SHIPPING_SERVICE_VERSION' ) ) {
	define( 'SHIPTIME_SHIPPING_SERVICE_VERSION', '1.0' );
	define( 'SHIPTIME_SHIPPING_SERVICE_POST_TYPE', 'shiptime_shipping_method' );

	require_once 'app' . DIRECTORY_SEPARATOR . 'Shiptime_Shipping_Exception.php';

	/**
	 * @var Shiptime_Shipping_Exception|null $shiptime_shipping_exception
	 */
	$shiptime_shipping_exception = null;

	function validate_WooCommerce()
	{
		if ( class_exists( 'WooCommerce' ) ) {
			$version = WooCommerce::instance()->version;
		}

		if ( empty( $version ) || version_compare( $version, '2.7' ) === - 1 ) {
			throw new Shiptime_Shipping_Exception( __( 'Woocommerce 2.7+ is required. ', 'shiptime_ls' ) );
		}

		return true;
	}
	
	function shiptime_shipping_init()
	{
		if ( SHIPTIME_SHIPPING_SERVICE_VERSION !== get_option( 'shiptime_shipping_service_version' ) ) {
			shiptime_shipping_activate();
		}
		global $shiptime_shipping_exception;
		try {
			validate_WooCommerce();
			register_post_type(
				'shiptime_shipping_service',
				array(
					'public'              => false,
					'hierarchical'        => false,
					'has_archive'         => false,
					'exclude_from_search' => false,
					'rewrite'             => false,
					'query_var'           => false,
					'delete_with_user'    => false,
					'_builtin'            => true,
				)
			);
			require_once 'app' . DIRECTORY_SEPARATOR . 'Shiptime_Shipping_Service.php';
			require_once 'app' . DIRECTORY_SEPARATOR . 'Shiptime_Shipping.php';
			
		} catch ( Shiptime_Shipping_Exception $shiptime_shipping_exception ) {
		}
	}

	function shiptime_shipping_error()
	{
		global $shiptime_shipping_exception;

		if ( $shiptime_shipping_exception !== null ) {
			echo esc_html('
				<div class="error notice">
					<p>Shiptime  Woocommerce notice: <b>' . $shiptime_shipping_exception->getMessage() . '</b></p>
				</div>
			');
		}
	}

	function shiptime_shipping_activate()
	{
		global $shiptime_shipping_exception;

		try {
			validate_WooCommerce();
		} catch ( Shiptime_Shipping_Exception $shiptime_shipping_exception ) {
			die ( $shiptime_shipping_exception->getMessage() );
		}

		if (is_multisite() && is_network_admin()) {
			$sites = get_sites();

			foreach ($sites as $site) {
				_activatePlugin($site->blog_id, true);
			}
			restore_current_blog();
		} else {
			_activatePlugin();
		}
	}

	function shiptime_shipping_deactivate()
	{
		if (is_multisite() && is_network_admin()) {
			$sites = get_sites();
			$pluginName = isset($GLOBALS['plugin']) ? $GLOBALS['plugin'] : '';

			foreach ($sites as $site) {
				switch_to_blog($site->blog_id);
				$activePlugins = (array)get_option('active_plugins', array());

				if (($key = array_search($pluginName, $activePlugins)) !== false) {
					unset($activePlugins[$key]);
					update_option('active_plugins', $activePlugins);
				}

				update_option('shiptime_shipping_service_active', false);
				update_option('woocommerce_shiptime_settings', false);
			}

			restore_current_blog();
		} else {

			update_option('shiptime_shipping_service_active', false);
			update_option('woocommerce_shiptime_settings', false);
		}
	}

	function shiptime_shipping_uninstall()
	{
		/**
		 * @global $wpdb wpdb Database Access Abstraction Object
		 */
		global $wpdb;
		$wpdb->query( 'DELETE FROM `' . $wpdb->prefix . 'posts` WHERE `post_type` = "' . SHIPTIME_SHIPPING_SERVICE_POST_TYPE . '"' );
		$wpdb->query( 'DELETE FROM `' . $wpdb->prefix . 'woocommerce_api_keys` WHERE `description` = "shiptime"' );
		delete_option( 'shiptime_shipping_service_version' );
		delete_option( 'shiptime_shipping_service_active' );
		delete_option( 'woocommerce_shiptime_settings' );
	}

	/**
	 * @param int  $siteId      Site Id
	 * @param bool $isMultisite Is Multisite Enabled
	 */
	function _activatePlugin($siteId = 1, $isMultisite = false)
	{
		if ($isMultisite) {
			switch_to_blog($siteId);
		}

		update_option('shiptime_shipping_service_version', SHIPTIME_SHIPPING_SERVICE_VERSION);
		update_option('shiptime_shipping_service_active', true);
		update_option('woocommerce_shiptime_settings', array("true"));

		if (empty(get_option('shiptime_shipping_service_secret'))) {
			update_option('shiptime_shipping_service_secret', wp_generate_password(50, true, true));
		}
	}

	register_activation_hook( __FILE__, 'shiptime_shipping_activate' );
	register_uninstall_hook( __FILE__, 'shiptime_shipping_uninstall' );
	register_deactivation_hook( __FILE__, 'shiptime_shipping_deactivate' );

	add_action( 'plugins_loaded', 'shiptime_shipping_init', 10, 3 );
	add_action( 'admin_notices', 'shiptime_shipping_error' );
	
	add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), 'apd_settings_link' );
    function apd_settings_link( array $links ) {
        $url = get_admin_url() . "admin.php?page=wc-settings&tab=shipping&section=shiptime";
        $settings_link = '<a href="' . $url . '">' . __('Settings', 'textdomain') . '</a>';
          $links[] = $settings_link;
        return $links;
      }

}
