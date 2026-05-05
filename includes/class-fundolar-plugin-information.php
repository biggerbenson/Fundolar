<?php
/**
 * Plugin details modal on Plugins screen (plugins_api + readme.txt).
 *
 * @package Fundolar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Fundolar_Plugin_Information
 */
class Fundolar_Plugin_Information {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'plugins_api', array( __CLASS__, 'filter_plugins_api' ), 20, 3 );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'filter_plugin_row_meta' ), 10, 2 );
	}

	/**
	 * Slug used in plugin-install.php?plugin=… (directory name).
	 *
	 * @return string
	 */
	public static function get_slug() {
		return dirname( plugin_basename( FUNDOLAR_PLUGIN_FILE ) );
	}

	/**
	 * Supply plugin_information for the thickbox (same API as WordPress.org).
	 *
	 * @param false|object|array $result Result.
	 * @param string               $action Action.
	 * @param array                $args   Args.
	 * @return false|object|array
	 */
	public static function filter_plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args['slug'] ) ) {
			return $result;
		}
		if ( self::get_slug() !== $args['slug'] ) {
			return $result;
		}
		$info = self::build_info_object();
		return $info ? $info : $result;
	}

	/**
	 * Add "View details" link like repository plugins.
	 *
	 * @param string[] $links Row meta.
	 * @param string   $file  Plugin basename.
	 * @return string[]
	 */
	public static function filter_plugin_row_meta( $links, $file ) {
		if ( plugin_basename( FUNDOLAR_PLUGIN_FILE ) !== $file ) {
			return $links;
		}
		$slug = self::get_slug();
		$url  = self_admin_url(
			'plugin-install.php?tab=plugin-information&plugin=' . rawurlencode( $slug ) . '&TB_iframe=true&width=772&height=900'
		);
		/* translators: Hidden accessibility text for plugin details link */
		$label = __( 'More information about Fundolar', 'fundolar' );
		$title = __( 'Fundolar', 'fundolar' );
		$html  = sprintf(
			'<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s" data-title="%s">%s</a>',
			esc_url( $url ),
			esc_attr( $label ),
			esc_attr( $title ),
			esc_html__( 'View details', 'fundolar' )
		);
		array_unshift( $links, $html );
		return $links;
	}

	/**
	 * Enqueue thickbox on Plugins screen so the details modal works.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_thickbox_on_plugins_screen( $hook_suffix ) {
		if ( 'plugins.php' !== $hook_suffix ) {
			return;
		}
		add_thickbox();
	}

	/**
	 * @return object|null
	 */
	private static function build_info_object() {
		$data = get_plugin_data( FUNDOLAR_PLUGIN_FILE, false, false );
		if ( empty( $data['Name'] ) ) {
			return null;
		}
		$slug        = self::get_slug();
		$readme_path = FUNDOLAR_PLUGIN_DIR . 'readme.txt';
		$sections    = is_readable( $readme_path ) ? self::parse_readme_sections( $readme_path ) : array();

		if ( empty( $sections['description'] ) && ! empty( $data['Description'] ) ) {
			$sections['description'] = wp_kses_post( wpautop( $data['Description'] ) );
		}

		$requires     = ! empty( $data['RequiresWP'] ) ? $data['RequiresWP'] : '6.0';
		$requires_php = ! empty( $data['RequiresPHP'] ) ? $data['RequiresPHP'] : '7.4';
		$tested       = self::readme_header_value( $readme_path, 'Tested up to' );
		if ( '' === $tested ) {
			$tested = get_bloginfo( 'version' );
		}

		$author = isset( $data['Author'] ) ? $data['Author'] : '';
		if ( isset( $data['AuthorURI'] ) && $data['AuthorURI'] ) {
			$author = '<a href="' . esc_url( $data['AuthorURI'] ) . '">' . esc_html( $data['Author'] ) . '</a>';
		}

		$obj = (object) array(
			'name'              => $data['Name'],
			'slug'              => $slug,
			'version'           => $data['Version'],
			'author'            => $author,
			'author_profile'    => isset( $data['AuthorURI'] ) ? $data['AuthorURI'] : '',
			'requires'          => $requires,
			'tested'            => $tested,
			'requires_php'      => $requires_php,
			'homepage'          => isset( $data['PluginURI'] ) ? $data['PluginURI'] : '',
			'short_description' => isset( $data['Description'] ) ? $data['Description'] : '',
			'download_link'     => '',
			'sections'          => array_filter( $sections ),
			'last_updated'      => self::readme_last_updated_guess( $readme_path ),
			'num_ratings'       => 0,
			'rating'            => 0,
			'ratings'           => array(),
			'active_installs'   => null,
			'donate_link'       => self::readme_header_value( $readme_path, 'Donate link' ),
		);

		$icons = array();
		if ( file_exists( FUNDOLAR_PLUGIN_DIR . 'icon-256x256.png' ) ) {
			$icons['2x'] = FUNDOLAR_PLUGIN_URL . 'icon-256x256.png';
		}
		if ( file_exists( FUNDOLAR_PLUGIN_DIR . 'icon-128x128.png' ) ) {
			$icons['1x'] = FUNDOLAR_PLUGIN_URL . 'icon-128x128.png';
		}
		if ( ! empty( $icons ) ) {
			$obj->icons = $icons;
		}

		return $obj;
	}

	/**
	 * @param string $readme_path Absolute path.
	 * @return string YYYY-MM-DD
	 */
	private static function readme_last_updated_guess( $readme_path ) {
		if ( is_readable( $readme_path ) ) {
			$m = filemtime( $readme_path );
			if ( $m ) {
				return gmdate( 'Y-m-d', $m );
			}
		}
		return gmdate( 'Y-m-d' );
	}

	/**
	 * @param string $readme_path Absolute path.
	 * @param string $key         Header key (readme style).
	 * @return string
	 */
	private static function readme_header_value( $readme_path, $key ) {
		if ( ! is_readable( $readme_path ) ) {
			return '';
		}
		$raw = file_get_contents( $readme_path );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}
		if ( preg_match( '/^' . preg_quote( $key, '/' ) . ':\s*(.+)$/mi', $raw, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	/**
	 * Parse wordpress.org–style readme.txt into HTML sections.
	 *
	 * @param string $readme_path Absolute path.
	 * @return array<string,string>
	 */
	private static function parse_readme_sections( $readme_path ) {
		$raw = file_get_contents( $readme_path );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		if ( strncmp( $raw, "\xEF\xBB\xBF", 3 ) === 0 ) {
			$raw = substr( $raw, 3 );
		}

		$map = array(
			'Description'               => 'description',
			'Installation'              => 'installation',
			'Frequently Asked Questions' => 'faq',
			'Screenshots'               => 'screenshots',
			'Privacy'                   => 'privacy',
			'Changelog'                 => 'changelog',
		);

		$parts = preg_split( '/^== (.+?) ==\s*$/m', $raw, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( empty( $parts ) || count( $parts ) < 3 ) {
			return array();
		}

		$sections = array();
		for ( $i = 1; $i < count( $parts ); $i += 2 ) {
			$title = isset( $parts[ $i ] ) ? trim( $parts[ $i ] ) : '';
			$body  = isset( $parts[ $i + 1 ] ) ? trim( $parts[ $i + 1 ] ) : '';
			if ( '' === $title || '' === $body ) {
				continue;
			}
			if ( 'Upgrade Notice' === $title ) {
				continue;
			}
			$key = isset( $map[ $title ] ) ? $map[ $title ] : sanitize_title( $title );
			$sections[ $key ] = self::readme_chunk_to_html( $body );
		}

		return array_filter( $sections );
	}

	/**
	 * Convert a readme body fragment to safe HTML.
	 *
	 * @param string $chunk Raw section text.
	 * @return string
	 */
	private static function readme_chunk_to_html( $chunk ) {
		$chunk = trim( $chunk );
		if ( '' === $chunk ) {
			return '';
		}

		$chunk = preg_replace_callback(
			'/^= (.+?) =\s*$/m',
			function ( $m ) {
				return "\n\n<h4>" . esc_html( trim( $m[1] ) ) . "</h4>\n\n";
			},
			$chunk
		);

		$chunk = preg_replace_callback(
			'/\*\*(.+?)\*\*/s',
			function ( $m ) {
				return '<strong>' . esc_html( $m[1] ) . '</strong>';
			},
			$chunk
		);

		$chunk = preg_replace_callback(
			'/`([^`]+)`/',
			function ( $m ) {
				return '<code>' . esc_html( $m[1] ) . '</code>';
			},
			$chunk
		);

		$chunk = preg_replace_callback(
			'/(?:^\* .+$\n?)+/m',
			function ( $m ) {
				$lines = preg_split( '/\R/', trim( $m[0] ) );
				$lis   = '';
				foreach ( $lines as $ln ) {
					if ( preg_match( '/^\* (.+)$/', trim( $ln ), $x ) ) {
						$lis .= '<li>' . wp_kses_post( $x[1] ) . '</li>';
					}
				}
				return $lis ? '<ul>' . $lis . '</ul>' : '';
			},
			$chunk
		);

		$html = wpautop( $chunk );

		$allowed = array(
			'p'          => array(),
			'br'         => array(),
			'strong'     => array(),
			'em'         => array(),
			'a'          => array(
				'href'   => array(),
				'title'  => array(),
				'target' => array(),
				'rel'    => array(),
			),
			'ul'         => array(),
			'ol'         => array(),
			'li'         => array(),
			'h2'         => array(),
			'h3'         => array(),
			'h4'         => array(),
			'code'       => array(),
			'pre'        => array(),
			'blockquote' => array(),
		);

		return wp_kses( $html, $allowed );
	}
}
