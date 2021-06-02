<?php
        /*
		* Plugin Name: PitchPrint
		* Plugin URI: https://pitchprint.com
		* Description: A beautiful web based print customization app for your online store. Integrates with WooCommerce.
		* Author: PitchPrint
		* Version: 10.0.4
		* Author URI: https://pitchprint.com
		* Requires at least: 3.8
		* Tested up to: 5.4
        * WC requires at least: 3.0.0
        * WC tested up to: 4.3.2
		*
		* @package PitchPrint
		* @category Core
		* @author PitchPrint
        */

	load_plugin_textdomain('PitchPrint', false, basename( dirname( __FILE__ ) ) . '/languages/' );

	include('utils/browser.php');
	
	function end_session() {
		if(session_id()) session_destroy();
	}
	add_action('wp_logout','end_session');

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

	class PitchPrint {

		public $version = '10.0.3';

		protected $editButtonsAdded = false;
		
		private $ppTable;

		public function __construct() {
			global $wpdb;
			$this->ppTable = $wpdb->prefix . 'pitchprint_projects';
			$this->define_constants();
			$this->init_hooks();
		}

		private function define_constants() {
			define('PP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
			define('PP_IOBASE', 'https://pitchprint.io');
			$browserValid = true; //browserValid();
			if ($browserValid) {
				define('PP_CLIENT_JS', 'https://pitchprint.io/rsc/js/client.js');
			} else {
				define('PP_CLIENT_JS', 'https://pitchprint.io/rsc/js/noes6.js');
			}
			define('PP_ADMIN_JS', 'https://pitchprint.io/rsc/js/a.wp.js');
			define('PPADMIN_DEF', "var PPADMIN = window.PPADMIN; if (typeof PPADMIN === 'undefined') window.PPADMIN = PPADMIN = { version: '9.0.0', readyFncs: [] };");
		}

		private function init_hooks() {
			if ($this->request_type('frontend')) {
				add_filter('woocommerce_get_cart_item_from_session', array($this, 'pp_get_cart_item_from_session'), 10, 2);
				add_filter('woocommerce_get_item_data',  array($this, 'pp_get_cart_mod'), 10, 2);
				add_filter('woocommerce_cart_item_thumbnail', array($this, 'pp_cart_thumbnail'), 70, 2);
				add_filter('woocommerce_cart_item_permalink', array($this, 'pp_cart_item_permalink'), 70, 2);
				add_filter('woocommerce_add_cart_item_data', array($this, 'pp_add_cart_item_data'), 10, 2);

				add_filter('woocommerce_display_item_meta', array($this, 'pp_order_items_display'), 10, 2);
				add_filter('woocommerce_order_items_meta_display', array($this, 'pp_order_items_meta_display'), 10, 2);
				add_filter('woocommerce_order_details_after_order_table', array($this, 'pp_order_after_table'));

				add_action('woocommerce_before_my_account',  array($this, 'pp_my_recent_order'));
				add_action('woocommerce_add_order_item_meta', array($this, 'pp_add_order_item_meta'), 70, 2);

				add_action('wp_head', array($this, 'pp_header_files'));
				add_action('woocommerce_before_add_to_cart_button', array($this, 'add_pp_edit_button'));
				add_action('woocommerce_after_cart', array($this, 'pp_get_cart_action'));
				add_action('woocommerce_after_checkout_form', array($this, 'pp_get_cart_action'));
				//add_action('woocommerce_thankyou', array($this,'pp_check_webhook_status'));
			} else if ($this->request_type('admin')) {
				add_action('admin_menu', array($this, 'ppa_actions'));
				add_action('woocommerce_admin_order_data_after_order_details', array($this, 'ppa_order_details'));
				add_action('woocommerce_product_options_pricing', array($this, 'ppa_design_selection'));
				add_action('woocommerce_process_product_meta', array($this, 'ppa_write_panel_save'));
				add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'ppa_add_settings_link'));
				add_action('admin_init', array($this, 'ppa_settings_api_init'));
			}
			add_action('woocommerce_order_status_changed', array($this,'pp_order_status_completed'),10,3);
		}

		function pp_check_webhook_status($order_id){
			$order = wc_get_order( $order_id );
			if($order->get_data()['status'] === 'processing') pp_order_status_completed($order_id, null, 'processing');
		}
		
		private function clearProjects($productId) {
			global $wpdb;
			$sessId = isset($_COOKIE['pitchprint_sessId']) ? $_COOKIE['pitchprint_sessId'] : false;
			if (!$sessId) return false;
			$wpdb->delete($this->ppTable, array('id' => $sessId, 'product_id' => $productId) );
		}
		
		private function clearOldProjects() {
			global $wpdb;
			$sql = "DELETE FROM `$this->ppTable` WHERE `expires` < '" . date('Y-m-d H:i:s', time()) . "';";
			$wpdb->query($sql);
		}
		
		function pp_order_status_completed($order_id, $status_from, $status_to) {
			$pp_webhookUrl = false;
			
			// Clear Old Projects
			if ( date('j') == '1' ) 
				$this->clearOldProjects();
			
			if ( $status_to === "completed" ) $pp_webhookUrl = 'order-complete';
			if ( $status_to === "processing" ) $pp_webhookUrl = 'order-processing';
			
			if ( $pp_webhookUrl ) {
				
				$order = wc_get_order($order_id);
				$order_data = $order->get_data();

				$billing = $order_data['billing'];
				$billingEmail = $billing['email'];
				$billingPhone = $billing['phone'];
				$billingName = $billing['first_name'] . " " . $billing['last_name'];
				
				$addressArr = ['address_1', 'address_2', 'city', 'postcode', 'country'];
				$billingAddress = '';
				foreach ($addressArr as $addKey) 
					if (!empty($billing[$addKey])) 
						$billingAddress .= $billing[$addKey].", ";
				$billingAddress = substr($billingAddress, 0, strlen($billingAddress) -2);

				$shippingName = $order_data['shipping']['first_name'] . " " . $order_data['shipping']['last_name'];
				$shippingAddress = $order->get_formatted_shipping_address();
				$status = $order_data['status'];

				$products = $order->get_items();
				$userId = $order_data['customer_id'];
				$items = array();

				foreach ($products as $item_key => $item_values) {
					$item_data = $item_values->get_data();
					$items[] = array(
						'name' => $item_data['name'],
						'id' => $item_data['product_id'],
						'qty' => $item_data['quantity'],
						'pitchprint' => wc_get_order_item_meta($item_key, '_w2p_set_option')
					);
				}

				// If empty Pitchprint value, then we won't trigger the webhook.
				$pp_empty = true;
				foreach ($items as $item) {
					if (!empty($item['pitchprint'])) {
						$pp_empty = false;
						break;
					}
				}

				if (!$pp_empty) {
				
					$items = json_encode($items);
					$cred = $this->pp_fetch_credentials();
					
					$ch = curl_init();
					
					curl_setopt($ch, CURLOPT_URL, "https://api.pitchprint.io/runtime/$pp_webhookUrl");
					curl_setopt($ch, CURLOPT_POST, true);
					
					$opts =  array (
								'products' => $items,
								'client' => 'wp',
								'billingEmail' => $billingEmail,
								'billingPhone' => $billingPhone,
								'billingName' => $billingName,
								'billingAddress' => $billingAddress,
								'shippingName' => $shippingName,
								'shippingAddress' => $shippingAddress,
								'orderId' => $order_id,
								'customer' => $userId,
								'status' => $status,
								'apiKey' => get_option('ppa_api_key'),
								'signature' => $cred['signature'],
								'timestamp' => $cred['timestamp']
							);
					
					curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts));
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
					
					$output = curl_exec($ch);
					$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
					$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
					$curlerr = curl_error($ch);
					curl_close($ch);

					if ($curlerr) {
					   error_log(print_r($curlerr, true));
					}
				}
			}
		}
		
		public function pp_order_after_table() {
			wp_enqueue_script('pitchprint_class', PP_CLIENT_JS);
			
			wc_enqueue_js("
				ajaxsearch = undefined;
				(function(_doc) {
					window.ppclient = new PitchPrintClient({
						userId: '" . (get_current_user_id() === 0 ? 'guest' : get_current_user_id())  . "',
						langCode: '" . substr(get_bloginfo('language'), 0, 2) . "',
						mode: 'edit',
						pluginRoot: '" . plugins_url('/', __FILE__) . "',
						apiKey: '" . get_option('ppa_api_key') . "',
						client: 'wp',
						afterValidation: '_sortCart'
					});
				})(document);");
		}
		
		public function pp_order_items_display($output, $item) {
			foreach ($item->get_meta_data() as $meta_id => $meta) {
				if ($meta->key === '_w2p_set_option') {
					$output .= '<span style="display:none" data-pp="' . rawurlencode($meta->value) . '" class="pp-cart-order"></span>';
				}
			}
			return $output;
        }
        public function pp_order_items_meta_display($output, $_this) {
            if (isset($_this->meta['_w2p_set_option'])) {
                if (!empty($_this->meta['_w2p_set_option'])) {
                    $val = $_this->meta['_w2p_set_option'][0];
                    $val = rawurlencode($val);
                    $output .= '<span  style="display:none" data-pp="' . $val . '" class="pp-cart-order"></span>';
                }
            }
            return $output;
        }

		public function pp_my_recent_order() {
			global $post, $woocommerce;
			wp_enqueue_script('pitchprint_class', PP_CLIENT_JS);
			wp_enqueue_script('prettyPhoto', $woocommerce->plugin_url() . '/assets/js/prettyPhoto/jquery.prettyPhoto.min.js', array( 'jquery' ), $woocommerce->version, true );
			wp_enqueue_script('prettyPhoto-init', $woocommerce->plugin_url() . '/assets/js/prettyPhoto/jquery.prettyPhoto.init.min.js', array( 'jquery' ), $woocommerce->version, true );
			wp_enqueue_style('woocommerce_prettyPhoto_css', $woocommerce->plugin_url() . '/assets/css/prettyPhoto.css' );
			
			echo '<div id="pp_mydesigns_div"></div>';
			wc_enqueue_js("
				ajaxsearch = undefined;
				(function(_doc) {
					if (typeof PitchPrintClient === 'undefined') return;
					window.ppclient = new PitchPrintClient({
						userId: '" . (get_current_user_id() === 0 ? 'guest' : get_current_user_id())  . "',
						langCode: '" . substr(get_bloginfo('language'), 0, 2) . "',
						mode: 'edit',
						pluginRoot: '" . plugins_url('/', __FILE__) . "',
						apiKey: '" . get_option('ppa_api_key') . "',
						client: 'wp',
						afterValidation: '_fetchProjects',
						isCheckoutPage: " . (is_checkout() ? 'true' : 'false') . "
					});
				})(document);");
		}
		
		public function pp_get_cart_action() {
			global $post, $woocommerce;
			wp_enqueue_script('pitchprint_class', PP_CLIENT_JS);
			wc_enqueue_js("
				ajaxsearch = undefined;
				(function(_doc) {
					if (typeof PitchPrintClient === 'undefined') return;
					window.ppclient = new PitchPrintClient({
						userId: '" . (get_current_user_id() === 0 ? 'guest' : get_current_user_id())  . "',
						langCode: '" . substr(get_bloginfo('language'), 0, 2) . "',
						mode: 'edit',
						pluginRoot: '" . plugins_url('/', __FILE__) . "',
						apiKey: '" . get_option('ppa_api_key') . "',
						client: 'wp',
						afterValidation: '_sortCart',
						isCheckoutPage: " . (is_checkout() ? 'true' : 'false') . "
					});
				})(document);");
		}

		public function pp_cart_thumbnail($img, $val) {
			if (!empty($val['_w2p_set_option'])) {
				$itm = $val['_w2p_set_option'];
				$itm = json_decode(rawurldecode($itm), true);
				if ($itm['type'] == 'p') {
					$img = '<img style="width:90px" src="' . PP_IOBASE . '/previews/' . $itm['projectId'] . '_1.jpg" >';
				} else {
					$img = '<img style="width:90px" src="' . $itm['previews'][0] . '" >';
				}
			}
			return $img;
		}

		public function pp_cart_item_permalink($link, $val) {
			if (!empty($val['_w2p_set_option'])) {
				$itm = $val['_w2p_set_option'];
				$itm = json_decode(rawurldecode($itm), true);
				if ($itm['type'] == 'p') {
					$link .=  (strpos($link, '?') === false ? '?' : '&') .'pitchprint=' . $itm['projectId'];
				}
			}
			return $link;
		}

		public function pp_get_cart_mod( $item_data, $cart_item ) {
			if (!empty($cart_item['_w2p_set_option'])) {
				$val = $cart_item['_w2p_set_option'];
				$itm = json_decode(rawurldecode($val), true);
				$item_data[] = array(
					'name'    => '<span id="' . $val . '" class="pp-cart-label"></span>',
					'display' => '<a href="#" id="' . $val . '" class="button pp-cart-data"></a>'
				);
			}
			return $item_data;
		}

		public function pp_add_order_item_meta($item_id, $cart_item) {
			global $woocommerce;
			if	( !empty($cart_item['_w2p_set_option']) )
				wc_add_order_item_meta($item_id, '_w2p_set_option', $cart_item['_w2p_set_option']);
			if	( gettype($cart_item) == 'object' && isset($cart_item->legacy_values) && isset($cart_item->legacy_values['_w2p_set_option']) )
				wc_add_order_item_meta($item_id, '_w2p_set_option', $cart_item->legacy_values['_w2p_set_option']);
		}

		public function pp_get_cart_item_from_session($cart_item, $values) {
			if (!empty($values['_w2p_set_option'])) $cart_item['_w2p_set_option'] = $values['_w2p_set_option'];
			return $cart_item;
		}

		public function pp_add_cart_item_data($cart_item_meta, $product_id) {
			$_projects = $this->getProjectData($product_id);
			if (isset($_projects)) {
				if (isset($_projects[$product_id])) {
					$cart_item_meta['_w2p_set_option'] = $_projects[$product_id];
					if (!isset($_SESSION['pp_cache'])) $_SESSION['pp_cache'] = array();
					$opt_ = json_decode(rawurldecode($_projects[$product_id]), true);
					if ($opt_['type'] === 'p') $_SESSION['pp_cache'][$opt_['projectId']] = $_projects[$product_id] . "";
					if ( isset($_SESSION['pp_projects']) && isset($_SESSION['pp_projects'][$product_id]) )
						unset($_SESSION['pp_projects'][$product_id]);
					else 
						$this->clearProjects($product_id);
				}
			}
			return $cart_item_meta;
		}

		private function register_session(){
			if(!session_id() && !headers_sent()) session_start();
		}
		
		private function getProjectData($product_id = null) {
			if (!$product_id) {
				global $post;
				$product_id = $post->ID;
			}
			
			$_projects = array();
			
			
			if ( isset($_COOKIE['pitchprint_sessId']) ) {
				global $wpdb;
				$sessId = $_COOKIE['pitchprint_sessId'];
				$sql = "SELECT `value` FROM `$this->ppTable` WHERE `product_id` = $product_id AND `id` = '$sessId';";
				$results = $wpdb->get_results($sql);
				if(count($results))
					$_projects[$product_id] = $results[0]->value;
					
			} else {
				$this->register_session();
				$_projects =  $_SESSION['pp_projects'];
			}
			return $_projects;
		}
		
		public function add_pp_edit_button() {
			if ($this->editButtonsAdded) {
				return;
			}
			$this->editButtonsAdded = true;

			global $post;
			global $woocommerce;
			$pp_mode = 'new';
			$pp_set_option = get_post_meta( $post->ID, '_w2p_set_option', true );
			$pp_display_option = get_post_meta( $post->ID, '_w2p_display_option', true );
			$pp_customization_required = get_post_meta( $post->ID, '_w2p_required_option', true );
			$pp_pdf_download = get_post_meta( $post->ID, '_w2p_pdf_download_option', true );
			// var_dump($pp_customization_required);die();
			$pp_customization_required = 
				( $pp_customization_required === 'undefined' || 
				( isset($pp_customization_required) && strlen($pp_customization_required) == 0) ) ? 0 : ( $pp_customization_required ? 1 : 0 );
			$pp_pdf_download = 
				( $pp_pdf_download === 'undefined' || 
				( isset($pp_pdf_download) && strlen($pp_pdf_download) == 0 ) ) ? 'undefined' : ( $pp_pdf_download ? 1: 0 );
			
			if (strpos($pp_set_option, ':') === false) $pp_set_option = $pp_set_option . ':0';
			$pp_set_option = explode(':', $pp_set_option);
			if (count($pp_set_option) === 2) $pp_set_option[2] = 0;
			$pp_project_id = '';
			$pp_uid = get_current_user_id() === 0 ? 'guest' : get_current_user_id();
			$pp_now_value = '';
			$pp_previews = '';
			$pp_upload_ready = false;

			$_projects = $this->getProjectData();

			$_ppcache = "";
			$pp_now_value = "";
			
			if ( !isset($_COOKIE['pitchprint_sessId']) ) {
				$this->register_session();
			
				if (isset($_GET['pitchprint']) && isset($_SESSION['pp_cache'])) {
					if (!empty($_GET['pitchprint']) && !empty($_SESSION['pp_cache'])) {
						if (!empty($_SESSION['pp_cache'][$_GET['pitchprint']])) $_ppcache = $_SESSION['pp_cache'][$_GET['pitchprint']];
					}
				}
			}

			if (!empty($_ppcache)) {
				$pp_now_value = $_ppcache;
			} else if (isset($_projects)) {
				if (isset($_projects[$post->ID])) {
					$pp_now_value = $_projects[$post->ID];
				}
			}
			
			$pp_design_id = $pp_set_option[0];

			if (!empty($pp_now_value)) {
				$opt_ = json_decode(rawurldecode($pp_now_value), true);
				if ($opt_['type'] === 'u') {
					$pp_upload_ready = true;
					$pp_mode = 'upload';
				} else if ($opt_['type'] === 'p') {
					$pp_mode = 'edit';
					$pp_project_id = $opt_['projectId'];
					$pp_previews = $opt_['numPages'];
					if (isset($opt_['designId']) && !empty($opt_['designId'])) $pp_design_id = $opt_['designId'];				}
			}

			$userData = '';

			if (is_user_logged_in()) {
				global $current_user;
				wp_get_current_user();
				$fname = addslashes($woocommerce->customer->get_billing_first_name());
				$lname = addslashes($woocommerce->customer->get_billing_last_name());
				$address_1 = $woocommerce->customer->get_billing_address_1();
				$address_2 = $woocommerce->customer->get_billing_address_2();
				$city = $woocommerce->customer->get_billing_city();
				$postcode = $woocommerce->customer->get_billing_postcode();
				$state = $woocommerce->customer->get_billing_state();
				$country = $woocommerce->customer->get_billing_country();
				$phone = $woocommerce->customer->get_billing_phone();

				$address = "{$address_1}<br>";
				if (!empty($address_2)) $address .= "{$address_2}<br>";
				$address .= "{$city} {$postcode}<br>";
				if (!empty($state)) $address .= "{$state}<br>";
				$address .= $country;
				$address = addslashes($address);

				$userData = ",
					userData: {
						email: '" . $current_user->user_email . "',
						name: '{$fname} {$lname}',
						firstname: '{$fname}',
						lastname: '{$lname}',
						telephone: '{$phone}',
						address: '{$address}'.split('<br>').join('\\n')
					}";
			}
			
			wc_enqueue_js("
				ajaxsearch = undefined;
				(function(_doc) {
					if (typeof PitchPrintClient === 'undefined') return;
					window.ppclient = new PitchPrintClient({
						displayMode: '{$pp_display_option}',
						customizationRequired: ". $pp_customization_required.",
						pdfDownload: ". $pp_pdf_download .",
						uploadUrl: '" . plugins_url('uploader/', __FILE__) . "',
						userId: '{$pp_uid}',
						langCode: '" . substr(get_bloginfo('language'), 0, 2) . "',
						enableUpload: {$pp_set_option[1]},
						designId: '{$pp_design_id}',
						previews: '{$pp_previews}',
						mode: '{$pp_mode}',
						createButtons: true,
						projectId: '{$pp_project_id}',
						pluginRoot: '" . plugins_url('/', __FILE__) . "',
						apiKey: '" . get_option('ppa_api_key') . "',
						client: 'wp',
						product: {
							id: '" . $post->ID . "',
							name: '{$post->post_name}'
						}{$userData},
						ppValue: '{$pp_now_value}'
					});
				})(document);");
			echo '
				<input type="hidden" id="_w2p_set_option" name="_w2p_set_option" value="' . $pp_now_value . '" />
				<div id="pp_main_btn_sec" class="ppc-main-btn-sec" > </div>';
		}

		public function pp_header_files() {
			global $post, $product;
			if (empty($post)) return;
			$pp_set_option = get_post_meta($post->ID, '_w2p_set_option', true);
			if (!empty($pp_set_option)) wp_enqueue_script('pitchprint_class', PP_CLIENT_JS);
		}

		public function ppa_write_panel_save( $post_id ) {
			update_post_meta($post_id, '_w2p_set_option', $_POST['ppa_values']);
			if ( ! add_post_meta( $post_id, '_w2p_display_option', $_POST['ppa_pick_display_mode'], true ) ) { 
			  update_post_meta( $post_id, '_w2p_display_option', $_POST['ppa_pick_display_mode']);
			}
			
			$reqCVal = get_post_meta($post_id, '_w2p_required_option', true);
			$reqNVal = $_POST['ppa_pick_required'];
			
			if($reqCVal !== NULL && $reqCVal !== 'checked' && strlen($reqNVal) == 0)
				$reqNVal = 'undefined';
			if ( ! add_post_meta( $post_id, '_w2p_required_option',$reqNVal, true ) ) { 
			  update_post_meta( $post_id, '_w2p_required_option', $reqNVal);
			}
			
			$downlCVal = get_post_meta($post_id, '_w2p_pdf_download_option', true);
			$downlNVal = $_POST['ppa_pick_pdf_download'];
			if($downlCVal !== NULL && $downlCVal !== 'checked' && strlen($downlNVal) == 0 )
				$downlNVal = 'undefined';
			if ( ! add_post_meta( $post_id, '_w2p_pdf_download_option', $downlNVal, true ) ) { 
			  update_post_meta( $post_id, '_w2p_pdf_download_option', $downlNVal);
			}
		}

		public function ppa_admin_page() {
			if (!class_exists('WooCommerce')) {
				echo ('<h3>This plugin depends on WooCommerce plugin. Kindly install <a target="_blank" href="https://wordpress.org/plugins/woocommerce/">WooCommerce here!</a></h3>');
				exit();
			}
			settings_errors();

			echo '<form method="post" action="options.php"><div class="wrap">';
				settings_fields('pitchprint');
				do_settings_sections('pitchprint');
				submit_button();
			echo '</div></form>';
		}

		public function ppa_actions() {
			add_menu_page('PitchPrint Settings', 'PitchPrint', 'manage_options', 'pitchprint', array($this, 'ppa_admin_page'), 'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiIHN0YW5kYWxvbmU9Im5vIj8+PHN2ZyAgIHhtbG5zOmRjPSJodHRwOi8vcHVybC5vcmcvZGMvZWxlbWVudHMvMS4xLyIgICB4bWxuczpjYz0iaHR0cDovL2NyZWF0aXZlY29tbW9ucy5vcmcvbnMjIiAgIHhtbG5zOnJkZj0iaHR0cDovL3d3dy53My5vcmcvMTk5OS8wMi8yMi1yZGYtc3ludGF4LW5zIyIgICB4bWxuczpzdmc9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiAgIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgICB2ZXJzaW9uPSIxLjEiICAgdmlld0JveD0iMCAwIDUuMjkxNjY2NSA1LjI5MTY2NiIgICBoZWlnaHQ9IjE5Ljk5OTk5OCIgICB3aWR0aD0iMjAiPiAgPHBhdGggICAgIGQ9Ik0gMS40MDQ0MDczLC0xLjMxMTMwMjJlLTcgMS4zNTU4MzEzLDAuNjk0NTQ4ODcgMi40MjUwNjMzLDEuNDQ5NTc3OSAyLjQ2MjE0MTMsMC45MTkzNTY4NyBIIDIuOTcyNDkgYyAwLjMwNjQwOTcsMCAwLjUyNzA0ODcsMC4wNjI3NyAwLjY2MTg2NDcsMC4xODgyMjUwMyAwLjEzNDc1NDcsMC4xMjU0OTUgMC4xOTI5NzcsMC4zMTk3MTUgMC4xNzQ1OTA2LDAuNTgyNjk3IC0wLjAxODIyNSwwLjI2MDYwNSAtMC4xMTc3NTk5LDAuNDU5MTE1IC0wLjI5ODU5NTUsMC41OTU0MjcgLTAuMTgwODk1NiwwLjEzNjM0MyAtMC40NDM4MzY3LDAuMjA0NDg1IC0wLjc4ODg2NzUsMC4yMDQ0ODUgSCAyLjM1MjMwNDEgbCAwLjA0Mjc0NCwtMC42MTE1NjUgLTEuMTc0NzU4NCwwLjc1NDQxNiAtMC4xODU5MjU3LDIuNjU4NjI0IGggMS4xMjIwMzU4IGwgMC4xMzE2MDEzLC0xLjg4MjExNyBoIDAuNDgxNDAxMiBjIDAuNjU2MzE1MiwwIDEuMTcyOTYxMywtMC4xNTA3OTkgMS41NTAwMTQ4LC0wLjQ1MjQ1MSBDIDQuNjk2NDAwOSwyLjY1NTQ5MzkgNC45MDQ4Mzk3LDIuMjE5OTkxOSA0Ljk0NDY1NDIsMS42NTA0OTI5IDQuOTgyOTYxOSwxLjEwMjc3MjkgNC44NDQ2MzE0LDAuNjkwNzU0ODcgNC41Mjk3NzI3LDAuNDE0NDI4ODcgNC4yMTQ4NTY2LDAuMTM4MTcxODcgMy43MzY1MjQ0LC0xLjMxMTMwMjJlLTcgMy4wOTQ2ODY1LC0xLjMxMTMwMjJlLTcgWiBNIDAuNDQzNDU0MzMsMC41OTUzODU4NyAwLjI5NTMwNTgzLDIuNzE0MzAyOSAxLjg4NjkxNzMsMS42NTUyOTY5IFoiICAgICBzdHlsZT0iZmlsbDpub25lIiAvPjwvc3ZnPg==');
		}
		public function ppa_settings_api_init() {
			add_settings_section('ppa_settings_section', 'PitchPrint Settings', array($this, 'ppa_create_settings'), 'pitchprint');
			add_settings_field('ppa_api_key', 'Api Key', array($this, 'ppa_api_key'), 'pitchprint', 'ppa_settings_section', array());
			add_settings_field('ppa_secret_key', 'Secret Key', array($this, 'ppa_secret_key'), 'pitchprint', 'ppa_settings_section', array());
			register_setting('pitchprint', 'ppa_api_key');
			register_setting('pitchprint', 'ppa_secret_key');
		}

		public function ppa_api_key() {
			echo '<input class="regular-text" id="ppa_api_key" name="ppa_api_key" type="text" value="' . get_option('ppa_api_key') . '" />';
		}
		public function ppa_secret_key() {
			echo '<input class="regular-text" id="ppa_secret_key" name="ppa_secret_key" type="text" value="' . get_option('ppa_secret_key') . '" />';
		}

		public function ppa_create_settings() {
			echo '<p>' . __("You can generate your api and secret keys from the <a target=\"_blank\" href=\"https://admin.pitchprint.io/domains\">PitchPrint domains page</a>", "PitchPrint") . '</p>';
		}

		function ppa_add_settings_link($links) {
			$settings_link = array('<a href="admin.php?page=pitchprint">' . __( 'Settings' ) . '</a>');
			return array_merge($settings_link, $links);
		}

		private function pp_fetch_credentials() {
			$timestamp = time();
			$api_key = get_option('ppa_api_key');
			$secret_key = get_option('ppa_secret_key');
			return array( 'signature' => md5($api_key . $secret_key . $timestamp), 'timestamp' => $timestamp);
		}

		public function ppa_order_details() {
			global $post, $woocommerce;
			$cred = $this->pp_fetch_credentials();
			wp_enqueue_script('pitchprint_admin', PP_ADMIN_JS);
			wc_enqueue_js( PPADMIN_DEF . "
				PPADMIN.vars = {
					credentials: { timestamp: '" . $cred['timestamp'] . "', apiKey: '" . get_option('ppa_api_key') . "', signature: '" . $cred['signature'] . "'}
				};
				PPADMIN.readyFncs.push('init');
				if (typeof PPADMIN.start !== 'undefined') PPADMIN.start();
			");
		}

		public function ppa_design_selection() {
			if (!class_exists('WooCommerce')) exit;
			global $post, $woocommerce;

			wp_enqueue_script('pitchprint_admin', PP_ADMIN_JS);

			echo '</div><div class="options_group show_if_simple show_if_variable"><input type="hidden" value="' . get_post_meta( $post->ID, '_w2p_set_option', true ) . '" id="ppa_values" name="ppa_values" >';
			
			$ppa_customization_required = get_post_meta($post->ID, '_w2p_required_option', true);
			$ppa_pdf_download = get_post_meta($post->ID, '_w2p_pdf_download_option', true);

			$ppa_upload_selected_option = '';
			$ppa_display_selected_option = get_post_meta($post->ID, '_w2p_display_option', true);
			//$ppa_hide_Cart_btn_option = '';
			$ppa_selected_option = get_post_meta( $post->ID, '_w2p_set_option', true );
			$ppa_selected_option = explode(':', $ppa_selected_option);
			if (count($ppa_selected_option) > 1) $ppa_upload_selected_option = ($ppa_selected_option[1] === '1' ? 'checked' : '');
			//if (count($ppa_selected_option) > 2) $ppa_hide_Cart_btn_option = ($ppa_selected_option[2] === '1' ? 'checked' : '');

			woocommerce_wp_select( array(
				'id'            => 'ppa_pick',
				'value'			=>	$ppa_selected_option[0],
				'wrapper_class' => '',
				'options'       => array('' => 'None'),
				'label'         => 'PitchPrint Design',
				'desc_tip'    	=> true,
				'description' 	=> __("Visit the PitchPrint Admin Panel to create and edit designs", 'PitchPrint')
			) );

			woocommerce_wp_checkbox( array(
				'id'            => 'ppa_pick_upload',
				'value'		    => $ppa_upload_selected_option,
				'label'         => '',
				'cbvalue'		=> 'checked',
				'description' 	=> '&#8678; ' . __("Check this to enable clients to upload their files", 'PitchPrint')
			) );

			woocommerce_wp_select( array(
				'id'            => 'ppa_pick_display_mode',
				'value'		    => $ppa_display_selected_option,
				'label'         => 'Display Mode',
				'options'       => array(''=>'Default', 'modal'=>'Full Window', 'inline'=>'Inline', 'mini'=>'Mini'),
				'cbvalue'		=> 'unchecked',
				'desc_tip'		=> true,
				'description' 	=>  __("Define the way that PitchPrint designer should open for this product on the front.")
			) );
			
			woocommerce_wp_checkbox( array(
				'id'            => 'ppa_pick_required',
				'value'		    => $ppa_customization_required,
				'label'         => '',
				'cbvalue'		=> 'checked',
				'description' 	=> '&#8678; ' . __("Check this to make customization compulsory for this product", 'PitchPrint')
			) );
			
			woocommerce_wp_checkbox( array(
				'id'            => 'ppa_pick_pdf_download',
				'value'		    => $ppa_pdf_download,
				'label'         => '',
				'cbvalue'		=> 'checked',
				'description' 	=> '&#8678; ' . __("Check this to allow PDF download for this product", 'PitchPrint')
			) );
			
			$cred = $this->pp_fetch_credentials();
			wc_enqueue_js( PPADMIN_DEF . "
				PPADMIN.vars = {
					credentials: { timestamp: '" . $cred['timestamp'] . "', apiKey: '" . get_option('ppa_api_key') . "', signature: '" . $cred['signature'] . "'},
					selectedOption: '{$ppa_selected_option[0]}'
				};
				PPADMIN.readyFncs.push('init', 'fetchDesigns');
				if (typeof PPADMIN.start !== 'undefined') PPADMIN.start();
			");
		}

		private function request_type( $type ) {
			switch ( $type ) {
				case 'admin' :
					return is_admin();
				case 'ajax' :
					return defined( 'DOING_AJAX' );
				case 'cron' :
					return defined( 'DOING_CRON' );
				case 'frontend' :
					return ( ! is_admin() || defined( 'DOING_AJAX' ) ) && ! defined( 'DOING_CRON' );
			}
		}

	}
	global $PitchPrint;
	$PitchPrint = new PitchPrint();

	function pp_install() {
		global $PitchPrint;
		$pp_version = get_option('pitchprint_version');
		
		if ($pp_version != $PitchPrint->version) {
			global $wpdb;
	
			$table_name 		= $wpdb->prefix . 'pitchprint_projects';
			$charset_collate	= $wpdb->get_charset_collate();
			
			$sql = "CREATE TABLE $table_name (
			  id varchar(55) NOT NULL ,
			  product_id mediumint(9) NOT NULL,
			  value TEXT  NOT NULL,
			  expires TIMESTAMP
			) $charset_collate;";
			
			$exec = dbDelta( $sql );
		}
		if ($pp_version) 
			update_option('pitchprint_version', $PitchPrint->version);
		else
			add_option('pitchprint_version', $PitchPrint->version);
	}
	
	register_activation_hook( __FILE__, 'pp_install');
	
	function register_session() {
		if(!isset($_COOKIE['pitchprint_sessId']))
			setcookie('pitchprint_sessId', uniqid('pp_w2p_', true), time()+60*60*24*30, '/');
	}
	
	add_action('init','register_session', 0);
	
	function pitchprint_doUpdate() {
		global $PitchPrint;
		if ( get_site_option('pitchprint_version') != $PitchPrint->version )	
			pp_install();
	}
	add_action('plugins_loaded', 'pitchprint_doUpdate');
?>
