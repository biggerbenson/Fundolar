<?php
/**
 * Dashboard widget: revenue over time.
 *
 * @package Fundolar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Fundolar_Dashboard_Widget
 */
class Fundolar_Dashboard_Widget {

	/**
	 * Register widget.
	 */
	public static function register() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'fundolar_reports',
			__( 'Fundolar: Donation activity', 'fundolar' ),
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render widget markup (chart drawn in JS).
	 */
	public static function render() {
		$kpi = Fundolar_DB::kpi_snapshot( 30 );
		$rev = $kpi['revenue'];
		$avg = $kpi['avg'];
		$don = $kpi['donors'];
		$ref = $kpi['refunds'];

		$remote = Fundolar_Platform::fetch_dashboard_stats();
		if ( ! is_wp_error( $remote ) && is_array( $remote ) ) {
			$rev = array(
				'now'  => isset( $remote['total_donations'] ) ? (float) $remote['total_donations'] : $rev['now'],
				'prev' => 0,
			);
			$don = array(
				'now'  => isset( $remote['total_donors'] ) ? (float) $remote['total_donors'] : $don['now'],
				'prev' => 0,
			);
			$avg_now = ( ! empty( $remote['total_transactions'] ) ) ? ( (float) $remote['total_donations'] / max( 1, (int) $remote['total_transactions'] ) ) : 0;
			$avg     = array(
				'now'  => $avg_now,
				'prev' => 0,
			);
			$ref = array(
				'now'  => 0,
				'prev' => 0,
			);
		}

		$rev_pct = self::pct_change( $rev['now'], $rev['prev'] );
		$avg_pct = self::pct_change( $avg['now'], $avg['prev'] );
		$don_pct = self::pct_change( (float) $don['now'], (float) $don['prev'] );
		$ref_pct = self::pct_change( $ref['now'], $ref['prev'] );

		$tx_url = admin_url( 'admin.php?page=fundolar-transactions' );
		?>
		<div class="fundolar-widget">
			<p class="fundolar-widget__intro"><?php esc_html_e( 'Quick view of completed donations.', 'fundolar' ); ?></p>
			<p><a href="<?php echo esc_url( $tx_url ); ?>"><?php esc_html_e( 'View all transactions', 'fundolar' ); ?></a></p>

			<div class="fundolar-widget__overview">
				<span class="fundolar-widget__overview-label"><?php esc_html_e( 'Overview', 'fundolar' ); ?></span>
				<div class="fundolar-widget__toggles" role="tablist" aria-label="<?php esc_attr_e( 'Period', 'fundolar' ); ?>">
					<button type="button" class="is-active" disabled><?php esc_html_e( 'Day', 'fundolar' ); ?></button>
					<button type="button" disabled><?php esc_html_e( 'Week', 'fundolar' ); ?></button>
					<button type="button" disabled><?php esc_html_e( 'Month', 'fundolar' ); ?></button>
				</div>
			</div>

			<div class="fundolar-widget__chart-wrap">
				<canvas id="fundolar-chart-canvas" width="600" height="220" aria-label="<?php esc_attr_e( 'Donations chart', 'fundolar' ); ?>"></canvas>
			</div>

			<div class="fundolar-widget__kpi">
				<div class="fundolar-kpi">
					<div class="fundolar-kpi__label"><?php esc_html_e( 'Total revenue', 'fundolar' ); ?></div>
					<div class="fundolar-kpi__delta <?php echo esc_attr( $rev_pct['class'] ); ?>"><?php echo wp_kses_post( $rev_pct['html'] ); ?></div>
					<div class="fundolar-kpi__value"><?php echo esc_html( '$' . number_format_i18n( $rev['now'], 2 ) ); ?></div>
				</div>
				<div class="fundolar-kpi">
					<div class="fundolar-kpi__label"><?php esc_html_e( 'Avg. donation', 'fundolar' ); ?></div>
					<div class="fundolar-kpi__delta <?php echo esc_attr( $avg_pct['class'] ); ?>"><?php echo wp_kses_post( $avg_pct['html'] ); ?></div>
					<div class="fundolar-kpi__value"><?php echo esc_html( '$' . number_format_i18n( $avg['now'], 2 ) ); ?></div>
				</div>
				<div class="fundolar-kpi">
					<div class="fundolar-kpi__label"><?php esc_html_e( 'Total donors', 'fundolar' ); ?></div>
					<div class="fundolar-kpi__delta <?php echo esc_attr( $don_pct['class'] ); ?>"><?php echo wp_kses_post( $don_pct['html'] ); ?></div>
					<div class="fundolar-kpi__value"><?php echo esc_html( (string) (int) $don['now'] ); ?></div>
				</div>
				<div class="fundolar-kpi">
					<div class="fundolar-kpi__label"><?php esc_html_e( 'Total refunds', 'fundolar' ); ?></div>
					<div class="fundolar-kpi__delta <?php echo esc_attr( $ref_pct['class'] ); ?>"><?php echo wp_kses_post( $ref_pct['html'] ); ?></div>
					<div class="fundolar-kpi__value"><?php echo esc_html( '$' . number_format_i18n( $ref['now'], 2 ) ); ?></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Percent change presentation.
	 *
	 * @param float $now  Current.
	 * @param float $prev Previous.
	 * @return array{class:string,html:string}
	 */
	private static function pct_change( $now, $prev ) {
		$prev = (float) $prev;
		$now  = (float) $now;
		if ( $prev <= 0 && $now <= 0 ) {
			return array(
				'class' => 'is-neutral',
				'html'  => esc_html__( '0%', 'fundolar' ),
			);
		}
		if ( $prev <= 0 ) {
			return array(
				'class' => 'is-up',
				'html'  => '&uarr; ' . esc_html__( 'new', 'fundolar' ),
			);
		}
		$pct = ( $now - $prev ) / $prev * 100;
		$cls = $pct >= 0 ? 'is-up' : 'is-down';
		$arrow = $pct >= 0 ? '&uarr;' : '&darr;';
		return array(
			'class' => $cls,
			'html'  => $arrow . ' ' . esc_html( number_format_i18n( abs( $pct ), 1 ) ) . '%',
		);
	}
}
