<?php
namespace SignalFlare;

defined( 'ABSPATH' ) || exit;

/**
 * Updates from Signal Tower, or from GitHub Releases, instead of wordpress.org,
 * so "Update available" shows on the Plugins page like any other plugin.
 *
 * Every release is signed on Clint's Mac. A package is installed only if its
 * signature matches the public key below, so even a compromised update server
 * or GitHub account can't push code to a site.
 *
 * Given a GitHub repo, the plugin reads the latest release's release.json asset
 * (the same fields Signal Tower serves) and downloads the zip attached to it.
 *
 * Keep this file identical in Field Mark and Signal Flare apart from the namespace.
 */
class Updater {

	/** Ed25519 public key that release signatures must match. */
	const PUBLIC_KEY = 'jlBkp5lmT6mfo0uMoA65VMBa2reVrec+hO/22+bUGK0=';

	const SERVER = 'https://clintdehner.com/wp-json/signal-tower/v1';

	/** How long to remember the latest release, and how long to wait after a failed check. */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;
	const RETRY_TTL = HOUR_IN_SECONDS;

	private string $basename;
	private string $slug;
	private string $version;

	/** Where release info comes from, and the prefix every package URL must have. */
	private string $release_url;
	private string $package_prefix;

	/**
	 * @param string|null $github_repo "owner/name" to update from GitHub Releases; null for Signal Tower.
	 */
	public function __construct( string $file, string $slug, string $version, ?string $github_repo = null ) {
		$this->basename = plugin_basename( $file );
		$this->slug     = $slug;
		$this->version  = $version;
		if ( $github_repo ) {
			$this->release_url    = 'https://github.com/' . $github_repo . '/releases/latest/download/release.json';
			$this->package_prefix = 'https://github.com/' . $github_repo . '/releases/download/';
		} else {
			$this->release_url    = self::SERVER . '/release/' . rawurlencode( $slug );
			$this->package_prefix = self::SERVER . '/package/' . $slug . '/';
		}
	}

	public function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject' ) );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'download' ), 10, 4 );
	}

	public static function cache_key( string $slug ): string {
		return 'signal_tower_release_' . $slug;
	}

	/**
	 * The latest signed release, or null when there is none or it can't be checked.
	 */
	public function release( bool $fresh = false ): ?array {
		$key = self::cache_key( $this->slug );
		if ( ! $fresh ) {
			$cached = get_site_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached['release'] ?? null;
			}
		}

		$response = wp_remote_get(
			$this->release_url,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		$release = null;
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$release = self::validate( json_decode( wp_remote_retrieve_body( $response ), true ), $this->slug, $this->package_prefix );
		}
		set_site_transient( $key, array( 'release' => $release ), $release ? self::CACHE_TTL : self::RETRY_TTL );
		return $release;
	}

	/**
	 * A release is trusted only if its fields are well formed and its signature
	 * covers this exact plugin, version and file hash.
	 */
	public static function validate( $data, string $slug, string $package_prefix ): ?array {
		if ( ! is_array( $data ) ) {
			return null;
		}
		$version   = (string) ( $data['version'] ?? '' );
		$sha256    = (string) ( $data['sha256'] ?? '' );
		$signature = base64_decode( (string) ( $data['signature'] ?? '' ), true );
		$package   = (string) ( $data['package'] ?? '' );
		if ( ( $data['slug'] ?? '' ) !== $slug
			|| ! preg_match( '/^\d+(\.\d+){1,3}$/', $version )
			|| ! preg_match( '/^[a-f0-9]{64}$/', $sha256 )
			|| false === $signature || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature )
			|| ! str_starts_with( $package, $package_prefix )
			|| ! function_exists( 'sodium_crypto_sign_verify_detached' )
		) {
			return null;
		}
		$message = $slug . "\n" . $version . "\n" . $sha256;
		if ( ! sodium_crypto_sign_verify_detached( $signature, $message, base64_decode( self::PUBLIC_KEY ) ) ) {
			return null;
		}
		return array(
			'slug'         => $slug,
			'version'      => $version,
			'sha256'       => $sha256,
			'package'      => $package,
			'tested'       => sanitize_text_field( (string) ( $data['tested'] ?? '' ) ),
			'requires'     => sanitize_text_field( (string) ( $data['requires'] ?? '' ) ),
			'requires_php' => sanitize_text_field( (string) ( $data['requires_php'] ?? '' ) ),
			'changelog'    => wp_kses_post( (string) ( $data['changelog'] ?? '' ) ),
			'published'    => sanitize_text_field( (string) ( $data['published'] ?? '' ) ),
		);
	}

	public function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$release = $this->release();
		if ( ! $release ) {
			return $transient;
		}
		$item = (object) array(
			'id'           => $this->basename,
			'slug'         => $this->slug,
			'plugin'       => $this->basename,
			'new_version'  => $release['version'],
			'package'      => $release['package'],
			'url'          => '',
			'tested'       => $release['tested'],
			'requires'     => $release['requires'],
			'requires_php' => $release['requires_php'],
		);
		if ( version_compare( $release['version'], $this->version, '>' ) ) {
			$transient->response[ $this->basename ] = $item;
			unset( $transient->no_update[ $this->basename ] );
		} else {
			// Listed as up to date, which also lets WordPress offer its auto-update switch.
			$transient->no_update[ $this->basename ] = $item;
			unset( $transient->response[ $this->basename ] );
		}
		return $transient;
	}

	/** The "View details" window on the Plugins page. */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ( $args->slug ?? '' ) !== $this->slug ) {
			return $result;
		}
		$release = $this->release();
		if ( ! $release ) {
			return $result;
		}
		$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $this->basename, false, false );
		return (object) array(
			'name'          => $data['Name'],
			'slug'          => $this->slug,
			'version'       => $release['version'],
			'author'        => $data['Author'],
			'requires'      => $release['requires'],
			'tested'        => $release['tested'],
			'requires_php'  => $release['requires_php'],
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => esc_html( $data['Description'] ),
				'changelog'   => $release['changelog'] ? $release['changelog'] : esc_html__( 'No notes for this release.', 'signal-flare' ),
			),
		);
	}

	/**
	 * Download the package ourselves and refuse it unless its hash matches the
	 * signed release. Covers the Update button, auto-updates and WP-CLI alike.
	 */
	public function download( $reply, $package, $upgrader, $hook_extra = array() ) {
		$ours = ( $hook_extra['plugin'] ?? '' ) === $this->basename
			|| ( is_string( $package ) && str_starts_with( $package, $this->package_prefix ) );
		if ( false !== $reply || ! $ours ) {
			return $reply;
		}

		$release = $this->release( true );
		if ( ! $release || $release['package'] !== $package ) {
			return new \WP_Error( 'signal_tower_unsigned', __( 'Update refused: this package does not match a signed release.', 'signal-flare' ) );
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = download_url( $package, 120 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( ! hash_equals( $release['sha256'], (string) hash_file( 'sha256', $file ) ) ) {
			wp_delete_file( $file );
			return new \WP_Error( 'signal_tower_hash', __( 'Update refused: the download does not match its signature.', 'signal-flare' ) );
		}
		return $file;
	}
}
