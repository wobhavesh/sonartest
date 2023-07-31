<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shiptime_Shipping_Service extends WC_Shipping_Method
{
	const RETRY_CNT = 3;
	const RETRY_SLP = 5;//sleep seconds before next retry

	private $_callback_url = '';
	private $_secret = '';
	private $_config;

	public function __construct( $id, $title, $description, $callback_url, $secret, $config )
	{
		$this->id                 = $id;
		$this->title              = $title;
		$this->method_title       = $title;
		$this->method_description = $description;
		$this->_callback_url      = $callback_url;
		$this->_secret            = $secret;
		$this->_config            = $config;

		$this->supports = array(
			'settings',
		);

		$this->init_settings();

		if ( empty( $this->settings ) ) {
			$this->settings = null;
			$this->init_form_fields();
			$this->init_settings();
			$this->_updateSettings();

		} elseif ( is_admin() ) {

			$this->init_form_fields();
			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
		}

		$this->enabled = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'yes';
	}

	public function init_form_fields()
	{
		$countries = WC()->countries;

		if ( isset( $countries ) ) {
			$countries_and_states = array();

			foreach ( $countries->get_countries() as $key => $value ) {
				$states = $countries->get_states( $key );

				if ( $states ) {
					foreach ( $states as $state_key => $state_value ) {
						$countries_and_states[ $key . ':' . $state_key ] = $value . ' - ' . $state_value;
					}
				} else {
					$countries_and_states[ $key ] = $value;
				}
			}
		} else {
			$countries_and_states = array();
		}

		if ( method_exists( $countries, 'get_base_address' ) ) {
			$default_country_and_state = $countries->get_base_country();
			if ($state = $countries->get_base_state()) {
				$default_country_and_state .= ':' . $state;
			}

			$default_address_1 = $countries->get_base_address();
			$default_address_2 = $countries->get_base_address_2();
			$default_city = $countries->get_base_city();
			$default_code = $countries->get_base_postcode();

		} else {
			reset( $countries_and_states );

			$default_country_and_state = key( $countries_and_states );
			$default_address_1 = '';
			$default_address_2 = '';
			$default_city = '';
			$default_code = '';
		}

		$this->form_fields = array(
			'enabled' => array(
				'title'       => __( 'Enable Live Shipping Rate Calculations', 'shiptime_ls' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'yes',
			),
			'display_errors' => array(
				'title'       => __( 'Display errors on the storefront', 'shiptime_ls' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'yes',
			),
			'origin_country_and_state'   => array(
				'title'   => __( 'Origin Country', 'shiptime_ls' ),
				'type'    => 'select',
				'options' => $countries_and_states,
				'default' => $default_country_and_state
			),
			'origin_city'      => array(
				'title'             => __( 'Origin City', 'shiptime_ls' ),
				'type'              => 'text',
				'custom_attributes' => array(
					'required' => 'required',
				),
				'default' => $default_city
			),
			'origin_address_1'   => array(
				'title'             => __( 'Origin Address Line 1', 'shiptime_ls' ),
				'type'              => 'text',
				'custom_attributes' => array(
					'required' => 'required',
				),
				'default' => $default_address_1
			),
			'origin_address_2'   => array(
				'title'             => __( 'Origin Address Line 2', 'shiptime_ls' ),
				'type'              => 'text',
				'default' => $default_address_2
			),
			'origin_postcode'  => array(
				'title'             => __( 'Origin Postcode', 'shiptime_ls' ),
				'type'              => 'text',
				'custom_attributes' => array(
					'required' => 'required',
				),
				'default' => $default_code
			),
		);
	}

	private function _get_origin()
	{
		$country_and_state = explode(':', $this->settings['origin_country_and_state'], 2);

		return array(
			'country' => $country_and_state[0],
			'state' => isset( $country_and_state[1] ) ? $country_and_state[1] : '',
			'city' => $this->settings['origin_city'],
			'address_1' => $this->settings['origin_address_1'],
			'address_2' => $this->settings['origin_address_2'],
			'postcode' => $this->settings['origin_postcode'],
		);
	}

	public function get_rates_for_package($package)
	{
		if (empty( $package['destination'] ) || ! $this->is_enabled() ) {
			return $this->rates;
		}

		$package['origin'] = $this->_get_origin();

		if ( empty( $package['origin']['city'] )
		     || empty( $package['origin']['address_1'] )
		     || empty( $package['destination'] )
		     || ! $this->is_enabled()
		) {
			$this->_error(__( 'Shipping origin address is not specified.', 'shiptime_ls' ));
		}

		$package['currency'] = get_woocommerce_currency();

		$package['items'] = array();
		foreach ($package['contents'] as $item) {
			$package['items'][] = $this->_prepare_item_data($item);
		}

		$package['destination']['first_name'] = WC()->customer->get_shipping_first_name();
		$package['destination']['last_name'] = WC()->customer->get_shipping_last_name();
		$package['destination']['company'] = WC()->customer->get_shipping_company();

    if (empty($this->_config['allowPreEstimate'])) {
      if (isset($this->_config['requiredDestAddrFields'])) {
        foreach ($this->_config['requiredDestAddrFields'] as $field) {
          if (empty($package['destination'][$field])) {
            return $this->rates;
          }
        }
      } elseif (empty($package['destination']['country'])
        || empty($package['destination']['postcode'])
        || empty($package['destination']['city'])
        || (empty($package['destination']['address_1']) && empty($package['destination']['address']))
      ) {
        return $this->rates;
      }
    } elseif (empty($package['destination']['country']) || empty($package['destination']['postcode'])) {
      $this->_error(__('Shipping address is not complete.', 'shiptime_ls'));

      return $this->rates;
    }

    unset( $package['contents'] );
		unset( $package['rates'] );

		try {
			foreach ($this->_requestRates($package) as $rate) {
				$this->add_rate(
					array(
						'id'        => $rate['id'],
						'label'     => $rate['label'],
						'cost'      => $rate['cost'],
						'taxes'     => $rate['taxes'],
						'calc_tax'  => $rate['calc_tax'],
						'meta_data' => $rate['meta_data'],
					)
				);
			}
		} catch (Shiptime_Shipping_Exception $e) {
			update_post_meta(
				str_replace( 'shiptime_shipping_', '', $this->id ),
				'shiptime_shipping_service_last_error',
				$e->getMessage() . PHP_EOL . $e->getTraceAsString()
			);

			$this->_error($e->getMessage());
		}

		return $this->rates;
	}

	private function _error($message, $messageType = "error")
	{
		if ($this->settings['display_errors'] === 'yes' && ! wc_has_notice( $message, $messageType ) ) {
			wc_add_notice( $message, $messageType );
		}
	}

	private function _prepare_item_data($item)
	{
		$itemData = $item['data'];
		/**
		 * @var WC_Product $itemData
		 */

		$data = array(
			'id'           => $itemData->get_id(),
			'sku'          => $itemData->get_sku(),
			'name'         => $itemData->get_name(),
			'variant_id'   => $item['variation_id'] ?: null,
			'weight'       => $itemData->get_weight(),
			'length'       => $itemData->get_length(),
			'width'        => $itemData->get_width(),
			'height'       => $itemData->get_height(),
			'quantity'     => $item['quantity'],
			'price'        => $itemData->get_price(),
			'subtotal'     => $item['line_subtotal'],
			'subtotal_tax' => $item['line_subtotal_tax'],
			'total'        => $item['line_total'],
			'total_tax'    => $item['line_tax'],
		);

		return $data;
	}

	/**
	 * @param $data
	 *
	 * @return array|WP_Error
	 */
	private function _requestRates($data)
	{
		$error_msg = __( 'Can\'t retrieve shipping rates.', 'shiptime_ls' );
		$retry_count = 0;

		while ($retry_count++ < self::RETRY_CNT) {
			$time = (string)time();
			$args = array(
				'sslverify' => true,
				'httpversion' => '1.1',
				'timeout' => 30,
				'redirection' => 0,
				'compress' => true,
				'body' => json_encode( $data ),
				'headers' => array(
					'content-type' => 'application/json'
				)
			);

			$headersToSign = array(
				'x-live-shipping-service-timestamp' => $time,
				'x-live-shipping-service-id' => $this->id
			);

			ksort($headersToSign);

			$args['headers']['x-live-shipping-service-sign'] = base64_encode(
				hash_hmac('sha256', json_encode($headersToSign) . $args['body'], $this->_secret, true)
			);

			$args['headers'] = $args['headers'] + $headersToSign;

			$res = wp_remote_post( $this->_callback_url, $args );

			$code = wp_remote_retrieve_response_code( $res );
			$body = wp_remote_retrieve_body( $res );

			switch ( $code ) {
				case 200:
					if (  wp_remote_retrieve_header( $res, 'content-type' ) === 'application/json'
					      && ( ( $response = json_decode( $body, true ) ) || $response == array() )
					) {
						return $response;
					}
					break;
				case 400:
					$error_msg = __( 'Can\'t retrieve shipping rates. Unauthorized.', 'shiptime_ls' );
					break;
				case 410:
				case 404:
				case 403:
					$error_msg = __( 'Live shipping rates provider is not available.', 'shiptime_ls' );
					$this->settings['enabled'] = 'no';//disable service
					$this->_updateSettings();
					break 2;
			}

			sleep(self::RETRY_SLP);
		}

		throw new Shitptime_Shipping_Exception($error_msg);
	}

	private function _updateSettings()
	{
		update_option( $this->get_option_key(), apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings ), 'yes' );
		$this->init_settings();
	}

}