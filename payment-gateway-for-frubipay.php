<?php
/**
 * Plugin Name: Payment Gateway for Frubipay
 * Description: Extend your WooCommerce store with Frubipay Payment Gateway
 * Author: OnePix
 * Author URI: https://onepix.net
 * Version: 1.0.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: frubipay_gateway
 */

add_filter('woocommerce_payment_gateways', 'add_frubipay_gateway_class');
function add_frubipay_gateway_class($gateways)
{
	$gateways[] = 'WC_Frubipay_Gateway'; // your class name is here
	return $gateways;
}

add_action('plugins_loaded', 'init_frubipay_gateway_class');
function init_frubipay_gateway_class() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return false;
	}

	class WC_Frubipay_Gateway extends WC_Payment_Gateway_CC {
		public function __construct() {
			$this->id                   = 'frubipay_gateway';
			$this->method_title         = esc_html__( 'Frubipay Gateway', 'frubipay_gateway' );
			$this->method_description   = esc_html__( 'Frubipay Payment Gateway', 'frubipay_gateway' );
			$this->supports             = array('products');

			$this->init_form_fields();
			$this->init_settings();

			$this->title        = $this->get_option('title');
			$this->description  = $this->get_option('description');
			$this->enabled      = $this->get_option('enabled');
			$this->order_prefix = $this->get_option('order_prefix');
			$this->api_key      = $this->get_option('api_key');
			$this->logging      = $this->get_option( 'logging' );
			$this->has_fields 	= true;

			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
			add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
		}

		public function init_form_fields() {

			$this->form_fields = array(
				'enabled' => array(
					'title'       => esc_html__('Enable/Disable', 'frubipay_gateway' ),
					'label'       => esc_html__('Enable Frubipay Gateway', 'frubipay_gateway' ),
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no'
				),

				'title' => array(
					'title'       => esc_html__('Title', 'frubipay_gateway' ),
					'type'        => 'text',
					'description' => esc_html__('This controls the title which the user sees during checkout.', 'frubipay_gateway' ),
					'default'     => esc_html__('Pay with Frubipay', 'frubipay_gateway' ),
					'desc_tip'    => true,
				),

				'description' => array(
					'title'       => esc_html__('Description', 'frubipay_gateway' ),
					'type'        => 'textarea',
					'description' => esc_html__('This controls the description which the user sees during checkout.', 'frubipay_gateway' ),
					'default'     => esc_html__('Pay with Frubipay payment gateway.', 'frubipay_gateway' ),
				),

				'order_prefix' => array(
					'title'       => esc_html__('Orders prefix', 'frubipay_gateway' ),
					'type'        => 'text',
					'default'     => 'wс-frubipay-',
				),

				'api_key' => array(
					'title'       => esc_html__('API Key', 'frubipay_gateway' ),
					'type'        => 'text',
				),

				'logging'      => array(
					'title'       => esc_html__( 'Enable logging', 'frubipay_gateway' ),
					'label'       => esc_html__( 'Enable/Disable', 'frubipay_gateway' ),
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no'
				),

			);
		}

		public function validate_fields(){

			if( empty( $_POST[ 'frubipay_gateway-card-number' ]) ) {
				wc_add_notice(  'Card number field is required!', 'error' );
				return false;
			}

			if( empty( $_POST[ 'frubipay_gateway-card-expiry' ]) ) {
				wc_add_notice(  'Expiry date field is required!', 'error' );
				return false;
			}

			if( empty( $_POST[ 'frubipay_gateway-card-cvc' ]) ) {
				wc_add_notice(  'Card code field is required!', 'error' );
				return false;
			}

			if( empty( $_POST[ 'frubipay_gateway-card-holder' ]) ) {
				wc_add_notice(  'Cardholder name field is required!', 'error' );
				return false;
			}
			return true;

		}

		public function form() {
			echo '<p>' . esc_html($this->description) . '</p>';
			require_once (plugin_dir_path(__FILE__) . 'template-parts/card-form.php');
		}

		public function payment_scripts() {

			if ( ! is_checkout() ) {
				return;
			}

			if ( ! is_ssl() ) {
				return;
			}

			if ( 'no' === $this->enabled ) {
				return;
			}

			wp_register_style('frubipay-frontend-styles', plugins_url('/assets/css/styles.css', __FILE__));
			wp_enqueue_style('frubipay-frontend-styles');
		}

		public function process_payment( $order_id ) {

			$order          = wc_get_order($order_id);
			$card           = sanitize_text_field($_POST['frubipay_gateway-card-number']);
			$expiry_date    = sanitize_text_field($_POST['frubipay_gateway-card-expiry']);
			$card_cvv       = sanitize_text_field($_POST['frubipay_gateway-card-cvc']);
			$card_holder    = sanitize_text_field($_POST['frubipay_gateway-card-holder']);
			$mobile_number  = $order->get_billing_phone();
			$email          = $order->get_billing_email();
			$order_total    = (float)$order->get_total();
			$city           = $order->get_billing_city();
			$state          = $order->get_billing_state();
			$country_code   = $order->get_billing_country();
			$country        = WC()->countries->countries[$country_code];
			$address        = $order->get_billing_address_1();

			if( $order->get_billing_address_2() ){
				$address    .= ' ' . $order->get_billing_address_2();
			}

			$url = 'https://api.frubipay.com/v1/payments';

			$args = array(
				"cardholder"    => $card_holder,
			    "number"        => str_replace(' ', '', $card),
			    "cvv"           => str_replace(' ', '', $card_cvv),
			    "expiryDate"    => str_replace(' ', '', $expiry_date),
			    "amount"        => $order_total,
			    "purchase"      => $this->order_prefix . $order_id,
			    "email"         => $email,
			    "phone"         => $mobile_number,
			    "address1"      => $address,
			    "city"          => $city,
			    "district"      => $state,
			    "country"       => $country,
			    "customerIP"    => WC_Geolocation::get_ip_address(),
			);

			$cart_items = array();
			foreach ( WC()->cart->get_cart() as $cart_item_id => $cart_item ) {
				$cart_item_data = array();

				if($cart_item['data']->get_title()){
					$cart_item_data['name'] = $cart_item['data']->get_title();
				}

				if($cart_item['data']->get_sku()){
					$cart_item_data['number'] = $cart_item['data']->get_sku();
				}

				if($cart_item['data']->get_short_description()){
					$cart_item_data['description'] = wp_strip_all_tags($cart_item['data']->get_short_description());
				}

				if($cart_item['data']->get_price()){
					$cart_item_data['price'] = (float)$cart_item['data']->get_price();
				}

				if($cart_item['quantity']){
					$cart_item_data['quantity'] = $cart_item['quantity'];
				}

				$cart_items[] = $cart_item_data;
			}
			$args['details'] = $cart_items;

			$this->lets_log(null, $args);

			$payment_request = wp_remote_post($url,  [
				'timeout'   => 20,
				'headers'   => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-type'  => 'application/json'
				),
				'body'              => json_encode($args),
			]);

			$this->lets_log($payment_request);

			$response_code  = $payment_request['response']['code'];
			$response       = json_decode($payment_request['body'], true);

			if( $response_code == 200 ){

				if( $response['success'] ){

					$order->add_order_note( esc_html__( 'Order paid via Frubipay.', 'frubipay_gateway' ), 1 );
					$order->payment_complete();
					WC()->cart->empty_cart();
					$thank_you_page = $order->get_checkout_order_received_url();
					return ['result' => 'success', 'redirect' => $thank_you_page];

				} else {

					$message    = 'Payment failed';
					wc_add_notice( $message , 'error');
					return ['result' => 'failure'];

				}

			} else {

				$message    = $response['message'];
				wc_add_notice( $message , 'error');
				return ['result' => 'failure'];

			}
		}

		private function lets_log( $response_data = null, $sent_data = null ) {
			if ( $this->logging == 'yes' ) {

				$logged_data = array();
				if( $response_data ) {
					if( isset($response_data['response']['code']) ){
						$logged_data['response_code'] = $response_data['response']['code'];
					}

					if( isset($response_data['body']) ){
						$logged_data['response_body'] = json_decode($response_data['body'], true);
					}
				}

				if( $sent_data ) {
					$logged_data['sent_data'] = $sent_data;
				}


				wc_get_logger()->debug( print_r( $logged_data, true ), [ 'source' => $this->id ] );
			}
		}
	}
}
