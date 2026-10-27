<?php
namespace SignalFlare;

defined( 'ABSPATH' ) || exit;

/**
 * The tools Claude can call. Each returns data (encoded to JSON for the reply)
 * or throws \RuntimeException with a message Claude can act on.
 */
class Tools {

	/** Largest file write, after base64 decoding. */
	const MAX_WRITE_BYTES = 20 * MB_IN_BYTES;

	/** Largest single read. */
	const MAX_READ_BYTES = 2 * MB_IN_BYTES;

	public static function definitions(): array {
		$tools = array(
			'site_info'      => array(
				'group'       => 'site',
				'description' => 'Overview of this WordPress site: versions, active theme, plugins, pages and Signal Flare status. Call this first.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
			),
			'execute_php'    => array(
				'group'       => 'code',
				'description' => 'Run PHP inside WordPress (all functions, $wpdb and plugins loaded). Use `return` to send back a value. Returns the return value, printed output and any warnings. Omit the opening <?php tag.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'code' => array(
							'type'        => 'string',
							'description' => 'PHP code to run.',
						),
					),
					'required'   => array( 'code' ),
				),
			),
			'run_wp_cli'     => array(
				'group'       => 'code',
				'description' => 'Run a WP-CLI command against this site. Pass arguments as a list, without the leading "wp", e.g. ["plugin", "list", "--format=json"].',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'args'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Command arguments.',
						),
						'timeout' => array(
							'type'        => 'integer',
							'description' => 'Seconds before the command is stopped (default 120, max 600).',
						),
					),
					'required'   => array( 'args' ),
				),
			),
			'create_login_link' => array(
				'group'       => 'code',
				'description' => 'Make a one-time link that logs a browser in as you, for testing the site in a browser. It must be opened within 2 minutes, works once, and the session lasts an hour.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'redirect' => array(
							'type'        => 'string',
							'description' => 'Where to land after logging in: a path on this site, e.g. "wp-admin/admin.php?page=fieldmark" or "/about/". Defaults to the dashboard.',
						),
					),
				),
			),
			'read_file'      => array(
				'group'       => 'files',
				'description' => 'Read a file under the WordPress folder. Relative paths start at the WordPress root. Binary or non-UTF-8 files come back base64-encoded. Use offset/limit (in lines) for large text files.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'path'   => array( 'type' => 'string' ),
						'offset' => array(
							'type'        => 'integer',
							'description' => 'First line to return (0-based).',
						),
						'limit'  => array(
							'type'        => 'integer',
							'description' => 'Number of lines to return.',
						),
					),
					'required'   => array( 'path' ),
				),
			),
			'write_file'     => array(
				'group'       => 'files',
				'description' => 'Create or overwrite a file under the WordPress folder, creating folders as needed. Send binary files (images, zips) with encoding "base64". PHP files are syntax-checked first and refused if they would not parse.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'path'     => array( 'type' => 'string' ),
						'content'  => array( 'type' => 'string' ),
						'encoding' => array(
							'type' => 'string',
							'enum' => array( 'utf8', 'base64' ),
						),
					),
					'required'   => array( 'path', 'content' ),
				),
			),
			'create_upload_link' => array(
				'group'       => 'files',
				'description' => 'Make a one-time link for uploading a large file (a plugin zip, media, a database export) straight to a path on the site with curl, without passing it through this conversation. Works once, for up to 64 MB. Code files (.php and the like) are refused; use write_file for those.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'path'      => array(
							'type'        => 'string',
							'description' => 'Where the file will be saved, e.g. "wp-content/uploads/signal-flare-build/fieldmark.zip".',
						),
						'overwrite' => array(
							'type'        => 'boolean',
							'description' => 'Replace the file if it exists (default false).',
						),
						'minutes'   => array(
							'type'        => 'integer',
							'description' => 'How long the link lasts (1-60, default 10).',
						),
					),
					'required'   => array( 'path' ),
				),
			),
			'edit_file'      => array(
				'group'       => 'files',
				'description' => 'Replace an exact string in a text file. old_string must appear exactly once unless replace_all is true. PHP files are syntax-checked before saving.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'path'        => array( 'type' => 'string' ),
						'old_string'  => array( 'type' => 'string' ),
						'new_string'  => array( 'type' => 'string' ),
						'replace_all' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'path', 'old_string', 'new_string' ),
				),
			),
			'list_directory' => array(
				'group'       => 'files',
				'description' => 'List a folder under the WordPress folder: folders first, then files with sizes.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'path'  => array( 'type' => 'string' ),
						'depth' => array(
							'type'        => 'integer',
							'description' => 'How many levels to descend (1-4, default 1).',
						),
					),
					'required'   => array( 'path' ),
				),
			),
			'delete_file'    => array(
				'group'       => 'files',
				'description' => 'Delete a file, or a folder when recursive is true. Core folders and Signal Flare itself cannot be deleted.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'path'      => array( 'type' => 'string' ),
						'recursive' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'path' ),
				),
			),
		);

		if ( ! Settings::code_tools() ) {
			$tools = array_filter( $tools, static fn( $t ) => 'site' === $t['group'] );
		}
		return $tools;
	}

	/**
	 * Run a tool by name.
	 *
	 * @throws \RuntimeException On any failure.
	 */
	public static function call( string $name, array $args ) {
		$tools = self::definitions();
		if ( ! isset( $tools[ $name ] ) ) {
			throw new \RuntimeException( Settings::code_tools() ? "Unknown tool: $name" : "Unknown tool: $name. Code and file tools are switched off in Signal Flare's settings." );
		}
		$method = 'tool_' . $name;
		return self::$method( $args );
	}

	/* ---------- Site ---------- */

	private static function tool_site_info( array $args ): array {
		global $wpdb;
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$plugins[] = array(
				'file'    => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => is_plugin_active( $file ),
			);
		}
		$pages = array();
		foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => 200 ) ) as $page ) {
			$pages[] = array(
				'id'     => $page->ID,
				'title'  => $page->post_title,
				'status' => $page->post_status,
				'path'   => '/' . get_page_uri( $page ),
			);
		}
		$theme = wp_get_theme();
		return array(
			'site'          => home_url(),
			'name'          => get_bloginfo( 'name' ),
			'wordpress'     => get_bloginfo( 'version' ),
			'php'           => PHP_VERSION,
			'database'      => $wpdb->db_version(),
			'table_prefix'  => $wpdb->prefix,
			'root'          => Paths::root(),
			'theme'         => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'permalinks'    => get_option( 'permalink_structure' ),
			'plugins'       => $plugins,
			'pages'         => $pages,
			'signal_flare'  => array(
				'version'    => SIGNAL_FLARE_VERSION,
				'code_tools' => Settings::code_tools(),
				'user'       => wp_get_current_user()->user_login,
			),
		);
	}

	/* ---------- Code ---------- */

	private static function tool_execute_php( array $args ): array {
		$code = (string) ( $args['code'] ?? '' );
		$code = preg_replace( '/^\s*<\?php\s*/', '', $code );
		$code = preg_replace( '/\?>\s*$/', '', $code );
		if ( '' === trim( $code ) ) {
			throw new \RuntimeException( 'No code to run.' );
		}

		$warnings = array();
		set_error_handler(
			static function ( $severity, $message, $file, $line ) use ( &$warnings ) {
				$warnings[] = "$message (line $line)";
				return true;
			}
		);
		ob_start();
		$error = null;
		$value = null;
		try {
			$value = eval( $code ); // phpcs:ignore Squiz.PHP.Eval -- this tool's purpose, admins only.
		} catch ( \Throwable $e ) {
			$error = get_class( $e ) . ': ' . $e->getMessage() . ' (line ' . $e->getLine() . ')';
		} finally {
			$output = (string) ob_get_clean();
			restore_error_handler();
		}

		if ( null !== $error ) {
			throw new \RuntimeException( $error . ( '' !== $output ? "\nOutput before the error:\n" . $output : '' ) );
		}
		return array(
			'return_value' => self::plain( $value ),
			'output'       => $output,
			'warnings'     => $warnings,
		);
	}

	private static function tool_run_wp_cli( array $args ): array {
		$cli_args = array_map( 'strval', (array) ( $args['args'] ?? array() ) );
		if ( ! $cli_args ) {
			throw new \RuntimeException( 'Pass the command as a list of arguments.' );
		}
		if ( 'wp' === $cli_args[0] ) {
			array_shift( $cli_args );
		}
		if ( ! function_exists( 'proc_open' ) ) {
			throw new \RuntimeException( 'This host does not allow running commands (proc_open is disabled).' );
		}
		$wp = self::find_wp_cli();
		if ( ! $wp ) {
			throw new \RuntimeException( 'WP-CLI was not found on this server.' );
		}

		$timeout = min( 600, max( 5, (int) ( $args['timeout'] ?? 120 ) ) );
		$command = escapeshellarg( $wp ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $cli_args ) ) . ' --path=' . escapeshellarg( Paths::root() ) . ' --no-color';

		$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, Paths::root() );
		if ( ! is_resource( $process ) ) {
			throw new \RuntimeException( 'Could not start WP-CLI.' );
		}
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$stdout = '';
		$stderr = '';
		$start  = time();
		$killed = false;
		while ( true ) {
			$stdout .= (string) stream_get_contents( $pipes[1] );
			$stderr .= (string) stream_get_contents( $pipes[2] );
			$status  = proc_get_status( $process );
			if ( ! $status['running'] ) {
				break;
			}
			if ( time() - $start > $timeout ) {
				proc_terminate( $process );
				$killed = true;
				break;
			}
			usleep( 50000 );
		}
		$stdout .= (string) stream_get_contents( $pipes[1] );
		$stderr .= (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit = proc_close( $process );
		$exit = isset( $status['exitcode'] ) && $status['exitcode'] >= 0 ? $status['exitcode'] : $exit;

		return array(
			'exit_code' => $killed ? null : $exit,
			'timed_out' => $killed,
			'stdout'    => $stdout,
			'stderr'    => $stderr,
		);
	}

	private static function tool_create_login_link( array $args ): array {
		return Links::create_login( (string) ( $args['redirect'] ?? '' ) );
	}

	public static function find_wp_cli(): string {
		$candidates = array_filter( array( (string) apply_filters( 'signal_flare_wp_cli_path', '' ), '/usr/local/bin/wp', '/usr/bin/wp' ) );
		foreach ( $candidates as $candidate ) {
			if ( is_executable( $candidate ) ) {
				return $candidate;
			}
		}
		if ( function_exists( 'shell_exec' ) ) {
			$found = trim( (string) shell_exec( 'command -v wp 2>/dev/null' ) );
			if ( '' !== $found && is_executable( $found ) ) {
				return $found;
			}
		}
		return '';
	}

	/* ---------- Files ---------- */

	private static function tool_read_file( array $args ): array {
		$path = Paths::resolve( (string) ( $args['path'] ?? '' ) );
		if ( ! is_file( $path ) ) {
			throw new \RuntimeException( 'No file at ' . Paths::relative( $path ) );
		}
		$size = (int) filesize( $path );

		if ( isset( $args['offset'] ) || isset( $args['limit'] ) ) {
			$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
			$limit  = max( 1, (int) ( $args['limit'] ?? 2000 ) );
			$lines  = array();
			$file   = new \SplFileObject( $path );
			$file->seek( $offset );
			while ( ! $file->eof() && count( $lines ) < $limit ) {
				$lines[] = rtrim( (string) $file->current(), "\r\n" );
				$file->next();
			}
			return array(
				'path'    => Paths::relative( $path ),
				'size'    => $size,
				'offset'  => $offset,
				'content' => implode( "\n", $lines ),
			);
		}

		if ( $size > self::MAX_READ_BYTES ) {
			throw new \RuntimeException( sprintf( 'The file is %s; read it in parts with offset and limit.', size_format( $size ) ) );
		}
		$content = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$binary  = ! mb_check_encoding( $content, 'UTF-8' ) || str_contains( $content, "\0" );
		return array(
			'path'     => Paths::relative( $path ),
			'size'     => $size,
			'encoding' => $binary ? 'base64' : 'utf8',
			'content'  => $binary ? base64_encode( $content ) : $content,
		);
	}

	private static function tool_write_file( array $args ): array {
		$path = Paths::resolve( (string) ( $args['path'] ?? '' ) );
		self::refuse_self( $path );
		$content = (string) ( $args['content'] ?? '' );
		if ( 'base64' === ( $args['encoding'] ?? 'utf8' ) ) {
			$content = base64_decode( $content, true );
			if ( false === $content ) {
				throw new \RuntimeException( 'The content is not valid base64.' );
			}
		}
		if ( strlen( $content ) > self::MAX_WRITE_BYTES ) {
			throw new \RuntimeException( 'That file is larger than 20 MB.' );
		}
		if ( is_dir( $path ) ) {
			throw new \RuntimeException( 'That path is a folder.' );
		}
		self::check_php( $path, $content );

		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new \RuntimeException( 'Could not create the folder ' . Paths::relative( $dir ) );
		}
		$existed = file_exists( $path );
		if ( false === file_put_contents( $path, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			throw new \RuntimeException( 'Could not write ' . Paths::relative( $path ) );
		}
		self::flush_opcache( $path );
		return array(
			'path'    => Paths::relative( $path ),
			'bytes'   => strlen( $content ),
			'created' => ! $existed,
		);
	}

	private static function tool_create_upload_link( array $args ): array {
		return Links::create_upload( (string) ( $args['path'] ?? '' ), ! empty( $args['overwrite'] ), (int) ( $args['minutes'] ?? 10 ) );
	}

	private static function tool_edit_file( array $args ): array {
		$path = Paths::resolve( (string) ( $args['path'] ?? '' ) );
		self::refuse_self( $path );
		if ( ! is_file( $path ) ) {
			throw new \RuntimeException( 'No file at ' . Paths::relative( $path ) );
		}
		$old = (string) ( $args['old_string'] ?? '' );
		$new = (string) ( $args['new_string'] ?? '' );
		if ( '' === $old ) {
			throw new \RuntimeException( 'old_string must not be empty.' );
		}
		$content = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$count   = substr_count( $content, $old );
		if ( 0 === $count ) {
			throw new \RuntimeException( 'old_string was not found in the file.' );
		}
		if ( $count > 1 && empty( $args['replace_all'] ) ) {
			throw new \RuntimeException( "old_string appears $count times; add more context or set replace_all." );
		}
		$updated = str_replace( $old, $new, $content );
		self::check_php( $path, $updated );
		if ( false === file_put_contents( $path, $updated ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			throw new \RuntimeException( 'Could not write ' . Paths::relative( $path ) );
		}
		self::flush_opcache( $path );
		return array(
			'path'         => Paths::relative( $path ),
			'replacements' => empty( $args['replace_all'] ) ? 1 : $count,
		);
	}

	private static function tool_list_directory( array $args ): array {
		$path = Paths::resolve( (string) ( $args['path'] ?? '.' ) );
		if ( ! is_dir( $path ) ) {
			throw new \RuntimeException( 'No folder at ' . Paths::relative( $path ) );
		}
		$depth   = min( 4, max( 1, (int) ( $args['depth'] ?? 1 ) ) );
		$entries = array();
		$limit   = 1000;
		self::walk( $path, $depth, $entries, $limit );
		return array(
			'path'      => Paths::relative( $path ),
			'entries'   => $entries,
			'truncated' => $limit <= 0,
		);
	}

	private static function walk( string $dir, int $depth, array &$entries, int &$limit ): void {
		$items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- unreadable folders are skipped.
		if ( false === $items ) {
			return;
		}
		$items = array_diff( $items, array( '.', '..' ) );
		usort(
			$items,
			static function ( $a, $b ) use ( $dir ) {
				$da = is_dir( "$dir/$a" );
				$db = is_dir( "$dir/$b" );
				return $da === $db ? strnatcasecmp( $a, $b ) : ( $da ? -1 : 1 );
			}
		);
		foreach ( $items as $item ) {
			if ( $limit-- <= 0 ) {
				return;
			}
			$full  = "$dir/$item";
			$isdir = is_dir( $full );
			$entries[] = $isdir
				? array( 'path' => Paths::relative( $full ) . '/', 'type' => 'dir' )
				: array( 'path' => Paths::relative( $full ), 'type' => 'file', 'size' => (int) @filesize( $full ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $isdir && $depth > 1 && ! is_link( $full ) ) {
				self::walk( $full, $depth - 1, $entries, $limit );
			}
		}
	}

	private static function tool_delete_file( array $args ): array {
		$path = Paths::resolve( (string) ( $args['path'] ?? '' ) );
		self::refuse_self( $path );
		if ( Paths::is_protected_dir( $path ) ) {
			throw new \RuntimeException( Paths::relative( $path ) . ' is a core folder and cannot be deleted.' );
		}
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return array(
				'path'    => Paths::relative( $path ),
				'deleted' => false,
			);
		}
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			if ( empty( $args['recursive'] ) ) {
				throw new \RuntimeException( 'That is a folder; set recursive to delete it and everything in it.' );
			}
			// Refuse if Signal Flare lives inside the folder being removed.
			if ( str_starts_with( rtrim( (string) realpath( SIGNAL_FLARE_DIR ), '/' ), $path . '/' ) ) {
				throw new \RuntimeException( 'That folder contains Signal Flare itself.' );
			}
			$count = self::remove_tree( $path );
		} else {
			if ( ! unlink( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				throw new \RuntimeException( 'Could not delete ' . Paths::relative( $path ) );
			}
			$count = 1;
		}
		return array(
			'path'          => Paths::relative( $path ),
			'deleted'       => true,
			'items_deleted' => $count,
		);
	}

	private static function remove_tree( string $dir ): int {
		$count = 0;
		foreach ( array_diff( (array) scandir( $dir ), array( '.', '..' ) ) as $item ) {
			$full = "$dir/$item";
			if ( is_dir( $full ) && ! is_link( $full ) ) {
				$count += self::remove_tree( $full );
			} else {
				unlink( $full ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				++$count;
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $count + 1;
	}

	/* ---------- Helpers ---------- */

	private static function refuse_self( string $path ): void {
		if ( Paths::is_self( $path ) ) {
			throw new \RuntimeException( 'Signal Flare cannot change its own files; that could cut off this connection. Update it as a plugin instead.' );
		}
	}

	/**
	 * Refuse PHP that wouldn't parse, so a typo can't white-screen the site.
	 */
	private static function check_php( string $path, string $content ): void {
		if ( ! preg_match( '/\.(php|phtml|inc)$/i', $path ) ) {
			return;
		}
		try {
			token_get_all( $content, TOKEN_PARSE );
		} catch ( \ParseError $e ) {
			throw new \RuntimeException( 'Not saved: the PHP would not parse. ' . $e->getMessage() . ' on line ' . $e->getLine() . '.' );
		}
	}

	private static function flush_opcache( string $path ): void {
		if ( function_exists( 'opcache_invalidate' ) && preg_match( '/\.php$/i', $path ) ) {
			@opcache_invalidate( $path, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/**
	 * Make a value JSON-safe: objects become arrays, resources become a label.
	 */
	private static function plain( $value ) {
		if ( is_resource( $value ) ) {
			return '(resource)';
		}
		if ( is_object( $value ) || is_array( $value ) ) {
			$json = wp_json_encode( $value, JSON_PARTIAL_OUTPUT_ON_ERROR );
			return false === $json ? '(value could not be encoded)' : json_decode( $json, true );
		}
		return $value;
	}
}
