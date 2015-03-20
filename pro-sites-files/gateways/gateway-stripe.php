<?php

/*
Pro Sites (Gateway: Stripe Payment Gateway)
*/

class ProSites_Gateway_Stripe {

	private static $complete_message = false;
	private static $cancel_message = false;
	private static $stripe_plans = array();

	/**
	 * Get gateway slug/name
	 * @return string
	 */
	public static function get_slug() {
		return 'stripe';
	}

	function __construct() {
		global $psts;
		//setup the Stripe API
		if ( ! class_exists( 'Stripe' ) ) {
			require_once( $psts->plugin_dir . "gateways/gateway-stripe-files/lib/Stripe.php" );
		}
		$stripe_secret_key = $psts->get_setting( 'stripe_secret_key' );
		Stripe::setApiKey( $stripe_secret_key );
		// Stripe::setApiVersion( '2013-08-13' );
		Stripe::setApiVersion( '2015-02-16' ); //make sure everyone is using the same API version. we can update this if/when necessary.

		if ( ! is_admin() ) {
			add_action( 'wp_enqueue_scripts', array( 'ProSites_Gateway_Stripe', 'do_scripts' ) );
		}

		//settings
		add_action( 'psts_gateway_settings', array( &$this, 'settings' ) );
		add_action( 'psts_settings_process', array( 'ProSites_Gateway_Stripe', 'settings_process' ), 10, 1 );

		//checkout stuff
		add_filter( 'psts_force_ssl', array( 'ProSites_Gateway_Stripe', 'force_ssl' ) );

		//handle webhook notifications
		add_action( 'wp_ajax_nopriv_psts_stripe_webhook', array( 'ProSites_Gateway_Stripe', 'webhook_handler' ) );
		add_action( 'wp_ajax_psts_stripe_webhook', array( 'ProSites_Gateway_Stripe', 'webhook_handler' ) );

		//sync levels with Stripe
		add_action( 'update_site_option_psts_levels', array( 'ProSites_Gateway_Stripe', 'update_psts_levels' ), 10, 3 );

		//plug management page
		add_action( 'psts_subscription_info', array( 'ProSites_Gateway_Stripe', 'subscription_info' ) );
		add_action( 'psts_subscriber_info', array( 'ProSites_Gateway_Stripe', 'subscriber_info' ) );
		add_action( 'psts_modify_form', array( 'ProSites_Gateway_Stripe', 'modify_form' ) );
		add_action( 'psts_modify_process', array( 'ProSites_Gateway_Stripe', 'process_modify' ) );
		add_action( 'psts_transfer_pro', array( 'ProSites_Gateway_Stripe', 'process_transfer' ), 10, 2 );

		//filter payment info
		add_action( 'psts_payment_info', array( 'ProSites_Gateway_Stripe', 'payment_info' ), 10, 2 );

		//return next payment date for emails
		add_filter( 'psts_next_payment', array( 'ProSites_Gateway_Stripe', 'next_payment' ) );

		//cancel subscriptions on blog deletion
		add_action( 'delete_blog', array( 'ProSites_Gateway_Stripe', 'cancel_blog_subscription' ) );

		//display admin notices
		add_action( 'admin_notices', array( &$this, 'admin_notices' ) );

		//update install script if necessary
		if ( $psts->get_setting( 'stripe_version' ) != $psts->version ) {
			$this->install();
		}
	}

	public static function do_scripts() {
		global $psts;

		if ( ! is_page() || get_the_ID() != $psts->get_setting( 'checkout_page' ) ) {
			return;
		}

		$stripe_secret_key      = $psts->get_setting( 'stripe_secret_key' );
		$stripe_publishable_key = $psts->get_setting( 'stripe_publishable_key' );

		wp_enqueue_script( 'jquery' );
		wp_enqueue_script( 'js-stripe', 'https://js.stripe.com/v2/', array( 'jquery' ) );
		wp_enqueue_script( 'stripe-token', $psts->plugin_url . 'gateways/gateway-stripe-files/stripe_token.js', array(
			'js-stripe',
			'jquery'
		) );
		wp_localize_script( 'stripe-token', 'stripe', array(
			'publisher_key' => $stripe_publishable_key,
			'name'          => __( 'Please enter the full Cardholder Name.', 'psts' ),
			'number'        => __( 'Please enter a valid Credit Card Number.', 'psts' ),
			'expiration'    => __( 'Please choose a valid expiration date.', 'psts' ),
			'cvv2'          => __( 'Please enter a valid card security code. This is the 3 digits on the signature panel, or 4 digits on the front of Amex cards.', 'psts' )
		) );
		add_action( 'wp_head', array( 'ProSites_Gateway_Stripe', 'checkout_js' ) );
	}

	private static function install() {
		global $wpdb, $psts;

		$table1 = "CREATE TABLE IF NOT EXISTS `{$wpdb->base_prefix}pro_sites_stripe_customers` (
		  blog_id bigint(20) NOT NULL,
			customer_id char(20) NOT NULL,
			subscription_id char(22) NOT NULL,
			PRIMARY KEY  (blog_id),
			UNIQUE KEY ix_subscription_id (subscription_id)
		) DEFAULT CHARSET=utf8;";

		if ( ! defined( 'DO_NOT_UPGRADE_GLOBAL_TABLES' ) || ( defined( 'DO_NOT_UPGRADE_GLOBAL_TABLES' ) && ! DO_NOT_UPGRADE_GLOBAL_TABLES ) ) {
			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
			dbDelta( $table1 );

			//3.5 upgrade - modify pro_sites table
			if ( $psts->version <= '3.5' ) {
				//Using dbDelta instead
				// $wpdb->query( "ALTER TABLE {$wpdb->base_prefix}pro_sites_stripe_customers ADD subscription_id char(22) NOT NULL" );
				// $wpdb->query( "ALTER TABLE {$wpdb->base_prefix}pro_sites_stripe_customers DROP KEY ix_customer_id" );
				// $wpdb->query( "ALTER TABLE {$wpdb->base_prefix}pro_sites_stripe_customers ADD UNIQUE KEY ix_subscription_id (subscription_id)" );
			}
		}

