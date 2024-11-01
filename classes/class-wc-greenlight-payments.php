<?php
/**
 *
 */

class WC_Greenlight_Payments extends WC_Payment_Gateway_CC {

	var $terminal_code;
	var $url;
	var $transaction_type;

	public function __construct() {
		$this->id                 = 'greenlight_payment';
		$this->icon               = '';
		$this->has_fields         = false;
		$this->method_title       = __( 'Greenlight Payment Gateway', 'wc-greenlight-gateway' );
		$this->method_description = __( 'Allows your store to use the Greenlight Payment method.', 'wc-greenlight-gateway' );
		$this->title              = $this->get_option( 'title' );
		$this->description        = $this->get_option( 'description' );
		$this->terminal_code      = $this->get_option( 'terminal_code' );
		$this->url                = $this->get_option( 'environment' ) === 'yes' ? 'https://dev.securetxn.com/web-api/transaction/transaction.php' : 'https://securetxn.com/web-api/transaction/transaction.php';
		$this->transaction_type   = $this->get_option( 'transaction_type' );
		$this->supports           = array(
			'products',
			'refunds',
			'default_credit_card_form',
		);
		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		/**
		 * Plugin actions.
		 */
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields() {
		$this->form_fields = apply_filters(
			'wc_greenlight_payment_fields',
			array(
				'enabled'          => array(
					'title'   => __( 'Enable/Disable', 'wc-greenlight-gateway' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable Greenlight Payment', 'wc-greenlight-gateway' ),
					'default' => 'yes',
				),
				'environment'      => array(
					'title'   => __( 'Enable sandbox environment', 'wc-greenlight-gateway' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable testing environment', 'wc-greenlight-gateway' ),
					'default' => 'yes',
				),
				'title'            => array(
					'title'       => __( 'Title', 'wc-greenlight-gateway' ),
					'type'        => 'text',
					'value'       => '',
					'description' => __( 'This controls the title for the payment method the customer sees during checkout.', 'wc-greenlight-gateway' ),
					'default'     => __( 'Pay with credit card', 'wc-greenlight-gateway' ),
					'desc_tip'    => true,
				),
				'description'      => array(
					'title'       => __( 'Description', 'wc-greenlight-gateway' ),
					'type'        => 'textarea',
					'description' => __( 'Payment method description that the customer will see on your checkout.', 'wc-greenlight-gateway' ),
					'default'     => '',
					'desc_tip'    => true,
				),
				'terminal_code'    => array(
					'title'       => __( 'Terminal Code', 'wc-greenlight-gateway' ),
					'type'        => 'text',
					'description' => __( 'The terminal code from greenlight', 'wc-greenlight-gateway' ),
					'default'     => '',
					'desc_tip'    => true,
				),
				'transaction_type' => array(
					'title'    => __( 'Transaction Type', 'wc-greenlight-gateway' ),
					'type'     => 'select',
					'options'  => array(
						'sale'      => 'Sale',
						'authorize' => 'Authorize',
						'capture'   => 'Capture',
					),
					'default'  => '1',
					'desc_tip' => true,
				),
			)
		);
	}

	/**
	 * Process the payment and return the result
	 *
	 * @param int $order_id  Created order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		if ( empty( preg_replace( '/\D/', '', $_REQUEST[ $this->id . '-card-number' ] ) ) || empty( preg_replace( '/\D/', '', $_REQUEST[ $this->id . '-card-expiry' ] ) ) || empty( preg_replace( '/\D/', '', $_REQUEST[ $this->id . '-card-cvc' ] ) ) ) {
			wc_add_notice( 'Credit card details required.', 'error' );
			return array(
				'result' => 'error',
			);
		}
		$order = wc_get_order( $order_id );
		$data = array();

		foreach ( $_POST as $key => $value ) {
			$data[ $key ] = sanitize_text_field( $value );
		}

		$data['total'] = number_format( $order->get_total(), 2, ',', '' );
		$body          = $this->prepare_payment_request( $data );
		$response      = $this->make_request( $body, $order );

		return $this->process_payment_response( $response, $order );
	}

	protected function process_payment_response( $response, WC_Order $order ) {
		if ( 'Approved' === $response['response'] && 'Approved' === $response['transaction_status'] ) {
			$order->payment_complete( $response['transaction_id'] );
			$order->update_status( 'processing', __( 'Payment completed', 'wc-greenlight-gateway' ) );
			$order->add_order_note( 'Response Code ' . $response['response_code'] );
			$return = array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		} else {
			wc_add_notice( 'Issue with processing payment.', 'error' );
			$order->update_status( 'on-hold', __( 'Issue with processing payment', 'wc-greenlight-gateway' ) . '<br>Response Code: ' . $response['response_code'] . '<br>Response: ' . $response['response'] . '<br>Transaction Status: ' . $response['transaction_status'] );
			$return = array(
				'result' => 'error',
			);
		}
		return $return;
	}

	protected function prepare_payment_request( $data ) {
		return array(
			'tender_type'     => 'credit',
			'action'          => $this->transaction_type,
			'terminal_code'   => $this->terminal_code,
			'card_number'     => preg_replace( '/\D/', '', $data[ $this->id . '-card-number' ] ),
			'exp'             => preg_replace( '/\D/', '', $data[ $this->id . '-card-expiry' ] ),
			'cvv'             => preg_replace( '/\D/', '', $data[ $this->id . '-card-cvc' ] ),
			'amount'          => $data['total'],
			'cardholder_name' => substr( $data['billing_first_name'] . ' ' . $data['billing_last_name'], 0, 25 ),
			'phone'           => substr( $data['billing_phone'], 0, 10 ),
			'email'           => substr( $data['billing_email'], 0, 30 ),
			'street1'         => substr( $data['billing_address_1'] . ' ' . $data['billing_address_2'], 0, 30 ),
			'city'            => substr( $data['billing_city'], 0, 25 ),
			'state'           => substr( $data['billing_state'], 0, 2 ),
			'postal_code'     => substr( $data['billing_postcode'], 0, 9 ),
		);
	}

	protected function make_request( $body, WC_Order $order = null ) {
		$response = wp_remote_post(
			$this->url,
			array(
				'method'      => 'POST',
				'timeout'     => 45,
				'redirection' => 5,
				'blocking'    => true,
				'body'        => $body,
			)
		);
		$order->add_order_note( print_r( wp_remote_retrieve_body( $response ), true ) );
		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			if ( $order ) {
				$order->add_order_note( $error_message );
			} else {
				echo "Something went wrong: $error_message";
			}
		} else {
			// print_r( $response );
			return json_decode( wp_remote_retrieve_body( $response ), true );
		}
	}

	/**
	 * Refund transaction
	 *
	 * @param  int   $order_id order id.
	 * @param  float $amount   order amount.
	 * @return bool           status of refund
	 */
	public function process_refund( $order_id, $amount = null, $message = '' ) {
		$order = wc_get_order( $order_id );

		if ( ! $this->can_refund_order( $order ) ) {
			return new WP_Error( 'error', __( 'Refund failed.', 'woocommerce' ) );
		}

		if ( null === $amount ) {
			$amount = $order->get_total();
		}

		if ( intval( $amount ) === intval( $order->get_total() ) && $this->can_be_voided( $order ) ) {
			// full refund
			$body = $this->prepare_void_request( $order->get_transaction_id() );
		} else {
			$body = $this->prepare_refund_request( $order->get_transaction_id(), $amount );
		}

		$response = $this->make_request( $body, $order );

		if ( 'Approved' === $response['response'] ) {
			$order->add_order_note(
				/* translators: 1: Refund amount, 2: Refund ID */
				sprintf( __( 'Refunded %1$s - Refund ID: %2$s', 'woocommerce' ), $amount, $response['transaction_id'] ) // phpcs:ignore WordPress.NamingConventions.ValidVariableName.NotSnakeCaseMemberVar
			);
			return true;
		} else {
			return false;
		}
	}

	protected function prepare_refund_request( $transaction_id, $amount ) {
		return array(
			'tender_type'    => 'credit',
			'action'         => 'refund',
			'terminal_code'  => $this->terminal_code,
			'amount'         => $amount,
			'transaction_id' => $transaction_id,
		);
	}

	protected function prepare_void_request( $transaction_id ) {
		return array(
			'tender_type'    => 'credit',
			'action'         => 'void',
			'terminal_code'  => $this->terminal_code,
			'transaction_id' => $transaction_id,
		);
	}

	protected function can_be_voided( WC_Order $order ) {
		if ( date( 'Y-m-d', strtotime( $order->get_date_created() ) ) === date( 'Y-m-d' ) && intval( date( 'H' ) ) < 23 ) {
			$order->add_order_note( 'Order Voided' );
			return true;
		}
		$order->add_order_note( 'Order Refunded' );
		return false;
	}

	/**
	 * Outputs fields for entering credit card information.
	 *
	 * @since 2.6.0
	 */
	public function form() {
		wp_enqueue_script( 'wc-credit-card-form' );

		$fields = array();

		$cvc_field = '<p class="form-row form-row-last validate-required">
			<label for="' . esc_attr( $this->id ) . '-card-cvc">' . esc_html__( 'Card code', 'woocommerce' ) . '&nbsp;<span class="required">*</span></label>
			<input id="' . esc_attr( $this->id ) . '-card-cvc" class="input-text wc-credit-card-form-card-cvc" inputmode="numeric" autocomplete="off" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" maxlength="4" placeholder="' . esc_attr__( 'CVC', 'woocommerce' ) . '" ' . $this->field_name( 'card-cvc' ) . ' style="width:100px" />
		</p>';

		$default_fields = array(
			'card-number-field' => '<p class="form-row form-row-wide validate-required">
				<label for="' . esc_attr( $this->id ) . '-card-number">' . esc_html__( 'Card number', 'woocommerce' ) . '&nbsp;<span class="required">*</span></label>
				<input id="' . esc_attr( $this->id ) . '-card-number" class="input-text wc-credit-card-form-card-number" inputmode="numeric" autocomplete="cc-number" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" placeholder="&bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull;" ' . $this->field_name( 'card-number' ) . ' />
			</p>',
			'card-expiry-field' => '<p class="form-row form-row-first validate-required" >
				<label for="' . esc_attr( $this->id ) . '-card-expiry">' . esc_html__( 'Expiry (MM/YY)', 'woocommerce' ) . '&nbsp;<span class="required">*</span></label>
				<input id="' . esc_attr( $this->id ) . '-card-expiry" class="input-text wc-credit-card-form-card-expiry" inputmode="numeric" autocomplete="cc-exp" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" placeholder="' . esc_attr__( 'MM / YY', 'woocommerce' ) . '" ' . $this->field_name( 'card-expiry' ) . ' />
			</p>',
		);

		if ( ! $this->supports( 'credit_card_form_cvc_on_saved_method' ) ) {
			$default_fields['card-cvc-field'] = $cvc_field;
		}

		$fields = wp_parse_args( $fields, apply_filters( 'woocommerce_credit_card_form_fields', $default_fields, $this->id ) );
		?>

		<fieldset id="wc-<?php echo esc_attr( $this->id ); ?>-cc-form" class='wc-credit-card-form wc-payment-form'>
			<?php do_action( 'woocommerce_credit_card_form_start', $this->id ); ?>
			<?php
			foreach ( $fields as $field ) {
				echo $field; // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped
			}
			?>
			<?php do_action( 'woocommerce_credit_card_form_end', $this->id ); ?>
			<div class="clear"></div>
		</fieldset>
		<?php

		if ( $this->supports( 'credit_card_form_cvc_on_saved_method' ) ) {
			echo '<fieldset>' . $cvc_field . '</fieldset>'; // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped
		}
	}
}
