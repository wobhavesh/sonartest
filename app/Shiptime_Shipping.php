<?php
 error_reporting(1);
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/*
 * Check if WooCommerce is active
 */
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
 
    function shiptime_shipping_method() {
        if ( ! class_exists( 'ShipTime_Shipping_Method' ) ) {
            class ShipTime_Shipping_Method extends WC_Shipping_Method {
                /**
                 * Constructor for your shipping class
                 *
                 * @access public
                 * @return void
                 */
                const RETRY_CNT = 3;
                const RETRY_SLP = 5;//sleep seconds before next retry
                
                public function __construct() {
                    
                    $this->id                 = 'shiptime'; 
                    $this->method_title       = __( 'ShipTime: Discount Shipping', 'shiptime' );  
                    $this->method_description = __( 'Discounted live shipping rates and label generation by <a href="https://shiptime.com">ShipTime</a>', 'shiptime' );  
                    // Availability & Countries
                    $this->availability = 'including';
 
                    $this->init();
 
                    $this->enabled = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'yes';
                    $this->title = isset( $this->settings['title'] ) ? $this->settings['title'] : __( 'ShipTime Shipping', 'shiptime' );

                }
 
                /**
                 * Init your settings
                 *
                 * @access public
                 * @return void
                 */
                function init() {
                    // Load the settings API
                    $this->init_form_fields(); 
                    $this->init_settings(); 
                    $this->_updateSettings();
                    // Save settings in admin if you have any defined
                    add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
                }

                private function _updateSettings()
                {
                  update_option( $this->get_option_key(), apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings ), 'yes' );
                  $this->init_settings();
                }

                protected function createApiKeys()
              {
                  global $wpdb;
                  global $woocommerce;


                    $consumer_key = 'ck_' . wc_rand_hash();
                    $consumer_secret = 'cs_' . wc_rand_hash();

                    $data = array(
                        'user_id' => get_current_user_id(),
                        'description' => 'shiptime',
                        'permissions' => 'read_write',
                        'consumer_key' => wc_api_hash($consumer_key),
                        'consumer_secret' => $consumer_secret,
                        'truncated_key' => substr($consumer_key, -7),
                    );

                    $table = $wpdb->prefix . 'woocommerce_api_keys';
                    $wpdb->query("DELETE FROM $table WHERE description = 'shiptime'");
                    $wpdb->insert(
                      $table,
                      $data,
                      array(
                          '%d',
                          '%s',
                          '%s',
                          '%s',
                          '%s',
                          '%s',
                      )
                    );
                  $setApikeys = array();
                  $setApikeys = get_option( 'woocommerce_shiptime_settings' );
                  $setApikeys['ApiKey'] = $consumer_key;
                  update_option('woocommerce_shiptime_settings', $setApikeys); 
                  return ['consumer_key' => $consumer_key, 'consumer_secret' => $consumer_secret];
                  
              }
                /**
                 * Define settings field for this shipping
                 * @return void 
                 */
                function init_form_fields() { 

                   $setApikeys = array();
                   $setApikeys = get_option( 'woocommerce_shiptime_settings' );
                   if(empty($setApikeys['ApiKey'])) {

                    $this->createApiKeys();
                  }

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
                      $default_country = WC()->countries->countries[WC()->countries->get_base_country()];

                    } else {
                      reset( $countries_and_states );

                      $default_country_and_state = key( $countries_and_states );
                      $default_address_1 = '';
                      $default_address_2 = '';
                      $default_city = '';
                      $default_code = '';
                      $default_country = '';
                    }
 
                    $this->form_fields = array(
 
                     'enabled' => array(
                          'title' => __( 'Enable', 'shiptime' ),
                          'type' => 'checkbox',
                          'label' => 'Enable ShipTime Plugin',
                          //'description' => __( 'Enable this checkbox to show ShipTime shipping rates.', 'shiptime' ),
                          'default' => 'no'
                          ),
                      'live_rates_enabled' => array(
                          'label'       => __( 'Enable Live Shipping Rate Calculations', 'shiptime_ls' ),
                          'type'        => 'checkbox',
                          'description' => '',
                          'default'     => 'no',
                          
                        ),
                     'connect' => array(
                          'title' => __( 'Connect', 'shiptime' ),
                          'type' => 'button',
                          'description' => __( 'Connect with store.', 'shiptime' ),
                          'default' => 'connect',
                          'desc_tip'  => false,
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
                          'ApiKey' => array(
                            // 'title' => __( 'API Key', 'shiptime' ),
                            'type' => 'hidden',
                            // 'description' => __( 'Define API key here', 'shiptime' ),
                            // 'default' => '',
                          ),
                          'title' => array(
                            //'title' => __( 'Title', 'shiptime' ),
                            'type' => 'hidden',
                            // 'description' => __( 'Title to be display on site', 'shiptime' ),
                            'default' => __( 'ShipTime Shipping', 'shiptime' )
                          ),
                          'liverateUrl' => array(
                            // 'title' => __( 'Live Rate Url', 'liverateUrl' ),
                            'type' => 'hidden',
                            // 'description' => __( 'Live Rate Url', 'liverateUrl' ),
                            // 'default' => ''
                          ),   
                     );


                    oauth_action_button_st();
                    add_action('admin_enqueue_scripts', 'oauth_action_button_st');
                    
                }
                public function is_available( $package ){
                    return true;
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

                  if($this->settings['live_rates_enabled'] == 'no')
                  {
                    return $this->rates;
                  }

                  if (empty( $package['destination'] ) || ! $this->is_enabled() ) {
                    return $this->rates;
                  }

                  $country = array();
                  $country['code2'] = $this->_get_origin()['country'];
                  $country['code3'] = 'CAN';
                  $country['name'] = WC()->countries->countries[WC()->countries->get_base_country()];



                  $country_and_state = explode(':', $this->settings['origin_country_and_state'], 2);

                  $state = array();
                  $state['code'] = $country_and_state[1];
                  $state['name'] = WC()->countries->states[$country_and_state[0]][$country_and_state[1]];

                  $package['origin'] = $this->_get_origin();
                  $package['origin']['first_name'] = null;
                  $package['origin']['last_name'] = null;
                  $package['origin']['country'] = $country;
                  $package['origin']['state'] = $state;

                  if ( empty( $package['origin']['city'] )
                       || empty( $package['origin']['address_1'] )
                       || empty( $package['destination'] )
                       || ! $this->is_enabled()
                  ) {
                   // $this->_error(__( 'Shipping origin address is not specified.', 'shiptime_ls' ));
                  }

                  $package['currency'] = get_woocommerce_currency();



                  $package['items'] = array();
                  foreach ($package['contents'] as $item) {
                    $package['items'][] = $this->_prepare_item_data($item);
                  }

                  $des_country = array();
                  $des_country['code2'] = WC()->customer->billing['country'];
                  $des_country['code3'] = 'CAN';
                  $des_country['name'] = WC()->countries->countries[WC()->customer->billing['country']];

                  $des_state = array();
                  $des_state['code'] = WC()->customer->billing['state'];

                  $des_state['name'] = WC()->countries->states[WC()->customer->billing['country']][WC()->customer->billing['state']];                 

                  $package['destination']['country'] = $des_country;
                  $package['destination']['state'] = $des_state;
                  $package['destination']['first_name'] = WC()->customer->get_shipping_first_name();
                  $package['destination']['last_name'] = WC()->customer->get_shipping_last_name();
                  $package['destination']['company'] = WC()->customer->get_shipping_company();
                 
                  
                  /*if(empty($package['destination']['country'])
                      || empty($package['destination']['postcode'])
                      || empty($package['destination']['city'])
                      || (empty($package['destination']['address_1']) && empty($package['destination']['address'])))
                  {
                    return $this->rates;
                  }*/

                  unset( $package['contents'] );
                  unset( $package['rates'] );
                  unset( $package['contents_cost'] );
                  unset( $package['applied_coupons'] );
                  unset( $package['cart_subtotal'] );
                  unset( $package['user'] );

                  $newPackage = array();
                  $newPackage['id'] = 1;
                  $newPackage['currency_code'] = $package['currency'];
                  $newPackage['origin'] = $package['origin'];
                  $newPackage['destination'] = $package['destination'];
                  $newPackage['items'] = $package['items'];
                  
                  $finalpackage['packages'] = array();
                  $finalpackage['packages']['0'] = $newPackage;

                 
                 

                  $time = (string)time();
                  $digits = 3;
                  $randId = rand(pow(10, $digits-1), pow(10, $digits)-1);
                  $args = array(
                      'sslverify' => false,
                      'httpversion' => '1.1',
                      'timeout' => 30,
                      'redirection' => 0,
                      'compress' => true,
                      'body' =>  json_encode($finalpackage) ,
                      'headers' => array(
                        'content-type' => 'application/json'
                      )
                  );
                  $headersToSign = array(
                      'X-Shipping-Service-Request-Timestamp' => '' . $time,
                      'X-Shipping-Service-Id' => '' . $randId
                  );
                  ksort($headersToSign);

                 

                  $getapikey = get_consumer_key($this->settings['ApiKey']); 

                 
                  
                  $encodeHmac = base64_encode(hash_hmac('sha256', json_encode($headersToSign) . json_encode($finalpackage), $getapikey->consumer_secret, true));
                 
                  $args['headers']['X-Shipping-Service-Signature'] = $encodeHmac;

                  $args['headers'] = $args['headers'] + $headersToSign;
                  $seturls = array();
                  $seturls = get_option( 'woocommerce_shiptime_settings' );
                  
                  $url = $seturls['liverateUrl'];
                  
                  if(empty($url))
                  {
                    return $this->rates;
                  } 
                  $res = wp_remote_post( $url, $args );


                   // print_r($url);die;

                    $getRates = json_decode($res['body']);                

                    foreach ($getRates->packages_rates[0]->rates as $rate) {
                      $ratedata = array(
                          'id'        => $rate->name,
                          'label'     => $rate->name,
                          'cost'      => $rate->total_cost,
                          'taxes'     => $rate->taxable,                         
                        );                                             
                        $this->add_rate( $ratedata );
                    }
                  return $this->rates;
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
                    'weight_unit'   => get_option('woocommerce_weight_unit'),
                  );
                  $data['additional_fields'] = array(
                        'dimensions_unit' =>  get_option('woocommerce_dimension_unit'),
                        'height' => $itemData->get_height(),
                        'width'        => $itemData->get_width(),
                        'length'       => $itemData->get_length(),
                    );
                  
                  return $data;
                }
            }
        }              
    }
 
    add_action( 'woocommerce_shipping_init', 'shiptime_shipping_method' );
 
    function add_shiptime_shipping_method( $methods ) {
        $methods['shiptime'] = 'ShipTime_Shipping_Method';
        return $methods;
    }

     add_action('wp_ajax_oauth_st', 'oauth_es_callback');

    function oauth_es_callback()
    {
        /** [[CUSTOM] FOR CONFIGURING .ENV FILE **////////
        /** @desc this loads the composer autoload file */
        require_once 'vendor/autoload.php';
        /** @desc this instantiates Dotenv and passes in our path to .env */
        $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
        $dotenv->load();
        
        if($_ENV['SHIPTIME_ENV'] == 'production'){
            $shiptime_api_url = isset( $_ENV['SHIPTIME_PRODUCTION_API_URL'] ) ? $_ENV['SHIPTIME_PRODUCTION_API_URL']  : '' ;
        } else if($_ENV['SHIPTIME_ENV'] == 'staging'){
            $shiptime_api_url = isset( $_ENV['SHIPTIME_STAGING_API_URL'] ) ? $_ENV['SHIPTIME_STAGING_API_URL']  : '' ;
        } else {
            $shiptime_api_url = isset( $_ENV['SHIPTIME_DEVELOPMENT_API_URL'] ) ? $_ENV['SHIPTIME_DEVELOPMENT_API_URL']  : '' ;
        }
        $shiptime_platform = isset( $_ENV['SHIPTIME_PLATFORM'] ) ? $_ENV['SHIPTIME_PLATFORM']  : '' ;
        //////////////////////////////////////////////////
                    
        $getApidata = array();
        $getApidata = get_option('woocommerce_shiptime_settings');
        $Apidata = [];
        $Apidata['storeUrl'] = esc_url(site_url());
        $Apidata['apiSecret'] = esc_html($getApidata['ApiKey']);
        $Apidata['platform'] = esc_html($shiptime_platform); //'WoocommerceNativeApi';
        $Apidata['apiUrl'] = esc_url($shiptime_api_url);
        
        echo json_encode($Apidata);        
        die;
    }
    add_filter( 'woocommerce_shipping_methods', 'add_shiptime_shipping_method' );

    function oauth_action_button_st()
    {
        wp_enqueue_script(
            'oauth_action_button_st',
            plugin_dir_url(__FILE__) . 'includes/assets/js/admin/ajax_oauth_st.js',
            array('jquery'),
            '5.0.4');
    }
    $shipTimeValues = array();
   $shipTimeValues = get_option( 'woocommerce_shiptime_settings' );
    
   if($shipTimeValues)
  {
     if($shipTimeValues['enabled'] == 'yes')
     {
           add_action('rest_api_init', function () {
              register_rest_route( 'shiptime', '/checkstatus', array(
                  'methods' => 'POST',
                  'callback' => 'newShipstatus'
              ));
          });

           add_action('rest_api_init', function () {
              register_rest_route( 'shiptime', '/checkauth', array(
                  'methods' => 'POST',
                  'callback' => 'checkauthenticaton'
              ));
          });
     }
  }

   function checkauthenticaton($req)
   {
    $getapiData = get_consumer_key($req['apiKey']); 

    if(!empty($getapiData)){
      $response['api_keys'] = $req['apiKey'];
      $response['apiSecret'] = $getapiData->consumer_secret;
    }
    else
    {
      $response['error_message'] = 'Invalid ApiKey';      
    }
    $res = new WP_REST_Response($response);
	$res->set_status(200);
	if($res->data['error_message'])
	{
	  $res->set_status(404);
	}  
    return ['req' => $res];
   }
   
    function newShipstatus($req) {
        $response['status'] = $req['status'];
        if($response['status'] === 'true'){
            $response['message'] = 'Connection Successful';
        }
        else
        {
            $response['message'] = 'Connection Error';
        }
        $res = new WP_REST_Response($response); 
        if($response['status'] === 'true'){          
           $res->set_status(200);           
        }else{
             $res->set_status(404);
        }
        global $wpdb;
        $table = $wpdb->prefix.'cart_table';
        $data = array('status' => $res->data['message'],'updatedAt'=>date('Y-m-d H:i:s'));
        $wpdb->insert($table,$data);
        return ['req' => $res];
          
        
    }
    function get_consumer_key( $consumer_key ) {
          global $wpdb;
          $consumer_key = wc_api_hash( sanitize_text_field( $consumer_key ) );
          $api         = $wpdb->get_row(
            $wpdb->prepare("
            SELECT key_id, user_id, permissions, consumer_key, consumer_secret, nonces
            FROM {$wpdb->prefix}woocommerce_api_keys
            WHERE consumer_key = %s
          ",
              $consumer_key
            )
          );
          return $api;
        }


    add_action('rest_api_init', function () {
              register_rest_route( 'shiptime', '/enableLiverate', array(
                  'methods' => 'POST',
                  'callback' => 'enableLiverateUrl'
              ));
    });

   function enableLiverateUrl($req)
     {    
      $response = array();
      $response['liverateUrl'] = esc_url($req['liverateUrl']);
      $response['enable'] = $req['enable'];

      $res = new WP_REST_Response($response);
      if(!empty($res))
      {
        if($response['enable'] == 'true')
        {
          $liveRateCheckboxValue = 'yes';
        }
        else
        {
          $liveRateCheckboxValue = 'no';
        }
        $seturls = array();
        $seturls = get_option( 'woocommerce_shiptime_settings' );
        $seturls['liverateUrl'] = esc_url($response['liverateUrl']);
        $seturls['live_rates_enabled'] = $liveRateCheckboxValue;
        update_option('woocommerce_shiptime_settings', $seturls);      
      }
      $res->set_status(200);
      return ['req' => $res];
     }

    add_action('rest_api_init', function () {
              register_rest_route( 'shiptime', '/storedelete', array(
                  'methods' => 'POST',
                  'callback' => 'storeDelete'
              ));
    });
    function storeDelete($req)
     {    
      $getApiKey = $req->get_params();
      $response = array();
      $response['apiKey'] = esc_html($getApiKey['apiKey']);
      $res = new WP_REST_Response($response);
      $setApikeys = array();
      $setApikeys = get_option( 'woocommerce_shiptime_settings' );
      
      if($res->data['apiKey'] == $setApikeys['ApiKey'])
      {
        update_option( 'woocommerce_shiptime_settings',false);  
        $res->message = 'Store Delete Successfully';
		$res->set_status(200);
      }
      else
      {
          $res->message = 'ApiKey does not match !';
		  $res->set_status(406);
      }
      
      
      return ['req' => $res];
     }

    function insert_cart_table_into_db(){
      global $wpdb;
      $charset_collate = $wpdb->get_charset_collate();
    $tablename = $wpdb->prefix."cart_table";
    $sql = "CREATE TABLE $tablename (
      id mediumint(11) NOT NULL AUTO_INCREMENT,
      status varchar(80) NOT NULL,
      updatedAt datetime,
      PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
      $is_error = empty( $wpdb->last_error );
      return $is_error;
    }

    insert_cart_table_into_db();
   
}
