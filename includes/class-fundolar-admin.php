<?php
/**
 * wp-admin settings, documentation, and transactions.
 *
 * @package Fundolar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Fundolar_Admin
 */
class Fundolar_Admin {

	/**
	 * Add menu.
	 */
	public static function menu() {
		add_menu_page(
			__( 'Fundolar', 'fundolar' ),
			__( 'Fundolar', 'fundolar' ),
			'manage_options',
			'fundolar-settings',
			array( __CLASS__, 'render_settings' ),
			'dashicons-heart',
			58
		);
		add_submenu_page(
			'fundolar-settings',
			__( 'Fundolar Settings', 'fundolar' ),
			__( 'Settings', 'fundolar' ),
			'manage_options',
			'fundolar-settings',
			array( __CLASS__, 'render_settings' )
		);
		add_submenu_page(
			'fundolar-settings',
			__( 'How to use Fundolar', 'fundolar' ),
			__( 'How to use', 'fundolar' ),
			'manage_options',
			'fundolar-how-to',
			array( __CLASS__, 'render_how_to_use' )
		);
		add_submenu_page(
			'fundolar-settings',
			__( 'Fundolar Transactions', 'fundolar' ),
			__( 'Transactions', 'fundolar' ),
			'manage_options',
			'fundolar-transactions',
			array( __CLASS__, 'render_transactions' )
		);
	}