		if ( $stripe_secret_key = $psts->get_setting( 'stripe_secret_key' ) ) {
			$psts->update_setting( 'stripe_version', $psts->version );

			if ( $psts->get_setting( 'stripe_plan_ids_updated', false ) ) {
				self::update_psts_levels( 'psts_levels', get_site_option( 'psts_levels' ), get_site_option( 'psts_levels' ) );
			} else {
				self::update_plan_ids_v2();
			}
		}
	}

	/**
	 * Display admin notices (if applicable)
	 * @todo Future: Move to a parent gateway class
	 */
	function admin_notices() {
		$blog_id = get_current_blog_id();
		if ( 1 == get_blog_option( $blog_id, 'psts_stripe_waiting' ) ) {
			echo '<div class="updated"><p><strong>' . __( 'There are pending changes to your account. This message will disappear once these pending changes are completed.', 'psts' ) . '</strong></p></div>';
		}
	}

	/**
	 * Update plan ids from old "level_period" convention to new "domain_level_period" convention
	 * @todo: This format may be redundant now, but keep it here for the time being.
	 */
	public static function update_plan_ids_v2() {
		global $psts;

		self::get_stripe_plans();
		$stripe_currency = $psts->get_setting( 'stripe_currency', 'USD' );
		$currency        = $psts->get_setting( 'currency', $stripe_currency );

		$levels  = (array) get_site_option( 'psts_levels' );
		$periods = array( 1, 3, 12 );

		foreach ( $levels as $level_id => $level ) {
			foreach ( $periods as $period ) {
				$plan_id = $level_id . '_' . $period;
				$plan    = self::get_plan_details( $plan_id );

				if ( self::plan_exists( $plan_id ) ) {
					try {
						self::delete_plan( $plan_id );
					} catch ( Exception $e ) {
					}
				}
				if ( is_object( $plan ) ) {
					self::add_plan( self::get_plan_id( $level_id, $period ), $plan->interval, $plan->interval_count, $plan->name, ( $plan->amount / 100 ) );
				}
			}
		}

		$psts->update_setting( 'stripe_plan_ids_updated', true );
	}

	/**
	 * Renders the Stripe settings for 'Pro Sites' > 'Payment Gateways' > 'Stripe'
	 * @todo: Make sure all Stripe countries are covered
	 */
	function settings() {
		global $psts;
		?>

		<div class="inside">
			<p class="description"><?php _e( "Accept Visa, MasterCard, American Express, Discover, JCB, and Diners Club cards directly on your site. You don't need a merchant account or gateway. Stripe handles everything, including storing cards, subscriptions, and direct payouts to your bank account. Credit cards go directly to Stripe's secure environment, and never hit your servers so you can avoid most PCI requirements.", 'psts' ); ?>
				<a href="https://stripe.com/" target="_blank"><?php _e( 'More Info &raquo;', 'psts' ) ?></a></p>

			<p><?php printf( __( 'To use Stripe you must <a href="https://manage.stripe.com/#account/webhooks" target="_blank">enter this webook url</a> (<strong>%s</strong>) in your account.', 'psts' ), network_site_url( 'wp-admin/admin-ajax.php?action=psts_stripe_webhook', 'admin' ) ); ?></p>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><?php _e( 'Stripe Mode', 'psts' ) ?></th>
					<td>
						<select name="psts[stripe_ssl]" class="chosen">
							<option value="1"<?php selected( $psts->get_setting( 'stripe_ssl' ), 1 ); ?>><?php _e( 'Force SSL (Live Site)', 'psts' ) ?></option>
							<option value="0"<?php selected( $psts->get_setting( 'stripe_ssl' ), 0 ); ?>><?php _e( 'No SSL (Testing)', 'psts' ) ?></option>
						</select><br/>
							<span class="description"><?php _e( 'When in live mode Stripe recommends you have an SSL certificate setup for your main blog/site where the checkout form will be displayed.', 'psts' ); ?>
								<a href="https://stripe.com/help/ssl" target="_blank"><?php _e( 'More Info &raquo;', 'psts' ) ?></a></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e( 'Stripe API Credentials', 'psts' ) ?></th>
					<td>
						<p><label><?php _e( 'Secret key', 'psts' ) ?><br/>
								<input value="<?php esc_attr_e( $psts->get_setting( "stripe_secret_key" ) ); ?>" style="width: 100%; max-width: 500px;" name="psts[stripe_secret_key]" type="text"/>
							</label></p>

						<p><label><?php _e( 'Publishable key', 'psts' ) ?><br/>
								<input value="<?php esc_attr_e( $psts->get_setting( "stripe_publishable_key" ) ); ?>" style="width: 100%; max-width: 500px;" name="psts[stripe_publishable_key]" type="text"/>
							</label></p><br/>
						<span class="description"><?php _e( 'You must login to Stripe to <a target="_blank" href="https://manage.stripe.com/#account/apikeys">get your API credentials</a>. You can enter your test credentials, then live ones when ready. When switching from test to live API credentials, if you were testing on a site that will be used in live mode, you need to manually clear the associated row from the *_pro_sites_stripe_customers table for the given blogid to prevent errors on checkout or management of the site.', 'psts' ) ?></span>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row" class="psts-help-div psts-stripe-currency"><?php echo __( 'Stripe Currency', 'psts' ) . $psts->help_text( __( 'The currency must match the currency of your Stripe account.', 'psts' ) ); ?></th>
					<td>
						<select name="psts[stripe_currency]" class="chosen">
							<?php
							$sel_currency = $psts->get_setting( "stripe_currency", 'USD' );
							$currencies   = array(
								"AUD" => 'AUD - Australian Dollar',
								"CAD" => 'CAD - Canadian Dollar',
								"EUR" => 'EUR - Euro',
								"GBP" => 'GBP - Pounds Sterling',
								"USD" => 'USD - U.S. Dollar',
							);

							foreach ( $currencies as $k => $v ) {
								echo '		<option value="' . $k . '"' . ( $k == $sel_currency ? ' selected' : '' ) . '>' . esc_html( $v, true ) . '</option>' . "\n";
							}
							?>
						</select>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row" class="psts-help-div psts-stripe-thankyou"><?php echo __( 'Thank You Message', 'psts' ) . $psts->help_text( __( 'Displayed on successful checkout. This is also a good place to paste any conversion tracking scripts like from Google Analytics. - HTML allowed', 'psts' ) ); ?></th>
					<td>
						<textarea name="psts[stripe_thankyou]" type="text" rows="4" wrap="soft" id="stripe_thankyou" style="width: 100%"/><?php echo esc_textarea( $psts->get_setting( 'stripe_thankyou' ) ); ?></textarea>
					</td>
				</tr>
			</table>
		</div>
	<?php
	}

	/**
	 * Perform actions when 'Pro Sites' > 'Settings' update.
	 *
	 * In this example, update Stripe plans with Pro Sites levels.
	 *
	 * @param $gateway_class
	 */
	public static function settings_process( $gateway_class ) {
		if ( get_class() == $gateway_class ) {
			self::update_psts_levels( 'psts_levels', get_site_option( 'psts_levels' ), get_site_option( 'psts_levels' ) );
		}

	}

	/**
	 * Filters the ssl on checkout page
	 * @return bool
	 */
	public static function force_ssl() {
		global $psts;

		return (bool) $psts->get_setting( 'stripe_ssl', false );
	}

	/**
	 * Renders the Year drop down on Stripe checkout
	 *
	 * @param string $sel Pass in a selected year
	 *
	 * @return string
	 */
	public static function year_dropdown( $sel = '' ) {
		$minYear = date( 'Y' );
		$maxYear = $minYear + 15;

		if ( empty( $sel ) ) {
			$sel = $minYear + 1;
		}

		$output = "<option value=''>--</option>";
		for ( $i = $minYear; $i < $maxYear; $i ++ ) {
			$output .= "<option value='" . substr( $i, 0, 4 ) . "'" . ( $sel == ( substr( $i, 0, 4 ) ) ? ' selected' : '' ) . ">" . $i . "</option>";
		}

		return $output;
	}

	/**
	 * Renders the month selection on Stripe checkout
	 *
	 * @param string $sel Pass in selected month
	 *
	 * @return string
	 */
	public static function month_dropdown( $sel = '' ) {
		if ( empty( $sel ) ) {
			$sel = date( 'n' );
		}
		$output = "<option value=''>--</option>";
		$output .= "<option" . ( $sel == 1 ? ' selected' : '' ) . " value='01'>01 - " . __( 'Jan', 'psts' ) . "</option>";
		$output .= "<option" . ( $sel == 2 ? ' selected' : '' ) . " value='02'>02 - " . __( 'Feb', 'psts' ) . "</option>";
		$output .= "<option" . ( $sel == 3 ? ' selected' : '' ) . " value='03'>03 - " . __( 'Mar', 'psts' ) . "</option>";
		$output .= "<option" . ( $sel == 4 ? ' selected' : '' ) . " value='04'>04 - " . __( 'Apr', 'psts' ) . "</option>";
		$output .= "<option" . ( $sel == 5 ? ' selected' : '' ) . " value='05'>05 - " . __( 'May', 'psts' ) . "</option>";
		$output .= "<option" . ( $sel == 6 ? ' selected' : '' ) . " value='06'>06 - " . __( 'Jun', 'psts' ) . "</option>";
		$output .= "<option" . ( $sel == 7 ? ' selected' : '' ) . " value='07'>07 - " . __( 'Jul', 'psts' ) . "</option>";
		$output .= "<option" . ( $sel == 8 ? ' selected' : '' ) . " value='08'>08 - " . __( 'Aug', 'psts' ) . "</option>";
		$output .= "<option" . ( $sel == 9 ? ' selected' : '' ) . " value='09'>09 - " . __( 'Sep', 'psts' ) . "</option>";
		$output .= "<option" . ( $sel == 10 ? ' selected' : '' ) . " value='10'>10 - " . __( 'Oct', 'psts' ) . "</option>";
		$output .= "<option" . ( $sel == 11 ? ' selected' : '' ) . " value='11'>11 - " . __( 'Nov', 'psts' ) . "</option>";
		$output .= "<option" . ( $sel == 12 ? ' selected' : '' ) . " value='12'>12 - " . __( 'Dec', 'psts' ) . "</option>";

		return $output;
	}

	/**
	 * Hooks into payment info meta for managing a Pro Site
	 *
	 * @todo Work out if still needed, then complete it.
	 *
	 * @param $payment_info
	 * @param $blog_id
	 *
	 * @return mixed
	 */
	public static function payment_info( $payment_info, $blog_id ) {
		global $psts;

		$customer_id  = self::get_customer_data( $blog_id )->customer_id;
		$next_billing = __( 'None', 'psts' );

		if ( $customer_id ) {
			/*if ($psts->get_setting('recurring_billing', true))
					$next_billing = date_i18n(get_blog_option($blog_id, 'date_format'), $psts->get_expire($blog_id));*/
			// !TODO - append extra details to $payment_info
		}

		return $payment_info;
	}

	/**
	 * Get the information for the Subscription metabox when managing Pro Site.
	 *
	 * @param $blog_id
	 *
	 * @return bool
	 */
	public static function subscription_info( $blog_id ) {
		global $psts;

		// Make sure Stripe was the last gateway or bail
		if ( ! ProSites_Helper_Gateway::is_last_gateway_used( $blog_id, self::get_slug() ) ) {
			return false;
		}

		$customer_id = self::get_customer_data( $blog_id )->customer_id;

		if ( $customer_id ) {
			echo '<ul>';

			if ( get_blog_option( $blog_id, 'psts_stripe_canceled' ) ) {
				$end_date = date_i18n( get_option( 'date_format' ), $psts->get_expire( $blog_id ) );
				echo '<li><strong>' . __( 'The Subscription Has Been Cancelled in Stripe', 'psts' ) . '</strong></li>';
				echo '<li>' . sprintf( __( 'They should continue to have access until %s.', 'psts' ), $end_date ) . '</li>';
			}

			echo '<li>' . sprintf( __( 'Stripe Customer ID: <strong><a href="https://manage.stripe.com/#test/customers/%s" target="_blank">%s</a></strong>', 'psts' ), $customer_id, $customer_id ) . '</li>';

			try {
				$existing_invoice_object = Stripe_Invoice::all( array( "customer" => $customer_id, "count" => 1 ) );
			} catch ( Exception $e ) {
				$error = $e->getMessage();
			}

			try {
				$customer_object = Stripe_Customer::retrieve( $customer_id );
			} catch ( Exception $e ) {
				$error = $e->getMessage();
			}

			if ( isset( $customer_object->active_card ) ) {
				$active_card = $customer_object->active_card->type;
				$last4       = $customer_object->active_card->last4;
				$exp_year    = $customer_object->active_card->exp_year;
				$exp_month   = $customer_object->active_card->exp_month;
				echo '<li>' . sprintf( __( 'Payment Method: <strong>%1$s Card</strong> ending in <strong>%2$s</strong>. Expires <strong>%3$s</strong>', 'psts' ), $active_card, $last4, $exp_month . '/' . $exp_year ) . '</li>';
			}

			$invoice_object = isset( $existing_invoice_object->data[0] ) ? $existing_invoice_object->data[0] : false;
			if ( $invoice_object ) {
				$prev_billing = date_i18n( get_option( 'date_format' ), $invoice_object->date );
				echo '<li>' . sprintf( __( 'Last Payment Date: <strong>%s</strong>', 'psts' ), $prev_billing ) . '</li>';
				$total = $invoice_object->total / 100;
				echo '<li>' . sprintf( __( 'Last Payment Amount: <strong>%s</strong>', 'psts' ), $psts->format_currency( $psts->get_setting( "stripe_currency", 'USD' ), $total ) ) . '</li>';
				echo '<li>' . sprintf( __( 'Last Payment Invoice ID: <strong>%s</strong>', 'psts' ), $invoice_object->id ) . '</li>';
			}

			if ( isset( $invoice_object->next_payment_attempt ) ) {
				$next_billing = date_i18n( get_option( 'date_format' ), $invoice_object->next_payment_attempt );
				echo '<li>' . sprintf( __( 'Next Payment Date: <strong>%s</strong>', 'psts' ), $next_billing ) . '</li>';
			}

			echo '</ul>';
			echo '<small>* (' . __( 'This does not include the initial payment at signup, or payments before the last payment method/plan change.', 'psts' ) . ')</small>';
			self::next_payment( $blog_id );
		} else {
			echo '<p>' . __( "This site is using different gateway so their information is not accessible.", 'psts' ) . '</p>';
		}
	}

	/**
	 * Renders the subscriber info metabox when managing a Pro Site
	 *
	 * @param $blog_id
	 *
	 * @return bool
	 */
	public static function subscriber_info( $blog_id ) {
		global $psts;

		if ( ! ProSites_Helper_Gateway::is_last_gateway_used( $blog_id, self::get_slug() ) ) {
			return false;
		}

		$customer_id = self::get_customer_data( $blog_id )->customer_id;

		if ( $customer_id ) {
			try {
				$custom_information = Stripe_Customer::retrieve( $customer_id );

				echo '<p><strong>' . stripslashes( $custom_information->description ) . '</strong><br />';

				if ( isset( $custom_information->default_source ) ) { //credit card
					$sources = $custom_information->sources->data;
					foreach ( $sources as $source ) {
						if ( $source->id == $custom_information->default_source ) {
							echo __( 'Type: ', 'psts' ) . stripslashes( ucfirst( $source['object'] ) ) . '<br />';
							echo __( 'Brand: ', 'psts' ) . stripslashes( $source['brand'] ) . '<br />';
							echo __( 'Country: ', 'psts' ) . stripslashes( $source['country'] ) . '</p>';
						}
					}

					echo '<p>' . stripslashes( $custom_information->email ) . '</p>';
				}
			} catch ( Exception $e ) {
				echo '<p>' . __( "Stripe returned an error retrieving the customer:", 'psts' ) . ' ' . stripslashes( $e->getMessage() ) . '</p>';
			}
		} else {
			echo '<p>' . __( "This site is using a different gateway so their information is not accessible.", 'psts' ) . '</p>';
		}
	}

	/**
	 * Timestamp of next payment if subscription active, else return false
	 *
	 * Catch, Stripe only does the next customer invoice and may not include
	 * this subscription.
	 *
	 * @param $blog_id
	 *
	 * @return bool|null
	 */
	public static function next_payment( $blog_id ) {
		global $psts;

		$next_billing    = false;
		$customer_data   = self::get_customer_data( $blog_id );
		$customer_id     = $customer_data->customer_id;
		$subscription_id = $customer_data->subscription_id;
		if ( $customer_id ) {

			if ( get_blog_option( $blog_id, 'psts_stripe_canceled' ) ) {
				return false;
			}

			try {
				$invoice_object = Stripe_Invoice::upcoming( array( "customer" => $customer_id ) );
				$subscription   = false;
				foreach ( $invoice_object->lines->data as $line_item ) {
					if ( 'subscription' == $line_item->type ) {
						$subscription = $line_item;
						break;
					}
				}
			} catch ( Exception $e ) {
				$error = $e->getMessage();
			}

			// Keep it to this blog and this subscription
			if ( ! empty( $subscription ) && $subscription->id == $subscription_id ) {
				$next_amount = $invoice_object->total / 100;

				if ( isset( $invoice_object->next_payment_attempt ) ) {
					$next_billing = $invoice_object->next_payment_attempt;
				}
			}

		}

		return $next_billing;
	}

	/**
	 * Renders the Modify Pro Site Status content for this gateway
	 *
	 * @param $blog_id
	 *
	 * @return bool
	 */
	public static function modify_form( $blog_id ) {
		global $psts, $wpdb;

		if ( ! ProSites_Helper_Gateway::is_last_gateway_used( $blog_id, self::get_slug() ) ) {
			return false;
		}

		$active_member   = false;
		$canceled_member = false;

		$end_date    = date_i18n( get_option( 'date_format' ), $psts->get_expire( $blog_id ) );
		$customer_id = self::get_customer_data( $blog_id )->customer_id;

		try {
			$existing_invoice_object = Stripe_Invoice::all( array( "customer" => $customer_id, "count" => 1 ) );
			$last_payment            = isset( $existing_invoice_object->data[0]->total ) ? ( $existing_invoice_object->data[0]->total / 100 ) : '';

			$cancel_status = get_blog_option( $blog_id, 'psts_stripe_canceled' );

			if ( $last_payment != '' ) {

				if ( $cancel_status == 0 && $cancel_status != '' ) {
					?>
					<h4><?php _e( 'Cancelations:', 'psts' ); ?></h4>
					<label><input type="radio" name="stripe_mod_action" value="cancel"/> <?php _e( 'Cancel Subscription Only', 'psts' ); ?>
						<small>(<?php printf( __( 'Their access will expire on %s', 'psts' ), $end_date ); ?>)</small>
					</label><br/>

					<label><input type="radio" name="stripe_mod_action" value="cancel_refund"/> <?php printf( __( 'Cancel Subscription and Refund Full (%s) Last Payment', 'psts' ), $psts->format_currency( false, $last_payment ) ); ?>
						<small>(<?php printf( __( 'Their access will expire on %s', 'psts' ), $end_date ); ?>)</small>
					</label><br/>
				<?php
				}
				?>

				<h4><?php _e( 'Refunds:', 'psts' ); ?></h4>
				<label><input type="radio" name="stripe_mod_action" value="refund"/> <?php printf( __( 'Refund Full (%s) Last Payment', 'psts' ), $psts->format_currency( false, $last_payment ) ); ?>
					<small>(<?php _e( 'Their subscription and access will continue', 'psts' ); ?>)</small>
				</label><br/>
				<label><input type="radio" name="stripe_mod_action" value="partial_refund"/> <?php printf( __( 'Refund a Partial %s Amount of Last Payment', 'psts' ), $psts->format_currency() . '<input type="text" name="refund_amount" size="4" value="' . $last_payment . '" />' ); ?>
					<small>(<?php _e( 'Their subscription and access will continue', 'psts' ); ?>)</small>
				</label><br/>
			<?php
			}

		} catch ( Exception $e ) {
			echo $e->getMessage();
		}
	}

	/**
	 * Process the changes from the Modify Pro Site Status metabox.
	 *
	 * @param $blog_id
	 */
	public static function process_modify( $blog_id ) {
		global $psts, $current_user;
		$success_msg = $error_msg = '';

		if ( isset( $_POST['stripe_mod_action'] ) ) {

			$customer_id             = self::get_customer_data( $blog_id )->customer_id;
			$exitsing_invoice_object = Stripe_Invoice::all( array( "customer" => $customer_id, "count" => 1 ) );
			$last_payment            = $exitsing_invoice_object->data[0]->total / 100;
			$refund_value            = $_POST['refund_amount'];
			$refund_amount           = $refund_value * 100;
			$refund_amount           = (int) $refund_amount;
			$refund                  = $last_payment;

			switch ( $_POST['stripe_mod_action'] ) {
				case 'cancel':
					$end_date = date_i18n( get_option( 'date_format' ), $psts->get_expire( $blog_id ) );

					try {
						$customer_data = self::get_customer_data( $blog_id );
						$customer_id   = $customer_data->customer_id;
						$sub_id        = $customer_data->subscription_id;
						$cu            = Stripe_Customer::retrieve( $customer_id );
						// Don't use ::cancelSubscription because it doesn't know which subscription if we have multiple
						$cu->subscriptions->retrieve( $sub_id )->cancel();

						//record stat
						$psts->record_stat( $blog_id, 'cancel' );
						$psts->log_action( $blog_id, sprintf( __( 'Subscription successfully cancelled by %1$s. They should continue to have access until %2$s', 'psts' ), $current_user->display_name, $end_date ) );
						$success_msg = sprintf( __( 'Subscription successfully cancelled. They should continue to have access until %s.', 'psts' ), $end_date );
						update_blog_option( $blog_id, 'psts_stripe_canceled', 1 );
					} catch ( Exception $e ) {
						$error_msg = $e->getMessage();
						$psts->log_action( $blog_id, sprintf( __( 'Attempt to Cancel Subscription by %1$s failed with an error: %2$s', 'psts' ), $current_user->display_name, $error_msg ) );
					}
					break;

				case 'cancel_refund':
					$end_date = date_i18n( get_option( 'date_format' ), $psts->get_expire( $blog_id ) );

					$cancellation_success = false;
					try {
						$customer_data = self::get_customer_data( $blog_id );
						$customer_id   = $customer_data->customer_id;
						$sub_id        = $customer_data->subscription_id;
						$cu            = Stripe_Customer::retrieve( $customer_id );
						// Don't use ::cancelSubscription because it doesn't know which subscription if we have multiple
						$cu->subscriptions->retrieve( $sub_id )->cancel();

						$cancellation_success = true;
						//record stat
					} catch ( Exception $e ) {
						$error_msg = $e->getMessage();
					}

					if ( $cancellation_success == false ) {
						$psts->log_action( $blog_id, sprintf( __( 'Attempt to Cancel Subscription and Refund Prorated (%1$s) Last Payment by %2$s failed with an error: %3$s', 'psts' ), $psts->format_currency( false, $refund ), $current_user->display_name, $error_msg ) );
						$error_msg = sprintf( __( 'Whoops, Stripe returned an error when attempting to cancel the subscription. Nothing was completed: %s', 'psts' ), $error_msg );
						break;
					}

					$refund_success = false;
					if ( $cancellation_success == true ) {
						try {
							$charge_object = Stripe_Charge::all( array( "count" => 1, "customer" => $customer_id ) );
							$charge_id     = $charge_object->data[0]->id;
							$ch            = Stripe_Charge::retrieve( $charge_id );
							$ch->refund();
							$refund_success = true;
						} catch ( Exception $e ) {
							$error_msg = $e->getMessage();
						}
					}

					if ( $refund_success == true ) {
						$psts->log_action( $blog_id, sprintf( __( 'Subscription cancelled and a prorated (%1$s) refund of last payment completed by %2$s', 'psts' ), $psts->format_currency( false, $refund ), $current_user->display_name ) );
						$success_msg = sprintf( __( 'Subscription cancelled and a prorated (%s) refund of last payment were successfully completed.', 'psts' ), $psts->format_currency( false, $refund ) );
						update_blog_option( $blog_id, 'psts_stripe_canceled', 1 );
					} else {
						$psts->log_action( $blog_id, sprintf( __( 'Subscription cancelled, but prorated (%1$s) refund of last payment by %2$s returned an error: %3$s', 'psts' ), $psts->format_currency( false, $refund ), $current_user->display_name, $error_msg ) );
						$error_msg = sprintf( __( 'Subscription cancelled, but prorated (%1$s) refund of last payment returned an error: %2$s', 'psts' ), $psts->format_currency( false, $refund ), $error_msg );
						update_blog_option( $blog_id, 'psts_stripe_canceled', 1 );
					}
					break;

				case 'refund':
					try {
						$charge_object = Stripe_Charge::all( array( "count" => 1, "customer" => $customer_id ) );
						$charge_id     = $charge_object->data[0]->id;
						$ch            = Stripe_Charge::retrieve( $charge_id );
						$ch->refund();
						$psts->log_action( $blog_id, sprintf( __( 'A full (%1$s) refund of last payment completed by %2$s The subscription was not cancelled.', 'psts' ), $psts->format_currency( false, $refund ), $current_user->display_name ) );
						$success_msg = sprintf( __( 'A full (%s) refund of last payment was successfully completed. The subscription was not cancelled.', 'psts' ), $psts->format_currency( false, $refund ) );
						$psts->record_refund_transaction( $blog_id, $charge_id, $refund );
					} catch ( Exception $e ) {
						$error_msg = $e->getMessage();
						$psts->log_action( $blog_id, sprintf( __( 'Attempt to issue a full (%1$s) refund of last payment by %2$s returned an error: %3$s', 'psts' ), $psts->format_currency( false, $refund ), $current_user->display_name, $error_msg ) );
						$error_msg = sprintf( __( 'Attempt to issue a full (%1$s) refund of last payment returned an error: %2$s', 'psts' ), $psts->format_currency( false, $refund ), $error_msg );
					}
					break;

				case 'partial_refund':
					try {
						$charge_object = Stripe_Charge::all( array( "count" => 1, "customer" => $customer_id ) );
						$charge_id     = $charge_object->data[0]->id;
						$ch            = Stripe_Charge::retrieve( $charge_id );
						$ch->refund( array( "amount" => $refund_amount ) );
						$psts->log_action( $blog_id, sprintf( __( 'A partial (%1$s) refund of last payment completed by %2$s The subscription was not cancelled.', 'psts' ), $psts->format_currency( false, $refund_value ), $current_user->display_name ) );
						$success_msg = sprintf( __( 'A partial (%s) refund of last payment was successfully completed. The subscription was not cancelled.', 'psts' ), $psts->format_currency( false, $refund_value ) );
						$psts->record_refund_transaction( $blog_id, $charge_id, $refund );
					} catch ( Exception $e ) {
						$error_msg = $e->getMessage();
						$psts->log_action( $blog_id, sprintf( __( 'Attempt to issue a partial (%1$s) refund of last payment by %2$s returned an error: %3$s', 'psts' ), $psts->format_currency( false, $refund_value ), $current_user->display_name, $error_msg ) );
						$error_msg = sprintf( __( 'Attempt to issue a partial (%1$s) refund of last payment returned an error: %2$s', 'psts' ), $psts->format_currency( false, $refund_value ), $error_msg );
					}
					break;

			}

		}

		//display resulting message
		if ( $success_msg ) {
			echo '<div class="updated fade"><p>' . $success_msg . '</p></div>';
		} else if ( $error_msg ) {
			echo '<div class="error fade"><p>' . $error_msg . '</p></div>';
		}
	}

	/**
	 * Handle transferring pro status from one blog to another
	 *
	 * Also updates the subscription metadata in Stripe.
	 *
	 * @param $from_id
	 * @param $to_id
	 */
	public static function process_transfer( $from_id, $to_id ) {
		global $wpdb;
		$customer_data = self::get_customer_data( $from_id );
		self::set_subscription_blog_id( $customer_data->subscription_id, $customer_data->customer_id, false, $to_id );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->base_prefix}pro_sites_stripe_customers SET blog_id = %d WHERE blog_id = %d", $to_id, $from_id ) );
	}

	/**
	 * Get a list of all stripe plans
	 *
	 * Note: This uses Stripe API paging. This has changed in from 2013-08-13
	 * to 2015-02-16 in the Stripe API.
	 *
	 * @param int $count Number of plans to retrieve at a time. MAX: 100
	 * @param bool $offset // DEPRECATED in new Stripe API
	 * @param int $listed Number of plans already retrieved
	 * @param string $last_object Plan ID of the last plan retrieved.
	 *
	 * @return array|void
	 */
	public static function get_stripe_plans( $count = 100, $offset = false, $listed = 0, $last_object = '' ) {

		if ( wp_cache_get( 'stripe_plans_cached', 'psts' ) ) {
			return self::$stripe_plans;
		}

		try {
			$args = array(
				'count'     => $count,
				'include[]' => 'total_count'
			);
			if ( ! empty( $last_object ) ) {
				$args['starting_after'] = $last_object;
			}
			$plans = Stripe_Plan::all( $args );
		} catch ( Exception $e ) {
			return;
		}

		$data        = $plans->data;
		$total_count = $plans->total_count;

		if ( count( $data ) > 0 ) {
			// New API
			$last_id = $data[ count( $data ) - 1 ]->id;
			$listed += count( $data );

			self::$stripe_plans = array_merge( $data, self::$stripe_plans );

			if ( $listed < $total_count ) {

				self::get_stripe_plans( $count, false, $listed, $last_id );

				return;
			}
		}

		wp_cache_set( 'stripe_plans_cached', true, 'psts' );
	}

	//check if plan exists on stripe
	public static function plan_exists( $plan_id ) {
		self::get_stripe_plans();

		foreach ( self::$stripe_plans as $plan ) {
			if ( $plan_id == $plan->id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get a plan's details from Stripe
	 *
	 * @param $plan_id
	 *
	 * @return bool
	 */
	public static function get_plan_details( $plan_id ) {
		self::get_stripe_plans();

		foreach ( self::$stripe_plans as $plan ) {
			if ( $plan_id == $plan->id ) {
				return $plan;
			}
		}

		return false;
	}

	/**
	 * Gets a unique identifier for plans
	 * @return mixed
	 */
	public static function get_plan_uid() {
		return str_replace( array( 'http://', 'https://', '/', '.' ), array( '', '', '', '_' ), network_home_url() );
	}

	/**
	 * Get a plan id based upon a given level and period
	 *
	 * @todo: Make sure we dont add the level and period twice.
	 *
	 * @param $level
	 * @param $period
	 *
	 * @return string
	 */
	public static function get_plan_id( $level, $period ) {
		return self::get_plan_uid() . '_' . $level . '_' . $period;
	}

	/**
	 * Update Stripe plans when Pro Site levels are changed.
	 *
	 * @param string $option
	 * @param bool $new_levels
	 * @param bool $old_levels
	 */
	public static function update_psts_levels( $option = '', $new_levels = false, $old_levels = false ) {
		global $psts;

		$stripe_currency = $psts->get_setting( 'stripe_currency', 'USD' );
		$currency        = $psts->get_setting( 'currency', $stripe_currency );

		if ( ! $new_levels ) {
			$new_levels = (array) get_site_option( 'psts_levels' );
		}
		if ( ! $old_levels ) {
			$old_levels = $new_levels;
		}
		//deleting
		if ( count( $old_levels ) > count( $new_levels ) ) {

			$level_id = 0;
			foreach ( $old_levels as $key => $value ) {
				$new_keys = array_keys( $new_levels );
				if ( ! in_array( $key, $new_keys ) ) {
					$level_id = $key;
				}
			}

			// Should not happen, but check anyway.
			if ( empty( $level_id ) ) {
				return;
			}

			$periods = array( 1, 3, 12 );

			foreach ( $periods as $period ) {
				$stripe_plan_id = self::get_plan_id( $level_id, $period );
				self::delete_plan( $stripe_plan_id );
			}

			return; // no further processing required
		}

		//update levels
		$periods = (array) $psts->get_setting( 'enabled_periods' );
		foreach ( $new_levels as $level_id => $level ) {
			$level_name = $level['name'];
			$plans      = array(
				1  => array(
					'int'       => 'month',
					'int_count' => 1,
					'desc'      => 'Monthly',
					'price'     => $level['price_1'],
				),
				3  => array(
					'int'       => 'month',
					'int_count' => 3,
					'desc'      => 'Quarterly',
					'price'     => $level['price_3'],
				),
				12 => array(
					'int'       => 'year',
					'int_count' => 1,
					'desc'      => 'Yearly',
					'price'     => $level['price_12'],
				),
			);

			foreach ( $plans as $period => $plan ) {
				$stripe_plan_id = self::get_plan_id( $level_id, $period );
				$plan_name      = $level_name . ': ' . $plan['desc'];

				if ( self::plan_exists( $stripe_plan_id ) ) {
					$plan_existing = self::get_plan_details( $stripe_plan_id );

					// Nothing needs to happen
					if ( $plan_existing->amount == ( $plan['price'] * 100 ) && $plan_existing->name == $plan_name && strtolower( $plan_existing->currency ) == strtolower( $currency ) ) {
						continue;
					}

					// Only the name needs changing, easy.
					if ( $plan_existing->amount == ( $plan['price'] * 100 ) && strtolower( $plan_existing->currency ) == strtolower( $currency ) ) {
						self::update_plan( $stripe_plan_id, $plan_name );
						continue;
					}

					// Price or currency has changed... we need a new plan.
					// Note: Existing Stripe subscriptions will remain until it is cancelled, regardless of plan change.
					self::delete_plan( $stripe_plan_id );
				}

				self::add_plan( $stripe_plan_id, $plan['int'], $plan['int_count'], $plan_name, $plan['price'] );
			}
		}
	}

	/**
	 * Retrieve a plan from Stripe
	 *
	 * @param $plan_id
	 *
	 * @return mixed
	 */
	public static function retrieve_plan( $plan_id ) {
		self::get_stripe_plans();

		foreach ( self::$stripe_plans as $plan ) {
			if ( $plan['id'] == $plan_id ) {
				return $plan;
			}
		}
	}

	/**
	 * Update a plan (only name can be updated)
	 *
	 * @todo Check if redundant.
	 *
	 * @param $plan_id
	 * @param $plan_name
	 */
	public static function update_plan( $plan_id, $plan_name ) {
		try {
			$plan = self::retrieve_plan( $plan_id );
			if ( ! empty( $plan ) ) {
				$plan->name = $plan_name;
				$plan->save();
			}
		} catch ( Exception $e ) {
		}
	}

	/**
	 * Delete a plan from Stripe
	 *
	 * @param $stripe_plan_id
	 * @param bool $retry
	 */
	public static function delete_plan( $stripe_plan_id, $retry = true ) {
		try {
			$plan = self::retrieve_plan( $stripe_plan_id );
			if ( ! empty( $plan ) ) {
				$plan->delete();
			}
		} catch ( Exception $e ) {
			//oh well
		}
	}

	/**
	 * Add a new plan to Stripe via API
	 *
	 * @param $stripe_plan_id string Calculated from level and period
	 * @param $int
	 * @param $int_count
	 * @param $name
	 * @param $level_price
	 */
	public static function add_plan( $stripe_plan_id, $int, $int_count, $name, $level_price ) {
		global $psts;
		try {
			$stripe_currency = $psts->get_setting( 'stripe_currency', 'USD' );
			$currency        = $psts->get_setting( 'currency', $stripe_currency );

			Stripe_Plan::create( array(
				"amount"         => round( $level_price * 100 ),
				"interval"       => $int,
				"interval_count" => $int_count,
				"name"           => "$name",
				"currency"       => $currency,
				"id"             => "$stripe_plan_id"
			) );
		} catch ( Exception $e ) {
		}
	}

	/**
	 * JS to be printed only on checkout page
	 */
	public static function checkout_js() {
		?>
		<script type="text/javascript"> jQuery(document).ready(function () {
				jQuery("a#stripe_cancel").click(function () {
					if (confirm("<?php echo __('Please note that if you cancel your subscription you will not be immune to future price increases. The price of un-canceled subscriptions will never go up!\n\nAre you sure you really want to cancel your subscription?\nThis action cannot be undone!', 'psts'); ?>")) return true; else return false;
				});
			});</script><?php
	}

	/**
	 * Store the latest customer id in the table
	 *
	 * @param $blog_id
	 * @param $customer_id
	 * @param string $domain
	 */
	public static function set_customer_data( $blog_id, $customer_id, $sub_id, $domain = 'deprecated' ) {
		global $wpdb, $psts;

		$exists = false;
		if ( ! empty( $blog_id ) ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->base_prefix}pro_sites_stripe_customers WHERE blog_id = %d", $blog_id ) );
		}

		if ( $exists ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->base_prefix}pro_sites_stripe_customers SET customer_id = %s, subscription_id = %s WHERE blog_id = %d", $customer_id, $sub_id, $blog_id ) );
		} else {
			//If we have blog id update stripe customer id for blog id otherwise store in signup meta
			if ( ! empty( $blog_id ) ) {
				$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->base_prefix}pro_sites_stripe_customers(blog_id, customer_id, subscription_id) VALUES (%d, %s, %s)", $blog_id, $customer_id, $sub_id ) );
			} else {
				/**
				 * @todo: work something else out
				 */
//				$signup_meta                          = '';
//				$signup_meta                          = $psts->get_signup_meta( $domain );
//				$signup_meta['stripe']['customer_id'] = $customer_id;
//				$psts->update_signup_meta( $signup_meta, $domain );
			}
		}
	}

	/**
	 * Get stripe customer id, one of the two arguments is required
	 *
	 * @param $blog_id
	 * @param bool|string $domain DEPRECATED
	 * @param bool|string $email
	 *
	 * @return bool
	 */
	public static function get_customer_data( $blog_id, $domain = false, $email = false ) {
		global $wpdb, $psts;

		// We might have to return an empty object...
		if ( empty( $blog_id ) && empty( $domain ) ) {

			// Try to get existing Stripe user by email
			if ( ! empty( $email ) ) {
				$data = false;
				$user = get_user_by( 'email', $email );
				if ( $user ) {
					$blogs_of_user = get_blogs_of_user( $user->ID );
					foreach ( $blogs_of_user as $blog_of_user ) {
						$data = self::get_customer_data( $blog_of_user->userblog_id );
						if ( ! empty( $data ) ) {
							break;
						}
					}
				}
				if ( $data ) {
					$data->subscription_id = false;

					return $data;
				}
			}

			// Create a fake object so that it doesn't fail when properties are called.
			$customer                  = new stdClass();
			$customer->customer_id     = false;
			$customer->subscription_id = false;

			return $customer;
		}

		if ( ! empty ( $blog_id ) ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT customer_id, subscription_id FROM {$wpdb->base_prefix}pro_sites_stripe_customers WHERE blog_id = %d", $blog_id ) );
			if ( empty( $row ) ) {
				$row                  = new stdClass();
				$row->customer_id     = false;
				$row->subscription_id = false;
			}

			return $row;
		} else {
			/**
			 * @todo work something else out
			 */
			//Get customer id from signup meta
//			$signup_meta = $psts->get_signup_meta( $domain );
//			if ( ! empty ( $signup_meta['stripe'] ) ) {
//				return ! empty ( $signup_meta['stripe']['customerid'] ) ? $signup_meta['stripe']['customerid'] : '';
//			}
		}
	}

	public static function get_blog_id( $customer_id ) {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( "SELECT blog_id FROM {$wpdb->base_prefix}pro_sites_stripe_customers WHERE customer_id = %s", $customer_id ) );
	}

	public static function get_current_plan( $blog_id ) {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( "SELECT CONCAT_WS('_', level, term) FROM {$wpdb->base_prefix}pro_sites WHERE blog_ID = %d", $blog_id ) );
	}

	/**
	 * Get default Stripe card. (Update to sources)
	 *
	 * @todo Update for newer Stripe API as card objects have been replaced by source objects
	 *
	 * @param $customer_object
	 *
	 * @return bool
	 */
	public static function get_default_card( $customer_object ) {
		if ( ! isset( $customer_object->cards ) ) {
			return false;
		}

		foreach ( $customer_object->cards->data as $card ) {
			if ( $card->id == $customer_object->default_card ) {
				return $card;
			}
		}

		return false;
	}

	/**
	 * Checks if a pro site should be extended and, if so, extends it
	 *
	 * @param $blog_id
	 * @param $period The plan's new period
	 * @param $gateway The gateway
	 * @param $level The plan's new level
	 * @param $amount The plan's new amount
	 * @param bool $expire The new plan's expiration date
	 * @param bool $is_payment Whether or not this is an invoice payment
	 * @param bool $is_recurring
	 *
	 * @return bool
	 */
	public static function maybe_extend( $blog_id, $period, $gateway, $level, $amount, $expire = false, $is_payment = false, $is_recurring = true, $args = array() ) {
		global $psts;

		$current_plan = self::get_current_plan( $blog_id );
		$new_plan     = ( $level . '_' . $period );

		if ( $current_plan == $new_plan ) {
			if ( ! $is_payment ) {
				//is not a payment, nothing to do
				return false;
			}

			$extend_window = (int) get_blog_option( $blog_id, 'psts_stripe_last_webhook_extend' ) + 300; //last extended + 5 minutes

			if ( time() < $extend_window ) {
				/* blog has already been extended by another webhook within the past
					 5 minutes - don't extend again */
				return false;
			}
		}

		$psts->extend( $blog_id, $period, $gateway, $level, $amount, $expire, $is_recurring );

		//send receipt email - this needs to be done AFTER extend is called
		$psts->email_notification( $blog_id, 'receipt', false, $args );

		update_blog_option( $blog_id, 'psts_stripe_last_webhook_extend', time() );

		return true;
	}

	/**
	 * The heart of the Stripe API integration
	 *
	 * Everything that Stripe does gets communicated back here.
	 *
	 * @return bool
	 */
	public static function webhook_handler() {
		global $wpdb, $psts, $current_site;
		$site_name = $current_site->site_name;

		$domain  = '';
		$blog_id = false;
		try {
			// retrieve the request's body and parse it as JSON
			$body       = @file_get_contents( 'php://input' );
			$event_json = json_decode( $body );

			if ( ! isset( $event_json->data->object->customer ) ) {
				return false;
			}

			$customer_id  = $event_json->data->object->customer;
			$subscription = self::get_subscription( $event_json );

			$event_type = $event_json->type;
			//If invoice has been created, activate user blog trial
			if ( 'invoiceitem.updated' == $event_type ||
			     'invoiceitem.created' == $event_type ||
			     'invoice.created' == $event_type ||
			     'invoice.payment_succeeded' == $event_type
			) {
				// Create generic class from Stripe\Subscription class

				if ( ! empty( $subscription->blog_id ) ) {
					$blog_id = $subscription->blog_id;
				} else if ( ! empty( $subscription ) ) {
					// activate to get ID
					$blog_id = ProSites_Helper_Registration::activate_blog( $subscription->activation, $subscription->is_trial, $subscription->period, $subscription->level, $subscription->trial_end );
					// set new ID
					self::set_subscription_blog_id( $subscription, $customer_id, $blog_id );
				}

				//Set Customer data
				if ( ! empty( $blog_id ) && isset( $subscription->id ) && ! empty( $subscription->id ) ) {
					self::set_customer_data( $blog_id, $customer_id, $subscription->id );
				}

			}

			if ( empty( $blog_id ) && isset( $subscription ) && isset( $subscription->blog_id ) && ! empty( $subscription->blog_id ) ) {
				$blog_id = $subscription->blog_id;
			}

			if ( $blog_id || $domain ) {

				// In case the blog has since been removed from the database, just exit
				$details = get_blog_details( $blog_id );
				if ( empty( $details ) ) {
					return false;
				}

				$date = date_i18n( get_option( 'date_format' ), $event_json->created );

				$amount   = $amount_formatted = $plan_amount = 0;
				$level    = $period = $plan = '';
				$is_trial = false;
				$plan_end = false;

				switch ( $event_type ) {
					case 'invoice.payment_succeeded' :
					case 'invoice.payment_failed' :
						$plan          = $subscription->plan->id;
						$is_trial      = $subscription->is_trial;
						$plan_end      = $subscription->period_end;
						$plan_amount   = $subscription->plan_amount;
						$amount        = $subscription->subscription_amount;
						$setup_fee_amt = $subscription->setup_fee;
						$has_setup_fee = ! empty( $setup_fee_amt );
						$discount_amount = $subscription->discount_amount;
						$has_discount = $subscription->has_discount;
						break;

					case 'customer.subscription.created' :
					case 'customer.subscription.updated' :
						$plan     = $subscription->plan->id;
						$amount   = $plan_amount = ( $subscription->plan->amount / 100 );
						$is_trial = $subscription->is_trial;
						$plan_end = ( $is_trial ) ? $subscription->trial_end : $subscription->period_end;
						break;
				}

				// Should be Stripe regardless if its a trial, we need the proper information returned later
				$gateway = self::get_slug();

				// ... but we should record that it is a trial.
				if ( $is_trial ) {
					ProSites_Helper_Registration::set_trial( $blog_id, 1 );
				}

				$amount_formatted = $psts->format_currency( false, $amount );
				$charge_id        = ( isset( $event_json->data->object->charge ) ) ? $event_json->data->object->charge : $event_json->data->object->id;

				if ( ! empty( $plan ) ) {
					$plan_parts = explode( '_', $plan );
					$period     = array_pop( $plan_parts );
					$level      = array_pop( $plan_parts );
				}
				if ( ! empty( $blog_id ) ) {
					/* 	reset the waiting status (this is used on the checkout screen to display a
						notice to customers that actions are pending on their account) */
					update_blog_option( $blog_id, 'psts_stripe_waiting', 0 );
				} elseif ( ! empty ( $domain ) ) {
					/**
					 * @todo redundant now
					 */
					//Update signup meta
//					$signup_meta                        = $psts->get_signup_meta( $domain );
//					$signup_meta['psts_stripe_waiting'] = 0;
//					$psts->update_signup_meta( $signup_meta, $domain );
				}

				switch ( $event_type ) {
					case 'invoice.payment_succeeded' :
						$psts->log_action( $blog_id, sprintf( __( 'Stripe webhook "%s" received: The %s payment was successfully received. Date: "%s", Charge ID "%s"', 'psts' ), $event_type, $amount_formatted, $date, $charge_id ) );
						$args = array();
						if ( $has_setup_fee ) {
							$args['setup_amount'] = $setup_fee_amt;
						}
						if( $has_discount ) {
							$args['discount_amount'] = $discount_amount;
						}
						self::maybe_extend( $blog_id, $period, $gateway, $level, $plan_amount, $plan_end, true, true, $args );
						break;

					case 'customer.subscription.created' :
						$period_string = ( $period == 1 ) ? 'month' : 'months';
						$psts->record_stat( $blog_id, 'signup' );
						$psts->log_action( $blog_id, sprintf( __( 'Stripe webhook "%1$s" received: Customer successfully subscribed to %2$s %3$s: %4$s every %5$s %6$s.', 'psts' ), $event_type, $site_name, $psts->get_level_setting( $level, 'name' ), $psts->format_currency( false, $plan_amount ), number_format_i18n( $period ), $period_string ), $domain );
						self::maybe_extend( $blog_id, $period, $gateway, $level, $plan_amount, $plan_end );
						break;

					case 'customer.subscription.updated' :
						$period_string       = ( $period == 1 ) ? 'month' : 'months';
						$current_plan        = self::get_current_plan( $blog_id );
						$plan_parts          = explode( '_', $current_plan );
						$current_plan_period = array_pop( $plan_parts );
						$current_plan_level  = array_pop( $plan_parts );

						if ( $current_plan_period != $period || $current_plan_level != $level ) {
							if ( $current_plan_level < $level ) {
								$psts->record_stat( $blog_id, 'upgrade' );
							} else {
								$psts->record_stat( $blog_id, 'modify' );
							}
						}

						$psts->log_action( $blog_id, sprintf( __( 'Stripe webhook "%s" received. The customer\'s subscription was successfully updated to %2$s %3$s: %4$s every %5$s %6$s.', 'psts' ), $event_type, $site_name, $psts->get_level_setting( $level, 'name' ), $psts->format_currency( false, $plan_amount ), number_format_i18n( $period ), $period_string ) );
						self::maybe_extend( $blog_id, $period, $gateway, $level, $plan_amount, $plan_end );
						break;

					case 'invoice.payment_failed' :
						$psts->log_action( $blog_id, sprintf( __( 'Stripe webhook "%s" received: The %s payment has failed. Date: "%s", Charge ID "%s"', 'psts' ), $event_type, $amount_formatted, $date, $charge_id ) );
						$psts->email_notification( $blog_id, 'failed' );
						break;

					case 'charge.disputed' :
						$psts->log_action( $blog_id, sprintf( __( 'Stripe webhook "%s" received: The customer disputed a charge with their bank (chargeback), Charge ID "%s"', 'psts' ), $event_type, $charge_id ) );
						$psts->withdraw( $blog_id );
						break;

					case 'customer.subscription.deleted' :
						update_blog_option( $blog_id, 'psts_stripe_canceled', 1 );
						$psts->log_action( $blog_id, sprintf( __( 'Stripe webhook "%s" received: The subscription has been canceled', 'psts' ), $event_type ) );
						break;

					default :
						$text = sprintf( __( 'Stripe webhook "%s" received', 'psts' ), $event_type );

						if ( $customer_id ) {
							$text .= sprintf( __( ': Customer ID: %s', 'psts' ), $customer_id );
						}

						$psts->log_action( $blog_id, $text );
						break;
				}
			}
			die( 1 );
		} catch ( Exception $ex ) {
			$message = $ex->getMessage();
			die( $message );
		}

	}

	/**
	 * Sets the Subscription's meta to include the $blod_id
	 *
	 * @param $subscription
	 * @param $customer_id
	 * @param $fallback_blog_id
	 * @param bool $new_blog_id
	 *
	 * @return bool
	 */
	public static function set_subscription_blog_id( $subscription, $customer_id, $fallback_blog_id, $new_blog_id = false ) {
		// Use $new_blog_id, or the $subscription->id if it exists, or the $fallback_blog_id.
		$the_blog_id = ! empty( $new_blog_id ) ? $new_blog_id : ! empty( $subscription->blog_id ) ? $subscription->blog_id : $fallback_blog_id;

		if ( ! empty( $subscription->blog_id ) && $the_blog_id == $subscription->blog_id ) {
			// Nothing to update if all the ids match the subscription id
			return $the_blog_id;
		} else {
			// Blog ID doesn't exist or a new blog ID has been given.
			$customer = Stripe_Customer::retrieve( $customer_id );

			// If the customer is deleted, just return the ID, most likely 0
			if ( isset( $customer->deleted ) && $customer->deleted ) {
				return $the_blog_id;
			}

			if ( is_object( $subscription ) && ! empty( $customer ) ) {
				$sub                    = $customer->subscriptions->retrieve( $subscription->id );
				$sub->metadata->blog_id = $the_blog_id;
				$sub->save();
			} else if ( ! empty( $subscription ) && ! empty( $customer ) ) {
				// if its just passed as a string
				$sub                    = $customer->subscriptions->retrieve( $subscription );
				$sub->metadata->blog_id = $the_blog_id;
				$sub->save();
			}

			return $the_blog_id;
		}
	}

	/**
	 * Uses Stripe API json object to retrieve the subscription object.
	 *
	 * @param $response
	 *
	 * @return bool
	 */
	public static function get_subscription( $response ) {
		$object = $response->data->object;

		$from_invoice = 'invoice' == $object->object ? true : false;
		$from_sub     = 'subscription' == $object->object ? true : false;

		if ( ! $from_invoice && ! $from_sub ) {
			return false;
		}

		// Get the subscription first
		$subscription = false;
		$coupon = false;

		if ( $from_invoice ) {
			foreach ( $object->lines->data as $line_item ) {
				if ( 'subscription' == $line_item->type ) {
					$subscription = $line_item;
					break;
				}
			}
			if ( ! $subscription ) {
				return false;
			}
			if( isset( $object->discount) && isset( $object->discount->coupon ) ) {
				$coupon = $object->discount->coupon;
			}
			// Get fields from Invoice
			$subscription->customer_id   = $object->customer;
			$subscription->paid          = $object->paid;
			$subscription->currency      = $object->currency;
			$subscription->last_charge   = $object->charge;
			$subscription->invoice_total = $object->total / 100;
		}

		if ( $from_sub ) {
			$subscription = $object;
		}

		$subscription->period_end   = isset( $subscription->period ) ? $subscription->period->end : false;
		$subscription->period_end   = empty( $subscription->period_end ) && isset( $subscription->current_period_end ) ? $subscription->current_period_end : $subscription->period_end;
		$subscription->period_start = isset( $subscription->period ) ? $subscription->period->start : false;
		$subscription->period_start = empty( $subscription->period_start ) && isset( $subscription->current_period_start ) ? $subscription->current_period_start : $subscription->period_start;

		// Get fields from subscription meta
		$subscription->period     = isset( $subscription->metadata->period ) ? $subscription->metadata->period : false;
		$subscription->level      = isset( $subscription->metadata->level ) ? $subscription->metadata->level : false;
		$subscription->activation = isset( $subscription->metadata->activation ) ? $subscription->metadata->activation : false;
		$subscription->blog_id    = isset( $subscription->metadata->blog_id ) ? $subscription->metadata->blog_id : false;
		if ( empty( $subscription->blog_id ) ) {
			$subscription->blog_id = ProSites_Helper_ProSite::get_blog_id( $subscription->activation );
		}
		$subscription->is_trial            = isset( $subscription->status ) && 'trialing' == $subscription->status ? true : false;
		$subscription->trial_end           = isset( $subscription->trial_end ) ? $subscription->trial_end : false;
		$subscription->trial_start         = isset( $subscription->trial_start ) ? $subscription->trial_start : false;
		$subscription->subscription_amount = isset( $subscription->amount ) ? ( $subscription->amount / 100 ) : ( $subscription->plan->amount / 100 );
		$subscription->plan_amount         = $subscription->is_trial ? ( $subscription->plan->amount / 100 ) : $subscription->subscription_amount;

		$subscription->discount_amount = 0;
		if( $coupon ) {
			$subscription->discount_amount = $coupon->amount_off / 100;
		}
		$subscription->has_discount = empty( $coupon ) ? false : true;

		// Get setup fee
		if( ! $coupon ) {
			$subscription->setup_fee     = isset( $subscription->invoice_total ) ? $subscription->invoice_total - $subscription->subscription_amount : 0;
		} else {
			$invoice_total = isset( $subscription->invoice_total ) ? $subscription->invoice_total - $subscription->subscription_amount : 0;
			// Adjust calculation by adding the discount back...
			$subscription->setup_fee = $invoice_total + $subscription->discount_amount;
		}
		$subscription->has_setup_fee = empty( $subscription->setup_fee ) ? false : true;

		return $subscription;
	}

	/**
	 * Cancel a particular blog's subscription
	 *
	 * @param $blog_id
	 */
	public static function cancel_blog_subscription( $blog_id ) {
		global $psts;

		$error       = '';
		$customer_id = self::get_customer_data( $blog_id )->customer_id;
		if ( $customer_id ) {
			try {
				$customer_data = self::get_customer_data( $blog_id );
				$customer_id   = $customer_data->customer_id;
				$sub_id        = $customer_data->subscription_id;
				$cu            = Stripe_Customer::retrieve( $customer_id );
				// Don't use ::cancelSubscription because it doesn't know which subscription if we have multiple
				$cu->subscriptions->retrieve( $sub_id )->cancel();
			} catch ( Exception $e ) {
				$error = $e->getMessage();
			}

			if ( empty( $error ) ) {
				//record stat
				$psts->record_stat( $blog_id, 'cancel' );
				$psts->email_notification( $blog_id, 'canceled' );
				update_blog_option( $blog_id, 'psts_stripe_canceled', 1 );
				$psts->log_action( $blog_id, __( 'Subscription successfully canceled because the blog was deleted.', 'psts' ) );
			}
		}
	}

	/**
	 * Get gateway's nice name
	 */
	public static function get_name() {
		return array(
			'stripe' => __( 'Stripe', 'psts' ),
		);
	}

	/**
	 * Render the gateway as called from ProSites_View_Front_Gateway
	 *
	 * @todo: Future: Allow preference for gateways to use CC checkout or other
	 * @todo: Review commented out code and add in the ones that make sense (e.g. statuses)
	 *
	 * @param $args
	 * @param $blog_id
	 * @param $domain
	 * @param bool $prefer_cc Not implemented yet.
	 *
	 * @return string
	 */
	public static function render_gateway( $render_data = array(), $args, $blog_id, $domain, $prefer_cc = true ) {
		global $psts, $wpdb, $current_site, $current_user;

		$content = '';

		$site_name = $current_site->site_name;
		$img_base  = $psts->plugin_url . 'images/';

		// Try stateless, or get from session
		$session_keys = array( 'new_blog_details', 'upgraded_blog_details', 'activation_key' );
		foreach( $session_keys as $key ) {
			$render_data[ $key ] = isset( $render_data[ $key ] ) ? $render_data[ $key ] : ProSites_Helper_Session::session( $key );
		}

//		$cancel_status  = get_blog_option( $blog_id, 'psts_stripe_canceled' );
//		$cancel_content = '';
//
//		$img_base  = $psts->plugin_url . 'images/';
//		$pp_active = false;

//		if ( $errmsg = $psts->errors->get_error_message( 'general' ) ) {
//			$content = '<div id="psts-general-error" class="psts-error">' . $errmsg . '</div>'; //hide top part of content if theres an error
//		}
//
//		if ( ! empty( $blog_id ) && 1 == get_blog_option( $blog_id, 'psts_stripe_waiting' ) ) {
//			$content .= '<div id="psts-general-error" class="psts-warning">' . __( 'There are pending changes to your account. This message will disappear once these pending changes are completed.', 'psts' ) . '</div>';
//		}
//
//				//print receipt send form
//				$content .= $psts->receipt_form( $blog_id );
//
//				if ( ! defined( 'PSTS_CANCEL_LAST' ) || ( defined( 'PSTS_CANCEL_LAST' ) && ! PSTS_CANCEL_LAST ) ) {
//					$content .= $cancel_content;
//				}
//

		$customer_data = self::get_customer_data( $blog_id );
		$customer_id   = $customer_data ? $customer_data->customer_id : false;
		if ( $customer_id ) {
			try {
				$customer_object = Stripe_Customer::retrieve( $customer_id );
			} catch ( Exception $e ) {
				$error = $e->getMessage();
			}
		}

		$period = isset( $args['period'] ) && ! empty( $args['period'] ) ? $args['period'] : 1;
		$level  = isset( $render_data['new_blog_details'] ) && isset( $render_data['new_blog_details']['level'] ) ? (int) $render_data['new_blog_details']['level'] : 0;
		$level  = isset( $render_data['upgraded_blog_details'] ) && isset( $render_data['upgraded_blog_details']['level'] ) ? (int) $render_data['upgraded_blog_details']['level'] : $level;

		$content .= '<form action="' . $psts->checkout_url( $blog_id, $domain ) . '" method="post" autocomplete="off"  id="stripe-payment-form">

			<input type="hidden" name="level" value="' . $level . '" />
			<input type="hidden" name="period" value="' . $period . '" />';

		if ( isset( $_POST['new_blog'] ) || ( isset( $_GET['action'] ) && 'new_blog' == $_GET['action'] ) ) {
			$content .= '<input type="hidden" name="new_blog" value="1" />';
		}

		if ( isset( $_GET['bid'] ) ) {
			$content .= '<input type="hidden" name="bid" value="' . (int) $_GET['bid'] . '" />';
		}

		// This is a new blog
		if ( isset( $render_data['activation_key'] ) ) {
			$content .= '<input type="hidden" name="activation" value="' . $render_data['activation_key'] . '" />';

			if ( isset( $render_data['new_blog_details'] ) ) {
				$user_name  = $render_data['new_blog_details']['username'];
				$user_email = $render_data['new_blog_details']['email'];
				$blogname   = $render_data['new_blog_details']['blogname'];
				$blog_title = $render_data['new_blog_details']['title'];

				$content .= '<input type="hidden" name="blog_username" value="' . $render_data['new_blog_details']['username'] . '" />';
				$content .= '<input type="hidden" name="blog_email" value="' . $render_data['new_blog_details']['email'] . '" />';
				$content .= '<input type="hidden" name="blog_name" value="' . $render_data['new_blog_details']['blogname'] . '" />';
				$content .= '<input type="hidden" name="blog_title" value="' . $render_data['new_blog_details']['title'] . '" />';
			}
		}

		//if existing customer, offer ability to checkout using saved credit card info
		if ( isset( $customer_object ) ) {
			$card_object = self::get_default_card( $customer_object );

			$content .= '<div id="psts-stripe-checkout-existing">
					<h2>' . esc_html( 'Checkout Using Existing Credit Card', 'psts' ) . '</h2>
					<table id="psts-cc-table-existing">
						<tr>
							<td class="pypl_label" align="right">' . esc_html__( 'Last 4 Digits:', 'psts' ) . '</td>
							<td>' . esc_html( $card_object->last4 ) . '</td>
						</tr>
						<tr>
						<td class="pypl_label" align="right">' . esc_html__( 'WordPress Password:', 'psts' ) . '</td>
							<td><input id="wp_password" name="wp_password" size="15" type="password" class="cctext" title="' . esc_attr__( 'Enter the WordPress password that you login with.', 'psts' ) . '" /></td>
						</tr>
					</table>
				</div>';
		}

		$content .= '<div id="psts-stripe-checkout">
				<h2>' . esc_html__( 'Checkout With a Credit Card:', 'psts' ) . '</h2>
				<div id="psts-processcard-error"></div>

				<table id="psts-cc-table">
					<tbody>
						<!-- Credit Card Number -->
						<tr>
							<td class="pypl_label" align="right">' . esc_html__( 'Card Number:', 'psts' ) . '&nbsp;</td>
							<td>';
		$content .= '<input id="cc_number" type="text" class="cctext card-number" value="" size="23" /><br />
								<img class="accepted-cards" src="' . esc_url( $img_base . 'stripe-cards.png' ) . '" />
							</td>
						</tr>

						<tr>
							<td class="pypl_label" align="right">' . esc_html__( 'Expiration Date:', 'psts' ) . '&nbsp;</td>
							<td valign="middle">';
//								if ( $errmsg = $psts->errors->get_error_message( 'expiration' ) ) {
//									$content .= '<div class="psts-error">' . esc_html( $errmsg ) . '</div>';
//								}
		$content .= '<select id="cc_month" class="card-expiry-month">' . self::month_dropdown() . '</select>&nbsp;/&nbsp;<select id="cc_year" class="card-expiry-year">' . self::year_dropdown() . '</select>
							</td>
						</tr>

						<!-- Card Security Code -->
						<tr>
							<td class="pypl_label" align="right"><nobr>' . esc_html__( 'Card Security Code:', 'psts' ) . '</nobr>&nbsp;</td>
							<td valign="middle">';
//								if ( $errmsg = $psts->errors->get_error_message( 'cvv2' ) ) {
//									$content .= '<div class="psts-error">' . esc_html( $errmsg ) . '</div>';
//								}
		$content .= '<label>
									<input id="cc_cvv2" size="5" maxlength="4" type="password" class="cctext card-cvc" title="' . esc_attr__( 'Please enter a valid card security code. This is the 3 digits on the signature panel, or 4 digits on the front of Amex cards.', 'psts' ) . '" />
									<img src="' . esc_url( $img_base . 'buy-cvv.gif' ) . '" height="27" width="42" title="' . esc_attr__( 'Please enter a valid card security code. This is the 3 digits on the signature panel, or 4 digits on the front of Amex cards.', 'psts' ) . '" />
								</label>
							</td>
						</tr>

						<tr>
							<td class="pypl_label" align="right">' . esc_html__( 'Cardholder Name:', 'psts' ) . '&nbsp;</td>
		                    <td>';
//								if ( $errmsg = $psts->errors->get_error_message( 'name' ) ) {
//									$content .= '<div class="psts-error">' . esc_html( $errmsg ) . '</div>';
//								}
		$content .= '<input id="cc_name" type="text" class="cctext card-first-name" value="" size="25" />
							</td>
						</tr>
					</tbody>
				</table>
				<input type="hidden" name="cc_stripe_checkout" value="1" />
				<p>
					<input type="submit" id="cc_stripe_checkout" name="stripe_checkout_button" value="' . esc_attr__( 'Subscribe', 'psts' ) . '" class="submit-button"/>
					<div id="stripe_processing" style="display: none;float: right;"><img src="' . esc_url( $img_base . 'loading.gif' ) . '" /> ' . esc_html__( 'Processing...', 'psts' ) . '</div>
				</p>
			</div>';

		$content .= '</form>';

		return $content;
	}

	/**
	 * @param $blog_id
	 * @param $domain
	 */
	public static function process_checkout_form( $process_data = array(), $blog_id, $domain ) {
		global $psts, $current_user, $current_site;

		$site_name = $current_site->site_name;
		$img_base  = $psts->plugin_url . 'images/';

		$session_keys = array( 'new_blog_details', 'upgraded_blog_details', 'COUPON_CODE', 'activation_key' );
		foreach( $session_keys as $key ) {
			$process_data[ $key ] = isset( $process_data[ $key ] ) ? $process_data[ $key ] : ProSites_Helper_Session::session( $key );
		}

		if ( ! empty( $domain ) ) {
			$site_name = ! empty ( $_POST['blogname'] ) ? $_POST['blogname'] : ! empty ( $_POST['signup_email'] ) ? $_POST['signup_email'] : '';
		}

		// Cancel subscription
		if ( isset( $_GET['action'] ) && $_GET['action'] == 'cancel' && wp_verify_nonce( $_GET['_wpnonce'], 'psts-cancel' ) ) {
			$error = '';

			try {
				$customer_data = self::get_customer_data( $blog_id );
				$customer_id   = $customer_data->customer_id;
				$sub_id        = $customer_data->subscription_id;
				$cu            = Stripe_Customer::retrieve( $customer_id );
				// Don't use ::cancelSubscription because it doesn't know which subscription if we have multiple
				$cu->subscriptions->retrieve( $sub_id )->cancel();
			} catch ( Exception $e ) {
				$error = $e->getMessage();
			}

			if ( $error != '' ) {
				self::$cancel_message = '<div id="message" class="error fade"><p>' . __( 'There was a problem canceling your subscription, please contact us for help: ', 'psts' ) . $error . '</p></div>';
			} else {
				//record stat
				$psts->record_stat( $blog_id, 'cancel' );
				$psts->email_notification( $blog_id, 'canceled' );
				update_blog_option( $blog_id, 'psts_stripe_canceled', 1 );

				$end_date = date_i18n( get_option( 'date_format' ), $psts->get_expire( $blog_id ) );
				$psts->log_action( $blog_id, sprintf( __( 'Subscription successfully cancelled by %1$s. They should continue to have access until %2$s', 'psts' ), $current_user->display_name, $end_date ) );
				self::$cancel_message = '<div id="message" class="updated fade"><p>' . sprintf( __( 'Your %1$s subscription has been canceled. You should continue to have access until %2$s.', 'psts' ), $site_name . ' ' . $psts->get_setting( 'rebrand' ), $end_date ) . '</p></div>';
			}
		}

		if ( isset( $_POST['cc_stripe_checkout'] ) && 1 == (int) $_POST['cc_stripe_checkout'] ) {

			//check for level
			if ( empty( $_POST['level'] ) || empty( $_POST['period'] ) ) {
				$psts->errors->add( 'general', __( 'Please choose your desired level and payment plan.', 'psts' ) );
				//	return;
			} else if ( ! isset( $_POST['stripeToken'] ) && empty( $_POST['wp_password'] ) ) {
				$psts->errors->add( 'general', __( 'There was an error processing your Credit Card with Stripe. Please try again.', 'psts' ) );
				//	return;
			}

			$error          = '';
			$success        = '';
			$plan           = self::get_plan_id( $_POST['level'], $_POST['period'] );
			$customer_id    = '';
			$current_plan_level = 0;
			$activation_key = isset( $_POST['activation'] ) ? $_POST['activation'] : '';
			$email          = ! empty ( $_POST['user_email'] ) ? $_POST['user_email'] : ( ! empty( $_POST['signup_email'] ) ? $_POST['signup_email'] : ( ! empty( $_POST['blog_email'] ) ? $_POST['blog_email'] : '' ) );
			$blog_id        = ! empty( $blog_id ) ? $blog_id : isset( $_POST['bid'] ) ? (int) $_POST['bid'] : 0;

			if ( ! empty( $blog_id ) ) {
				$customer_id = self::get_customer_data( $blog_id )->customer_id;
				$email       = isset( $current_user->user_email ) ? $current_user->user_email : get_blog_option( $blog_id, 'admin_email' );
				if ( $current_plan = self::get_current_plan( $blog_id ) ) {
					list( $current_plan_level, $current_plan_period ) = explode( '_', $current_plan );
				}
			} else {
				if ( empty( $email ) && isset( $process_data['new_blog_details'] ) && isset( $process_data['new_blog_details']['user_email'] ) ) {
					$email = $process_data['new_blog_details']['user_email'];
				}
				$customer_id = self::get_customer_data( false, false, $email )->customer_id;
			}

			if ( ! self::plan_exists( $plan ) ) {
				$psts->errors->add( 'general', sprintf( __( 'Stripe plan %1$s does not exist.', 'psts' ), $plan ) );

				return;
			}

			try {

				if ( ! $customer_id ) {
					try {
						$customer_args = array(
							'email'       => $email,
							'description' => sprintf( __( '%s user', 'psts' ), $site_name ),
							'card'        => $_POST['stripeToken'],
							'metadata'    => array(
								'domain' => $domain,
							)
						);

						$user = get_user_by( 'email', $email );
						if ( $user ) {
							$blog_string                       = '';
							$customer_args['metadata']['user'] = $user->user_login;
							$customer_args['description']      = sprintf( __( '%s user - %s ', 'psts' ), $site_name, $user->first_name . ' ' . $user->last_name );
							$user_blogs                        = get_blogs_of_user( $user->ID );
							foreach ( $user_blogs as $user_blog ) {
								$blog_string .= $user_blog->blogname . ', ';
							}
							$customer_args['metadata']['blogs'] = $blog_string;
						}

						if ( ! $domain ) {
							unset( $customer_args['metadata']['domain'] );
						}

						$c = Stripe_Customer::create( $customer_args );
					} catch ( Exception $e ) {
						$psts->errors->add( 'general', __( 'The Stripe customer could not be created. Please try again.', 'psts' ) );

						return;
					}

					//Update the stripe customer id, this is temporary, will be overridden by subscription or charge id
					self::set_customer_data( $blog_id, $c->id, 'ak_' . $activation_key );
					$customer_id = $c->id;
					$new         = true;
				} else {

					// Get a customer if they exist
					try {
						$c = Stripe_Customer::retrieve( $customer_id );
					} catch ( Exception $e ) {
						$psts->errors->add( 'general', __( 'The Stripe customer could not be retrieved. Please try again.', 'psts' ) );

						return;
					}

					$c->description = sprintf( __( '%s user', 'psts' ), $site_name );
					$c->email       = $email;

					$user = get_user_by( 'email', $email );
					if ( $user ) {
						$blog_string       = '';
						$c->metadata->user = $user->user_login;
						$c->description    = sprintf( __( '%s user - %s ', 'psts' ), $site_name, $user->first_name . ' ' . $user->last_name );
						$user_blogs        = get_blogs_of_user( $user->ID );
						foreach ( $user_blogs as $user_blog ) {
							$blog_string .= $user_blog->blogname . ', ';
						}
						$c->metadata->blogs = $blog_string;
					}

					$c->save();
					$new = false;

					//validate wp password (if applicable)
					if ( ! empty( $_POST['wp_password'] ) && ! wp_check_password( $_POST['wp_password'], $current_user->data->user_pass, $current_user->ID ) ) {
						$psts->errors->add( 'general', __( 'The password you entered is incorrect.', 'psts' ) );

						return;
					}
				}

				//prepare vars

				// Attempt to use the site's currency. Or Stripe if none defined.
				// Note, Stripe will revert to merchant currency if a currency is
				// not supported. Bonus!
				$stripe_currency = $psts->get_setting( 'stripe_currency', 'USD' );
				$currency        = $psts->get_setting( 'currency', $stripe_currency );

				$amount_off    = false;
				$paymentAmount = $initAmount = $psts->get_level_setting( $_POST['level'], 'price_' . $_POST['period'] );
				$trial_days    = $psts->get_setting( 'trial_days', 0 );
				$cp_code       = false;
				$setup_fee     = (float) $psts->get_setting( 'setup_fee', 0 );
				$has_coupon    = ( isset( $process_data['COUPON_CODE'] ) && ProSites_Helper_Coupons::check_coupon( $process_data['COUPON_CODE'], $blog_id, $_POST['level'], $_POST['period'], $domain ) ) ? true : false;
				$has_setup_fee = $psts->has_setup_fee( $blog_id, $_POST['level'] );
				$recurring     = $psts->get_setting( 'recurring_subscriptions', 1 );

				if ( $has_setup_fee ) {
					$initAmount = $setup_fee + $paymentAmount;
				}

				if ( $has_coupon || $has_setup_fee ) {

					$lifetime = 'once';
					if ( $has_coupon ) {
						//apply coupon
						$adjusted_values = ProSites_Helper_Coupons::get_adjusted_level_amounts( $process_data['COUPON_CODE'] );
						$coupon_obj      = ProSites_Helper_Coupons::get_coupon( $process_data['COUPON_CODE'] );
						$lifetime        = isset( $coupon_obj['lifetime'] ) && 'indefinite' == $coupon_obj['lifetime'] ? 'forever' : 'once';
						//	$coupon_value = $psts->coupon_value( $process_data['COUPON_CODE'], $paymentAmount );
						$coupon_value = $adjusted_values[ $_POST['level'] ][ 'price_' . $_POST['period'] ];
						// $amount_off   = $paymentAmount - $coupon_value['new_total'];
						$amount_off = $paymentAmount - $coupon_value;
						$initAmount -= $amount_off;
						$initAmount = 0 > $initAmount ? 0 : $initAmount; // avoid negative

						$cpn = false;
						try {
							$cpn = Stripe_Coupon::create( array(
								'amount_off'      => ( $amount_off * 100 ),
								'duration'        => $lifetime,
								'currency'        => $currency,
								'max_redemptions' => 1,
							) );
						} catch ( Exception $e ) {
							$psts->errors->add( 'general', __( 'Temporary Stripe coupon could not be generated correctly. Please try again.', 'psts' ) );

							return;
						}

						$cp_code = $cpn->id;
					}

					if ( $recurring ) {
						$recurringAmmount = 'forever' == $lifetime && $has_coupon ? $coupon_value : $paymentAmount;
						if ( $_POST['period'] == 1 ) {
							$desc = $site_name . ' ' . $psts->get_level_setting( $_POST['level'], 'name' ) . ': ' . sprintf( __( '%1$s for the first month, then %2$s each month', 'psts' ), $psts->format_currency( $currency, $initAmount ), $psts->format_currency( $currency, $recurringAmmount ) );
						} else {
							$desc = $site_name . ' ' . $psts->get_level_setting( $_POST['level'], 'name' ) . ': ' . sprintf( __( '%1$s for the first %2$s month period, then %3$s every %4$s months', 'psts' ), $psts->format_currency( $currency, $initAmount ), $_POST['period'], $psts->format_currency( $currency, $recurringAmmount ), $_POST['period'] );
						}
					} else {
						if ( ! empty( $blog_id ) ) {
							$initAmount = $psts->calc_upgrade_cost( $blog_id, $_POST['level'], $_POST['period'], $initAmount );
						}
						if ( $_POST['period'] == 1 ) {
							$desc = $site_name . ' ' . $psts->get_level_setting( $_POST['level'], 'name' ) . ': ' . sprintf( __( '%1$s for 1 month', 'psts' ), $psts->format_currency( $currency, $initAmount ) );
						} else {
							$desc = $site_name . ' ' . $psts->get_level_setting( $_POST['level'], 'name' ) . ': ' . sprintf( __( '%1$s for %2$s months', 'psts' ), $psts->format_currency( $currency, $initAmount ), $_POST['period'] );
						}
					}

				} elseif ( $recurring ) {
					if ( $_POST['period'] == 1 ) {
						$desc = $site_name . ' ' . $psts->get_level_setting( $_POST['level'], 'name' ) . ': ' . sprintf( __( '%1$s %2$s each month', 'psts' ), $psts->format_currency( $currency, $paymentAmount ), $currency );
					} else {
						$desc = $site_name . ' ' . $psts->get_level_setting( $_POST['level'], 'name' ) . ': ' . sprintf( __( '%1$s %2$s every %3$s months', 'psts' ), $psts->format_currency( $currency, $paymentAmount ), $currency, $_POST['period'] );
					}
				} else {
					if ( ! empty( $blog_id ) ) {
						$paymentAmount = $psts->calc_upgrade_cost( $blog_id, $_POST['level'], $_POST['period'], $paymentAmount );
					}
					if ( $_POST['period'] == 1 ) {
						$desc = $site_name . ' ' . $psts->get_level_setting( $_POST['level'], 'name' ) . ': ' . sprintf( __( '%1$s for 1 month', 'psts' ), $psts->format_currency( $currency, $paymentAmount ) );
					} else {
						$desc = $site_name . ' ' . $psts->get_level_setting( $_POST['level'], 'name' ) . ': ' . sprintf( __( '%1$s for %2$s months', 'psts' ), $psts->format_currency( $currency, $paymentAmount ), $_POST['period'] );
					}
				}

				// Override the Stripe description
				$desc = apply_filters( 'psts_stripe_checkout_desc', $desc, $_POST['period'], $_POST['level'], $paymentAmount, $initAmount, $blog_id, $domain );

				// Time to process invoices with Stripe
				if ( $recurring ) {
					// Recurring subscription

					// Assign plan to customer
					$args = array(
						"plan"    => $plan,
						"prorate" => true,
					);

					// If there is a coupon, add its reference
					if ( $cp_code ) {
						$args["coupon"] = $cp_code;
					}

					// If this is a trial before the subscription starts
					if ( $psts->is_trial_allowed( $blog_id ) ) {
						if ( isset( $process_data['new_blog_details'] ) || ! $psts->is_existing( $blog_id ) ) {
							//customer is new - add trial days
							$args['trial_end'] = strtotime( '+ ' . $trial_days . ' days' );
						} elseif ( is_pro_trial( $blog_id ) && $psts->get_expire( $blog_id ) > time() ) {
							//customer's trial is still valid - carry over existing expiration date
							$args['trial_end'] = $psts->get_expire( $blog_id );
						}
					}

					// Meta data for `pay before blog` creation
					$args['metadata'] = array(
						'domain' => ! empty( $domain ) ? $domain : '',
						'period' => $_POST['period'],
						'level'  => $_POST['level']
					);
					if ( ! $domain ) {
						unset( $args['metadata']['domain'] );
					}
					// new blog
					if ( isset( $_POST['activation'] ) ) {
						$args['metadata']['activation'] = $_POST['activation'];
					}

					// Invoice for the setup fee
					if ( $has_setup_fee ) {
						try {
							$customer_args = array(
								'customer'    => $customer_id,
								'amount'      => ( $setup_fee * 100 ),
								'currency'    => $currency,
								'description' => __( 'One-time setup fee', 'psts' ),
								'metadata'    => array(
									'domain'    => ! empty( $domain ) ? $domain : '',
									'period'    => $_POST['period'],
									'level'     => $_POST['level'],
									'setup_fee' => 'yes',
								)
							);
							if ( ! $domain ) {
								unset( $customer_args['metadata']['domain'] );
							}
							// new blog
							if ( isset( $_POST['activation'] ) ) {
								$customer_args['metadata']['activation'] = $_POST['activation'];
							}
							Stripe_InvoiceItem::create( $customer_args );
						} catch ( Exception $e ) {
							wp_mail(
								get_blog_option( $blog_id, 'admin_email' ),
								__( 'Error charging setup fee. Attention required!', 'psts' ),
								sprintf( __( 'An error occurred while charging a setup fee of %1$s to Stripe customer %2$s. You will need to manually process this amount.', 'psts' ), $psts->format_currency( $currency, $setup_fee ), $customer_id )
							);
						}
					}

					// Create/update subscription
					try {

						$result = false;
						$sub    = false;

						// Brand new blog...
						if ( empty( $blog_id ) ) {
							$result = $c->subscriptions->create( $args );
						} else {

							$customer_data = self::get_customer_data( $blog_id );

							try {
								$sub          = $c->subscriptions->retrieve( $customer_data->subscription_id );
								$sub_id       = $sub->id;
								$sub->plan    = isset( $args['plan'] ) ? $args['plan'] : $sub->plan;
								$sub->prorate = isset( $args['prorate'] ) ? $args['prorate'] : $sub->prorate;
								if ( isset( $args['coupon'] ) ) {
									$sub->coupon = $args['coupon'];
								}
								if ( isset( $args['trial_end'] ) ) {
									$sub->trial_end = $args['trial_end'];
								}

								$sub->metadata->period = $args['metadata']['period'];
								$sub->metadata->level  = $args['metadata']['level'];
								if ( isset( $args['metadata']['activation'] ) ) {
									$sub->metadata->activation = $args['metadata']['activation'];
								} else {
									$activation_key = ProSites_Helper_ProSite::get_activation_key( $blog_id );
									if ( ! empty( $activation_key ) ) {
										$sub->metadata->activation = $activation_key;
									}
								}
								$sub->metadata->blog_id = $blog_id;
								if ( isset( $args['metadata']['domain'] ) ) {
									$sub->metadata->domain = $args['metadata']['domain'];
								}

								$sub->save();
							} catch ( Exception $e ) {
								// Fall through...
							}

							// Additional blog
							if ( empty( $sub ) ) {
								$args['metadata']['blog_id'] = $blog_id;
								if ( ! isset( $args['metadata']['activation'] ) ) {
									$activation_key = ProSites_Helper_ProSite::get_activation_key( $blog_id );
									if ( ! empty( $activation_key ) ) {
										$args['metadata']['activation'] = $activation_key;
									}
								}
								$result = $c->subscriptions->create( $args );
							}
							// This one is now deprecated
							// $result = $c->updateSubscription( $args );
						}

						// Capture success as soon as we can!
						if ( ! empty( $result ) ) {

							$sub_id     = $result->id;
							$plan       = $result->plan;
							$plan_parts = explode( '_', $plan->id );
							$period     = array_pop( $plan_parts );
							$level      = array_pop( $plan_parts );
							$trial      = isset( $plan->status ) && 'trialing' == $plan->status ? true : false;
							$expire     = $trial ? $plan->trial_end : $result->current_period_end;
							$blog_id    = ProSites_Helper_Registration::activate_blog( $activation_key, $trial, $period, $level, $expire );

							if ( isset( $process_data['new_blog_details'] ) ) {
								ProSites_Helper_Session::session( array('new_blog_details','blog_id'), $blog_id );
								ProSites_Helper_Session::session( array('new_blog_details','payment_success'), true );
							} else {
								ProSites_Helper_Session::session( 'upgrade_blog_details', array() );
								ProSites_Helper_Session::session( array('upgrade_blog_details','blog_id'), $blog_id );
								ProSites_Helper_Session::session( array('upgrade_blog_details','level'), $level );
								ProSites_Helper_Session::session( array('upgrade_blog_details','period'), $period );
								ProSites_Helper_Session::session( array('upgrade_blog_details','payment_success'), true );
							}
							self::set_customer_data( $blog_id, $customer_id, $sub_id );
						}

						// Update the sub with the new blog id (old subscriptions will update later).
						if ( ! empty( $blog_id ) ) {
							$sub                    = $c->subscriptions->retrieve( $sub_id );
							$sub->metadata->blog_id = $blog_id;
							$sub->save();
						}

					} catch ( Exception $e ) {
						$body  = $e->getJsonBody();
						$error = $body['error'];
						$psts->errors->add( 'general', $error['message'] );

						return;
					}

				} else {
					// Not a subscription, this is a one of payment, charged for 1 term
					try {

						if ( ! empty( $blog_id ) ) {
							$initAmount = $psts->calc_upgrade_cost( $blog_id, $_POST['level'], $_POST['period'], $initAmount );
						}

						$customer_args = array(
							'customer'    => $customer_id,
							'amount'      => ( $initAmount * 100 ),
							'currency'    => $currency,
							'description' => $desc,
							'metadata'    => array(
								'domain' => ! empty( $domain ) ? $domain : '',
								'period' => $_POST['period'],
								'level'  => $_POST['level']
							)
						);
						if ( ! $domain ) {
							unset( $customer_args['metadata']['domain'] );
						}
						// new blog
						if ( isset( $_POST['activation'] ) ) {
							$customer_args['metadata']['activation'] = $_POST['activation'];
						}

						/**
						 * 1 off charge of not trialing, but if trialing, just send a zero-dollar invoice
						 */
						if ( empty( $trial_days ) || 0 == $customer_args['amount'] ) {
							$result = Stripe_Charge::create( $customer_args );
						} else {
							$result = Stripe_InvoiceItem::create( $customer_args );
						}

						// Capture success as soon as we can!
						if ( $result ) {
							$period  = (int) $_POST['period'];
							$level   = (int) $_POST['level'];
							$blog_id = ProSites_Helper_Registration::activate_blog( $activation_key, false, $period, $level );
							if ( isset( $process_data['new_blog_details'] ) ) {
								ProSites_Helper_Session::session( array('new_blog_details','blog_id'), $blog_id );
								ProSites_Helper_Session::session( array('new_blog_details','payment_success'), true );
							}
							self::set_customer_data( $blog_id, $customer_id, $result->id );
						}

						if ( $current_plan = self::get_current_plan( $blog_id ) ) {
							list( $current_plan_level, $current_plan_period ) = explode( '_', $current_plan );
						}

						$old_expire = $psts->get_expire( $blog_id );
						$new_expire = ( $old_expire && $old_expire > time() ) ? $old_expire : false;
						$psts->extend( $blog_id, $_POST['period'], self::get_slug(), $_POST['level'], $initAmount, $new_expire, false );
						$psts->email_notification( $blog_id, 'receipt' );

						if ( isset( $current_plan_level ) ) {
							if ( $current_plan_level > $_POST['level'] ) {
								$psts->record_stat( $blog_id, 'upgrade' );
							} else {
								$psts->record_stat( $blog_id, 'modify' );
							}
						} else {
							$psts->record_stat( $blog_id, 'signup' );
						}

					} catch ( Stripe_CardError $e ) {
						$body = $e->getJsonBody();
						$err  = $body['error'];
						$psts->errors->add( 'general', $e['message'] );
					} catch ( Exception $e ) {
						$psts->errors->add( 'general', __( 'An unknown error occurred while processing your payment. Please try again.', 'psts' ) );
					}

				}

				//delete the temporary coupon code
				if ( $cp_code ) {
					try {
						$cpn = Stripe_Coupon::retrieve( $cp_code );
						$cpn->delete();
					} catch ( Exception $e ) {
						wp_mail(
							get_blog_option( $blog_id, 'admin_email' ),
							__( 'Error deleting temporary Stripe coupon code. Attention required!.', 'psts' ),
							sprintf( __( 'An error occurred when attempting to delete temporary Stripe coupon code %1$s. You will need to manually delete this coupon via your Stripe account.', 'psts' ), $cp_code )
						);
					}

					$psts->use_coupon( $process_data['COUPON_CODE'], $blog_id, $domain );
				}

				if ( $new || $psts->is_blog_canceled( $blog_id ) ) {
					// Added for affiliate system link
					if ( $recurring ) {
						$psts->log_action( $blog_id, sprintf( __( 'User creating new subscription via CC: Subscription created (%1$s) - Customer ID: %2$s', 'psts' ), $desc, $customer_id ), $domain );
					} else {
						$psts->log_action( $blog_id, sprintf( __( 'User completed new payment via CC: Site created/extended (%1$s) - Customer ID: %2$s', 'psts' ), $desc, $customer_id ), $domain );
					}
					do_action( 'supporter_payment_processed', $blog_id, $paymentAmount, $_POST['period'], $_POST['level'] );
				} else {
					$psts->log_action( $blog_id, sprintf( __( 'User modifying subscription via CC: Plan changed to (%1$s) - %2$s', 'psts' ), $desc, $customer_id ), $domain );
				}

				//display GA ecommerce in footer
				$psts->create_ga_ecommerce( $blog_id, $_POST['period'], $initAmount, $_POST['level'], $site_name, $domain );

				if ( ! empty( $blog_id ) ) {
					update_blog_option( $blog_id, 'psts_stripe_canceled', 0 );
					/* 	some times there is a lag receiving webhooks from Stripe. we want to be able to check for that
						and display an appropriate message to the customer (e.g. there are changes pending to your account) */
					update_blog_option( $blog_id, 'psts_stripe_waiting', 1 );
				} else {

					if ( isset( $process_data['activation_key'] ) ) {

						//Update signup meta
						$key                                 = $process_data['activation_key'];
						$signup_meta                         = '';
						$signup_meta                         = $psts->get_signup_meta( $key );
						$signup_meta['psts_stripe_canceled'] = 0;
						$signup_meta['psts_stripe_waiting']  = 1;
						$psts->update_signup_meta( $signup_meta, $key );
					}
				}

				update_blog_option( $blog_id, 'psts_stripe_waiting', 1 );
				if ( empty( self::$complete_message ) ) {
					self::$complete_message = __( 'Your payment was successfully recorded! You should be receiving an email receipt shortly.', 'psts' );
				}

			} catch ( Exception $e ) {
				$psts->errors->add( 'general', $e->getMessage() );
			}

		}

	}

	/**
	 * Populate an array with existing user information
	 *
	 * Array will be rendered by ProSites_View_Front_Gateway
	 *
	 * @param $blog_id
	 * @param $domain
	 * @param bool $get_all
	 *
	 * @return array|bool
	 */
	public static function get_existing_user_information( $blog_id, $domain, $get_all = true ) {
		global $psts;
		$args     = array();
		$img_base = $psts->plugin_url . 'images/';

		$trialing = ProSites_Helper_Registration::is_trial( $blog_id );
		if ( $trialing ) {
			$args['trial'] = '<div id="psts-general-error" class="psts-warning">' . __( 'You are still within your trial period. Once your trial finishes your account will be automatically charged.', 'psts' ) . '</div>';
		}

		// Pending information
		/**
		 * @todo: Hook psts_blog_info_pending (Front/Gateway.php)
		 */
		if ( ! empty( $blog_id ) && 1 == get_blog_option( $blog_id, 'psts_stripe_waiting' ) ) {
			$args['pending'] = '<div id="psts-general-error" class="psts-warning">' . __( 'There are pending changes to your account. This message will disappear once these pending changes are completed.', 'psts' ) . '</div>';
		}

		// Successful payment
		if ( self::$complete_message ) {
			// @todo: Hook psts_blog_info_complete_message
			$args['complete_message'] = '<div id="psts-complete-msg">' . self::$complete_message . '</div>';
			// @todo: Hook psts_blog_info_thanks_message
			$args['thanks_message']   = '<p>' . $psts->get_setting( 'stripe_thankyou' ) . '</p>';

			//If Checking out on signup, there wouldn't be a blogid probably
//			if ( ! empty ( $domain ) ) {
//				//Hardcoded, TODO: Search for alternative
//				$admin_url = is_ssl() ? trailingslashit( "https://$domain" ) . 'wp-admin/' : trailingslashit( "http://$domain" ) . 'wp-admin/';
//				$args['visit_site_message'] = '<p><a href="' . $admin_url . '">' . __( 'Visit your newly upgraded site &raquo;', 'psts' ) . '</a></p>';
//			} else {
			$args['visit_site_message'] = '<p><a href="' . get_admin_url( $blog_id, '', 'http' ) . '">' . __( 'Go to your site &raquo;', 'psts' ) . '</a></p>';
//			}
			self::$complete_message = false;
		}

		// Cancellation message
		if ( self::$cancel_message ) {
			$args['cancel']               = true;
			$args['cancellation_message'] = self::$cancel_message;
			self::$cancel_message         = false;
		}

		// Existing customer information --- only if $get_all is true (default)
		$customer_id = self::get_customer_data( $blog_id )->customer_id;
		if ( ! empty( $customer_id ) && $get_all ) {

			try {
				$customer_object = Stripe_Customer::retrieve( $customer_id );
			} catch ( Exception $e ) {
				$error = $e->getMessage();
			}

			// Move to render info class
			$end_date = date_i18n( get_option( 'date_format' ), $psts->get_expire( $blog_id ) );
			$level    = $psts->get_level_setting( $psts->get_level( $blog_id ), 'name' );

			$is_recurring      = $psts->is_blog_recurring( $blog_id );
			$args['recurring'] = $is_recurring;
			// If invoice cant be created, its not looking good. Cancel.
			try {
				$invoice_object = Stripe_Invoice::upcoming( array( "customer" => $customer_id ) );
			} catch ( Exception $e ) {
				if ( $is_recurring ) {
					$args['cancel']               = true;
					$args['cancellation_message'] = '<div class="psts-cancel-notification">
													<p class="label"><strong>' . __( 'Your subscription has been canceled', 'psts' ) . '</strong></p>
													<p>' . sprintf( __( 'This site should continue to have %1$s features until %2$s.', 'psts' ), $level, $end_date ) . '</p>';
				}
			}

			$args['level']   = $level;
			$args['expires'] = $end_date;

			// All good, keep populating the array.
			if ( ! isset( $args['cancel'] ) ) {

				// Get the last valid card
				if ( isset( $customer_object->cards->data[0] ) && isset( $customer_object->default_card ) ) {
					foreach ( $customer_object->cards->data as $tmpcard ) {
						if ( $tmpcard->id == $customer_object->default_card ) {
							$card = $tmpcard;
							break;
						}
					}
				} elseif ( isset( $customer_object->active_card ) ) { //for API pre 2013-07-25
					$card = $customer_object->active_card;
				}
				$args['card_type']           = $card->brand;
				$args['card_reminder']       = $card->last4;
				$args['card_digit_location'] = 'end';
				$args['card_expire_month']   = $card->exp_month;
				$args['card_expire_year']    = $card->exp_year;

				// Get the period
				$plan_parts     = explode( '_', $customer_object->subscriptions->data[0]->plan->id );
				$period         = array_pop( $plan_parts );
				$args['period'] = $period;

				// Get last payment date
				try {
					$existing_invoice_object = Stripe_Invoice::all( array( "customer" => $customer_id, "count" => 1 ) );
				} catch ( Exception $e ) {
					$error = $e->getMessage();
				}
				if ( isset( $existing_invoice_object->data[0] ) && $customer_object->subscriptions->data[0]->status != 'trialing' ) {
					$args['last_payment_date'] = $existing_invoice_object->data[0]->date;
				}
				// Get next payment date
				if ( isset( $invoice_object->next_payment_attempt ) ) {
					$args['next_payment_date'] = $invoice_object->next_payment_attempt;
				}
				// Cancellation link
				if ( $is_recurring ) {
					if ( is_pro_site( $blog_id ) ) {
						$args['cancel_info'] = '<p class="prosites-cancel-description">' . sprintf( __( 'If you choose to cancel your subscription this site should continue to have %1$s features until %2$s.', 'psts' ), $level, $end_date ) . '</p>';
						$cancel_label        = __( 'Cancel Your Subscription', 'psts' );
						// CSS class of <a> is important to handle confirmations
						$args['cancel_link'] = '<p class="prosites-cancel-link"><a class="cancel-prosites-plan button" href="' . wp_nonce_url( $psts->checkout_url( $blog_id ) . '&action=cancel', 'psts-cancel' ) . '" title="' . esc_attr( $cancel_label ) . '">' . esc_html( $cancel_label ) . '</a></p>';
					}
				}

				// Receipt form
				$args['receipt_form'] = $psts->receipt_form( $blog_id );

			}

			// Show all is true
			$args['all_fields'] = true;
		}

		return empty( $args ) ? array() : $args;
	}

	public static function get_merchant_countries() {
		return array(
			'AU' => 'Australia',
			'CA' => 'Canada',
			'IE' => 'Ireland',
			'UK' => 'United Kingdom',
			'US' => 'United States',
			'BE' => 'Belgium (Beta)',
			'FI' => 'Finland (Beta)',
			'FR' => 'France (Beta)',
			'DE' => 'Germany (Beta)',
			'LU' => 'Luxembourg (Beta)',
			'NL' => 'Netherlands (Beta)',
			'ES' => 'Spain (Beta)',
			'DK' => 'Denmark (Beta)',
			'NO' => 'Norway (Beta)',
			'SE' => 'Sweden (Beta)',
			'AT' => 'Austria (Beta)',
			'IT' => 'Italy (Beta)',
			'CH' => 'Switzerland (Private Beta)',
		);
	}

	public static function get_supported_currencies() {

		return array(
			'AED' => array( 'United Arab Emirates Dirham', '62F, 2E ,625' ),
			'AFN' => array( 'Afghan Afghani', '60b' ),
			'ALL' => array( 'Albanian Lek', '4c, 65, 6b' ),
			'AMD' => array( 'Armenian Dram', '58F' ),
			'ANG' => array( 'Netherlands Antillean Gulden', '192' ),
			'AOA' => array( 'Angolan Kwanza', '4B, 7A' ),
			'ARS' => array( 'Argentine Peso', '24' ),
			'AUD' => array( 'Australian Dollar', '24' ),
			'AWG' => array( 'Aruban Florin', '192' ),
			'AZN' => array( 'Azerbaijani Manat', '43c, 430, 43d' ),
			'BAM' => array( 'Bosnia & Herzegovina Convertible Mark', '4b, 4d' ),
			'BBD' => array( 'Barbadian Dollar', '24' ),
			'BDT' => array( 'Bangladeshi Taka', '09F3' ),
			'BGN' => array( 'Bulgarian Lev', '43b, 432' ),
			'BIF' => array( 'Burundian Franc', '46, 42, 75' ),
			'BMD' => array( 'Bermudian Dollar', '24' ),
			'BND' => array( 'Brunei Dollar', '24' ),
			'BOB' => array( 'Bolivian Boliviano', '24, 62' ),
			'BRL' => array( 'Brazilian Real', '52, 24' ),
			'BSD' => array( 'Bahamian Dollar', '24' ),
			'BWP' => array( 'Botswana Pula', '50' ),
			'BZD' => array( 'Belize Dollar', '42, 5a, 24' ),
			'CAD' => array( 'Canadian Dollar', '24' ),
			'CDF' => array( 'Congolese Franc', '46, 43' ),
			'CHF' => array( 'Swiss Franc', '43, 48, 46' ),
			'CLP' => array( 'Chilean Peso', '24' ),
			'CNY' => array( 'Chinese Renminbi Yuan', 'a5' ),
			'COP' => array( 'Colombian Peso', '24' ),
			'CRC' => array( 'Costa Rican Colón', '20a1' ),
			'CVE' => array( 'Cape Verdean Escudo', '24' ),
			'CZK' => array( 'Czech Koruna', '4b, 10d' ),
			'DJF' => array( 'Djiboutian Franc', '46, 64, 6A' ),
			'DKK' => array( 'Danish Krone', '6b, 72' ),
			'DOP' => array( 'Dominican Peso', '52, 44, 24' ),
			'DZD' => array( 'Algerian Dinar', '62F, 62C' ),
			'EEK' => array( 'Estonian Kroon', '6b, 72' ),
			'EGP' => array( 'Egyptian Pound', 'a3' ),
			'ETB' => array( 'Ethiopian Birr', '1265, 122D' ),
			'EUR' => array( 'Euro', '20ac' ),
			'FJD' => array( 'Fijian Dollar', '24' ),
			'FKP' => array( 'Falkland Islands Pound', 'a3' ),
			'GBP' => array( 'British Pound', 'a3' ),
			'GEL' => array( 'Georgian Lari', '10DA' ),
			'GIP' => array( 'Gibraltar Pound', 'a3' ),
			'GMD' => array( 'Gambian Dalasi', '44' ),
			'GNF' => array( 'Guinean Franc', '46, 47' ),
			'GTQ' => array( 'Guatemalan Quetzal', '51' ),
			'GYD' => array( 'Guyanese Dollar', '24' ),
			'HKD' => array( 'Hong Kong Dollar', '24' ),
			'HNL' => array( 'Honduran Lempira', '4c' ),
			'HRK' => array( 'Croatian Kuna', '6b, 6e' ),
			'HTG' => array( 'Haitian Gourde', '47' ),
			'HUF' => array( 'Hungarian Forint', '46, 74' ),
			'IDR' => array( 'Indonesian Rupiah', '52, 70' ),
			'ILS' => array( 'Israeli New Sheqel', '20aa' ),
			'INR' => array( 'Indian Rupee', '20B9' ),
			'ISK' => array( 'Icelandic Króna', '6b, 72' ),
			'JMD' => array( 'Jamaican Dollar', '4a, 24' ),
			'JPY' => array( 'Japanese Yen', 'a5' ),
			'KES' => array( 'Kenyan Shilling', '4B, 53, 68' ),
			'KGS' => array( 'Kyrgyzstani Som', '43b, 432' ),
			'KHR' => array( 'Cambodian Riel', '17db' ),
			'KMF' => array( 'Comorian Franc', '43, 46' ),
			'KRW' => array( 'South Korean Won', '20a9' ),
			'KYD' => array( 'Cayman Islands Dollar', '24' ),
			'KZT' => array( 'Kazakhstani Tenge', '43b, 432' ),
			'LAK' => array( 'Lao Kip', '20ad' ),
			'LBP' => array( 'Lebanese Pound', 'a3' ),
			'LKR' => array( 'Sri Lankan Rupee', '20a8' ),
			'LRD' => array( 'Liberian Dollar', '24' ),
			'LSL' => array( 'Lesotho Loti', '4C' ),
			'LTL' => array( 'Lithuanian Litas', '4c, 74' ),
			'LVL' => array( 'Latvian Lats', '4c, 73' ),
			'MAD' => array( 'Moroccan Dirham', '62F, 2E, 645, 2E' ),
			'MDL' => array( 'Moldovan Leu', '6C, 65, 69' ),
			'MGA' => array( 'Malagasy Ariary', '41, 72' ),
			'MKD' => array( 'Macedonian Denar', '434, 435, 43d' ),
			'MNT' => array( 'Mongolian Tögrög', '20ae' ),
			'MOP' => array( 'Macanese Pataca', '4D, 4F, 50, 24' ),
			'MRO' => array( 'Mauritanian Ouguiya', '55, 4D' ),
			'MUR' => array( 'Mauritian Rupee', '20a8' ),
			'MVR' => array( 'Maldivian Rufiyaa', '52, 66' ),
			'MWK' => array( 'Malawian Kwacha', '4D, 4B' ),
			'MXN' => array( 'Mexican Peso', '24' ),
			'MYR' => array( 'Malaysian Ringgit', '52, 4d' ),
			'MZN' => array( 'Mozambican Metical', '4d, 54' ),
			'NAD' => array( 'Namibian Dollar', '24' ),
			'NGN' => array( 'Nigerian Naira', '20a6' ),
			'NIO' => array( 'Nicaraguan Córdoba', '43, 24' ),
			'NOK' => array( 'Norwegian Krone', '6b, 72' ),
			'NPR' => array( 'Nepalese Rupee', '20a8' ),
			'NZD' => array( 'New Zealand Dollar', '24' ),
			'PAB' => array( 'Panamanian Balboa', '42, 2f, 2e' ),
			'PEN' => array( 'Peruvian Nuevo Sol', '53, 2f, 2e' ),
			'PGK' => array( 'Papua New Guinean Kina', '4B' ),
			'PHP' => array( 'Philippine Peso', '20b1' ),
			'PKR' => array( 'Pakistani Rupee', '20a8' ),
			'PLN' => array( 'Polish Złoty', '7a, 142' ),
			'PYG' => array( 'Paraguayan Guaraní', '47, 73' ),
			'QAR' => array( 'Qatari Riyal', 'fdfc' ),
			'RON' => array( 'Romanian Leu', '6c, 65, 69' ),
			'RSD' => array( 'Serbian Dinar', '414, 438, 43d, 2e' ),
			'RUB' => array( 'Russian Ruble', '440, 443, 431' ),
			'RWF' => array( 'Rwandan Franc', '52, 20A3' ),
			'SAR' => array( 'Saudi Riyal', 'fdfc' ),
			'SBD' => array( 'Solomon Islands Dollar', '24' ),
			'SCR' => array( 'Seychellois Rupee', '20a8' ),
			'SEK' => array( 'Swedish Krona', '6b, 72' ),
			'SGD' => array( 'Singapore Dollar', '24' ),
			'SHP' => array( 'Saint Helenian Pound', 'a3' ),
			'SLL' => array( 'Sierra Leonean Leone', '4C, 65' ),
			'SOS' => array( 'Somali Shilling', '53' ),
			'SRD' => array( 'Surinamese Dollar', '24' ),
			'STD' => array( 'São Tomé and Príncipe Dobra', '44, 62' ),
			'SVC' => array( 'Salvadoran Colón', '24' ),
			'SZL' => array( 'Swazi Lilangeni', '45' ),
			'THB' => array( 'Thai Baht', 'e3f' ),
			'TJS' => array( 'Tajikistani Somoni', '73, 6F, 6D, 6F, 6E, 69' ),
			'TOP' => array( 'Tongan Paʻanga', '54, 24' ),
			'TRY' => array( 'Turkish Lira', '20BA' ),
			'TTD' => array( 'Trinidad and Tobago Dollar', '54, 54, 24' ),
			'TWD' => array( 'New Taiwan Dollar', '4e, 54, 24' ),
			'TZS' => array( 'Tanzanian Shilling', '78, 2F, 79' ),
			'UAH' => array( 'Ukrainian Hryvnia', '20b4' ),
			'UGX' => array( 'Ugandan Shilling', '55, 53, 68' ),
			'USD' => array( 'United States Dollar', '24' ),
			'UYU' => array( 'Uruguayan Peso', '24, 55' ),
			'UZS' => array( 'Uzbekistani Som', '43b, 432' ),
			'VND' => array( 'Vietnamese Đồng', '20ab' ),
			'VUV' => array( 'Vanuatu Vatu', '56, 54' ),
			'WST' => array( 'Samoan Tala', '24' ),
			'XAF' => array( 'Central African Cfa Franc', '46, 43, 46, 41' ),
			'XCD' => array( 'East Caribbean Dollar', '24' ),
			'XOF' => array( 'West African Cfa Franc', '43, 46, 41' ),
			'XPF' => array( 'Cfp Franc', '46' ),
			'YER' => array( 'Yemeni Rial', 'fdfc' ),
			'ZAR' => array( 'South African Rand', '52' ),
			'ZMW' => array( 'Zambian Kwacha', '4B' ),
		);
	}

}

//register the gateway
psts_register_gateway( 'ProSites_Gateway_Stripe', __( 'Stripe', 'psts' ), __( 'Stripe handles everything, including storing cards, subscriptions, and direct payouts to your bank account.', 'psts' ) );
