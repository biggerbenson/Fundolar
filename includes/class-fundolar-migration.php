<?php
/**
 * Seamless upgrade from the legacy Fundora plugin (data, URLs, shortcodes).
 *
 * @package Fundolar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Fundolar_Migration
 */
class Fundolar_Migration {

	const DB_VERSION_OPTION = 'fundolar_db_version';

	const LEGACY_SETTINGS_OPTION = 'fundora_settings';

	const LEGACY_TABLE = 'fundora_transactions';

	const LEGACY_SHORTCODE = 'fundora_donate';

	const LEGACY_GATEWAY_PARAM = 'fundora_gateway';

	const LEGACY_CENTRAL_HOSTS = array(
		'app.fundora.com',
		'central.fundora.com',
		'fundora.com',
	);

	/**
	 * Run pending migrations once per request (early).
	 */
	public static function boot() {
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}
		self::maybe_migrate();
	}

	/**
	 * Whether legacy Fundora data still needs importing (settings option or transactions table).
	 *
	 * @return bool
	 */
	public static function has_legacy_install() {
		if ( get_option( self::LEGACY_SETTINGS_OPTION, null ) !== null ) {
			return true;
		}
		return self::legacy_table_exists();
	}

	/**
	 * Whether a one-time legacy import has already completed.
	 *
	 * @return bool
	 */
	public static function legacy_import_done() {
		return (bool) get_option( 'fundolar_legacy_import_done', false );
	}

	/**
	 * Run idempotent migration steps.
	 */
	public static function maybe_migrate() {
		$stored_version = (string) get_option( self::DB_VERSION_OPTION, '0' );
		$target_version = defined( 'FUNDOLAR_VERSION' ) ? FUNDOLAR_VERSION : '0';

		if ( self::has_legacy_install() ) {
			$ran_legacy = self::migrate_legacy_install();
			if ( $ran_legacy ) {
				self::mark_migrated_from_fundora();
				update_option( 'fundolar_pending_central_sync', 1, false );
			}
			self::cleanup_legacy_branding();
			update_option( 'fundolar_legacy_import_done', 1, false );
		} elseif ( ! self::legacy_import_done() ) {
			update_option( 'fundolar_legacy_import_done', 1, false );
		}

		if ( version_compare( $stored_version, $target_version, '<' ) ) {
			Fundolar_DB::create_tables();
			if ( version_compare( $stored_version, '1.3.5', '<' ) ) {
				Fundolar_DB::backfill_usd_ledger();
				if ( Fundolar_Payments::is_central_connected() ) {
					Fundolar_Platform::reconcile_central_usd_ledger( 500 );
				}
			}
			update_option( self::DB_VERSION_OPTION, $target_version, false );
			// Queue dashboard welcome so admins see it after install or update.
			update_option( 'fundolar_welcome_notice_version', $target_version, false );
		}
	}

	/**
	 * Import legacy settings, transactions, and content references (in-place upgrade; plugin stays active).
	 *
	 * @return bool True when any legacy data was migrated.
	 */
	private static function migrate_legacy_install() {
		$migrated = false;

		if ( self::migrate_settings() ) {
			$migrated = true;
		}
		if ( self::migrate_transactions_table() ) {
			$migrated = true;
		}
		if ( self::migrate_post_shortcodes() ) {
			$migrated = true;
		}
		return $migrated;
	}

	/**
	 * Copy fundora_settings into fundolar_settings.
	 *
	 * @return bool
	 */
	private static function migrate_settings() {
		$legacy = get_option( self::LEGACY_SETTINGS_OPTION, null );
		if ( null === $legacy || ! is_array( $legacy ) ) {
			return false;
		}

		$current = get_option( Fundolar_Payments::OPTION, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		$merged = wp_parse_args( $current, Fundolar_Payments::get_settings() );
		foreach ( $legacy as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			if ( array_key_exists( $key, $merged ) && self::settings_value_populated( $merged[ $key ] ) ) {
				continue;
			}
			$merged[ $key ] = $value;
		}

		if ( ! empty( $merged['platform_base_url'] ) ) {
			$merged['platform_base_url'] = self::normalize_legacy_central_url( (string) $merged['platform_base_url'] );
		}

		update_option( Fundolar_Payments::OPTION, $merged, false );
		delete_option( self::LEGACY_SETTINGS_OPTION );
		delete_option( 'fundora_version' );
		delete_option( 'fundora_db_version' );

		return true;
	}

	/**
	 * Whether a settings value should be treated as intentionally set.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function settings_value_populated( $value ) {
		if ( is_array( $value ) ) {
			return count( $value ) > 0;
		}
		if ( is_numeric( $value ) ) {
			return (float) $value !== 0.0;
		}
		return '' !== trim( (string) $value );
	}

	/**
	 * Map old Central hostnames to the current production app URL.
	 *
	 * @param string $url Stored URL.
	 * @return string
	 */
	public static function normalize_legacy_central_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}
		$host = strtolower( (string) $parts['host'] );
		if ( in_array( $host, self::LEGACY_CENTRAL_HOSTS, true ) ) {
			return Fundolar_Platform::PLATFORM_BASE_URL;
		}
		return $url;
	}

	/**
	 * Move legacy transaction rows into the Fundolar table.
	 *
	 * @return bool
	 */
	private static function migrate_transactions_table() {
		global $wpdb;

		$legacy_table = $wpdb->prefix . self::LEGACY_TABLE;
		$new_table    = Fundolar_DB::table();

		if ( ! self::table_exists( $legacy_table ) ) {
			return false;
		}

		$new_exists = self::table_exists( $new_table );
		if ( ! $new_exists ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "RENAME TABLE `{$legacy_table}` TO `{$new_table}`" );
			return true;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$legacy_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$legacy_table}`" );
		if ( $legacy_count < 1 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$legacy_table}`" );
			return false;
		}

		$columns = 'created_at, donor_name, donor_email, currency, amount_gross, amount_platform_fee, amount_net, status, gateway, gateway_ref, receipt_amount_display, meta';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "INSERT INTO `{$new_table}` ({$columns}) SELECT {$columns} FROM `{$legacy_table}`" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$legacy_table}`" );

		return true;
	}

	/**
	 * Replace legacy shortcode tags in stored post content (silent content upgrade).
	 *
	 * @return bool
	 */
	private static function migrate_post_shortcodes() {
		global $wpdb;

		$legacy_tag = self::LEGACY_SHORTCODE;
		$new_tag    = 'fundolar_donate';
		$like       = '%' . $wpdb->esc_like( '[' . $legacy_tag ) . '%';

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status NOT IN ('trash','auto-draft')",
				$like
			)
		);
		if ( empty( $ids ) ) {
			return false;
		}

		$updated = 0;
		foreach ( $ids as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post || ! is_string( $post->post_content ) ) {
				continue;
			}
			$content = $post->post_content;
			$content = str_replace( '[' . $legacy_tag, '[' . $new_tag, $content );
			if ( $content === $post->post_content ) {
				continue;
			}
			$wpdb->update(
				$wpdb->posts,
				array( 'post_content' => $content ),
				array( 'ID' => (int) $post_id ),
				array( '%s' ),
				array( '%d' )
			);
			clean_post_cache( (int) $post_id );
			++$updated;
		}

		return $updated > 0;
	}

	/**
	 * Remove legacy scheduled events.
	 */
	private static function clear_legacy_cron_hooks() {
		wp_clear_scheduled_hook( 'fundora_platform_heartbeat' );
	}

	/**
	 * @param string $table Full table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}

	/**
	 * @return bool
	 */
	private static function legacy_table_exists() {
		global $wpdb;
		return self::table_exists( $wpdb->prefix . self::LEGACY_TABLE );
	}

	/**
	 * Whether this site upgraded from the legacy Fundora plugin.
	 *
	 * @return bool
	 */
	public static function migrated_from_fundora() {
		return (bool) get_option( 'fundolar_migrated_from_fundora', false );
	}

	/**
	 * Record that a Fundora → Fundolar data migration completed.
	 */
	public static function mark_migrated_from_fundora() {
		update_option( 'fundolar_migrated_from_fundora', time(), false );
	}

	/**
	 * Remove leftover Fundora options, transients, and cron hooks so only Fundolar remains visible.
	 */
	public static function cleanup_legacy_branding() {
		global $wpdb;

		self::clear_legacy_cron_hooks();

		$legacy_options = array(
			self::LEGACY_SETTINGS_OPTION,
			'fundora_version',
			'fundora_db_version',
			'fundora_settings_migrated',
			'fundora_platform_heartbeat',
		);
		foreach ( $legacy_options as $option ) {
			delete_option( $option );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_fundora_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_fundora_' ) . '%'
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'fundora_' ) . '%'
			)
		);

		$legacy_table = $wpdb->prefix . self::LEGACY_TABLE;
		if ( self::table_exists( $legacy_table ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$legacy_table}`" );
		}
	}

	/**
	 * Whether Central historical sync is still pending after a legacy import.
	 *
	 * @return bool
	 */
	public static function pending_central_sync() {
		return (bool) get_option( 'fundolar_pending_central_sync', false );
	}

	/**
	 * Mark Central historical sync as complete.
	 */
	public static function mark_central_sync_done() {
		delete_option( 'fundolar_pending_central_sync' );
	}

	/**
	 * Register hooks that keep a single Fundolar entry in the Plugins screen.
	 */
	public static function register_bootstrap_hooks() {
		add_filter( 'all_plugins', array( __CLASS__, 'filter_all_plugins' ), 20 );
		// WordPress.org: do not activate/deactivate other plugins or rewrite active_plugins.
		if ( defined( 'FUNDOLAR_WPORG' ) && FUNDOLAR_WPORG ) {
			return;
		}
		add_action( 'activated_plugin', array( __CLASS__, 'on_plugin_activated' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_cleanup_shadow_bootstraps' ), 1 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrader_complete' ), 10, 2 );
	}

	/**
	 * Plugin folder slug (fundolar or legacy fundora).
	 *
	 * @return string
	 */
	public static function plugin_folder_slug() {
		return defined( 'FUNDOLAR_PLUGIN_DIR' ) ? basename( FUNDOLAR_PLUGIN_DIR ) : 'fundolar';
	}

	/**
	 * Canonical bootstrap file for this install.
	 *
	 * @return string Plugin basename, e.g. fundolar/fundolar.php.
	 */
	public static function canonical_bootstrap_basename() {
		return self::plugin_folder_slug() . '/fundolar.php';
	}

	/**
	 * Secondary bootstrap files that must not appear as separate plugins.
	 *
	 * @return string[]
	 */
	public static function shadow_bootstrap_basenames() {
		$folder = self::plugin_folder_slug();
		$shadows = array( $folder . '/fundora.php' );
		// Also clean common leftover paths from failed updates / rebrand.
		$shadows[] = 'fundora/fundora.php';
		$shadows[] = 'fundora/fundolar.php';
		if ( 'fundolar' !== $folder ) {
			$shadows[] = 'fundolar/fundora.php';
		}
		return array_values( array_unique( $shadows ) );
	}

	/**
	 * Hide duplicate Fundolar / Fundora bootstrap entries from the Plugins list.
	 * Keeps a single canonical row: {folder}/fundolar.php (prefers fundolar/fundolar.php).
	 *
	 * @param array<string,array<string,mixed>> $plugins All plugins.
	 * @return array<string,array<string,mixed>>
	 */
	public static function filter_all_plugins( $plugins ) {
		if ( ! is_array( $plugins ) ) {
			return $plugins;
		}

		$canonical = self::canonical_bootstrap_basename();
		$keep      = null;
		$remove    = array();

		foreach ( $plugins as $basename => $data ) {
			$basename = (string) $basename;
			$name     = isset( $data['Name'] ) ? (string) $data['Name'] : '';
			$domain   = isset( $data['TextDomain'] ) ? (string) $data['TextDomain'] : '';
			$file     = strtolower( basename( $basename ) );
			$is_ours  = in_array( $file, array( 'fundolar.php', 'fundora.php' ), true )
				|| in_array( strtolower( $domain ), array( 'fundolar', 'fundora' ), true )
				|| in_array( strtolower( $name ), array( 'fundolar', 'fundora' ), true );

			if ( ! $is_ours ) {
				continue;
			}

			if ( $basename === $canonical || ( null === $keep && 'fundolar.php' === $file ) ) {
				if ( null === $keep ) {
					$keep = $basename;
				} elseif ( $basename !== $keep ) {
					$remove[] = $basename;
				}
				continue;
			}

			$remove[] = $basename;
		}

		// Prefer fundolar/fundolar.php when several folders exist.
		if ( isset( $plugins['fundolar/fundolar.php'] ) ) {
			$keep = 'fundolar/fundolar.php';
		} elseif ( null === $keep ) {
			foreach ( array_keys( $plugins ) as $basename ) {
				if ( 'fundolar.php' === strtolower( basename( (string) $basename ) ) ) {
					$keep = (string) $basename;
					break;
				}
			}
		}

		foreach ( $plugins as $basename => $data ) {
			$basename = (string) $basename;
			$file     = strtolower( basename( $basename ) );
			$name     = isset( $data['Name'] ) ? strtolower( (string) $data['Name'] ) : '';
			$domain   = isset( $data['TextDomain'] ) ? strtolower( (string) $data['TextDomain'] ) : '';
			$is_ours  = in_array( $file, array( 'fundolar.php', 'fundora.php' ), true )
				|| in_array( $domain, array( 'fundolar', 'fundora' ), true )
				|| in_array( $name, array( 'fundolar', 'fundora' ), true );
			if ( $is_ours && $basename !== $keep ) {
				unset( $plugins[ $basename ] );
			}
		}

		return $plugins;
	}

	/**
	 * After activation, ensure only the canonical bootstrap remains.
	 *
	 * @param string $plugin Plugin basename.
	 * @param bool   $network_wide Network activation.
	 */
	public static function on_plugin_activated( $plugin, $network_wide ) {
		unset( $network_wide );
		$canonical = self::canonical_bootstrap_basename();
		$shadows   = self::shadow_bootstrap_basenames();
		if ( ! in_array( $plugin, array_merge( array( $canonical ), $shadows ), true ) ) {
			return;
		}
		self::ensure_canonical_plugin_active();
		self::deactivate_shadow_bootstraps();
		self::remove_shadow_bootstrap_files();
	}

	/**
	 * Cleanup duplicates after plugin updates.
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $options  Options.
	 */
	public static function on_upgrader_complete( $upgrader, $options ) {
		unset( $upgrader );
		if ( empty( $options['action'] ) || 'update' !== $options['action'] || empty( $options['type'] ) || 'plugin' !== $options['type'] ) {
			return;
		}
		$canonical = self::canonical_bootstrap_basename();
		$plugins   = isset( $options['plugins'] ) && is_array( $options['plugins'] ) ? $options['plugins'] : array();
		if ( ! in_array( $canonical, $plugins, true ) ) {
			$hit = false;
			foreach ( self::shadow_bootstrap_basenames() as $shadow ) {
				if ( in_array( $shadow, $plugins, true ) ) {
					$hit = true;
					break;
				}
			}
			if ( ! $hit ) {
				return;
			}
		}
		self::ensure_canonical_plugin_active();
		self::deactivate_shadow_bootstraps();
		self::remove_shadow_bootstrap_files();
	}

	/**
	 * Remove shadow bootstrap files on admin load (idempotent).
	 */
	public static function maybe_cleanup_shadow_bootstraps() {
		self::ensure_canonical_plugin_active();
		self::ensure_canonical_plugin_active();
		self::deactivate_shadow_bootstraps();
		self::remove_shadow_bootstrap_files();
	}

	/**
	 * Ensure the active plugin path points at a single fundolar.php bootstrap.
	 */
	public static function ensure_canonical_plugin_active() {
		$preferred = 'fundolar/fundolar.php';
		$active    = (array) get_option( 'active_plugins', array() );
		$ours      = array();
		foreach ( $active as $basename ) {
			$file = strtolower( basename( (string) $basename ) );
			if ( in_array( $file, array( 'fundolar.php', 'fundora.php' ), true ) ) {
				$ours[] = (string) $basename;
			}
		}
		if ( empty( $ours ) ) {
			return;
		}

		$keep = in_array( $preferred, $ours, true ) ? $preferred : null;
		if ( null === $keep ) {
			foreach ( $ours as $basename ) {
				if ( 'fundolar.php' === strtolower( basename( $basename ) ) ) {
					$keep = $basename;
					break;
				}
			}
		}
		if ( null === $keep ) {
			$keep = self::canonical_bootstrap_basename();
		}

		$changed = false;
		$new     = array();
		foreach ( $active as $basename ) {
			$basename = (string) $basename;
			$file     = strtolower( basename( $basename ) );
			if ( in_array( $file, array( 'fundolar.php', 'fundora.php' ), true ) ) {
				if ( $basename === $keep ) {
					$new[] = $basename;
				} else {
					$changed = true;
				}
				continue;
			}
			$new[] = $basename;
		}
		if ( ! in_array( $keep, $new, true ) ) {
			$new[]   = $keep;
			$changed = true;
		}
		if ( $changed ) {
			update_option( 'active_plugins', array_values( array_unique( $new ) ) );
		}

		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', array() );
			$net_changed = false;
			$keep_time   = isset( $network[ $keep ] ) ? $network[ $keep ] : time();
			foreach ( array_keys( $network ) as $basename ) {
				$file = strtolower( basename( (string) $basename ) );
				if ( in_array( $file, array( 'fundolar.php', 'fundora.php' ), true ) && $basename !== $keep ) {
					unset( $network[ $basename ] );
					$net_changed = true;
				}
			}
			if ( ! isset( $network[ $keep ] ) ) {
				$network[ $keep ] = $keep_time;
				$net_changed      = true;
			}
			if ( $net_changed ) {
				update_site_option( 'active_sitewide_plugins', $network );
			}
		}
	}

	/**
	 * Deactivate non-canonical bootstrap plugins in the same folder.
	 */
	public static function deactivate_shadow_bootstraps() {
		$shadows = self::shadow_bootstrap_basenames();
		if ( empty( $shadows ) ) {
			return;
		}
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		deactivate_plugins( $shadows, true );

		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', array() );
			$changed = false;
			foreach ( $shadows as $shadow ) {
				if ( isset( $network[ $shadow ] ) ) {
					unset( $network[ $shadow ] );
					$changed = true;
				}
			}
			if ( $changed ) {
				update_site_option( 'active_sitewide_plugins', $network );
			}
		}
	}

	/**
	 * Delete secondary bootstrap PHP files so WordPress cannot list them twice.
	 * Paths are resolved from FUNDOLAR_PLUGIN_FILE / FUNDOLAR_PLUGIN_DIR (plugin_dir_path).
	 */
	public static function remove_shadow_bootstrap_files() {
		$paths = array();
		if ( defined( 'FUNDOLAR_PLUGIN_DIR' ) ) {
			$paths[] = trailingslashit( FUNDOLAR_PLUGIN_DIR ) . 'fundora.php';
			$plugins_root = dirname( untrailingslashit( FUNDOLAR_PLUGIN_DIR ) );
			$paths[]      = trailingslashit( $plugins_root ) . 'fundora/fundora.php';
			$paths[]      = trailingslashit( $plugins_root ) . 'fundolar/fundora.php';
		} elseif ( defined( 'FUNDOLAR_PLUGIN_FILE' ) ) {
			$paths[] = trailingslashit( plugin_dir_path( FUNDOLAR_PLUGIN_FILE ) ) . 'fundora.php';
		}

		foreach ( array_unique( $paths ) as $file ) {
			if ( ! is_file( $file ) ) {
				continue;
			}
			if ( function_exists( 'wp_delete_file' ) ) {
				wp_delete_file( $file );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $file );
			}
		}
	}
}
