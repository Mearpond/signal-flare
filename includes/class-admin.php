<?php
namespace SignalFlare;

defined( 'ABSPATH' ) || exit;

/**
 * Settings, connection instructions and the activity log.
 */
class Admin {

	const SLUG = 'signal-flare';

	const PER_PAGE = 50;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_signal_flare_save', array( __CLASS__, 'save' ) );
	}

	public static function menu(): void {
		add_menu_page(
			'Signal Flare',
			'Signal Flare',
			Settings::capability(),
			self::SLUG,
			array( __CLASS__, 'render' ),
			'data:image/svg+xml;base64,' . base64_encode( (string) file_get_contents( SIGNAL_FLARE_DIR . 'assets/icon-mono.svg' ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions -- local plugin file.
			81
		);
	}

	public static function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG === $hook ) {
			wp_enqueue_style( 'signal-flare-admin', SIGNAL_FLARE_URL . 'assets/admin.css', array(), SIGNAL_FLARE_VERSION );
		}
	}

	public static function save(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'signal-flare' ), 403 );
		}
		check_admin_referer( 'signal_flare_save' );
		Settings::update(
			array(
				'enabled'    => ! empty( $_POST['enabled'] ),
				'code_tools' => ! empty( $_POST['code_tools'] ),
			)
		);
		Log::add( 'settings', (string) wp_json_encode( Settings::get() ), 'ok', '', 0 );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		$settings = Settings::get();
		$user     = wp_get_current_user();
		$on       = $settings['enabled'];

		echo '<div class="wrap sf-admin">';
		echo '<header class="sf-header"><div class="sf-header__brand"><img src="' . esc_url( SIGNAL_FLARE_URL . 'assets/icon.svg' ) . '" alt=""><h1>Signal Flare</h1></div>';
		echo '<span class="sf-pill ' . ( $on ? 'sf-pill--on' : '' ) . '">' . esc_html( $on ? __( 'On', 'signal-flare' ) : __( 'Off', 'signal-flare' ) ) . '</span></header>';

		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="sf-notice" role="status">' . esc_html__( 'Saved.', 'signal-flare' ) . '</div>';
		}

		echo '<div class="sf-grid">';

		// Switches.
		echo '<section class="sf-card"><h2>' . esc_html__( 'Access', 'signal-flare' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'signal_flare_save' );
		echo '<input type="hidden" name="action" value="signal_flare_save">';
		self::toggle( 'enabled', $settings['enabled'], __( 'Signal Flare is on', 'signal-flare' ), __( 'Master switch. While off, Claude cannot connect at all.', 'signal-flare' ) );
		self::toggle( 'code_tools', $settings['code_tools'], __( 'Allow code and file tools', 'signal-flare' ), __( 'Run PHP, run WP-CLI, and read, write and delete files. This is full control of the site. Turn it off when you are not building.', 'signal-flare' ) );
		echo '<button class="sf-button sf-button--primary">' . esc_html__( 'Save', 'signal-flare' ) . '</button></form></section>';

		// Connection.
		echo '<section class="sf-card"><h2>' . esc_html__( 'Connect Claude', 'signal-flare' ) . '</h2><ol class="sf-steps">';
		if ( ! wp_is_application_passwords_available() ) {
			echo '<li class="sf-warning">' . esc_html__( 'Application Passwords are turned off on this site (often by a security plugin). Signal Flare needs them to log in.', 'signal-flare' ) . '</li>';
		}
		echo '<li>' . wp_kses(
			sprintf(
				/* translators: %s: link to the profile page. */
				__( 'Create an Application Password named "Claude" on <a href="%s">your profile</a>. Copy it; WordPress shows it only once.', 'signal-flare' ),
				esc_url( admin_url( 'profile.php#application-passwords-section' ) )
			),
			array( 'a' => array( 'href' => true ) )
		) . '</li>';
		// The password is read with a hidden prompt, so it never shows on screen or lands in shell history.
		// Named after the site, so several sites can be connected side by side.
		$command = "read -rs \"pw?Application password: \" && echo && claude mcp add --transport http --scope user " . self::connection_name() . ' '
			. Server::url()
			. " --header \"Authorization: Basic \$(printf '%s:%s' '" . $user->user_login . "' \"\$pw\" | base64)\"; unset pw";
		echo '<li>' . esc_html__( 'On your Mac, paste this into Terminal and press Return. When it asks, paste the password and press Return again (it stays hidden):', 'signal-flare' ) . '<pre class="sf-code">' . esc_html( $command ) . '</pre></li>';
		echo '<li>' . esc_html__( 'Start a new Claude session. Signal Flare\'s tools appear automatically.', 'signal-flare' ) . '</li>';
		echo '</ol><p class="sf-muted">' . esc_html__( 'To cut Claude off instantly, switch Signal Flare off or revoke the Application Password.', 'signal-flare' ) . '</p></section>';

		echo '</div>';

		self::render_log();
		echo '</div>';
	}

	/** e.g. "signal-flare-example-com" for https://www.example.com. */
	private static function connection_name(): string {
		$host = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $host ) ), '-' );
		return 'signal-flare' . ( '' !== $slug ? '-' . $slug : '' );
	}

	private static function toggle( string $name, bool $checked, string $label, string $help ): void {
		echo '<label class="sf-toggle"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( $checked, true, false ) . '><span class="sf-toggle__switch" aria-hidden="true"></span><span><strong>' . esc_html( $label ) . '</strong><small>' . esc_html( $help ) . '</small></span></label>';
	}

	private static function render_log(): void {
		$page  = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$total = Log::count();
		$rows  = Log::recent( self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );

		echo '<section class="sf-card sf-card--wide"><h2>' . esc_html__( 'Activity', 'signal-flare' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p class="sf-muted">' . esc_html__( 'Nothing yet. Every action Claude takes through Signal Flare will be listed here.', 'signal-flare' ) . '</p></section>';
			return;
		}
		echo '<table class="sf-table"><thead><tr><th>' . esc_html__( 'When', 'signal-flare' ) . '</th><th>' . esc_html__( 'Tool', 'signal-flare' ) . '</th><th>' . esc_html__( 'Details', 'signal-flare' ) . '</th><th>' . esc_html__( 'Result', 'signal-flare' ) . '</th><th>' . esc_html__( 'User', 'signal-flare' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$time    = strtotime( $row->created . ' UTC' );
			$user    = get_userdata( (int) $row->user_id );
			$summary = (string) $row->summary;
			echo '<tr>';
			echo '<td class="sf-nowrap" title="' . esc_attr( wp_date( 'Y-m-d H:i:s', $time ) ) . '">' . esc_html( sprintf( __( '%s ago', 'signal-flare' ), human_time_diff( $time ) ) ) . '</td>';
			echo '<td><code>' . esc_html( $row->tool ) . '</code></td>';
			echo '<td><details><summary>' . esc_html( mb_strimwidth( $summary, 0, 90, '…' ) ) . '</summary><pre class="sf-code">' . esc_html( $summary ) . '</pre></details></td>';
			echo '<td><span class="sf-pill ' . ( 'ok' === $row->status ? 'sf-pill--on' : 'sf-pill--error' ) . '"' . ( $row->message ? ' title="' . esc_attr( $row->message ) . '"' : '' ) . '>' . esc_html( $row->status ) . '</span> <span class="sf-muted">' . esc_html( (int) $row->duration_ms . ' ms' ) . '</span></td>';
			echo '<td>' . esc_html( $user ? $user->user_login : '#' . $row->user_id ) . '<br><span class="sf-muted">' . esc_html( $row->ip ) . '</span></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<nav class="sf-pager">';
			if ( $page > 1 ) {
				echo '<a class="sf-button" href="' . esc_url( add_query_arg( 'paged', $page - 1 ) ) . '">' . esc_html__( 'Newer', 'signal-flare' ) . '</a>';
			}
			echo '<span class="sf-muted">' . esc_html( sprintf( '%d / %d', $page, $pages ) ) . '</span>';
			if ( $page < $pages ) {
				echo '<a class="sf-button" href="' . esc_url( add_query_arg( 'paged', $page + 1 ) ) . '">' . esc_html__( 'Older', 'signal-flare' ) . '</a>';
			}
			echo '</nav>';
		}
		echo '</section>';
	}
}
