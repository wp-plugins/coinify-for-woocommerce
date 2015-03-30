<?php
/*
Plugin Name: WooCommerce Coinify
Plugin URI: https://coinify.com/developers/plugins
Description: Extends WooCommerce with an bitcoin gateway.
Version: 1.1
Author: Kris
Author URI: https://coinify.com
*/

add_filter('woocommerce_payment_gateways', 'woocommerce_add_coinify_gateway');
add_action('plugins_loaded', 'woocommerce_coinify_init', 0);

if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) 
{
	/**
	* Add the Gateway to WooCommerce
	**/
	function woocommerce_add_coinify_gateway($methods)
	{
		$methods[] = 'WC_coinify';
		return $methods;
	}

	function woocommerce_coinify_init()
	{
		if (!class_exists('WC_Payment_Gateway'))
		{
			return;
		}	

		class WC_coinify extends WC_Payment_Gateway
		{
			public function __construct()
			{
				$this->id = 'coinify';
				$this->icon = plugins_url( 'images/bitcoin.png', __FILE__ );
				$this->has_fields = false;

				$this->init_form_fields();
				$this->init_settings();

				$this->title = $this->settings['title'];
				$this->description = $this->settings['description'];
				$this->apikey = $this->settings['apikey'];
				$this->secret = $this->settings['secret'];
				$this->debug = $this->settings['debug'];

				$this->msg['message'] = "";
				$this->msg['class'] = "";

				add_action('init', array(&$this, 'check_coinify_response'));

				add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));

				add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'check_coinify_response' ) );

				// Valid for use.
				$this->enabled = (($this->settings['enabled'] && !empty($this->apikey) && !empty($this->secret)) ? 'yes' : 'no');

				// Checking if apikey is not empty.
				$this->apikey == '' ? add_action( 'admin_notices', array( &$this, 'apikey_missing_message' ) ) : '';

				// Checking if app_secret is not empty.
				$this->secret == '' ? add_action( 'admin_notices', array( &$this, 'secret_missing_message' ) ) : '';
			}

			function init_form_fields()
			{
				$this->form_fields = array(
					'enabled' => array(
						'title' => __( 'Enable/Disable', 'coinify' ),
						'type' => 'checkbox',
						'label' => __( 'Enable coinify', 'coinify' ),
						'default' => 'yes'
					),
					'title' => array(
						'title' => __( 'Title', 'coinify' ),
						'type' => 'text',
						'description' => __( 'This controls the title which the user sees during checkout.', 'coinify' ),
						'default' => __( 'coinify', 'coinify' )
					),
					'description' => array(
						'title' => __( 'Description', 'coinify' ),
						'type' => 'textarea',
						'description' => __( 'This controls the description which the user sees during checkout.', 'coinify' ),
						'default' => __( 'You will be redirected to coinify.com to complete your purchase.', 'coinify' )
					),
					'apikey' => array(
						'title' => __( 'Invoice API Key', 'coinify' ),
						'type' => 'password',
						'description' => __( 'Please enter your Coinify Invoice API key', 'coinify' ) . ' ' . sprintf( __( 'You can get this information in: %scoinify Account%s.', 'coinify' ), '<a href="https://coinify.com/merchant" target="_blank">', '</a>' ),
						'default' => ''
					),
					'secret' => array(
						'title' => __( 'IPN Secret', 'coinify' ),
						'type' => 'password',
						'description' => __( 'Please enter your coinify IPN secret', 'coinify' ) . ' ' . sprintf( __( 'You can get this information in: %scoinify Account%s.', 'coinify' ), '<a href="https://coinify.com/merchant" target="_blank">', '</a>' ),
						'default' => ''
					),
					'debug' => array(
						'title' => __( 'Debug Log', 'coinify' ),
						'type' => 'checkbox',
						'label' => __( 'Enable logging', 'coinify' ),
						'default' => 'no',
						'description' => __( 'Log coinify events, such as API requests, inside <code>woocommerce/logs/coinify.txt</code>', 'coinify'  ),
					)
				);
			}

			public function admin_options()
			{
				?>
				<h3><?php _e('Coinify Checkout', 'coinify');?></h3>

				<div id="wc_get_started">
					<span class="main"><?php _e('Provides a secure way to accept bitcoins.', 'coinify'); ?></span>
					<p><a href="https://coinify.com/signup" target="_blank" class="button button-primary"><?php _e('Join for free', 'coinify'); ?></a> <a href="https://coinify.com/developers/plugins" target="_blank" class="button"><?php _e('Learn more about WooCommerce and coinify', 'coinify'); ?></a></p>
				</div>

				<table class="form-table">
					<?php $this->generate_settings_html(); ?>
				</table>
				<?php
			}

			/**
			*  There are no payment fields for coinify, but we want to show the description if set.
			**/
			function payment_fields()
			{
				if ($this->description)
					echo wpautop(wptexturize($this->description));
			}


			/**
			* Process the payment and return the result
			**/
			function process_payment($order_id)
			{
				$order = new WC_Order($order_id);

				$item_names = array();

				if (sizeof($order->get_items()) > 0) : foreach ($order->get_items() as $item) :
					if ($item['qty']) $item_names[] = $item['name'] . ' x ' . $item['qty'];
				endforeach; endif;

				$item_name = sprintf( __('Order %s' , 'woocommerce'), $order->get_order_number() ) . " - " . implode(', ', $item_names);

				$ch = curl_init();
				curl_setopt_array($ch, array(
				CURLOPT_URL => 'https://coinify.com/api/v1/invoice',
				CURLOPT_USERPWD => $this->apikey,
				CURLOPT_POSTFIELDS => 'price=' . number_format($order->order_total, 2, '.', '') . '&currency=' . get_woocommerce_currency() . '&item=' . $item_name . '&custom=' . json_encode(array(
					'email' => $order->billing_email,
					'order_id' => $order->id,
					'order_key' => $order->order_key,
					'returnurl' => rawurlencode(esc_url($this->get_return_url($order))),
					'callbackurl' => get_site_url() . '/?wc-api=WC_coinify',
					'cancelurl' => rawurlencode(esc_url($order->get_cancel_order_url())))
				),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HTTPAUTH => CURLAUTH_BASIC));
				$redirect = curl_exec($ch);
				curl_close($ch);

				return array(
					'result' => 'success',
					'redirect' => $redirect
				);
			}

			/**
			* Check for valid coinify server callback
			**/
			function check_coinify_response()
			{
				$hash = hash('sha512', $_POST['transaction']['hash'] . $this->secret);

				if ($_POST['hash'] == $hash && $_POST['status'] == 1) {
					// Do your magic here, and return 200 OK to coinify.

					$order_id = intval($_POST['custom']['order_id']);
					$order_key = $_POST['custom']['order_key'];

					$order = new WC_Order($order_id);

					if ($order->order_key !== $order_key)
					{
						if ($this->debug=='yes') $this->log->add( 'coinify', 'Error: Order Key does not match invoice.' );
					}
					else
					{
						if ($order->status == 'completed')
						{
							if ($this->debug=='yes') $this->log->add( 'coinify', 'Aborting, Order #' . $order_id . ' is already complete.' );
						}
						else
						{
							// Validate Amount
							if ($_POST['fiat']['amount'] >= $order->get_total())
							{
								// Payment completed
								$order->add_order_note( __('IPN: Payment completed notification from coinify', 'woocommerce') );
								$order->payment_complete();

								if ($this->debug=='yes') $this->log->add( 'coinify', 'Payment complete.' );
							}
							else
							{
								if ($this->debug == 'yes')
								{
									$this->log->add( 'coinify', 'Payment error: Amounts do not match (gross ' . $_POST['fiat']['amount'] . ')' );
								}

								// Put this order on-hold for manual checking
								$order->update_status( 'on-hold', sprintf( __( 'IPN: Validation error, amounts do not match (gross %s).', 'woocommerce' ), $_POST['fiat']['amount'] ) );
							}
						}
					}

					header('HTTP/1.1 200 OK');
					exit;
				}
			}

			/**
			 * Adds error message when not configured the api key.
			 *
			 * @return string Error Mensage.
			 */
			public function apikey_missing_message() {
				$message = '<div class="error">';
					$message .= '<p>' . sprintf( __( '<strong>Gateway Disabled</strong> You should enter your Invoice API key in coinify configuration. %sClick here to configure!%s' , 'wccoinify' ), '<a href="' . get_admin_url() . 'admin.php?page=wc-settings&amp;tab=checkout&amp;section=wc_coinify">', '</a>' ) . '</p>';
				$message .= '</div>';

				echo $message;
			}

			/**
			 * Adds error message when not configured the secret.
			 *
			 * @return String Error Mensage.
			 */
			public function secret_missing_message() {
				$message = '<div class="error">';
					$message .= '<p>' . sprintf( __( '<strong>Gateway Disabled</strong> You should enter your IPN secret in coinify configuration. %sClick here to configure!%s' , 'wccoinify' ), '<a href="' . get_admin_url() . 'admin.php?page=wc-settings&amp;tab=checkout&amp;section=wc_coinify">', '</a>' ) . '</p>';
				$message .= '</div>';

				echo $message;
			}
		}
	}
}
