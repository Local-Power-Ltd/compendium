<?php
/**
 * Plugin Name:       Local Power — AI Discovery Files
 * Plugin URI:        https://github.com/Local-Power-Ltd/compendium
 * Description:       Serves llms.txt, llms-full.txt and ai.txt from the domain root, keeping them in sync with the public Local Power Compendium repository on GitHub. Adds rel=alternate pointers to those files and welcomes AI crawlers in robots.txt. Emits no schema.org markup — structured data is left entirely to Rank Math. Adds nothing visible to human visitors and hides nothing from them.
 * Version:           3.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Local Power Ltd
 * License:           GPL-2.0-or-later
 * Text Domain:       local-power-ai-files
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Local_Power_AI_Files {

	const VERSION       = '3.0.0';
	const REWRITE_FLAG  = 'lp_ai_files_flushed_' . self::VERSION;
	const CRON_HOOK     = 'lp_ai_files_refresh';
	const OPTION_PREFIX = 'lp_ai_file_';

	/**
	 * Root-served plain-text files: request path => filename in /data and in the repo.
	 */
	private const ROOT_FILES = array(
		'llms.txt'      => 'llms.txt',
		'llms-full.txt' => 'llms-full.txt',
		'ai.txt'        => 'ai.txt',
	);

	/**
	 * Public repository the files are kept in sync with.
	 *
	 * Content is fetched from here on a schedule (never during a visitor's
	 * request) and stored in the options table. If the fetch fails for any
	 * reason, the copy bundled in /data is served instead, so the URLs never
	 * break and no visitor ever waits on a remote call.
	 */
	private const REPO_URL = 'https://github.com/Local-Power-Ltd/compendium';
	private const RAW_BASE = 'https://raw.githubusercontent.com/Local-Power-Ltd/compendium/main/';

	/** Refresh interval, and the shortest plausible valid file. */
	private const REFRESH_INTERVAL = 6 * HOUR_IN_SECONDS;
	private const MIN_BYTES        = 200;

	public static function init(): void {
		$self = new self();

		add_action( 'init', array( $self, 'register_rewrites' ) );
		add_filter( 'query_vars', array( $self, 'register_query_var' ) );
		add_action( 'template_redirect', array( $self, 'serve_root_file' ) );

		add_action( 'wp_head', array( $self, 'emit_machine_pointers' ), 6 );
		add_filter( 'robots_txt', array( $self, 'filter_robots_txt' ), 10, 2 );

		add_action( self::CRON_HOOK, array( __CLASS__, 'refresh_all' ) );

		register_activation_hook( __FILE__, array( __CLASS__, 'on_activate' ) );
		register_deactivation_hook( __FILE__, array( __CLASS__, 'on_deactivate' ) );
	}

	/* ---------------------------------------------------------------------
	 * Rewrite rules
	 * ------------------------------------------------------------------ */

	public function register_rewrites(): void {
		foreach ( array_keys( self::ROOT_FILES ) as $path ) {
			add_rewrite_rule(
				'^' . preg_quote( $path, '/' ) . '$',
				'index.php?lp_ai_file=' . rawurlencode( $path ),
				'top'
			);
		}

		if ( get_option( self::REWRITE_FLAG ) !== 'yes' ) {
			flush_rewrite_rules( false );
			update_option( self::REWRITE_FLAG, 'yes', false );
		}
	}

	public function register_query_var( array $vars ): array {
		$vars[] = 'lp_ai_file';
		return $vars;
	}

	/* ---------------------------------------------------------------------
	 * Serving
	 * ------------------------------------------------------------------ */

	public function serve_root_file(): void {
		$requested = get_query_var( 'lp_ai_file' );
		if ( ! $requested || ! isset( self::ROOT_FILES[ $requested ] ) ) {
			return;
		}

		$body = self::get_content( $requested );
		if ( null === $body ) {
			return; // Fall through to a normal 404 rather than serving an empty file.
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: all' );
		header( 'Cache-Control: public, max-age=3600' );

		if ( ! headers_sent() ) {
			header_remove( 'Expires' );
			header_remove( 'Pragma' );
		}

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/**
	 * Stored copy from GitHub if we have one, otherwise the bundled file.
	 *
	 * This never makes a network call. Fetching happens only on the cron hook
	 * and on activation, so a visitor request is never blocked on GitHub being
	 * up, fast, or reachable at all.
	 */
	private static function get_content( string $path ): ?string {
		$stored = get_option( self::OPTION_PREFIX . sanitize_key( $path ), '' );
		if ( is_string( $stored ) && strlen( $stored ) >= self::MIN_BYTES ) {
			return $stored;
		}

		$file = plugin_dir_path( __FILE__ ) . 'data/' . self::ROOT_FILES[ $path ];
		if ( is_readable( $file ) ) {
			return (string) file_get_contents( $file );
		}

		return null;
	}

	/* ---------------------------------------------------------------------
	 * Refresh from GitHub
	 * ------------------------------------------------------------------ */

	/**
	 * Pull all three files from the public repository.
	 *
	 * Runs on the cron hook and on activation. A file is only stored if the
	 * response is a 200 carrying plausible plain text — so a GitHub error page,
	 * a redirect to a login, a truncated body or an empty file can never
	 * overwrite good content. Anything that fails simply leaves the previous
	 * copy in place.
	 */
	public static function refresh_all(): void {
		foreach ( array_keys( self::ROOT_FILES ) as $path ) {
			$response = wp_remote_get(
				self::RAW_BASE . self::ROOT_FILES[ $path ],
				array(
					'timeout'     => 10,
					'redirection' => 2,
					'user-agent'  => 'Local-Power-AI-Files/' . self::VERSION . '; ' . home_url( '/' ),
				)
			);

			if ( is_wp_error( $response ) ) {
				continue;
			}

			if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}

			$body = wp_remote_retrieve_body( $response );

			// Must be substantial, and must not be an HTML error page.
			if ( ! is_string( $body ) || strlen( $body ) < self::MIN_BYTES ) {
				continue;
			}
			if ( '<' === substr( ltrim( $body ), 0, 1 ) ) {
				continue;
			}

			update_option( self::OPTION_PREFIX . sanitize_key( $path ), $body, false );
		}

		update_option( 'lp_ai_files_last_refresh', time(), false );
	}

	/* ---------------------------------------------------------------------
	 * Head output — link elements only, no structured data
	 * ------------------------------------------------------------------ */

	/**
	 * Emit machine-readable pointers to the files.
	 *
	 * <link> elements in <head>: part of the document's metadata, not its body.
	 * No browser renders them to a human visitor, and they are not hidden from
	 * anyone either — they appear in source and are served identically to every
	 * user agent.
	 *
	 * No schema.org markup is emitted anywhere in this plugin. Organization,
	 * LocalBusiness, Person, Service and WebSite entities are Rank Math's
	 * responsibility, so there is no possibility of two competing graphs.
	 */
	public function emit_machine_pointers(): void {
		if ( is_404() ) {
			return;
		}

		$links = array(
			array(
				'rel'   => 'alternate',
				'type'  => 'text/markdown',
				'href'  => home_url( '/llms.txt' ),
				'title' => 'Local Power — structured summary for language models (llms.txt)',
			),
			array(
				'rel'   => 'alternate',
				'type'  => 'text/markdown',
				'href'  => home_url( '/llms-full.txt' ),
				'title' => 'Local Power — full machine-readable compendium',
			),
		);

		foreach ( $links as $link ) {
			printf(
				"<link rel=\"%s\" type=\"%s\" href=\"%s\" title=\"%s\" />\n",
				esc_attr( $link['rel'] ),
				esc_attr( $link['type'] ),
				esc_url( $link['href'] ),
				esc_attr( $link['title'] )
			);
		}

		printf(
			"<link rel=\"related\" href=\"%s\" title=\"Local Power Compendium — version-controlled source\" />\n",
			esc_url( self::REPO_URL )
		);
	}

	/* ---------------------------------------------------------------------
	 * robots.txt
	 * ------------------------------------------------------------------ */

	/**
	 * Append AI crawler directives and the llms.txt pointer to robots.txt.
	 *
	 * Note: this filter only runs when WordPress generates robots.txt itself.
	 * If a static robots.txt exists in the web root, the web server serves that
	 * and this filter never fires — see INSTALL.md.
	 */
	public function filter_robots_txt( string $output, $public ): string {
		if ( ! $public ) {
			return $output;
		}

		$append = "\n"
			. "# ---------------------------------------------------------------\n"
			. "# AI and LLM crawlers — explicitly welcome.\n"
			. "# Structured summary: " . home_url( '/llms.txt' ) . "\n"
			. "# Full compendium:    " . home_url( '/llms-full.txt' ) . "\n"
			. "# Source repository:  " . self::REPO_URL . "\n"
			. "# ---------------------------------------------------------------\n\n";

		$agents = array(
			'GPTBot', 'OAI-SearchBot', 'ChatGPT-User',
			'ClaudeBot', 'Claude-Web', 'Claude-SearchBot', 'anthropic-ai',
			'PerplexityBot', 'Perplexity-User',
			'Google-Extended', 'Applebot-Extended', 'Bingbot',
			'CCBot', 'Amazonbot', 'meta-externalagent', 'Bytespider',
			'cohere-ai', 'DuckAssistBot', 'MistralAI-User', 'YouBot',
		);

		foreach ( $agents as $agent ) {
			$append .= "User-agent: {$agent}\n";
			$append .= "Allow: /\n";
			$append .= "Disallow: /wp-admin/\n\n";
		}

		return $output . $append;
	}

	/* ---------------------------------------------------------------------
	 * Activation and deactivation
	 * ------------------------------------------------------------------ */

	public static function on_activate(): void {
		delete_option( self::REWRITE_FLAG );
		flush_rewrite_rules( false );

		// Pull once now, then every six hours.
		self::refresh_all();

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + self::REFRESH_INTERVAL, 'twicedaily', self::CRON_HOOK );
		}
	}

	public static function on_deactivate(): void {
		delete_option( self::REWRITE_FLAG );
		flush_rewrite_rules( false );

		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}
}

Local_Power_AI_Files::init();
