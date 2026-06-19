<?php
/**
 * wp-admin dashboard notices (rebrand + Central onboarding).
 *
 * @package Fundolar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Fundolar_Admin_Notices
 */
class Fundolar_Admin_Notices {

	const CONNECT_DISMISS_DAYS = 14;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_fundolar_dismiss_admin_notice', array( __CLASS__, 'ajax_dismiss' ) );
	}

	/**
	 * Styles for branded admin notices.
	 *
	 * @param string $hook Admin hook suffix.
	 */
	public static function enqueue_assets( $hook ) {
		if ( ! self::should_enqueue_assets( $hook ) ) {
			return;
		}
		wp_enqueue_style(
			'fundolar-admin',
			FUNDOLAR_PLUGIN_URL . 'resources/css/fundolar-admin.css',
			array(),
			FUNDOLAR_VERSION
		);
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

	/**
	 * @param string $hook Hook suffix.
	 * @return bool
	 */
	private static function should_enqueue_assets( $hook ) {
		if ( 'index.php' === $hook ) {
			return true;
		}
		return false !== strpos( $hook, 'fundolar' );
	}

	/**
	 * Output admin notices.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::render_sync_success_notice();

		$screen     = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id  = $screen ? (string) $screen->id : '';
		$dashboard  = ( 'dashboard' === $screen_id );
		$fundolar   = ( '' !== $screen_id && false !== strpos( $screen_id, 'fundolar' ) );
		$connected  = self::is_connected_to_central();

		if ( $dashboard && self::should_show_rebrand_notice() ) {
			self::render_rebrand_notice();
			return;
		}

		if ( ( $dashboard || $fundolar ) && self::should_show_central_upsell() ) {
			self::render_central_upsell_notice();
		}
	}

	/**
	 * @return bool
	 */
	public static function should_show_central_upsell() {
		if ( self::should_show_rebrand_notice() ) {
			return false;
		}
		$dismissed = get_user_meta( get_current_user_id(), 'fundolar_dismiss_connect_notice', true );
		if ( $dismissed && ( time() - (int) $dismissed ) < ( DAY_IN_SECONDS * self::CONNECT_DISMISS_DAYS ) ) {
			return false;
		}
		if ( ! Fundolar_Payments::is_central_connected() ) {
			return true;
		}
		return count( Fundolar_Payments::gateways_ready_for_front() ) === 0;
	}

	/**
	 * @deprecated Use should_show_central_upsell().
	 * @return bool
	 */
	public static function should_show_connect_notice() {
		return self::should_show_central_upsell();
	}

	/**
	 * @return bool
	 */
	private static function is_connected_to_central() {
		$s = Fundolar_Payments::get_settings();
		return ! empty( $s['platform_api_key'] );
	}

	/**
	 * @return bool
	 */
	public static function should_show_rebrand_notice() {
		if ( ! Fundolar_Migration::migrated_from_fundora() ) {
			return false;
		}
		return ! get_user_meta( get_current_user_id(), 'fundolar_dismiss_rebrand_notice', true );
	}

	/**
	 * One-time success after historical Central sync.
	 */
	private static function render_sync_success_notice() {
		$sync_notice = get_transient( 'fundolar_central_sync_notice' );
		if ( ! is_array( $sync_notice ) || empty( $sync_notice['synced'] ) ) {
			return;
		}
		delete_transient( 'fundolar_central_sync_notice' );
		$remaining = isset( $sync_notice['remaining'] ) ? (int) $sync_notice['remaining'] : 0;
		if ( $remaining > 0 ) {
			/* translators: 1: number synced, 2: number remaining */
			$message = sprintf(
				__( '%1$d donations were synced to Fundolar Central. %2$d remain and will sync automatically.', 'fundolar' ),
				(int) $sync_notice['synced'],
				$remaining
			);
		} else {
			/* translators: %d: number of donations synced */
			$message = sprintf(
				__( '%d donations were synced to Fundolar Central.', 'fundolar' ),
				(int) $sync_notice['synced']
			);
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Rebrand welcome for former Fundora users (dashboard only).
	 */
	private static function render_rebrand_notice() {
		$settings_url = admin_url( 'admin.php?page=fundolar-settings' );
		?>
		<div class="notice fundolar-admin-notice fundolar-admin-notice--rebrand is-dismissible" data-fundolar-dismiss="rebrand">
			<div class="fundolar-admin-notice__inner">
				<span class="fundolar-admin-notice__icon dashicons dashicons-heart" aria-hidden="true"></span>
				<div class="fundolar-admin-notice__body">
					<p class="fundolar-admin-notice__title"><?php esc_html_e( 'Welcome to Fundolar', 'fundolar' ); ?></p>
					<p class="fundolar-admin-notice__text">
						<?php esc_html_e( 'We have rebranded from Fundora. Your donation forms, settings, and transaction history were preserved automatically — no action needed on your part.', 'fundolar' ); ?>
					</p>
					<p class="fundolar-admin-notice__actions">
						<a href="<?php echo esc_url( $settings_url ); ?>" class="button button-secondary">
							<?php esc_html_e( 'View Fundolar settings', 'fundolar' ); ?>
						</a>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Encourage optional Central for additional payment methods (dashboard + Fundolar screens).
	 */
	private static function render_central_upsell_notice() {
		$settings_url = admin_url( 'admin.php?page=fundolar-settings#payments' );
		$register_url = Fundolar_Platform::PLATFORM_BASE_URL . '/owner/register';
		$how_url      = admin_url( 'admin.php?page=fundolar-how-to' );
		?>
		<div class="notice fundolar-admin-notice fundolar-admin-notice--connect is-dismissible" data-fundolar-dismiss="connect">
			<div class="fundolar-admin-notice__inner">
				<span class="fundolar-admin-notice__icon dashicons dashicons-admin-plugins" aria-hidden="true"></span>
				<div class="fundolar-admin-notice__body">
					<p class="fundolar-admin-notice__title"><?php esc_html_e( 'Connect Fundolar Central', 'fundolar' ); ?></p>
					<p class="fundolar-admin-notice__text">
						<?php if ( Fundolar_Payments::is_central_connected() ) : ?>
							<?php esc_html_e( 'This site is connected but no payment methods are active yet. Enable gateways in Fundolar Central admin, then click Sync gateways under Settings → Payments.', 'fundolar' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Payment methods are configured in Fundolar Central. Connect your site with a site key and sync to show Stripe, PayPal, Mobile Money (UG), and more on your donation form.', 'fundolar' ); ?>
						<?php endif; ?>
					</p>
					<p class="fundolar-admin-notice__actions">
						<a href="<?php echo esc_url( $settings_url ); ?>" class="button button-primary">
							<?php esc_html_e( 'Open payment settings', 'fundolar' ); ?>
						</a>
						<a href="<?php echo esc_url( $register_url ); ?>" class="button button-secondary" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Create free account', 'fundolar' ); ?>
						</a>
						<a href="<?php echo esc_url( $how_url ); ?>" class="fundolar-admin-notice__link">
							<?php esc_html_e( 'Setup guide', 'fundolar' ); ?>
						</a>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * @deprecated Use render_central_upsell_notice().
	 */
	private static function render_connect_notice() {
		self::render_central_upsell_notice();
	}

	/**
	 * Dismiss rebrand or connect notice via AJAX.
	 */
	public static function ajax_dismiss() {
		check_ajax_referer( 'fundolar_support', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$type = isset( $_POST['notice_type'] ) ? sanitize_key( wp_unslash( $_POST['notice_type'] ) ) : '';
		if ( 'rebrand' === $type ) {
			update_user_meta( get_current_user_id(), 'fundolar_dismiss_rebrand_notice', 1 );
			wp_send_json_success();
		}
		if ( 'connect' === $type ) {
			update_user_meta( get_current_user_id(), 'fundolar_dismiss_connect_notice', time() );
			wp_send_json_success();
		}
		wp_send_json_error();
	}
}
