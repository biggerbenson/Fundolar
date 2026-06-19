<?php
/**
 * Front donation form (shortcode).
 *
 * @package Fundolar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Fundolar_Form
 */
class Fundolar_Form {

	/**
	 * Currency symbol for display (preset buttons, fee line).
	 *
	 * @param string $code ISO currency code.
	 * @return string
	 */
	private static function currency_symbol( $code ) {
		$code = strtoupper( substr( sanitize_text_field( $code ), 0, 3 ) );
		$map  = array(
			'USD' => '$',
			'EUR' => '€',
			'GBP' => '£',
			'NGN' => '₦',
			'KES' => 'KSh',
			'GHS' => '₵',
			'ZAR' => 'R',
			'UGX' => 'USh',
			'TZS' => 'TSh',
		);
		return isset( $map[ $code ] ) ? $map[ $code ] : $code . ' ';
	}

	/**
	 * Shortcode output.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'title' => __( 'Support Our Cause', 'fundolar' ),
				'intro' => __( 'Help our organization by donating today! All donations go directly to making a difference for our cause.', 'fundolar' ),
			),
			$atts,
			'fundolar_donate'
		);

		$queried = get_queried_object();
		$pid     = ( $queried && isset( $queried->ID ) ) ? (int) $queried->ID : 0;
		$ret     = $pid ? get_permalink( $pid ) : home_url( '/' );
		Fundolar_Plugin::enqueue_form_assets( $ret ? $ret : home_url( '/' ) );

		$s        = Fundolar_Payments::get_settings();
		$primary  = esc_attr( $s['color_primary'] );
		$accent   = esc_attr( $s['color_accent'] );
		$currency = esc_attr( $s['default_currency'] );
		$amounts = array_map( 'floatval', (array) $s['preset_amounts'] );
		if ( empty( $amounts ) ) {
			$amounts = array( 10, 20, 50, 100, 200 );
		}
		$default_sel    = $amounts[ min( 3, count( $amounts ) - 1 ) ];
		$enabled_toggled = array_values( array_unique( array_map( 'sanitize_key', (array) $s['enabled_gateways'] ) ) );
		$enabled         = Fundolar_Payments::gateways_ready_for_front();
		$has_mobile_ug   = in_array( 'mobile_money_ug', $enabled, true );
		$first_gw        = isset( $enabled[0] ) ? $enabled[0] : '';
		$layout          = isset( $s['form_layout'] ) ? sanitize_key( $s['form_layout'] ) : 'portrait';
		if ( ! array_key_exists( $layout, Fundolar_Payments::form_layouts() ) ) {
			$layout = 'portrait';
		}

		ob_start();
		?>
		<div class="fundolar-card fundolar-donate fundolar-donate--layout-<?php echo esc_attr( $layout ); ?>" style="--fundolar-primary: <?php echo $primary; ?>; --fundolar-accent: <?php echo $accent; ?>;" data-fundolar-root>
			<div class="fundolar-donate__inner">
				<header class="fundolar-donate__hero fundolar-hero">
					<h2 class="fundolar-hero__title"><?php echo esc_html( $atts['title'] ); ?></h2>
					<?php if ( '' !== trim( (string) $atts['intro'] ) ) : ?>
						<p class="fundolar-hero__intro"><?php echo esc_html( $atts['intro'] ); ?></p>
					<?php endif; ?>
					<p class="fundolar-hero__badge"><span class="fundolar-hero__badge-inner"><?php esc_html_e( '100% Secure Donation', 'fundolar' ); ?></span></p>
				</header>

				<form class="fundolar-form" id="fundolar-donate-form" novalidate>
				<?php wp_nonce_field( 'fundolar_donate', 'fundolar_nonce_field', false ); ?>

				<div class="fundolar-giving">
					<header class="fundolar-giving__header">
						<h3 class="fundolar-giving__title" id="fundolar-giving-heading"><?php esc_html_e( 'How much do you want to donate', 'fundolar' ); ?></h3>
						<p class="fundolar-giving__lead"><?php esc_html_e( 'All donations directly impact our organization and help us further our mission.', 'fundolar' ); ?></p>
					</header>

					<section class="fundolar-amount-block" aria-labelledby="fundolar-amount-heading">
						<div class="fundolar-section__head fundolar-amount-block__head">
							<h4 class="fundolar-amount-block__title" id="fundolar-amount-heading"><?php esc_html_e( 'Select an amount to donate', 'fundolar' ); ?></h4>
							<div class="fundolar-currency-wrap">
								<label class="fundolar-sr-only" for="fundolar-currency"><?php esc_html_e( 'Currency', 'fundolar' ); ?></label>
								<select id="fundolar-currency" name="currency" class="fundolar-currency-select">
									<?php
									$codes = array( 'USD', 'EUR', 'GBP', 'NGN', 'KES', 'GHS', 'ZAR', 'UGX', 'TZS' );
									foreach ( $codes as $c ) {
										$sym = self::currency_symbol( $c );
										printf(
											'<option value="%1$s" data-symbol="%4$s"%5$s>%2$s %3$s</option>',
											esc_attr( $c ),
											esc_html( $c ),
											esc_html( $sym ),
											esc_attr( $sym ),
											selected( $currency, $c, false )
										);
									}
									?>
								</select>
								<?php if ( in_array( 'mobile_money_ug', $enabled_toggled, true ) ) : ?>
									<p class="fundolar-field__hint fundolar-currency-hint" id="fundolar-ugx-hint" <?php echo $has_mobile_ug ? 'hidden' : ''; ?>>
										<?php esc_html_e( 'Select UGX to pay with Mobile Money (Uganda).', 'fundolar' ); ?>
									</p>
								<?php endif; ?>
							</div>
						</div>
						<div class="fundolar-presets" role="group" aria-label="<?php esc_attr_e( 'Preset amounts', 'fundolar' ); ?>">
							<?php
							$sym_cur = self::currency_symbol( $currency );
							foreach ( $amounts as $a ) {
								$sel = ( abs( $a - $default_sel ) < 0.001 );
								$txt = $sym_cur . number_format_i18n( $a, 0 );
								printf(
									'<button type="button" class="fundolar-preset%s" data-amount="%s">%s</button>',
									$sel ? ' is-selected' : '',
									esc_attr( (string) $a ),
									esc_html( $txt )
								);
							}
							?>
						</div>
						<div class="fundolar-field fundolar-field--amount-custom">
							<label class="fundolar-field__label fundolar-sr-only" for="fundolar-custom-amount"><?php esc_html_e( 'Enter custom amount', 'fundolar' ); ?></label>
							<input type="number" id="fundolar-custom-amount" name="amount" class="fundolar-input fundolar-input--amount" min="1" step="0.01" value="<?php echo esc_attr( (string) $default_sel ); ?>" required placeholder="<?php esc_attr_e( 'Enter custom amount', 'fundolar' ); ?>" />
						</div>
					</section>

					<div class="fundolar-cover-fees">
						<label class="fundolar-cover-fees__label">
							<input type="checkbox" id="fundolar-cover-fees" name="fundolar_cover_fees" value="1" class="fundolar-cover-fees__input" />
							<span class="fundolar-cover-fees__box" aria-hidden="true"></span>
							<span class="fundolar-cover-fees__text">
								<?php esc_html_e( 'I\'d like to help cover the transaction fees of ', 'fundolar' ); ?>
								<strong class="fundolar-cover-fees__sum" id="fundolar-cover-fees-sum">—</strong>
								<?php esc_html_e( ' for my donation.', 'fundolar' ); ?>
							</span>
						</label>
					</div>
				</div>

				<section class="fundolar-section fundolar-section--donor" aria-labelledby="fundolar-donor-heading">
					<header class="fundolar-donor__header">
						<h3 class="fundolar-section__title fundolar-donor__title" id="fundolar-donor-heading"><?php echo esc_html( __( "Who's giving today", 'fundolar' ) ); ?></h3>
						<p class="fundolar-section__hint fundolar-donor__hint"><?php echo esc_html( __( "We'll never share this information with anyone.", 'fundolar' ) ); ?></p>
					</header>
					<div class="fundolar-form__row fundolar-form__row--name-split">
						<div class="fundolar-field">
							<label class="fundolar-field__label" for="fundolar-first-name"><?php esc_html_e( 'First name', 'fundolar' ); ?> <abbr class="fundolar-required" title="<?php esc_attr_e( 'required', 'fundolar' ); ?>">*</abbr></label>
							<input type="text" id="fundolar-first-name" class="fundolar-input" required autocomplete="given-name" placeholder="<?php esc_attr_e( 'First name', 'fundolar' ); ?>" />
						</div>
						<div class="fundolar-field">
							<label class="fundolar-field__label" for="fundolar-last-name"><?php esc_html_e( 'Last name', 'fundolar' ); ?></label>
							<input type="text" id="fundolar-last-name" class="fundolar-input" autocomplete="family-name" placeholder="<?php esc_attr_e( 'Last name', 'fundolar' ); ?>" />
						</div>
					</div>
					<div class="fundolar-field">
						<label class="fundolar-field__label" for="fundolar-email"><?php esc_html_e( 'Email address', 'fundolar' ); ?> <abbr class="fundolar-required" title="<?php esc_attr_e( 'required', 'fundolar' ); ?>">*</abbr></label>
						<input type="email" id="fundolar-email" name="email" class="fundolar-input" required autocomplete="email" placeholder="<?php esc_attr_e( 'Email address', 'fundolar' ); ?>" />
					</div>
				</section>
				<?php if ( ! empty( $enabled ) ) : ?>
				<section class="fundolar-section fundolar-section--pay" aria-labelledby="fundolar-pay-heading">
					<h3 class="fundolar-section__title" id="fundolar-pay-heading"><?php esc_html_e( 'Payment method', 'fundolar' ); ?></h3>
					<div class="fundolar-gateways" role="radiogroup" aria-label="<?php esc_attr_e( 'Payment method', 'fundolar' ); ?>">
						<?php foreach ( $enabled as $gw ) : ?>
							<?php
							$logo_url = Fundolar_Payments::gateway_logo_url( $gw );
							$gw_label = Fundolar_Payments::gateway_label( $gw );
							?>
							<label class="fundolar-gateway" data-gateway="<?php echo esc_attr( $gw ); ?>">
								<input type="radio" name="gateway" value="<?php echo esc_attr( $gw ); ?>" <?php checked( $gw, $first_gw ); ?> />
								<span class="fundolar-gateway__tile">
									<?php if ( '' !== $logo_url ) : ?>
										<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $gw_label ); ?>" loading="lazy" width="56" height="36" class="fundolar-gateway__logo" onerror="this.style.display='none';this.nextElementSibling.style.display='block';" />
									<?php endif; ?>
									<span class="fundolar-gateway__fallback" style="<?php echo '' !== $logo_url ? 'display:none' : ''; ?>"><?php echo esc_html( $gw_label ); ?></span>
								</span>
								<span class="fundolar-gateway__currency-hint" hidden></span>
							</label>
						<?php endforeach; ?>
					</div>
				</section>
				<?php elseif ( ! empty( $enabled_toggled ) ) : ?>
					<p class="fundolar-notice">
						<?php
						if ( in_array( 'mobile_money_ug', $enabled_toggled, true ) && ! in_array( 'mobile_money_ug', $enabled, true ) ) {
							esc_html_e( 'Mobile Money (UG) is enabled in Central but credentials have not synced yet. Open Fundolar → Settings → Payments and click Sync gateways.', 'fundolar' );
						} else {
							esc_html_e( 'Payment setup is still completing. Please try again shortly.', 'fundolar' );
						}
						?>
					</p>
				<?php else : ?>
					<p class="fundolar-notice"><?php esc_html_e( 'No payment methods are available. Connect Fundolar Central under Settings → Payments and sync gateways.', 'fundolar' ); ?></p>
				<?php endif; ?>
				<div id="fundolar-card-element" class="fundolar-stripe-wrap" hidden></div>
				<div id="fundolar-paypal-container" class="fundolar-paypal-wrap" hidden></div>
				<div id="fundolar-mobile-money-wrap" class="fundolar-mobile-money-wrap" hidden>
					<div class="fundolar-field">
						<label class="fundolar-field__label" for="fundolar-mobile-phone"><?php esc_html_e( 'Mobile Money phone (Uganda)', 'fundolar' ); ?> <abbr class="fundolar-required" title="<?php esc_attr_e( 'required', 'fundolar' ); ?>">*</abbr></label>
						<input type="tel" id="fundolar-mobile-phone" class="fundolar-input" inputmode="tel" autocomplete="tel" placeholder="<?php esc_attr_e( 'e.g. 0771234567', 'fundolar' ); ?>" />
						<p class="fundolar-field__hint"><?php esc_html_e( 'MTN or Airtel number. You will receive a prompt on your phone to approve payment.', 'fundolar' ); ?></p>
					</div>
				</div>
				<div id="fundolar-message" class="fundolar-message" role="status" aria-live="polite"></div>
				<button type="submit" class="fundolar-submit" <?php echo empty( $enabled ) ? 'disabled' : ''; ?>>
					<?php esc_html_e( 'Donate', 'fundolar' ); ?>
				</button>
				</form>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
