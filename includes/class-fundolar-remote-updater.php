<?php
/**
 * Pull plugin updates from https://fundolar.com/plugin/fundolar.zip
 *
 * Version is read from info.json (preferred) or version.txt next to the ZIP.
 * The download package is always the hosted ZIP URL.
 *
 * @package Fundolar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Fundolar_Remote_Updater
 */
class Fundolar_Remote_Updater {

	const CACHE_KEY = 'fundolar_remote_update_meta';

	/**
	 * Register update hooks.
	 */
	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'filter_plugins_api' ), 30, 3 );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'filter_plugin_row_meta' ), 15, 2 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_source_directory' ), 10, 4 );
		add_filter( 'upgrader_pre_install', array( __CLASS__, 'cleanup_before_upgrade' ), 10, 2 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'verify_package_before_install' ), 10, 3 );
		add_action( 'in_plugin_update_message-' . plugin_basename( FUNDOLAR_PLUGIN_FILE ), array( __CLASS__, 'update_message' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ), 10, 2 );
		add_action( 'load-plugins.php', array( __CLASS__, 'maybe_cleanup_backup_artifacts' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_plugins_screen_assets' ) );
		add_action( 'wp_ajax_fundolar_check_updates', array( __CLASS__, 'ajax_check_updates' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_manual_update_check' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_update_check_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_backup_artifact_notice' ) );
	}

	/**
	 * Hosted ZIP that WordPress downloads when updating.
	 *
	 * @return string
	 */
	public static function package_url() {
		$url = defined( 'FUNDOLAR_UPDATE_PACKAGE_URL' )
			? (string) FUNDOLAR_UPDATE_PACKAGE_URL
			: 'https://fundolar.com/plugin/fundolar.zip';
		$url = (string) apply_filters( 'fundolar_update_package_url', $url );
		return self::sanitize_update_url( trim( $url ) );
	}

	/**
	 * Companion info.json URL (version metadata).
	 *
	 * @return string
	 */
	public static function info_url() {
		$url = defined( 'FUNDOLAR_UPDATE_INFO_URL' )
			? (string) FUNDOLAR_UPDATE_INFO_URL
			: 'https://fundolar.com/plugin/info.json';
		$url = (string) apply_filters( 'fundolar_update_info_url', $url );
		return self::sanitize_update_url( trim( $url ) );
	}

	/**
	 * Plain-text version.txt fallback URL.
	 *
	 * @return string
	 */
	public static function version_txt_url() {
		$url = defined( 'FUNDOLAR_UPDATE_VERSION_URL' )
			? (string) FUNDOLAR_UPDATE_VERSION_URL
			: 'https://fundolar.com/plugin/version.txt';
		$url = (string) apply_filters( 'fundolar_update_version_url', $url );
		return self::sanitize_update_url( trim( $url ) );
	}

	/**
	 * Allowlist update metadata / package hosts.
	 *
	 * @param string $url Candidate URL.
	 * @return string
	 */
	public static function sanitize_update_url( $url ) {
		$url = esc_url_raw( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || empty( $p['scheme'] ) || empty( $p['host'] ) ) {
			return '';
		}
		if ( 'https' !== strtolower( (string) $p['scheme'] ) ) {
			return '';
		}
		$host = strtolower( (string) $p['host'] );
		$allowed = array( 'fundolar.com', 'www.fundolar.com', 'app.fundolar.com' );
		/**
		 * Filter allowed update package hosts.
		 *
		 * @param string[] $allowed Hosts.
		 */
		$allowed = (array) apply_filters( 'fundolar_allowed_update_hosts', $allowed );
		$allowed = array_map( 'strtolower', array_map( 'strval', $allowed ) );
		if ( ! in_array( $host, $allowed, true ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * @return string
	 */
	public static function plugin_basename() {
		return plugin_basename( FUNDOLAR_PLUGIN_FILE );
	}

	/**
	 * @return string
	 */
	public static function plugin_slug() {
		return dirname( self::plugin_basename() );
	}

	/**
	 * @param object $transient Update transient.
	 * @return object
	 */
	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}
		if ( empty( $transient->checked ) || ! is_array( $transient->checked ) ) {
			return $transient;
		}

		$remote = self::get_remote_metadata();
		if ( empty( $remote['version'] ) || empty( $remote['package'] ) ) {
			return $transient;
		}

		$basename = self::plugin_basename();
		$current  = isset( $transient->checked[ $basename ] ) ? (string) $transient->checked[ $basename ] : FUNDOLAR_VERSION;
		if ( ! version_compare( $remote['version'], $current, '>' ) ) {
			return $transient;
		}

		$plugin_data = get_plugin_data( FUNDOLAR_PLUGIN_FILE, false, false );
		$transient->response[ $basename ] = (object) array(
			'id'           => 'fundolar.com/plugin',
			'slug'         => self::plugin_slug(),
			'plugin'       => $basename,
			'new_version'  => $remote['version'],
			'url'          => ! empty( $remote['url'] ) ? $remote['url'] : ( isset( $plugin_data['PluginURI'] ) ? $plugin_data['PluginURI'] : 'https://fundolar.com/' ),
			'package'      => $remote['package'],
			'icons'        => self::plugin_icons(),
			'requires'     => ! empty( $remote['requires'] ) ? $remote['requires'] : ( ! empty( $plugin_data['RequiresWP'] ) ? $plugin_data['RequiresWP'] : '6.0' ),
			'requires_php' => ! empty( $remote['requires_php'] ) ? $remote['requires_php'] : ( ! empty( $plugin_data['RequiresPHP'] ) ? $plugin_data['RequiresPHP'] : '7.4' ),
			'tested'       => ! empty( $remote['tested'] ) ? $remote['tested'] : self::readme_tested_up_to(),
		);

		return $transient;
	}

	/**
	 * @param false|object|array $result Result.
	 * @param string               $action Action.
	 * @param object|array         $args   Args.
	 * @return false|object|array
	 */
	public static function filter_plugins_api( $result, $action, $args ) {
		$slug = '';
		if ( is_object( $args ) && ! empty( $args->slug ) ) {
			$slug = (string) $args->slug;
		} elseif ( is_array( $args ) && ! empty( $args['slug'] ) ) {
			$slug = (string) $args['slug'];
		}
		if ( 'plugin_information' !== $action || self::plugin_slug() !== $slug ) {
			return $result;
		}

		$remote = self::get_remote_metadata();
		if ( empty( $remote['version'] ) ) {
			return $result;
		}

		if ( is_object( $result ) ) {
			if ( version_compare( $remote['version'], FUNDOLAR_VERSION, '>' ) && ! empty( $remote['package'] ) ) {
				$result->version       = $remote['version'];
				$result->download_link = $remote['package'];
			}
			if ( ! empty( $remote['url'] ) ) {
				$result->homepage = $remote['url'];
			}
		}

		return $result;
	}

	/**
	 * Download update packages ourselves so we can enforce host allowlist + optional SHA-256.
	 *
	 * @param bool|WP_Error $reply    Prior reply.
	 * @param string        $package  Package URL.
	 * @param WP_Upgrader   $upgrader Upgrader.
	 * @return bool|string|WP_Error Local file path, false to continue, or error.
	 */
	public static function verify_package_before_install( $reply, $package, $upgrader ) {
		unset( $upgrader );
		if ( false !== $reply ) {
			return $reply;
		}
		$package = (string) $package;
		$ours    = self::package_url();
		$remote  = self::get_remote_metadata();
		$expected_package = ! empty( $remote['package'] ) ? (string) $remote['package'] : $ours;
		if ( $package !== $ours && $package !== $expected_package ) {
			return $reply;
		}
		$safe = self::sanitize_update_url( $package );
		if ( '' === $safe ) {
			return new WP_Error(
				'fundolar_update_host',
				__( 'Update package URL is not on an allowed Fundolar host.', 'fundolar' )
			);
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = download_url( $safe, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$expected_hash = ! empty( $remote['sha256'] ) ? (string) $remote['sha256'] : '';
		if ( strlen( $expected_hash ) === 64 && is_readable( $file ) ) {
			$actual = hash_file( 'sha256', $file );
			if ( ! is_string( $actual ) || ! hash_equals( $expected_hash, strtolower( $actual ) ) ) {
				wp_delete_file( $file );
				return new WP_Error(
					'fundolar_update_hash',
					__( 'Update package failed integrity check (SHA-256 mismatch).', 'fundolar' )
				);
			}
		}
		return $file;
	}

	/**
	 * Rename extracted package folder to the installed plugin directory.
	 *
	 * @param string      $source        Extracted source path.
	 * @param string      $remote_source Remote source path.
	 * @param WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $hook_extra    Hook extra args.
	 * @return string|WP_Error
	 */
	public static function fix_source_directory( $source, $remote_source, $upgrader, $hook_extra ) {
		unset( $remote_source, $upgrader );

		if ( empty( $hook_extra['plugin'] ) || self::plugin_basename() !== $hook_extra['plugin'] ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return $source;
		}

		$target_name = self::plugin_slug();
		$found       = self::locate_package_root( $source );
		if ( '' === $found ) {
			return new WP_Error(
				'fundolar_remote_package',
				__( 'The downloaded package does not contain Fundolar.', 'fundolar' )
			);
		}

		$source = untrailingslashit( $found );
		if ( basename( $source ) === $target_name ) {
			return $source;
		}

		$new_source = trailingslashit( dirname( $source ) ) . $target_name;
		if ( $wp_filesystem->exists( $new_source ) ) {
			$wp_filesystem->delete( $new_source, true );
		}

		if ( ! $wp_filesystem->move( $source, $new_source, true ) ) {
			return new WP_Error(
				'fundolar_remote_install',
				__( 'Could not move the downloaded Fundolar update into place.', 'fundolar' )
			);
		}

		return $new_source;
	}

	/**
	 * Find the directory that contains includes/fundolar-load.php inside an extracted package.
	 *
	 * @param string $source Extracted source path.
	 * @return string Absolute path with trailing slash, or empty string.
	 */
	private static function locate_package_root( $source ) {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return '';
		}

		$source = trailingslashit( $source );
		$candidates = array(
			$source,
			$source . 'fundolar/',
		);

		foreach ( $candidates as $dir ) {
			if ( $wp_filesystem->exists( $dir . 'includes/fundolar-load.php' ) || $wp_filesystem->exists( $dir . 'includes\\fundolar-load.php' ) ) {
				return $dir;
			}
			if ( $wp_filesystem->exists( $dir . 'fundolar.php' ) ) {
				return $dir;
			}
		}

		// Scan one level of subdirectories (handles odd archive layouts).
		$files = $wp_filesystem->dirlist( $source, false );
		if ( is_array( $files ) ) {
			foreach ( $files as $name => $meta ) {
				if ( empty( $meta['type'] ) || 'd' !== $meta['type'] ) {
					continue;
				}
				$dir = trailingslashit( $source . $name );
				if ( $wp_filesystem->exists( $dir . 'includes/fundolar-load.php' ) || $wp_filesystem->exists( $dir . 'fundolar.php' ) ) {
					return $dir;
				}
			}
		}

		return '';
	}

	/**
	 * @param string[] $links Row meta links.
	 * @param string   $file  Plugin basename.
	 * @return string[]
	 */
	public static function filter_plugin_row_meta( $links, $file ) {
		if ( self::plugin_basename() !== $file || ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%1$s" class="fundolar-check-updates" data-nonce="%2$s">%3$s</a>',
			esc_url( self::manual_check_url() ),
			esc_attr( wp_create_nonce( 'fundolar_check_updates' ) ),
			esc_html__( 'Check for updates', 'fundolar' )
		);

		return $links;
	}

	/**
	 * Scripts for AJAX update check on the Plugins screen.
	 *
	 * @param string $hook Admin hook suffix.
	 */
	public static function enqueue_plugins_screen_assets( $hook ) {
		if ( 'plugins.php' !== $hook || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		wp_enqueue_script(
			'fundolar-update-check',
			FUNDOLAR_PLUGIN_URL . 'resources/js/fundolar-update-check.js',
			array(),
			FUNDOLAR_VERSION,
			true
		);
		wp_localize_script(
			'fundolar-update-check',
			'fundolarUpdateCheck',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => 'fundolar_check_updates',
				'i18n'    => array(
					'checking' => __( 'Checking…', 'fundolar' ),
					'error'    => __( 'Could not check for Fundolar updates. Try again in a moment.', 'fundolar' ),
				),
			)
		);
	}

	/**
	 * AJAX: check hosted package version and return a status message.
	 */
	public static function ajax_check_updates() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error(
				array(
					'status'  => 'error',
					'message' => __( 'Sorry, you are not allowed to update plugins.', 'fundolar' ),
				),
				403
			);
		}

		check_ajax_referer( 'fundolar_check_updates', 'nonce' );

		$result  = self::check_for_updates( true );
		$payload = self::format_check_result( $result );

		if ( 'error' === $payload['status'] ) {
			wp_send_json_error( $payload );
		}

		wp_send_json_success( $payload );
	}

	/**
	 * Build user-facing message payload from a check result.
	 *
	 * @param array{status:string,message?:string,remote_version?:string,current_version?:string,update_url?:string} $result Check result.
	 * @return array{status:string,message:string,remote_version?:string,current_version?:string,update_url?:string,html:string}
	 */
	public static function format_check_result( array $result ) {
		$status  = isset( $result['status'] ) ? (string) $result['status'] : 'error';
		$current = isset( $result['current_version'] ) ? (string) $result['current_version'] : FUNDOLAR_VERSION;
		$remote  = isset( $result['remote_version'] ) ? (string) $result['remote_version'] : '';
		$update  = isset( $result['update_url'] ) ? (string) $result['update_url'] : '';

		if ( 'latest' === $status ) {
			$message = __( 'You are using the latest version of Fundolar.', 'fundolar' );
			$html    = esc_html( $message );
			return array(
				'status'          => 'latest',
				'message'         => $message,
				'remote_version'  => $remote !== '' ? $remote : $current,
				'current_version' => $current,
				'html'            => $html,
			);
		}

		if ( 'update_available' === $status ) {
			$message = sprintf(
				/* translators: %s: new plugin version */
				__( 'A newer version of Fundolar is available: %s.', 'fundolar' ),
				$remote
			);
			$html = esc_html( $message );
			if ( '' !== $update ) {
				$html .= sprintf(
					' <a href="%s"><strong>%s</strong></a>',
					esc_url( $update ),
					esc_html__( 'Update now', 'fundolar' )
				);
			}
			return array(
				'status'          => 'update_available',
				'message'         => $message,
				'remote_version'  => $remote,
				'current_version' => $current,
				'update_url'      => $update,
				'html'            => $html,
			);
		}

		$message = ! empty( $result['message'] )
			? (string) $result['message']
			: __( 'Could not check for Fundolar updates. Try again in a moment.', 'fundolar' );

		return array(
			'status'          => 'error',
			'message'         => $message,
			'current_version' => $current,
			'html'            => esc_html( $message ),
		);
	}

	/**
	 * @return string
	 */
	public static function manual_check_url() {
		return wp_nonce_url(
			add_query_arg( 'fundolar_check_updates', '1', self_admin_url( 'plugins.php' ) ),
			'fundolar_check_updates'
		);
	}

	/**
	 * Non-JS fallback: full-page check from the Plugins screen.
	 */
	public static function handle_manual_update_check() {
		if ( empty( $_GET['fundolar_check_updates'] ) ) {
			return;
		}
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to update plugins.', 'fundolar' ) );
		}
		check_admin_referer( 'fundolar_check_updates' );

		$result = self::check_for_updates( true );
		set_transient( 'fundolar_update_check_notice_' . get_current_user_id(), $result, MINUTE_IN_SECONDS );

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = self_admin_url( 'plugins.php' );
		}
		$redirect = remove_query_arg( array( 'fundolar_check_updates', '_wpnonce' ), $redirect );

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Show the result of a non-JS update check.
	 */
	public static function render_update_check_notice() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$result = get_transient( 'fundolar_update_check_notice_' . get_current_user_id() );
		if ( ! is_array( $result ) || empty( $result['status'] ) ) {
			return;
		}
		delete_transient( 'fundolar_update_check_notice_' . get_current_user_id() );

		$payload = self::format_check_result( $result );
		$class   = 'notice-info';
		if ( 'latest' === $payload['status'] || 'update_available' === $payload['status'] ) {
			$class = 'notice-success';
		} elseif ( 'error' === $payload['status'] ) {
			$class = 'notice-error';
		}

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			wp_kses_post( $payload['html'] )
		);
	}

	/**
	 * Force a fresh remote check and refresh WordPress plugin update state.
	 *
	 * @param bool $refresh_wp_transient Whether to rebuild update_plugins.
	 * @return array{status:string,message?:string,remote_version?:string,current_version?:string,update_url?:string}
	 */
	public static function check_for_updates( $refresh_wp_transient = true ) {
		$current = defined( 'FUNDOLAR_VERSION' ) ? FUNDOLAR_VERSION : '0';
		delete_site_transient( self::CACHE_KEY );

		$remote = self::get_remote_metadata( true );
		if ( empty( $remote['version'] ) || empty( $remote['package'] ) ) {
			return array(
				'status'          => 'error',
				'message'         => __( 'Could not read a Fundolar version from fundolar.com/plugin/. Upload info.json (or version.txt) next to fundolar.zip.', 'fundolar' ),
				'current_version' => $current,
			);
		}

		if ( $refresh_wp_transient ) {
			delete_site_transient( 'update_plugins' );
			if ( ! function_exists( 'wp_update_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/update.php';
			}
			if ( function_exists( 'wp_update_plugins' ) ) {
				wp_update_plugins();
			}
		}

		if ( version_compare( $remote['version'], $current, '>' ) ) {
			$basename = self::plugin_basename();
			return array(
				'status'          => 'update_available',
				'remote_version'  => $remote['version'],
				'current_version' => $current,
				'update_url'      => wp_nonce_url(
					self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $basename ) ),
					'upgrade-plugin_' . $basename
				),
			);
		}

		return array(
			'status'          => 'latest',
			'remote_version'  => $remote['version'],
			'current_version' => $current,
		);
	}

	/**
	 * @param array  $plugin_data Plugin data.
	 * @param object $response    Update response.
	 */
	public static function update_message( $plugin_data, $response ) {
		unset( $plugin_data );
		if ( empty( $response->new_version ) ) {
			return;
		}
		printf(
			' <a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( 'https://fundolar.com/' ),
			esc_html__( 'View Fundolar site', 'fundolar' )
		);
	}

	/**
	 * @param WP_Upgrader $upgrader Upgrader.
	 * @param array       $options  Options.
	 */
	public static function clear_cache( $upgrader, $options ) {
		unset( $upgrader );
		if ( empty( $options['action'] ) || 'update' !== $options['action'] || empty( $options['type'] ) || 'plugin' !== $options['type'] ) {
			return;
		}
		if ( empty( $options['plugins'] ) || ! is_array( $options['plugins'] ) ) {
			return;
		}
		if ( ! in_array( self::plugin_basename(), $options['plugins'], true ) ) {
			return;
		}
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * @param bool $force_refresh Skip cached remote metadata.
	 * @return array{version:string,package:string,url:string,requires?:string,requires_php?:string,tested?:string}
	 */
	private static function get_remote_metadata( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_site_transient( self::CACHE_KEY );
			if ( is_array( $cached ) && ! empty( $cached['version'] ) && ! empty( $cached['package'] ) ) {
				return $cached;
			}
		}

		$package = self::package_url();
		if ( '' === $package ) {
			return array();
		}

		$meta = self::fetch_info_json();
		if ( empty( $meta['version'] ) ) {
			$meta = self::fetch_version_txt();
		}

		if ( empty( $meta['version'] ) ) {
			return array();
		}

		$chosen = array(
			'version'      => self::normalize_version( (string) $meta['version'] ),
			'package'      => ! empty( $meta['package'] ) ? self::sanitize_update_url( (string) $meta['package'] ) : $package,
			'url'          => ! empty( $meta['url'] ) ? esc_url_raw( (string) $meta['url'] ) : 'https://fundolar.com/',
			'requires'     => ! empty( $meta['requires'] ) ? sanitize_text_field( (string) $meta['requires'] ) : '',
			'requires_php' => ! empty( $meta['requires_php'] ) ? sanitize_text_field( (string) $meta['requires_php'] ) : '',
			'tested'       => ! empty( $meta['tested'] ) ? sanitize_text_field( (string) $meta['tested'] ) : '',
			'sha256'       => ! empty( $meta['sha256'] ) ? strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) $meta['sha256'] ) ) : '',
		);

		if ( '' === $chosen['package'] ) {
			$chosen['package'] = $package;
		}

		if ( '' === $chosen['version'] || '' === $chosen['package'] ) {
			return array();
		}

		$ttl = (int) apply_filters( 'fundolar_remote_update_cache_ttl', 6 * HOUR_IN_SECONDS );
		set_site_transient( self::CACHE_KEY, $chosen, max( 300, $ttl ) );

		return $chosen;
	}

	/**
	 * @return array<string,string>
	 */
	private static function fetch_info_json() {
		$response = self::remote_request( self::info_url() );
		if ( is_wp_error( $response ) ) {
			return array();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return array();
		}

		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) || empty( $json['version'] ) ) {
			return array();
		}

		return array(
			'version'      => (string) $json['version'],
			'package'      => isset( $json['download_url'] ) ? (string) $json['download_url'] : ( isset( $json['package'] ) ? (string) $json['package'] : '' ),
			'url'          => isset( $json['homepage'] ) ? (string) $json['homepage'] : ( isset( $json['url'] ) ? (string) $json['url'] : '' ),
			'requires'     => isset( $json['requires'] ) ? (string) $json['requires'] : '',
			'requires_php' => isset( $json['requires_php'] ) ? (string) $json['requires_php'] : '',
			'tested'       => isset( $json['tested'] ) ? (string) $json['tested'] : '',
			'sha256'       => isset( $json['sha256'] ) ? (string) $json['sha256'] : ( isset( $json['package_sha256'] ) ? (string) $json['package_sha256'] : '' ),
		);
	}

	/**
	 * @return array{version:string}
	 */
	private static function fetch_version_txt() {
		$response = self::remote_request( self::version_txt_url() );
		if ( is_wp_error( $response ) ) {
			return array();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return array();
		}

		$body = trim( (string) wp_remote_retrieve_body( $response ) );
		if ( '' === $body ) {
			return array();
		}

		// First non-empty line.
		$lines = preg_split( '/\R/', $body );
		$line  = is_array( $lines ) && isset( $lines[0] ) ? trim( (string) $lines[0] ) : $body;
		$version = self::normalize_version( $line );
		if ( '' === $version ) {
			return array();
		}

		return array( 'version' => $version );
	}

	/**
	 * @param string $version Raw version or tag.
	 * @return string
	 */
	private static function normalize_version( $version ) {
		$version = trim( (string) $version );
		if ( '' === $version ) {
			return '';
		}
		if ( preg_match( '/^v?(\d+(?:\.\d+)+)/i', $version, $matches ) ) {
			return $matches[1];
		}
		return $version;
	}

	/**
	 * @param string $url Request URL.
	 * @return array|WP_Error
	 */
	private static function remote_request( $url ) {
		return wp_remote_get(
			$url,
			array(
				'timeout'   => 20,
				'headers'   => array(
					'Accept'     => 'application/json, text/plain, */*',
					'User-Agent' => 'Fundolar-WordPress-Plugin/' . FUNDOLAR_VERSION . '; ' . home_url( '/' ),
				),
				'sslverify' => (bool) apply_filters( 'fundolar_remote_update_sslverify', true ),
			)
		);
	}

	/**
	 * @return array<string,string>
	 */
	private static function plugin_icons() {
		$icons = array();
		if ( file_exists( FUNDOLAR_PLUGIN_DIR . 'icon-256x256.png' ) ) {
			$icons['2x'] = FUNDOLAR_PLUGIN_URL . 'icon-256x256.png';
		}
		if ( file_exists( FUNDOLAR_PLUGIN_DIR . 'icon-128x128.png' ) ) {
			$icons['1x'] = FUNDOLAR_PLUGIN_URL . 'icon-128x128.png';
		}
		return $icons;
	}

	/**
	 * @return string
	 */
	private static function readme_tested_up_to() {
		$readme = FUNDOLAR_PLUGIN_DIR . 'readme.txt';
		if ( ! is_readable( $readme ) ) {
			return get_bloginfo( 'version' );
		}
		$raw = file_get_contents( $readme );
		if ( ! is_string( $raw ) || ! preg_match( '/^Tested up to:\s*(.+)$/mi', $raw, $matches ) ) {
			return get_bloginfo( 'version' );
		}
		return trim( $matches[1] );
	}

	/**
	 * Remove leftover editor backup files that block in-place plugin updates.
	 *
	 * @return string[] Deleted file paths relative to the plugin root.
	 */
	public static function delete_plugin_backup_artifacts() {
		if ( ! defined( 'FUNDOLAR_PLUGIN_DIR' ) ) {
			return array();
		}

		$root     = wp_normalize_path( FUNDOLAR_PLUGIN_DIR );
		$deleted  = array();
		$patterns = array( '*.bak-bom', '*.bak', '*.bak.*' );

		foreach ( $patterns as $pattern ) {
			foreach ( (array) glob( trailingslashit( $root ) . $pattern, GLOB_NOSORT ) as $path ) {
				if ( ! is_file( $path ) ) {
					continue;
				}
				if ( @unlink( $path ) ) {
					$deleted[] = ltrim( str_replace( $root, '', wp_normalize_path( $path ) ), '/' );
				}
			}
		}

		$includes = trailingslashit( $root ) . 'includes/';
		if ( is_dir( $includes ) ) {
			foreach ( (array) glob( $includes . '*.bak*', GLOB_NOSORT ) as $path ) {
				if ( ! is_file( $path ) ) {
					continue;
				}
				if ( @unlink( $path ) ) {
					$deleted[] = ltrim( str_replace( $root, '', wp_normalize_path( $path ) ), '/' );
				}
			}
		}

		return $deleted;
	}

	/**
	 * @return string[]
	 */
	public static function find_plugin_backup_artifacts() {
		if ( ! defined( 'FUNDOLAR_PLUGIN_DIR' ) ) {
			return array();
		}

		$root  = wp_normalize_path( FUNDOLAR_PLUGIN_DIR );
		$found = array();
		$paths = array(
			trailingslashit( $root ) . 'includes/class-fundolar-admin.php.bak-bom',
		);

		foreach ( (array) glob( trailingslashit( $root ) . '**/*.bak*', GLOB_NOSORT ) as $path ) {
			$paths[] = $path;
		}
		foreach ( (array) glob( trailingslashit( $root ) . 'includes/*.bak*', GLOB_NOSORT ) as $path ) {
			$paths[] = $path;
		}

		foreach ( array_unique( $paths ) as $path ) {
			if ( is_file( $path ) ) {
				$found[] = ltrim( str_replace( $root, '', wp_normalize_path( $path ) ), '/' );
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Delete stale backup files when opening the Plugins screen.
	 */
	public static function maybe_cleanup_backup_artifacts() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		self::delete_plugin_backup_artifacts();
	}

	/**
	 * @param bool|WP_Error $response   Response.
	 * @param array         $hook_extra Hook extra.
	 * @return bool|WP_Error
	 */
	public static function cleanup_before_upgrade( $response, $hook_extra ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( empty( $hook_extra['plugin'] ) || plugin_basename( FUNDOLAR_PLUGIN_FILE ) !== $hook_extra['plugin'] ) {
			return $response;
		}
		self::delete_plugin_backup_artifacts();
		return $response;
	}

	/**
	 * Warn when backup artifacts remain and block updates until removed manually.
	 */
	public static function render_backup_artifact_notice() {
		if ( ! is_admin() || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}

		$artifacts = self::find_plugin_backup_artifacts();
		if ( empty( $artifacts ) ) {
			return;
		}

		$list = implode(
			', ',
			array_map(
				static function ( $path ) {
					return '<code>fundolar/' . esc_html( $path ) . '</code>';
				},
				$artifacts
			)
		);

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s %s</p></div>',
			esc_html__( 'Fundolar update blocked:', 'fundolar' ),
			esc_html__( 'Delete these leftover backup files via your hosting file manager or FTP, then update again:', 'fundolar' ),
			wp_kses_post( $list )
		);
	}
}
