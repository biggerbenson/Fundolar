<?php
/**
 * Main plugin controller.
 *
 * @package Fundolar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Fundolar_Plugin
 */
class Fundolar_Plugin {

	/**
	 * Instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Singleton.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Fundolar_Plugin constructor.
	 */
	private function __construct() {
		Fundolar_Plugin_Information::init();
		add_action( 'plugins_loaded', array( 'Fundolar_Migration', 'boot' ), 5 );
		add_action( 'init', array( $this, 'load_i18n' ) );
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'admin_init', array( $this, 'maybe_sync_historical_donations' ) );
		add_action( 'admin_init', array( $this, 'maybe_sync_gateways_admin' ) );
		add_action( 'admin_init', array( $this, 'maybe_sync_pending_deletions' ) );
		add_action( 'fundolar_platform_heartbeat', array( 'Fundolar_Platform', 'run_heartbeat' ) );
		add_action( 'rest_api_init', array( 'Fundolar_REST', 'register' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
		add_action( 'wp_dashboard_setup', array( 'Fundolar_Dashboard_Widget', 'register' ) );
		add_action( 'admin_menu', array( 'Fundolar_Admin', 'menu' ) );
		add_action( 'admin_init', array( 'Fundolar_Admin', 'register_settings' ) );
		add_action( 'wp_ajax_fundolar_support', array( 'Fundolar_Admin', 'ajax_support_request' ) );
		add_action( 'template_redirect', array( $this, 'gateway_returns' ), 5 );
		add_filter( 'plugin_action_links_' . plugin_basename( FUNDOLAR_PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Activation: DB table.
	 */
	public static function activate() {
		Fundolar_Migration::boot();
		Fundolar_DB::create_tables();
		if ( ! get_option( Fundolar_Payments::OPTION ) ) {
			update_option(
				Fundolar_Payments::OPTION,
				array(
					'enabled_gateways'   => array(),
					'payment_mode'       => Fundolar_Payments::MODE_CENTRAL,
					'preset_amounts'     => array( 10, 20, 50, 100, 200 ),
					'platform_base_url' => Fundolar_Platform::PLATFORM_BASE_URL,
				)
			);
		}
		if ( ! wp_next_scheduled( 'fundolar_platform_heartbeat' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'fundolar_platform_heartbeat' );
		}
	}

	/**
	 * Clear scheduled events.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'fundolar_platform_heartbeat' );
	}

	/**
	 * Load translations.
	 */
	public function load_i18n() {
		load_plugin_textdomain( 'fundolar', false, dirname( plugin_basename( FUNDOLAR_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Shortcode.
	 */
	public function register_shortcode() {
		add_shortcode( 'fundolar_donate', array( 'Fundolar_Form', 'shortcode' ) );
		add_shortcode( Fundolar_Migration::LEGACY_SHORTCODE, array( 'Fundolar_Form', 'shortcode' ) );
	}

	/**
	 * Whether post content may include the donation shortcode (classic or block editor).
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function post_needs_form_assets( WP_Post $post ) {
		if ( has_shortcode( $post->post_content, 'fundolar_donate' )
			|| has_shortcode( $post->post_content, Fundolar_Migration::LEGACY_SHORTCODE ) ) {
			return true;
		}
		if ( function_exists( 'has_blocks' ) && has_blocks( $post->post_content ) && function_exists( 'parse_blocks' ) ) {
			return self::blocks_contain_fundolar_shortcode( parse_blocks( $post->post_content ) );
		}
		return false;
	}

	/**
	 * Walk block tree for Shortcode block containing fundolar_donate.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return bool
	 */
	private static function blocks_contain_fundolar_shortcode( array $blocks ) {
		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			if ( 'core/shortcode' === $block['blockName'] && ! empty( $block['innerHTML'] )
				&& ( false !== strpos( $block['innerHTML'], 'fundolar_donate' )
					|| false !== strpos( $block['innerHTML'], Fundolar_Migration::LEGACY_SHORTCODE ) ) ) {
				return true;
			}
			if ( ! empty( $block['innerBlocks'] ) && self::blocks_contain_fundolar_shortcode( $block['innerBlocks'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Enqueue and localize front-end form scripts (idempotent).
	 *
	 * @param string $return_url Preferred return URL after hosted checkout (fallback if JS cannot read location).
	 */
	public static function enqueue_form_assets( $return_url = '' ) {
		Fundolar_Platform::ensure_gateways_synced_for_display();
		wp_enqueue_style(
			'fundolar-form',
			FUNDOLAR_PLUGIN_URL . 'resources/css/fundolar-form.css',
			array(),
			FUNDOLAR_VERSION
		);
		wp_enqueue_script(
			'fundolar-form',
			FUNDOLAR_PLUGIN_URL . 'resources/js/fundolar-form.js',
			array( 'wp-api-fetch', 'wp-i18n' ),
			FUNDOLAR_VERSION,
			true
		);
		$s = Fundolar_Payments::get_settings();
		if ( '' === $return_url ) {
			$return_url = home_url( '/' );
		}
		$ready_gateways = Fundolar_Payments::gateways_ready_for_front();
		wp_localize_script(
			'fundolar-form',
			'fundolarForm',
			array(
				'nonce'        => wp_create_nonce( 'fundolar_donate' ),
				'restNonce'    => wp_create_nonce( 'wp_rest' ),
				'restUrl'      => esc_url_raw( rest_url( 'fundolar/v1/' ) ),
				'stripePk'     => $s['stripe_publishable'],
				'paypalClient' => $s['paypal_client_id'],
				'enabled'      => $ready_gateways,
				'syncedGateways' => array_values( array_unique( array_map( 'sanitize_key', (array) ( $s['enabled_gateways'] ?? array() ) ) ) ),
				'gatewayMeta'  => Fundolar_Payments::synced_gateway_meta(),
				'gatewayAssets' => Fundolar_Payments::gateway_assets_for_js( $ready_gateways ),
				'pesapalCurrencies' => Fundolar_Payments::pesapal_supported_currencies(),
				'fxBaseCurrency' => 'USD',
				'fxRates'      => Fundolar_Payments::fx_rates_usd_base(),
				'amounts'      => $s['preset_amounts'],
				'currency'     => $s['default_currency'],
				'primary'      => $s['color_primary'],
				'accent'       => $s['color_accent'],
				'returnUrl'    => esc_url_raw( $return_url ),
				'platformFeeRate' => Fundolar_Fees::effective_percentage_for_js(),
				'i18n'         => array(
					'loading'   => __( 'Processing…', 'fundolar' ),
					'error'     => __( 'Something went wrong. Please try again.', 'fundolar' ),
					'success'   => __( 'Thank you for your donation.', 'fundolar' ),
					'validation' => __( 'Please complete your name, email, and amount before continuing.', 'fundolar' ),
					'paystackKesOnly' => __( 'Paystack checkout is currently available only for KES.', 'fundolar' ),
					'pesapalCurrencyOnly' => __( 'Pesapal checkout is available only for mobile-money enabled currencies.', 'fundolar' ),
					'mobileMoneyUgxOnly' => __( 'Mobile Money (UG) is available only for UGX.', 'fundolar' ),
					'mobileMoneyPhone' => __( 'Enter your Uganda mobile money phone number.', 'fundolar' ),
					'mobileMoneyPending' => __( 'Check your phone and approve the Mobile Money prompt…', 'fundolar' ),
					'mobileMoneyFailed' => __( 'Mobile Money payment was not completed.', 'fundolar' ),
					'switchCurrencyForGateway' => __( 'Use %s', 'fundolar' ),
					'needsKeys' => __( 'This payment method is not available right now. Ask your platform admin to enable it in Fundolar Central, then sync gateways.', 'fundolar' ),
				),
			)
		);
	}

	/**
	 * Public assets when the main queried post includes the shortcode.
	 */
	public function enqueue_public() {
		if ( ! is_singular() ) {
			return;
		}
		global $post;
		if ( ! is_a( $post, 'WP_Post' ) || ! self::post_needs_form_assets( $post ) ) {
			return;
		}
		$url = get_permalink( $post ) ? get_permalink( $post ) : home_url( '/' );
		self::enqueue_form_assets( $url );
	}

	/**
	 * Admin assets for settings + widget.
	 *
	 * @param string $hook Hook.
	 */
	public function enqueue_admin( $hook ) {
		Fundolar_Plugin_Information::enqueue_thickbox_on_plugins_screen( $hook );
		if ( 'index.php' === $hook ) {
			wp_enqueue_style( 'fundolar-admin', FUNDOLAR_PLUGIN_URL . 'resources/css/fundolar-admin.css', array(), FUNDOLAR_VERSION );
			wp_enqueue_script(
				'fundolar-dashboard',
				FUNDOLAR_PLUGIN_URL . 'resources/js/fundolar-dashboard.js',
				array(),
				FUNDOLAR_VERSION,
				true
			);
			$series = Fundolar_DB::daily_series( 14 );
			$kpi    = Fundolar_DB::kpi_snapshot( 30 );
			wp_localize_script(
				'fundolar-dashboard',
				'fundolarDash',
				array(
					'labels' => $series['labels'],
					'values' => $series['values'],
					'kpi'    => $kpi,
				)
			);
		}
		if ( false !== strpos( $hook, 'fundolar' ) ) {
			wp_enqueue_style( 'fundolar-admin', FUNDOLAR_PLUGIN_URL . 'resources/css/fundolar-admin.css', array(), FUNDOLAR_VERSION );
			wp_enqueue_script(
				'fundolar-admin',
				FUNDOLAR_PLUGIN_URL . 'resources/js/fundolar-admin.js',
				array(),
				FUNDOLAR_VERSION,
				true
			);
			wp_localize_script(
				'fundolar-admin',
				'fundolarAdminL10n',
				array(
					'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
					'supportNonce' => wp_create_nonce( 'fundolar_support' ),
					'copied'       => __( 'Copied!', 'fundolar' ),
					'copyFailed'   => __( 'Could not copy', 'fundolar' ),
					'supportThanks' => __( 'Thank you — we have received your message.', 'fundolar' ),
					'supportError' => __( 'Something went wrong. Please try again.', 'fundolar' ),
				)
			);
		}
	}

	/**
	 * Gateway return handling.
	 */
	public function gateway_returns() {
		$gateway_param = '';
		if ( ! empty( $_GET['fundolar_gateway'] ) ) {
			$gateway_param = 'fundolar_gateway';
		} elseif ( ! empty( $_GET[ Fundolar_Migration::LEGACY_GATEWAY_PARAM ] ) ) {
			$gateway_param = Fundolar_Migration::LEGACY_GATEWAY_PARAM;
		}
		if ( '' === $gateway_param ) {
			return;
		}
		$g = isset( $_GET[ $gateway_param ] ) ? sanitize_key( wp_unslash( $_GET[ $gateway_param ] ) ) : '';
		if ( 'paystack' === $g ) {
			$ref = '';
			if ( isset( $_GET['reference'] ) ) {
				$ref = sanitize_text_field( wp_unslash( $_GET['reference'] ) );
			} elseif ( isset( $_GET['trxref'] ) ) {
				$ref = sanitize_text_field( wp_unslash( $_GET['trxref'] ) );
			}
			if ( $ref ) {
				Fundolar_Payments::paystack_verify_and_update( $ref );
			}
			wp_safe_redirect( remove_query_arg( array( 'fundolar_gateway', Fundolar_Migration::LEGACY_GATEWAY_PARAM, 'reference', 'trxref' ) ) );
			exit;
		}
		if ( 'flutterwave' === $g ) {
			$tx  = isset( $_GET['tx_ref'] ) ? sanitize_text_field( wp_unslash( $_GET['tx_ref'] ) ) : '';
			$tid = isset( $_GET['transaction_id'] ) ? sanitize_text_field( wp_unslash( $_GET['transaction_id'] ) ) : '';
			if ( $tx && $tid ) {
				Fundolar_Payments::flutterwave_verify_and_update( $tx, $tid );
			}
			wp_safe_redirect( remove_query_arg( array( 'fundolar_gateway', Fundolar_Migration::LEGACY_GATEWAY_PARAM, 'tx_ref', 'transaction_id', 'status' ) ) );
			exit;
		}
		if ( 'pesapal' === $g ) {
			$tracking  = isset( $_GET['OrderTrackingId'] ) ? sanitize_text_field( wp_unslash( $_GET['OrderTrackingId'] ) ) : '';
			$reference = isset( $_GET['OrderMerchantReference'] ) ? sanitize_text_field( wp_unslash( $_GET['OrderMerchantReference'] ) ) : '';
			if ( $tracking ) {
				Fundolar_Payments::pesapal_verify_and_update( $tracking, $reference );
			}
			wp_safe_redirect( remove_query_arg( array( 'fundolar_gateway', Fundolar_Migration::LEGACY_GATEWAY_PARAM, 'OrderTrackingId', 'OrderMerchantReference', 'OrderNotificationType' ) ) );
			exit;
		}
	}

	/**
	 * Refresh gateway settings when viewing Fundolar admin screens.
	 */
	public function maybe_sync_gateways_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( '' === $page || false === strpos( $page, 'fundolar' ) ) {
			return;
		}
		Fundolar_Platform::ensure_gateways_synced_for_display();
	}

	/**
	 * Pull Central donation deletions into the local transaction log.
	 */
	public function maybe_sync_pending_deletions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! Fundolar_Payments::is_central_connected() ) {
			return;
		}
		if ( get_transient( 'fundolar_deletion_sync_lock' ) ) {
			return;
		}
		set_transient( 'fundolar_deletion_sync_lock', 1, 120 );
		Fundolar_Platform::sync_pending_deletions();
		Fundolar_Platform::reconcile_central_usd_ledger( 50 );
	}

	/**
	 * Background sync for sites connected after a legacy import.
	 */
	public function maybe_sync_historical_donations() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! Fundolar_Migration::pending_central_sync() ) {
			return;
		}
		$s = Fundolar_Payments::get_settings();
		if ( empty( $s['platform_api_key'] ) ) {
			return;
		}
		$result = Fundolar_Platform::sync_historical_donations( 25 );
		if ( is_wp_error( $result ) ) {
			return;
		}
		if ( ! empty( $result['synced'] ) ) {
			set_transient(
				'fundolar_central_sync_notice',
				array(
					'synced'    => (int) $result['synced'],
					'remaining' => (int) $result['remaining'],
				),
				120
			);
		}
	}

	/**
	 * Plugin row links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		$how_url = admin_url( 'admin.php?page=fundolar-how-to' );
		array_unshift(
			$links,
			'<a href="' . esc_url( $how_url ) . '">' . esc_html__( 'How to use', 'fundolar' ) . '</a>'
		);
		$url = admin_url( 'admin.php?page=fundolar-settings' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'fundolar' ) . '</a>' );
		return $links;
	}
}
