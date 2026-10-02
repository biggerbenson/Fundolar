<?php
/**
 * wp-admin dashboard notices (post-update welcome + Central onboarding).
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

		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = $screen ? (string) $screen->id : '';
		$dashboard = ( 'dashboard' === $screen_id );
		$fundolar  = ( '' !== $screen_id && false !== strpos( $screen_id, 'fundolar' ) );

		if ( $dashboard && self::should_show_welcome_notice() ) {
			self::render_welcome_notice();
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
		if ( self::should_show_welcome_notice() ) {
			return false;
		}
		if ( Fundolar_Payments::is_central_only_distribution() ) {
			if ( Fundolar_Payments::is_central_connected() && count( Fundolar_Payments::gateways_ready_for_front() ) > 0 ) {
				return false;
			}
		} elseif ( Fundolar_Payments::is_own_keys_mode() && count( Fundolar_Payments::gateways_ready_for_front() ) > 0 ) {
			return false;
		}
		$dismissed = get_user_meta( get_current_user_id(), 'fundolar_dismiss_connect_notice', true );
		if ( $dismissed && ( time() - (int) $dismissed ) < ( DAY_IN_SECONDS * self::CONNECT_DISMISS_DAYS ) ) {
			return false;
		}
		if ( Fundolar_Payments::is_central_mode() ) {
			if ( ! Fundolar_Payments::is_central_connected() ) {
				return true;
			}
			return count( Fundolar_Payments::gateways_ready_for_front() ) === 0;
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
		return '' !== Fundolar_Payments::get_platform_api_key();
	}

	/**
	 * Version string for the pending post-update welcome notice.
	 *
	 * @return string
	 */
	public static function welcome_notice_version() {
		$version = (string) get_option( 'fundolar_welcome_notice_version', '' );
		if ( '' !== $version ) {
			return $version;
		}
		// Back-compat: former Fundora installs that migrated before the welcome option existed.
		if ( Fundolar_Migration::migrated_from_fundora()
			&& ! get_user_meta( get_current_user_id(), 'fundolar_dismiss_rebrand_notice', true )
			&& ! get_user_meta( get_current_user_id(), 'fundolar_dismiss_welcome_notice', true ) ) {
			return defined( 'FUNDOLAR_VERSION' ) ? FUNDOLAR_VERSION : '';
		}
		return '';
	}

	/**
	 * Dismissable dashboard welcome after install or update.
	 *
	 * @return bool
	 */
	public static function should_show_welcome_notice() {
		$version = self::welcome_notice_version();
		if ( '' === $version ) {
			return false;
		}
		$dismissed = (string) get_user_meta( get_current_user_id(), 'fundolar_dismiss_welcome_notice', true );
		if ( $dismissed === $version ) {
			return false;
		}
		return true;
	}

	/**
	 * @deprecated Use should_show_welcome_notice().
	 * @return bool
	 */
	public static function should_show_rebrand_notice() {
		return self::should_show_welcome_notice() && Fundolar_Migration::migrated_from_fundora();
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
	 * Post-update / post-install welcome (dashboard only).
	 */
	private static function render_welcome_notice() {
		$version      = self::welcome_notice_version();
		$settings_url = admin_url( 'admin.php?page=fundolar-settings' );
		$from_fundora = Fundolar_Migration::migrated_from_fundora();
		?>
		<div class="notice fundolar-admin-notice fundolar-admin-notice--welcome is-dismissible" data-fundolar-dismiss="welcome">
			<div class="fundolar-admin-notice__inner">
				<span class="fundolar-admin-notice__icon dashicons dashicons-yes-alt" aria-hidden="true"></span>
				<div class="fundolar-admin-notice__body">
					<p class="fundolar-admin-notice__title">
						<?php
						printf(
							/* translators: %s: plugin version number */
							esc_html__( 'Welcome to Fundolar (%s)', 'fundolar' ),
							esc_html( $version )
						);
						?>
					</p>
					<p class="fundolar-admin-notice__text">
						<?php if ( $from_fundora ) : ?>
							<?php esc_html_e( 'Your site was updated from Fundora to the latest Fundolar. Donation forms, settings, and transaction history were preserved automatically.', 'fundolar' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Fundolar is up to date on this site. Take a moment to review your payment and form settings so donations keep working smoothly.', 'fundolar' ); ?>
						<?php endif; ?>
					</p>
					<ul class="fundolar-admin-notice__list">
						<?php if ( $from_fundora ) : ?>
							<li><?php esc_html_e( 'Shortcodes and gateway return URLs continue to work without changes.', 'fundolar' ); ?></li>
							<li><?php esc_html_e( 'Connect Fundolar Central under Settings → Payments and sync your payment methods.', 'fundolar' ); ?></li>
						<?php else : ?>
							<li><?php esc_html_e( 'Connect Fundolar Central and sync payment methods under Settings → Payments.', 'fundolar' ); ?></li>
							<li><?php esc_html_e( 'Review preset amounts, emails, and form options if you customize them.', 'fundolar' ); ?></li>
							<li><?php esc_html_e( 'Use the How-to guide anytime for setup and troubleshooting steps.', 'fundolar' ); ?></li>
						<?php endif; ?>
					</ul>
					<p class="fundolar-admin-notice__actions">
						<a href="<?php echo esc_url( $settings_url ); ?>" class="button button-primary">
							<?php esc_html_e( 'Update settings', 'fundolar' ); ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=fundolar-how-to' ) ); ?>" class="fundolar-admin-notice__link">
							<?php esc_html_e( 'Setup guide', 'fundolar' ); ?>
						</a>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * @deprecated Use render_welcome_notice().
	 */
	private static function render_rebrand_notice() {
		self::render_welcome_notice();
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
					<p class="fundolar-admin-notice__title"><?php esc_html_e( 'Finish payment setup', 'fundolar' ); ?></p>
					<p class="fundolar-admin-notice__text">
						<?php if ( Fundolar_Payments::is_central_connected() ) : ?>
							<?php esc_html_e( 'This site is connected to Fundolar Central but no payment methods are active yet. Enable gateways in your Fundolar dashboard, then click Sync gateways under Settings → Payments.', 'fundolar' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Connect Fundolar Central with your site key under Settings → Payments, then sync gateways to start accepting donations.', 'fundolar' ); ?>
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
	 * Dismiss welcome or connect notice via AJAX.
	 */
	public static function ajax_dismiss() {
		check_ajax_referer( 'fundolar_support', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$type = isset( $_POST['notice_type'] ) ? sanitize_key( wp_unslash( $_POST['notice_type'] ) ) : '';
		if ( 'welcome' === $type || 'rebrand' === $type ) {
			$version = self::welcome_notice_version();
			if ( '' === $version ) {
				$version = defined( 'FUNDOLAR_VERSION' ) ? FUNDOLAR_VERSION : '1';
			}
			update_user_meta( get_current_user_id(), 'fundolar_dismiss_welcome_notice', $version );
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
