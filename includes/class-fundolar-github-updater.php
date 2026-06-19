<?php
/**
 * Pull plugin updates from the Fundolar GitHub repository.
 *
 * @package Fundolar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Fundolar_Github_Updater
 */
class Fundolar_Github_Updater {

	const CACHE_KEY = 'fundolar_github_update_meta';

	/**
	 * Register update hooks.
	 */
	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'filter_plugins_api' ), 30, 3 );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'filter_plugin_row_meta' ), 15, 2 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_source_directory' ), 10, 4 );
		add_action( 'in_plugin_update_message-' . plugin_basename( FUNDOLAR_PLUGIN_FILE ), array( __CLASS__, 'update_message' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'handle_manual_update_check' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_update_check_notice' ) );
	}

	/**
	 * GitHub owner/repo slug.
	 *
	 * @return string
	 */
	public static function repo_slug() {
		$repo = defined( 'FUNDOLAR_GITHUB_REPO' ) ? (string) FUNDOLAR_GITHUB_REPO : 'biggerbenson/fundolar';
		$repo = trim( $repo, '/' );
		$repo = (string) apply_filters( 'fundolar_github_repo', $repo );
		return sanitize_text_field( $repo );
	}

	/**
	 * Default branch used when no GitHub release is newer.
	 *
	 * @return string
	 */
	public static function branch() {
		$branch = defined( 'FUNDOLAR_GITHUB_BRANCH' ) ? (string) FUNDOLAR_GITHUB_BRANCH : 'main';
		$branch = (string) apply_filters( 'fundolar_github_branch', $branch );
		return sanitize_text_field( $branch );
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
			'id'            => self::repo_slug(),
			'slug'          => self::plugin_slug(),
			'plugin'        => $basename,
			'new_version'   => $remote['version'],
			'url'           => ! empty( $remote['url'] ) ? $remote['url'] : ( isset( $plugin_data['PluginURI'] ) ? $plugin_data['PluginURI'] : '' ),
			'package'       => $remote['package'],
			'icons'         => self::plugin_icons(),
			'requires'      => ! empty( $plugin_data['RequiresWP'] ) ? $plugin_data['RequiresWP'] : '6.0',
			'requires_php'  => ! empty( $plugin_data['RequiresPHP'] ) ? $plugin_data['RequiresPHP'] : '7.4',
			'tested'        => self::readme_tested_up_to(),
		);

		return $transient;
	}

	/**
	 * Add download link/details for the update thickbox.
	 *
	 * @param false|object|array $result Result.
	 * @param string               $action Action.
	 * @param array                $args   Args.
	 * @return false|object|array
	 */
	public static function filter_plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args['slug'] ) || self::plugin_slug() !== $args['slug'] ) {
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
	 * Rename extracted GitHub archive folder to the installed plugin directory.
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
		$source      = trailingslashit( $source );
		$load_file   = $source . 'includes/fundolar-load.php';

		if ( ! $wp_filesystem->exists( $load_file ) ) {
			return new WP_Error(
				'fundolar_github_package',
				__( 'The downloaded GitHub package does not contain Fundolar.', 'fundolar' )
			);
		}

		if ( basename( $source ) === $target_name ) {
			return untrailingslashit( $source );
		}

		$new_source = trailingslashit( dirname( $source ) ) . $target_name;
		if ( $wp_filesystem->exists( $new_source ) ) {
			$wp_filesystem->delete( $new_source, true );
		}

		if ( ! $wp_filesystem->move( untrailingslashit( $source ), $new_source, true ) ) {
			return new WP_Error(
				'fundolar_github_install',
				__( 'Could not move the downloaded Fundolar update into place.', 'fundolar' )
			);
		}

		return $new_source;
	}

	/**
	 * Add a manual GitHub update check beside the Visit plugin site link.
	 *
	 * @param string[] $links Row meta links.
	 * @param string   $file  Plugin basename.
	 * @return string[]
	 */
	public static function filter_plugin_row_meta( $links, $file ) {
		if ( self::plugin_basename() !== $file || ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::manual_check_url() ),
			esc_html__( 'Check for updates', 'fundolar' )
		);

		return $links;
	}

	/**
	 * Admin URL that forces a fresh GitHub update check.
	 *
	 * @return string
	 */
	public static function manual_check_url() {
		return wp_nonce_url(
			add_query_arg( 'fundolar_check_updates', '1', self_admin_url( 'plugins.php' ) ),
			'fundolar_check_updates'
		);
	}

	/**
	 * Handle manual update checks from the Plugins screen.
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
	 * Show the result of a manual update check.
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

		$class = 'notice-info';
		$message = '';

		if ( 'update_available' === $result['status'] ) {
			$class = 'notice-success';
			$update_url = '';
			if ( ! empty( $result['update_url'] ) ) {
				$update_url = sprintf(
					' <a href="%s"><strong>%s</strong></a>',
					esc_url( (string) $result['update_url'] ),
					esc_html__( 'Update now', 'fundolar' )
				);
			}
			$message = sprintf(
				/* translators: 1: remote version, 2: installed version */
				__( 'Fundolar %1$s is available on GitHub (you have %2$s).', 'fundolar' ),
				esc_html( (string) ( $result['remote_version'] ?? '' ) ),
				esc_html( (string) ( $result['current_version'] ?? FUNDOLAR_VERSION ) )
			) . $update_url;
		} elseif ( 'latest' === $result['status'] ) {
			$class = 'notice-success';
			$message = sprintf(
				/* translators: %s: plugin version */
				__( 'Fundolar is up to date (%s).', 'fundolar' ),
				esc_html( (string) ( $result['remote_version'] ?? FUNDOLAR_VERSION ) )
			);
		} else {
			$class = 'notice-error';
			$message = ! empty( $result['message'] )
				? (string) $result['message']
				: __( 'Could not check GitHub for Fundolar updates. Try again in a moment.', 'fundolar' );
		}

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			wp_kses_post( $message )
		);
	}

	/**
	 * Force a fresh GitHub check and refresh WordPress plugin update state.
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
				'message'         => sprintf(
					/* translators: %s: GitHub repository slug */
					__( 'Could not read a Fundolar version from GitHub (%s).', 'fundolar' ),
					esc_html( self::repo_slug() )
				),
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
		$repo = self::repo_slug();
		printf(
			' <a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( 'https://github.com/' . $repo . '/releases' ),
			esc_html__( 'View release notes on GitHub', 'fundolar' )
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
	 * @param bool $force_refresh Skip cached GitHub metadata.
	 * @return array{version:string,package:string,url:string,source:string}
	 */
	private static function get_remote_metadata( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_site_transient( self::CACHE_KEY );
			if ( is_array( $cached ) && ! empty( $cached['version'] ) && ! empty( $cached['package'] ) ) {
				return $cached;
			}
		}

		$repo = self::repo_slug();
		if ( '' === $repo || false === strpos( $repo, '/' ) ) {
			return array();
		}

		$release = self::fetch_latest_release( $repo );
		$branch  = self::fetch_branch_metadata( $repo, self::branch() );

		$chosen = array();
		if ( ! empty( $release['version'] ) && ! empty( $release['package'] ) ) {
			$chosen = $release;
		}
		if ( ! empty( $branch['version'] ) && ! empty( $branch['package'] ) ) {
			if ( empty( $chosen['version'] ) || version_compare( $branch['version'], $chosen['version'], '>' ) ) {
				$chosen = $branch;
			}
		}

		if ( empty( $chosen['version'] ) || empty( $chosen['package'] ) ) {
			return array();
		}

		$ttl = (int) apply_filters( 'fundolar_github_update_cache_ttl', 12 * HOUR_IN_SECONDS );
		set_site_transient( self::CACHE_KEY, $chosen, max( 300, $ttl ) );

		return $chosen;
	}

	/**
	 * @param string $repo Owner/repo.
	 * @return array{version:string,package:string,url:string,source:string}
	 */
	private static function fetch_latest_release( $repo ) {
		$response = self::github_request( 'https://api.github.com/repos/' . $repo . '/releases/latest' );
		if ( is_wp_error( $response ) ) {
			return array();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return array();
		}
		if ( $code < 200 || $code >= 300 ) {
			return array();
		}

		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) ) {
			return array();
		}

		$version = self::normalize_version( (string) ( $json['tag_name'] ?? '' ) );
		if ( '' === $version ) {
			return array();
		}

		$package = self::pick_release_package( $json );
		if ( '' === $package ) {
			return array();
		}

		return array(
			'version' => $version,
			'package' => $package,
			'url'     => isset( $json['html_url'] ) ? esc_url_raw( (string) $json['html_url'] ) : 'https://github.com/' . $repo,
			'source'  => 'release',
		);
	}

	/**
	 * @param string $repo   Owner/repo.
	 * @param string $branch Branch name.
	 * @return array{version:string,package:string,url:string,source:string}
	 */
	private static function fetch_branch_metadata( $repo, $branch ) {
		$raw_url  = sprintf( 'https://raw.githubusercontent.com/%s/%s/fundolar.php', $repo, rawurlencode( $branch ) );
		$response = self::github_request( $raw_url, false );
		if ( is_wp_error( $response ) ) {
			return array();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return array();
		}

		$version = self::parse_version_header( wp_remote_retrieve_body( $response ) );
		if ( '' === $version ) {
			return array();
		}

		$package = sprintf(
			'https://github.com/%s/archive/refs/heads/%s.zip',
			$repo,
			rawurlencode( $branch )
		);

		return array(
			'version' => $version,
			'package' => $package,
			'url'     => 'https://github.com/' . $repo,
			'source'  => 'branch',
		);
	}

	/**
	 * @param array<string,mixed> $release Release JSON.
	 * @return string
	 */
	private static function pick_release_package( array $release ) {
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( ! is_array( $asset ) || empty( $asset['browser_download_url'] ) ) {
					continue;
				}
				$name = strtolower( (string) ( $asset['name'] ?? '' ) );
				if ( preg_match( '/\.zip$/', $name ) ) {
					return esc_url_raw( (string) $asset['browser_download_url'] );
				}
			}
		}
		if ( ! empty( $release['zipball_url'] ) ) {
			return esc_url_raw( (string) $release['zipball_url'] );
		}
		return '';
	}

	/**
	 * @param string $body Plugin header file contents.
	 * @return string
	 */
	private static function parse_version_header( $body ) {
		if ( ! is_string( $body ) || '' === $body ) {
			return '';
		}
		if ( preg_match( '/^\s*\*\s*Version:\s*([^\r\n*]+)/mi', $body, $matches ) ) {
			return self::normalize_version( trim( $matches[1] ) );
		}
		return '';
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
		if ( preg_match( '/^v(\d+(?:\.\d+)+)$/i', $version, $matches ) ) {
			return $matches[1];
		}
		return $version;
	}

	/**
	 * @param string $url        Request URL.
	 * @param bool   $github_api Whether to send GitHub API accept header.
	 * @return array|WP_Error
	 */
	private static function github_request( $url, $github_api = true ) {
		$headers = array(
			'User-Agent' => 'Fundolar-WordPress-Plugin/' . FUNDOLAR_VERSION . '; ' . home_url( '/' ),
		);
		if ( $github_api ) {
			$headers['Accept'] = 'application/vnd.github+json';
		}
		if ( defined( 'FUNDOLAR_GITHUB_TOKEN' ) && '' !== (string) FUNDOLAR_GITHUB_TOKEN ) {
			$headers['Authorization'] = 'Bearer ' . sanitize_text_field( (string) FUNDOLAR_GITHUB_TOKEN );
		}

		return wp_remote_get(
			$url,
			array(
				'timeout'    => 20,
				'headers'    => $headers,
				'sslverify'  => (bool) apply_filters( 'fundolar_github_sslverify', true ),
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
}
