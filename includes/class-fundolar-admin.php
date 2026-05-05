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
		Fundolar_Payments::save_settings( $input );
		if ( ! empty( $input['fundolar_connect_platform'] ) ) {
			$connected = Fundolar_Platform::connect_site();
			if ( is_wp_error( $connected ) ) {
				add_settings_error( 'fundolar', 'platform-connect-failed', $connected->get_error_message(), 'error' );
			} else {
				add_settings_error( 'fundolar', 'platform-connected', __( 'Connected. Payment settings were synced.', 'fundolar' ), 'success' );
			}
		} elseif ( ! empty( $input['fundolar_sync_platform'] ) ) {
			$sync = Fundolar_Platform::sync_gateway_settings();
			if ( is_wp_error( $sync ) ) {
				add_settings_error( 'fundolar', 'platform-sync-failed', $sync->get_error_message(), 'error' );
			} else {
				add_settings_error( 'fundolar', 'platform-sync-success', __( 'Latest payment settings were synced.', 'fundolar' ), 'success' );
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
		$subject = sprintf( __( '[Fundolar] %1$s — %2$s', 'fundolar' ), $label, wp_parse_url( $site, PHP_URL_HOST ) );
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
	 * How to use — standalone help page (submenu).
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
				__( 'Connect your site to Fundolar Central, then start collecting donations.', 'fundolar' ),
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
					<h2><?php esc_html_e( 'Get started in a few steps', 'fundolar' ); ?></h2>
				</div>
				<div class="fundolar-card__body">
					<p class="fundolar-card__intro"><?php esc_html_e( 'Follow these steps once. You can reopen this page anytime from the Fundolar menu.', 'fundolar' ); ?></p>
					<ol class="fundolar-howto-steps">
						<li>
							<strong><?php esc_html_e( 'Show the donation form on your site', 'fundolar' ); ?></strong>
							<?php esc_html_e( 'Edit a page or post and add this shortcode where you want the form:', 'fundolar' ); ?>
							<code class="fundolar-howto-code">[fundolar_donate]</code>
							<?php esc_html_e( 'Publish and visit the page to preview.', 'fundolar' ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Connect Fundolar Central', 'fundolar' ); ?></strong>
							<?php esc_html_e( 'Go to', 'fundolar' ); ?>
							<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Fundolar → Settings → Payments', 'fundolar' ); ?></a>.
							<?php esc_html_e( 'Paste your site key, click connect, then sync. Your WordPress site stays aligned with Fundolar Central — no extra gateway setup is required here beyond that.', 'fundolar' ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Set currency and quick amounts (optional)', 'fundolar' ); ?></strong>
							<?php esc_html_e( 'On the General tab in Settings, choose a default currency and preset donation amounts (one per line). Donors see these as quick-select buttons.', 'fundolar' ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Match your brand (optional)', 'fundolar' ); ?></strong>
							<?php esc_html_e( 'Use the Layout tab to pick a form style and colors.', 'fundolar' ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Optional: email notifications', 'fundolar' ); ?></strong>
							<?php esc_html_e( 'Under Advanced in Settings, you can email admins when a donation succeeds and send a receipt to donors.', 'fundolar' ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Track donations', 'fundolar' ); ?></strong>
							<?php esc_html_e( 'View payments anytime under', 'fundolar' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=fundolar-transactions' ) ); ?>"><?php esc_html_e( 'Fundolar → Transactions', 'fundolar' ); ?></a>.
							<?php esc_html_e( 'A summary may also appear on your WordPress dashboard.', 'fundolar' ); ?>
						</li>
					</ol>
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
					<div class="fundolar-card">
						<div class="fundolar-card__head">
							<h2><?php esc_html_e( 'Payments connection', 'fundolar' ); ?></h2>
						</div>
						<div class="fundolar-card__body">
							<table class="fundolar-cred-table" role="presentation">
								<tbody>
									<tr>
										<th scope="row"><label for="fundolar_platform_site_key"><?php esc_html_e( 'Site key', 'fundolar' ); ?></label></th>
										<td>
											<input class="regular-text" name="platform_site_key" id="fundolar_platform_site_key" type="text" value="<?php echo esc_attr( isset( $s['platform_site_key'] ) ? $s['platform_site_key'] : '' ); ?>" placeholder="<?php esc_attr_e( 'lic_...', 'fundolar' ); ?>" autocomplete="off" />
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Connection state', 'fundolar' ); ?></th>
										<td>
											<?php if ( ! empty( $s['platform_api_key'] ) ) : ?>
												<span class="fundolar-pill fundolar-pill--ok"><?php esc_html_e( 'Connected', 'fundolar' ); ?></span>
											<?php else : ?>
												<span class="fundolar-pill fundolar-pill--soon"><?php esc_html_e( 'Not connected', 'fundolar' ); ?></span>
											<?php endif; ?>
											<?php if ( ! empty( $s['platform_sync_error'] ) ) : ?>
												<p class="description" style="color:#b32d2e;"><?php echo esc_html( $s['platform_sync_error'] ); ?></p>
											<?php endif; ?>
											<?php if ( ! empty( $s['platform_last_sync_at'] ) ) : ?>
												<p class="description"><?php printf( esc_html__( 'Last synced: %s', 'fundolar' ), esc_html( $s['platform_last_sync_at'] ) ); ?></p>
											<?php endif; ?>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Withdrawal threshold', 'fundolar' ); ?></th>
										<td>
											<p><strong><?php echo esc_html( '$' . number_format_i18n( max( 100, (float) ( isset( $s['platform_withdraw_threshold'] ) ? $s['platform_withdraw_threshold'] : 100 ) ), 2 ) ); ?></strong></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Payment methods', 'fundolar' ); ?></th>
										<td>
											<div class="fundolar-gateway-grid">
												<?php foreach ( Fundolar_Payments::gateways() as $g ) : ?>
													<span class="fundolar-gateway-tile">
														<span class="fundolar-gateway-tile__inner">
															<span class="fundolar-gateway-tile__row">
																<span class="fundolar-gateway-tile__name"><?php echo esc_html( ucfirst( $g ) ); ?></span>
																<?php if ( in_array( $g, (array) $s['enabled_gateways'], true ) && Fundolar_Payments::gateway_ready( $g ) ) : ?>
																	<span class="fundolar-pill fundolar-pill--ok"><?php esc_html_e( 'Ready', 'fundolar' ); ?></span>
																<?php else : ?>
																	<span class="fundolar-pill fundolar-pill--soon"><?php esc_html_e( 'Not available', 'fundolar' ); ?></span>
																<?php endif; ?>
															</span>
														</span>
													</span>
												<?php endforeach; ?>
											</div>
										</td>
									</tr>
								</tbody>
							</table>
							<p style="margin-top:1rem;">
								<button type="submit" class="button button-primary" name="fundolar_connect_platform" value="1"><?php esc_html_e( 'Connect with site key', 'fundolar' ); ?></button>
								<button type="submit" class="button" name="fundolar_sync_platform" value="1"><?php esc_html_e( 'Sync from central panel', 'fundolar' ); ?></button>
							</p>
						</div>
					</div>
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
						<div id="fundolar-support-thanks" class="fundolar-support-thanks" hidden role="status"><?php esc_html_e( 'Thank you — we have received your message.', 'fundolar' ); ?></div>
						<form id="fundolar-support-form" class="fundolar-support-form" novalidate>
							<table class="fundolar-cred-table" role="presentation">
								<tbody>
									<tr>
										<th scope="row"><label for="fundolar_support_type"><?php esc_html_e( 'Topic', 'fundolar' ); ?></label></th>
										<td>
											<select id="fundolar_support_type" name="support_type" required>
												<option value=""><?php esc_html_e( 'Select…', 'fundolar' ); ?></option>
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
								<th><?php esc_html_e( 'Platform fee', 'fundolar' ); ?></th>
								<th><?php esc_html_e( 'Net to site', 'fundolar' ); ?></th>
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
										<td><?php echo esc_html( $row['currency'] . ' ' . number_format_i18n( (float) $row['receipt_amount_display'], 2 ) ); ?></td>
										<td><?php echo esc_html( number_format_i18n( (float) $row['amount_platform_fee'], 4 ) ); ?></td>
										<td><?php echo esc_html( number_format_i18n( (float) $row['amount_net'], 4 ) ); ?></td>
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
}