	/**
	 * Save main settings.
	 */
	public static function register_settings() {
		if ( ! empty( $_GET['fundolar_oauth_ok'] ) && current_user_can( 'manage_options' ) ) {
			$gw = sanitize_key( wp_unslash( $_GET['fundolar_oauth_ok'] ) );
			if ( in_array( $gw, array( 'stripe', 'paypal' ), true ) ) {
				add_settings_error( 'fundolar', 'oauth-ok', __( 'Account connected successfully.', 'fundolar' ), 'success' );
			}
		}
		if ( empty( $_POST['fundolar_save_settings'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'fundolar_save_settings' );
		$input = wp_unslash( $_POST );
		if ( isset( $input['preset_amounts_raw'] ) ) {
			$lines   = preg_split( '/\R/', $input['preset_amounts_raw'] );
			$amounts = array();
			foreach ( $lines as $line ) {
				$v = floatval( trim( $line ) );
				if ( $v > 0 && $v < 1000000 ) {
					$amounts[] = $v;
				}
			}
			$input['preset_amounts'] = $amounts;
		}
		if ( ! empty( $input['fundolar_connect_platform'] ) || ! empty( $input['fundolar_sync_platform'] ) ) {
			$input['payment_mode'] = Fundolar_Payments::MODE_CENTRAL;
		}
		Fundolar_Payments::save_settings( $input );
		if ( ! empty( $input['fundolar_connect_platform'] ) ) {
			$connected = Fundolar_Platform::connect_site();
			if ( is_wp_error( $connected ) ) {
				add_settings_error( 'fundolar', 'platform-connect-failed', $connected->get_error_message(), 'error' );
			} else {
				$msg = __( 'Connected. Payment settings were synced.', 'fundolar' );
				$msg = self::append_historical_sync_message( $msg );
				add_settings_error( 'fundolar', 'platform-connected', $msg, 'success' );
			}
		} elseif ( ! empty( $input['fundolar_sync_platform'] ) ) {
			$sync = Fundolar_Platform::maybe_sync_gateways( true );
			if ( is_wp_error( $sync ) ) {
				add_settings_error( 'fundolar', 'platform-sync-failed', $sync->get_error_message(), 'error' );
			} else {
				$history = Fundolar_Platform::sync_historical_donations( 50 );
				$msg     = __( 'Latest payment settings were synced.', 'fundolar' );
				if ( ! is_wp_error( $history ) && ! empty( $history['synced'] ) ) {
					$msg = self::append_historical_sync_message( $msg, $history );
				}
				add_settings_error( 'fundolar', 'platform-sync-success', $msg, 'success' );
			}
		} else {
			add_settings_error( 'fundolar', 'saved', __( 'Settings saved.', 'fundolar' ), 'success' );
		}
	}

	/**
	 * AJAX: send support / inquiry email (admin only).
	 */
	public static function ajax_support_request() {
		check_ajax_referer( 'fundolar_support', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'fundolar' ) ) );
		}
		$type = isset( $_POST['support_type'] ) ? sanitize_key( wp_unslash( $_POST['support_type'] ) ) : '';
		$msg  = isset( $_POST['support_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['support_message'] ) ) : '';
		$labels = array(
			'feature_request'      => __( 'Request new feature', 'fundolar' ),
			'general_inquiry'      => __( 'General inquiry', 'fundolar' ),
			'custom_development'   => __( 'Custom development', 'fundolar' ),
			'technical_support'    => __( 'Technical support', 'fundolar' ),
		);
		if ( ! isset( $labels[ $type ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Please choose a valid topic.', 'fundolar' ) ) );
		}
		if ( strlen( $msg ) < 10 ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a bit more detail (at least 10 characters).', 'fundolar' ) ) );
		}
		$user  = wp_get_current_user();
		$from  = $user && $user->user_email ? $user->user_email : get_option( 'admin_email' );
		$site  = home_url( '/' );
		$label = $labels[ $type ];
		/* translators: 1: topic label, 2: site URL */
		$subject = sprintf( __( '[Fundolar] %1$s â€” %2$s', 'fundolar' ), $label, wp_parse_url( $site, PHP_URL_HOST ) );
		$body    = implode(
			"\n",
			array(
				__( 'Topic:', 'fundolar' ) . ' ' . $label,
				__( 'Site:', 'fundolar' ) . ' ' . $site,
				__( 'From (WordPress user):', 'fundolar' ) . ' ' . ( $user ? $user->display_name : '' ) . ' <' . $from . '>',
				'',
				$msg,
			)
		);
		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			'Reply-To: ' . $from,
		);
		$sent = wp_mail( Fundolar_Emails::SUPPORT_INBOX, $subject, $body, $headers );
		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'The email could not be sent.', 'fundolar' ) ) );
		}
		wp_send_json_success();
	}

	/**
	 * Shared app header for Fundolar admin screens.
	 *
	 * @param string               $title    Page title (H1).
	 * @param string               $subtitle Subtitle paragraph.
	 * @param array<int,array{url:string,label:string}>|null $actions Optional action buttons (defaults to Transactions only).
	 */
	private static function render_app_header( $title, $subtitle, $actions = null ) {
		if ( null === $actions ) {
			$actions = array(
				array(
					'url'   => admin_url( 'admin.php?page=fundolar-transactions' ),
					'label' => __( 'Transactions', 'fundolar' ),
				),
			);
		}
		?>
		<div class="fundolar-app__header">
			<div class="fundolar-app__brand">
				<div class="fundolar-app__logo" aria-hidden="true">
					<span class="dashicons dashicons-heart"></span>
				</div>
				<div class="fundolar-app__titles">
					<h1><?php echo esc_html( $title ); ?></h1>
					<p class="fundolar-app__subtitle"><?php echo esc_html( $subtitle ); ?></p>
				</div>
			</div>
			<div class="fundolar-app__header-actions">
				<?php
				foreach ( $actions as $action ) {
					$url   = isset( $action['url'] ) ? $action['url'] : '';
					$label = isset( $action['label'] ) ? $action['label'] : '';
					if ( '' === $url || '' === $label ) {
						continue;
					}
					?>
					<a href="<?php echo esc_url( $url ); ?>" class="button"><?php echo esc_html( $label ); ?></a>
					<?php
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * How to use â€” standalone help page (submenu).
	 */
	public static function render_how_to_use() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings_url = admin_url( 'admin.php?page=fundolar-settings' );
		$support_url  = admin_url( 'admin.php?page=fundolar-settings#support' );
		?>
		<div class="wrap fundolar-admin fundolar-app fundolar-howto-page">
			<?php
			self::render_app_header(
				__( 'How to use Fundolar', 'fundolar' ),
				__( 'Set up donations by connecting Fundolar Central and syncing payment gateways.', 'fundolar' ),
				array(
					array(
						'url'   => $settings_url,
						'label' => __( 'Settings', 'fundolar' ),
					),
					array(
						'url'   => admin_url( 'admin.php?page=fundolar-transactions' ),
						'label' => __( 'Transactions', 'fundolar' ),
					),
				)
			);
			?>
			<div class="fundolar-card">
				<div class="fundolar-card__head">
					<h2><?php esc_html_e( 'How the plugin works', 'fundolar' ); ?></h2>
				</div>
				<div class="fundolar-card__body">
					<ol class="fundolar-howto-steps">
						<li>
							<strong><?php esc_html_e( 'Add the donation form', 'fundolar' ); ?></strong>
							<?php esc_html_e( 'Place the shortcode on any page or post:', 'fundolar' ); ?>
							<code class="fundolar-howto-code">[fundolar_donate]</code>
						</li>
						<li>
							<strong><?php esc_html_e( 'Connect Fundolar Central', 'fundolar' ); ?></strong>
							<?php esc_html_e( 'Under Fundolar â†’ Settings â†’ Payments, paste your site key, connect, and click Sync gateways. Payment methods are enabled in Fundolar Central admin.', 'fundolar' ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Platform fee (3.5%)', 'fundolar' ); ?></strong>
							<?php esc_html_e( 'Each donation records gross amount, platform fee, and net to your site.', 'fundolar' ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Track results', 'fundolar' ); ?></strong>
							<?php esc_html_e( 'View donations under Fundolar â†’ Transactions and on the WordPress dashboard widget.', 'fundolar' ); ?>
						</li>
					</ol>
				</div>
			</div>

			<div class="fundolar-card">
				<div class="fundolar-card__head">
					<h2><?php esc_html_e( 'Fundolar Central setup', 'fundolar' ); ?></h2>
				</div>
				<div class="fundolar-card__body">
					<ol class="fundolar-howto-steps">
						<li>
							<strong><?php esc_html_e( 'Configure gateways in Central', 'fundolar' ); ?></strong>
							<?php esc_html_e( 'In Fundolar Central admin â†’ Settings â†’ Payments, enable gateways and enter API keys (Stripe, PayPal, Mobile Money UG, Paystack, Pesapal, Flutterwave).', 'fundolar' ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Create a site key', 'fundolar' ); ?></strong>
							<a href="<?php echo esc_url( Fundolar_Platform::PLATFORM_BASE_URL . '/owner/register' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Register at Fundolar Central', 'fundolar' ); ?></a>
							<?php esc_html_e( 'and add a WordPress site integration.', 'fundolar' ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Connect in WordPress', 'fundolar' ); ?></strong>
							<a href="<?php echo esc_url( $settings_url . '#payments' ); ?>"><?php esc_html_e( 'Fundolar â†’ Settings â†’ Payments', 'fundolar' ); ?></a>
							<?php esc_html_e( 'â€” paste the site key, click Connect Fundolar Central, then Sync gateways.', 'fundolar' ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Test a donation', 'fundolar' ); ?></strong>
							<?php esc_html_e( 'Publish your page with the shortcode and complete a small test payment.', 'fundolar' ); ?>
						</li>
					</ol>
				</div>
			</div>

			<div class="fundolar-card">
				<div class="fundolar-card__head">
					<h2><?php esc_html_e( 'Optional customization', 'fundolar' ); ?></h2>
				</div>
				<div class="fundolar-card__body">
					<ul class="fundolar-howto-steps" style="list-style:disc;padding-left:1.25rem;">
						<li><?php esc_html_e( 'General tab â€” currency and preset amounts.', 'fundolar' ); ?></li>
						<li><?php esc_html_e( 'Layout tab â€” form style and brand colors.', 'fundolar' ); ?></li>
						<li><?php esc_html_e( 'Advanced tab â€” admin and donor email notifications.', 'fundolar' ); ?></li>
					</ul>
				</div>
			</div>
			<div class="fundolar-card">
				<div class="fundolar-card__head">
					<h2><?php esc_html_e( 'Need more help?', 'fundolar' ); ?></h2>
				</div>
				<div class="fundolar-card__body">
					<p class="fundolar-card__intro fundolar-howto-support-line">
						<?php esc_html_e( 'Open Settings and use the Support tab, or email us from there.', 'fundolar' ); ?>
						<a href="<?php echo esc_url( $support_url ); ?>" class="button fundolar-howto-support-btn"><?php esc_html_e( 'Open Support', 'fundolar' ); ?></a>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Settings page (tabbed).
	 */
	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s           = Fundolar_Payments::get_settings_for_display();
		$ready_count = count( Fundolar_Payments::gateways_ready_for_front() );
		settings_errors( 'fundolar' );
		$layouts     = Fundolar_Payments::form_layouts();
		$cur_layout  = isset( $s['form_layout'] ) ? sanitize_key( $s['form_layout'] ) : 'portrait';
		if ( ! array_key_exists( $cur_layout, $layouts ) ) {
			$cur_layout = 'portrait';
		}
		?>
		<div class="wrap fundolar-admin fundolar-app fundolar-settings-wrap">
			<?php
			self::render_app_header(
				__( 'Fundolar', 'fundolar' ),
				__( 'Configure donations, payments, layout, notifications, and get help.', 'fundolar' )
			);
			?>

			<div class="fundolar-tabs fundolar-tabs--primary" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'fundolar' ); ?>">
				<button type="button" class="fundolar-tabs__btn is-active" role="tab" aria-selected="true" data-tab="general" id="fundolar-tab-general"><?php esc_html_e( 'General', 'fundolar' ); ?></button>
				<button type="button" class="fundolar-tabs__btn" role="tab" aria-selected="false" tabindex="-1" data-tab="payments" id="fundolar-tab-payments">
					<?php esc_html_e( 'Payments', 'fundolar' ); ?>
					<?php if ( $ready_count > 0 ) : ?>
						<span class="fundolar-tabs__badge"><?php echo esc_html( (string) (int) $ready_count ); ?></span>
					<?php endif; ?>
				</button>
				<button type="button" class="fundolar-tabs__btn" role="tab" aria-selected="false" tabindex="-1" data-tab="layout" id="fundolar-tab-layout"><?php esc_html_e( 'Layout', 'fundolar' ); ?></button>
				<button type="button" class="fundolar-tabs__btn" role="tab" aria-selected="false" tabindex="-1" data-tab="advanced" id="fundolar-tab-advanced"><?php esc_html_e( 'Advanced', 'fundolar' ); ?></button>
				<button type="button" class="fundolar-tabs__btn" role="tab" aria-selected="false" tabindex="-1" data-tab="support" id="fundolar-tab-support"><?php esc_html_e( 'Support', 'fundolar' ); ?></button>
			</div>

			<form method="post" action="" class="fundolar-settings-form" id="fundolar-settings-form-main">
				<?php wp_nonce_field( 'fundolar_save_settings' ); ?>
				<input type="hidden" name="fundolar_save_settings" value="1" />
				<?php
				if ( ! defined( 'FUNDOLAR_CENTRAL_URL' ) || ! is_string( FUNDOLAR_CENTRAL_URL ) || '' === trim( FUNDOLAR_CENTRAL_URL ) ) :
					?>
				<input type="hidden" name="platform_base_url" value="<?php echo esc_attr( Fundolar_Platform::PLATFORM_BASE_URL ); ?>" />
				<?php endif; ?>

				<div class="fundolar-tab-panel" data-panel="general" role="tabpanel" aria-labelledby="fundolar-tab-general">
					<div class="fundolar-card">
						<div class="fundolar-card__head">
							<h2><?php esc_html_e( 'Defaults', 'fundolar' ); ?></h2>
						</div>
						<div class="fundolar-card__body">
							<table class="fundolar-cred-table" role="presentation">
								<tbody>
									<tr>
										<th scope="row"><label for="fundolar_default_currency"><?php esc_html_e( 'Default currency', 'fundolar' ); ?></label></th>
										<td>
											<input name="default_currency" id="fundolar_default_currency" type="text" maxlength="3" value="<?php echo esc_attr( $s['default_currency'] ); ?>" class="regular-text" style="max-width:6rem;text-transform:uppercase;" />
											<p class="description"><?php esc_html_e( 'ISO code (e.g. USD, NGN, EUR). Donors can still pick from the form list where offered.', 'fundolar' ); ?></p>
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
					<div class="fundolar-card">
						<div class="fundolar-card__head">
							<h2><?php esc_html_e( 'Preset amounts', 'fundolar' ); ?></h2>
						</div>
						<div class="fundolar-card__body">
							<p class="fundolar-card__intro"><?php esc_html_e( 'One amount per line, up to ten values. These power the quick-select chips on the form.', 'fundolar' ); ?></p>
							<textarea name="preset_amounts_raw" rows="7" class="large-text code" id="fundolar_preset_amounts" style="width:100%;max-width:32rem;"><?php echo esc_textarea( implode( "\n", array_map( 'strval', (array) $s['preset_amounts'] ) ) ); ?></textarea>
						</div>
					</div>
				</div>

				<div class="fundolar-tab-panel" data-panel="payments" role="tabpanel" aria-labelledby="fundolar-tab-payments" hidden aria-hidden="true">
					<?php self::render_payments_tab( $s ); ?>
				</div>

				<div class="fundolar-tab-panel" data-panel="layout" role="tabpanel" aria-labelledby="fundolar-tab-layout" hidden aria-hidden="true">
					<div class="fundolar-card">
						<div class="fundolar-card__head">
							<h2><?php esc_html_e( 'Form layout', 'fundolar' ); ?></h2>
						</div>
						<div class="fundolar-card__body">
							<p class="fundolar-card__intro"><?php esc_html_e( 'Controls how the public donation form is arranged.', 'fundolar' ); ?></p>
							<table class="fundolar-cred-table" role="presentation">
								<tbody>
									<tr>
										<th scope="row"><label for="fundolar_form_layout"><?php esc_html_e( 'Layout style', 'fundolar' ); ?></label></th>
										<td>
											<select name="form_layout" id="fundolar_form_layout">
												<?php foreach ( $layouts as $slug => $label ) : ?>
													<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $cur_layout, $slug ); ?>><?php echo esc_html( $label ); ?></option>
												<?php endforeach; ?>
											</select>
											<p class="description"><?php esc_html_e( 'Portrait: ~736px max width, hero header, stacked flow. Landscape: wider (~864px), two-column names on large screens. Inline: fills the content column (100% width). Compact: narrow card for tight spaces. Split: hero beside fields from ~640px up.', 'fundolar' ); ?></p>
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
					<div class="fundolar-card">
						<div class="fundolar-card__head">
							<h2><?php esc_html_e( 'Colors', 'fundolar' ); ?></h2>
						</div>
						<div class="fundolar-card__body">
							<table class="fundolar-cred-table" role="presentation">
								<tbody>
									<tr>
										<th scope="row"><label for="fundolar_color_primary"><?php esc_html_e( 'Primary color', 'fundolar' ); ?></label></th>
										<td>
											<div class="fundolar-color-row">
												<input name="color_primary" type="text" id="fundolar_color_primary" value="<?php echo esc_attr( $s['color_primary'] ); ?>" class="fundolar-color" />
											</div>
											<p class="description"><?php esc_html_e( 'Headings, buttons, and selected states.', 'fundolar' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="fundolar_color_accent"><?php esc_html_e( 'Accent color', 'fundolar' ); ?></label></th>
										<td>
											<div class="fundolar-color-row">
												<input name="color_accent" type="text" id="fundolar_color_accent" value="<?php echo esc_attr( $s['color_accent'] ); ?>" class="fundolar-color" />
											</div>
											<p class="description"><?php esc_html_e( 'Highlights and primary button text.', 'fundolar' ); ?></p>
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<div class="fundolar-tab-panel" data-panel="advanced" role="tabpanel" aria-labelledby="fundolar-tab-advanced" hidden aria-hidden="true">
					<div class="fundolar-card">
						<div class="fundolar-card__head">
							<h2><?php esc_html_e( 'Email notifications', 'fundolar' ); ?></h2>
						</div>
						<div class="fundolar-card__body">
							<p class="fundolar-card__intro"><?php esc_html_e( 'Optional messages sent when a donation is successfully completed.', 'fundolar' ); ?></p>
							<table class="fundolar-cred-table" role="presentation">
								<tbody>
									<tr>
										<th scope="row"><?php esc_html_e( 'Admin notice', 'fundolar' ); ?></th>
										<td>
											<input type="hidden" name="notify_admin_on_success" value="0" />
											<label><input type="checkbox" name="notify_admin_on_success" value="1" <?php checked( ! empty( $s['notify_admin_on_success'] ) && '1' === (string) $s['notify_admin_on_success'] ); ?> /> <?php esc_html_e( 'Send an email to site staff when a donation succeeds', 'fundolar' ); ?></label>
											<p class="description" style="margin-top:0.75rem;"><label for="fundolar_notify_recipients"><?php esc_html_e( 'Recipient addresses (optional)', 'fundolar' ); ?></label></p>
											<textarea name="notify_admin_recipients" id="fundolar_notify_recipients" class="large-text" rows="3" placeholder="<?php esc_attr_e( 'Leave blank to use the WordPress admin email only', 'fundolar' ); ?>"><?php echo esc_textarea( isset( $s['notify_admin_recipients'] ) ? $s['notify_admin_recipients'] : '' ); ?></textarea>
											<p class="description"><?php esc_html_e( 'Separate multiple addresses with commas or new lines.', 'fundolar' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Donor receipt', 'fundolar' ); ?></th>
										<td>
											<input type="hidden" name="donor_receipt_enabled" value="0" />
											<label><input type="checkbox" name="donor_receipt_enabled" value="1" <?php checked( ! empty( $s['donor_receipt_enabled'] ) && '1' === (string) $s['donor_receipt_enabled'] ); ?> /> <?php esc_html_e( 'Email the donor a thank-you / receipt after a successful donation', 'fundolar' ); ?></label>
											<table class="fundolar-cred-table" style="margin-top:1rem;" role="presentation">
												<tbody>
													<tr>
														<th scope="row"><label for="fundolar_donor_from_name"><?php esc_html_e( 'From name', 'fundolar' ); ?></label></th>
														<td><input name="donor_receipt_from_name" id="fundolar_donor_from_name" type="text" class="regular-text" value="<?php echo esc_attr( isset( $s['donor_receipt_from_name'] ) ? $s['donor_receipt_from_name'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Defaults to site title', 'fundolar' ); ?>" /></td>
													</tr>
													<tr>
														<th scope="row"><label for="fundolar_donor_from_email"><?php esc_html_e( 'From email', 'fundolar' ); ?></label></th>
														<td><input name="donor_receipt_from_email" id="fundolar_donor_from_email" type="email" class="regular-text" value="<?php echo esc_attr( isset( $s['donor_receipt_from_email'] ) ? $s['donor_receipt_from_email'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Defaults to admin email', 'fundolar' ); ?>" /></td>
													</tr>
													<tr>
														<th scope="row"><label for="fundolar_donor_subject"><?php esc_html_e( 'Email subject', 'fundolar' ); ?></label></th>
														<td><input name="donor_email_subject" id="fundolar_donor_subject" type="text" class="large-text" value="<?php echo esc_attr( isset( $s['donor_email_subject'] ) ? $s['donor_email_subject'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Thank you for your donation', 'fundolar' ); ?>" /></td>
													</tr>
													<tr>
														<th scope="row"><label for="fundolar_donor_template"><?php esc_html_e( 'Email body (HTML)', 'fundolar' ); ?></label></th>
														<td>
															<?php
															$tpl = isset( $s['donor_email_template'] ) ? $s['donor_email_template'] : '';
															?>
															<textarea name="donor_email_template" id="fundolar_donor_template" class="large-text code" rows="10" style="width:100%;max-width:40rem;"><?php echo esc_textarea( $tpl ); ?></textarea>
															<p class="description"><?php esc_html_e( 'Placeholders:', 'fundolar' ); ?> <code>{{name}}</code>, <code>{{email}}</code>, <code>{{amount}}</code>, <code>{{currency}}</code>, <code>{{site}}</code>, <code>{{gateway}}</code>. <?php esc_html_e( 'Leave empty for a simple default message.', 'fundolar' ); ?></p>
														</td>
													</tr>
												</tbody>
											</table>
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<div class="fundolar-save-bar">
					<?php submit_button( __( 'Save changes', 'fundolar' ), 'primary large', 'submit', false ); ?>
					<p class="fundolar-save-bar__hint"><?php esc_html_e( 'Secrets you leave blank are not overwritten.', 'fundolar' ); ?></p>
				</div>
			</form>

			<div class="fundolar-tab-panel" data-panel="support" role="tabpanel" aria-labelledby="fundolar-tab-support" hidden aria-hidden="true">
				<div class="fundolar-card">
					<div class="fundolar-card__head">
						<h2><?php esc_html_e( 'Support', 'fundolar' ); ?></h2>
					</div>
					<div class="fundolar-card__body">
						<?php
						$support_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
						$support_host = is_string( $support_host ) ? $support_host : '';
						$support_mailto = 'mailto:' . rawurlencode( Fundolar_Emails::SUPPORT_INBOX );
						$support_mailto .= '?subject=' . rawurlencode( '[Fundolar] ' . $support_host );
						?>
						<div class="fundolar-support-admin-cta">
							<p class="fundolar-card__intro" style="margin-top:0;"><?php esc_html_e( 'Contact support using email or the form below.', 'fundolar' ); ?></p>
							<p class="fundolar-support-admin-cta__buttons">
								<a class="button button-primary" href="<?php echo esc_url( $support_mailto ); ?>"><?php esc_html_e( 'Email Fundolar support', 'fundolar' ); ?></a>
								<button type="button" class="button" id="fundolar-support-focus-composer"><?php esc_html_e( 'Write a message below', 'fundolar' ); ?></button>
							</p>
						</div>
						<hr class="fundolar-support-divider" />
						<p class="fundolar-card__intro"><?php esc_html_e( 'Send a message from this site. It is delivered by email; a short confirmation appears here when it is queued.', 'fundolar' ); ?></p>
						<div id="fundolar-support-thanks" class="fundolar-support-thanks" hidden role="status"><?php esc_html_e( 'Thank you â€” we have received your message.', 'fundolar' ); ?></div>
						<form id="fundolar-support-form" class="fundolar-support-form" novalidate>
							<table class="fundolar-cred-table" role="presentation">
								<tbody>
									<tr>
										<th scope="row"><label for="fundolar_support_type"><?php esc_html_e( 'Topic', 'fundolar' ); ?></label></th>
										<td>
											<select id="fundolar_support_type" name="support_type" required>
												<option value=""><?php esc_html_e( 'Selectâ€¦', 'fundolar' ); ?></option>
												<option value="feature_request"><?php esc_html_e( 'Request new feature', 'fundolar' ); ?></option>
												<option value="general_inquiry"><?php esc_html_e( 'General inquiry', 'fundolar' ); ?></option>
												<option value="custom_development"><?php esc_html_e( 'Custom development', 'fundolar' ); ?></option>
												<option value="technical_support"><?php esc_html_e( 'Technical support', 'fundolar' ); ?></option>
											</select>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="fundolar_support_message"><?php esc_html_e( 'Message', 'fundolar' ); ?></label></th>
										<td><textarea id="fundolar_support_message" name="support_message" class="large-text" rows="6" required style="width:100%;max-width:40rem;"></textarea></td>
									</tr>
								</tbody>
							</table>
							<p><button type="submit" class="button button-primary" id="fundolar-support-submit"><?php esc_html_e( 'Send message', 'fundolar' ); ?></button></p>
						</form>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Transactions list.
	 */
	public static function render_transactions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$gw     = isset( $_GET['gateway'] ) ? sanitize_key( wp_unslash( $_GET['gateway'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$page   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

		$res = Fundolar_DB::query_transactions(
			array(
				'status'   => $status,
				'gateway'  => $gw,
				'search'   => $search,
				'page'     => $page,
				'per_page' => 25,
			)
		);
		?>
		<div class="wrap fundolar-admin fundolar-app">
			<?php
			self::render_app_header(
				__( 'Transactions', 'fundolar' ),
				__( 'Filter by status, gateway, or search donors and references.', 'fundolar' )
			);
			?>

			<div class="fundolar-card">
				<div class="fundolar-card__body">
					<form method="get" class="fundolar-filters">
						<input type="hidden" name="page" value="fundolar-transactions" />
						<select name="status">
							<option value=""><?php esc_html_e( 'All statuses', 'fundolar' ); ?></option>
							<option value="completed" <?php selected( $status, 'completed' ); ?>><?php esc_html_e( 'Successful', 'fundolar' ); ?></option>
							<option value="failed" <?php selected( $status, 'failed' ); ?>><?php esc_html_e( 'Failed', 'fundolar' ); ?></option>
							<option value="pending" <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'Pending', 'fundolar' ); ?></option>
							<option value="refunded" <?php selected( $status, 'refunded' ); ?>><?php esc_html_e( 'Refunded', 'fundolar' ); ?></option>
						</select>
						<select name="gateway">
							<option value=""><?php esc_html_e( 'All gateways', 'fundolar' ); ?></option>
							<?php foreach ( Fundolar_Payments::gateways() as $g ) : ?>
								<option value="<?php echo esc_attr( $g ); ?>" <?php selected( $gw, $g ); ?>><?php echo esc_html( $g ); ?></option>
							<?php endforeach; ?>
						</select>
						<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search email or reference', 'fundolar' ); ?>" />
						<?php submit_button( __( 'Filter', 'fundolar' ), 'secondary', '', false ); ?>
					</form>

					<table class="wp-list-table widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'ID', 'fundolar' ); ?></th>
								<th><?php esc_html_e( 'Date', 'fundolar' ); ?></th>
								<th><?php esc_html_e( 'Donor', 'fundolar' ); ?></th>
								<th><?php esc_html_e( 'Receipt (donor)', 'fundolar' ); ?></th>
								<th><?php esc_html_e( 'Platform fee (USD)', 'fundolar' ); ?></th>
								<th><?php esc_html_e( 'Net to site (USD)', 'fundolar' ); ?></th>
								<th><?php esc_html_e( 'Status', 'fundolar' ); ?></th>
								<th><?php esc_html_e( 'Gateway', 'fundolar' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $res->rows ) ) : ?>
								<tr><td colspan="8"><?php esc_html_e( 'No transactions found.', 'fundolar' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $res->rows as $row ) : ?>
									<tr>
										<td><?php echo esc_html( (string) $row['id'] ); ?></td>
										<td><?php echo esc_html( $row['created_at'] ); ?></td>
										<td><?php echo esc_html( $row['donor_name'] ); ?><br /><small><?php echo esc_html( $row['donor_email'] ); ?></small></td>
										<td><?php echo esc_html( Fundolar_Ledger::receipt_label( $row ) ); ?></td>
										<td><?php echo esc_html( 'USD ' . number_format_i18n( (float) $row['amount_platform_fee'], 4 ) ); ?></td>
										<td><?php echo esc_html( 'USD ' . number_format_i18n( (float) $row['amount_net'], 4 ) ); ?></td>
										<td><?php echo esc_html( $row['status'] ); ?></td>
										<td><?php echo esc_html( $row['gateway'] ); ?><br /><small><?php echo esc_html( $row['gateway_ref'] ); ?></small></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
					<?php
					$total_pages = max( 1, (int) ceil( $res->total / 25 ) );
					if ( $total_pages > 1 ) {
						echo '<div class="tablenav"><div class="tablenav-pages">';
						for ( $i = 1; $i <= $total_pages; $i++ ) {
							$url = add_query_arg(
								array(
									'page'    => 'fundolar-transactions',
									'paged'   => $i,
									'status'  => $status,
									'gateway' => $gw,
									's'       => $search,
								),
								admin_url( 'admin.php' )
							);
							$cls = $i === $page ? ' class="current"' : '';
							printf( '<a href="%s"%s>%d</a> ', esc_url( $url ), $cls, $i );
						}
						echo '</div></div>';
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Payments tab â€” Fundolar Central sync only.
	 *
	 * @param array $s Display settings.
	 */
	private static function render_payments_tab( array $s ) {
		$fee_pct        = number_format( Fundolar_Fees::rate() * 100, 1 );
		$central_active = Fundolar_Payments::is_central_connected();
		$register_url   = Fundolar_Platform::PLATFORM_BASE_URL . '/owner/register';
		$synced         = array_values( array_unique( array_map( 'sanitize_key', (array) ( $s['enabled_gateways'] ?? array() ) ) ) );
		$ready          = Fundolar_Payments::gateways_ready_for_front();
		?>
		<div class="fundolar-fee-banner" role="note">
			<span class="fundolar-fee-banner__icon dashicons dashicons-info" aria-hidden="true"></span>
			<div class="fundolar-fee-banner__text">
				<strong><?php esc_html_e( 'Platform fee', 'fundolar' ); ?></strong>
				<?php
				printf(
					/* translators: %s: fee percentage e.g. 3.5 */
					esc_html__( 'A %s%% platform fee is applied to each donation. Payment methods and API keys are managed in Fundolar Central and synced to this site.', 'fundolar' ),
					esc_html( $fee_pct )
				);
				?>
			</div>
		</div>

		<div class="fundolar-card">
			<div class="fundolar-card__head">
				<h2><?php esc_html_e( 'Fundolar Central', 'fundolar' ); ?></h2>
				<?php if ( $central_active ) : ?>
					<span class="fundolar-pill fundolar-pill--ok"><?php esc_html_e( 'Connected', 'fundolar' ); ?></span>
				<?php endif; ?>
			</div>
			<div class="fundolar-card__body">
				<p class="fundolar-card__intro">
					<?php esc_html_e( 'Connect this WordPress site to Fundolar Central with your site key. Payment methods are configured by the platform administrator in Central — after you sync, active gateways appear on your donation form.', 'fundolar' ); ?>
				</p>
				<table class="fundolar-cred-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="fundolar_platform_site_key"><?php esc_html_e( 'Site key', 'fundolar' ); ?></label></th>
							<td>
								<input class="regular-text" name="platform_site_key" id="fundolar_platform_site_key" type="text" value="<?php echo esc_attr( $s['platform_site_key'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'lic_...', 'fundolar' ); ?>" autocomplete="off" />
								<p class="description">
									<a href="<?php echo esc_url( $register_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Create a free Fundolar account', 'fundolar' ); ?></a>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Synced gateways', 'fundolar' ); ?></th>
							<td>
								<?php if ( ! $central_active ) : ?>
									<p class="description"><?php esc_html_e( 'Connect with your site key, then click Sync gateways.', 'fundolar' ); ?></p>
								<?php elseif ( empty( $synced ) ) : ?>
									<p class="description"><?php esc_html_e( 'No gateways are active in Central yet. Enable gateways in Central admin, then sync again.', 'fundolar' ); ?></p>
								<?php else : ?>
									<div class="fundolar-gateway-grid">
										<?php foreach ( $synced as $g ) : ?>
											<?php
											$is_ready  = in_array( $g, $ready, true );
											?>
											<span class="fundolar-gateway-tile">
												<span class="fundolar-gateway-tile__inner">
													<span class="fundolar-gateway-tile__row">
														<span class="fundolar-gateway-tile__name"><?php echo esc_html( Fundolar_Payments::gateway_label( $g ) ); ?></span>
										<?php if ( $is_ready ) : ?>
											<span class="fundolar-pill fundolar-pill--ok"><?php esc_html_e( 'Active', 'fundolar' ); ?></span>
										<?php else : ?>
											<span class="fundolar-pill fundolar-pill--soon"><?php esc_html_e( 'Awaiting Central setup', 'fundolar' ); ?></span>
										<?php endif; ?>
										<?php
										$currencies = Fundolar_Payments::gateway_currencies( $g );
										if ( ! empty( $currencies ) ) :
											?>
											<span class="description" style="display:block;margin-top:0.25rem;"><?php echo esc_html( implode( ', ', $currencies ) ); ?></span>
										<?php endif; ?>
													</span>
												</span>
											</span>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
							</td>
						</tr>
						<?php if ( ! empty( $s['platform_sync_error'] ) ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Last error', 'fundolar' ); ?></th>
							<td><p class="description" style="color:#b32d2e;"><?php echo esc_html( $s['platform_sync_error'] ); ?></p></td>
						</tr>
						<?php endif; ?>
						<?php if ( ! empty( $s['platform_last_sync_at'] ) ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Last synced', 'fundolar' ); ?></th>
							<td><p class="description"><?php echo esc_html( $s['platform_last_sync_at'] ); ?></p></td>
						</tr>
						<?php endif; ?>
					</tbody>
				</table>
				<p class="fundolar-connect-actions">
					<button type="submit" class="button button-primary" name="fundolar_connect_platform" value="1"><?php esc_html_e( 'Connect Fundolar Central', 'fundolar' ); ?></button>
					<button type="submit" class="button" name="fundolar_sync_platform" value="1"><?php esc_html_e( 'Sync gateways', 'fundolar' ); ?></button>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Append donation history sync summary to an admin success message.
	 *
	 * @param string               $message Base message.
	 * @param array<string,int>|null $result  Optional sync result from Fundolar_Platform::sync_historical_donations().
	 * @return string
	 */
	private static function append_historical_sync_message( $message, $result = null ) {
		if ( null === $result ) {
			$cached = get_transient( 'fundolar_last_historical_sync' );
			if ( is_array( $cached ) ) {
				delete_transient( 'fundolar_last_historical_sync' );
				$result = $cached;
			}
		}
		if ( ! is_array( $result ) || empty( $result['synced'] ) ) {
			return $message;
		}
		$remaining = isset( $result['remaining'] ) ? (int) $result['remaining'] : 0;
		if ( $remaining > 0 ) {
			/* translators: 1: number synced, 2: number remaining */
			$message .= ' ' . sprintf(
				__( '%1$d past donations were synced to Central (%2$d remaining).', 'fundolar' ),
				(int) $result['synced'],
				$remaining
			);
		} else {
			/* translators: %d: number of donations synced */
			$message .= ' ' . sprintf(
				__( '%d past donations were synced to Central.', 'fundolar' ),
				(int) $result['synced']
			);
		}
		return $message;
	}
}
