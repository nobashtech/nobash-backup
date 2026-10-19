<?php
/**
 * Unified Nobash Backup administration shell.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Admin;

use Nobash\Backup\Security\Capabilities;
use Nobash\Backup\Storage\LocalStorage;

defined( 'ABSPATH' ) || exit;

/**
 * Class AdminPage
 */
class AdminPage {

	/**
	 * Screen slugs.
	 *
	 * @var array<string, string>
	 */
	const SCREENS = array(
		'dashboard'   => 'dashboard',
		'backup'      => 'backup',
		'export'      => 'backup',
		'backups'     => 'backups',
		'restore'     => 'restore',
		'import'      => 'import',
		'automation'  => 'automation',
		'reports'     => 'reports',
		'settings'    => 'settings',
		'help'        => 'help',
		'reset'       => 'settings',
	);

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_post_nobash_backup_download', array( $this, 'download_archive' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'network_admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . NOBASH_BACKUP_BASENAME, array( $this, 'plugin_action_links' ), 100 );
		add_filter( 'network_admin_plugin_action_links_' . NOBASH_BACKUP_BASENAME, array( $this, 'plugin_action_links' ), 100 );
		add_action( 'admin_bar_menu', array( $this, 'register_admin_bar' ), 80 );
	}

	/**
	 * Stream an archive through an authenticated wp-admin request.
	 * The capability and nonce are checked independently of REST authentication.
	 *
	 * @return void
	 */
	public function download_archive() {
		if ( ! Capabilities::current_user_can() ) {
			wp_die( esc_html__( 'You are not allowed to download backups.', 'nobash-backup' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'nobash_backup_download' );
		$filename = isset( $_GET['filename'] ) && is_string( $_GET['filename'] ) ? wp_unslash( $_GET['filename'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked above; resolve_archive strictly allowlists the raw filename and rejects traversal instead of normalizing it.
		$path = ( new LocalStorage() )->resolve_archive( $filename );
		if ( null === $path ) {
			wp_die( esc_html__( 'Archive not found.', 'nobash-backup' ), '', array( 'response' => 404 ) );
		}
		$range = isset( $_SERVER['HTTP_RANGE'] ) && is_string( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		$result = \Nobash\Backup\Http\ArchiveDownload::stream( $path, $range );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => isset( $data['status'] ) ? (int) $data['status'] : 500 ) );
		}
	}

	/**
	 * Add Settings link before Deactivate on Plugins screen.
	 *
	 * @param array<string, string> $links Existing links.
	 * @return array<string, string>
	 */
	public function plugin_action_links( $links ) {
		$url      = $this->admin_page_url( 'settings' );
		$settings = array(
			'settings' => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'nobash-backup' ) . '</a>',
			'docs' => '<a href="https://backup.nobash.com/docs/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Documentation', 'nobash-backup' ) . '</a>',
		);

		return array_merge( $settings, $links );
	}

	/**
	 * Add compact Nobash Backup shortcuts to the WordPress admin toolbar.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin toolbar instance.
	 * @return void
	 */
	public function register_admin_bar( $wp_admin_bar ) {
		if ( ! is_admin() || ! is_user_logged_in() || ! Capabilities::current_user_can() ) {
			return;
		}

		$settings = get_option( 'nobash_backup_settings', array() );
		if ( isset( $settings['show_admin_bar_action'] ) && ! $settings['show_admin_bar_action'] ) {
			return;
		}
		$count_label = '';
		try {
			$count_label = ' (' . number_format_i18n( ( new LocalStorage() )->count_archives() ) . ')';
		} catch ( \Throwable $error ) {
			// A storage failure must not break unrelated wp-admin pages or claim zero backups.
		}
		$count_html = '<span class="nobash-backup-toolbar-count">' . esc_html( $count_label ) . '</span>';

		$wp_admin_bar->add_node(
			array(
				'id'    => 'nobash-backup',
				'title' => '<span class="ab-label">' . esc_html__( 'Nobash Backup', 'nobash-backup' ) . $count_html . '</span>',
				'href'  => $this->admin_page_url( 'backups' ),
				'meta'  => array(
					'class' => 'nobash-backup-toolbar',
				),
			)
		);

		$items = array(
			'backup'    => __( 'New Export', 'nobash-backup' ),
			'backups'   => __( 'Backups', 'nobash-backup' ),
			'restore'   => __( 'Restore', 'nobash-backup' ),
			'import'    => __( 'Import', 'nobash-backup' ),
		);

		foreach ( $items as $view => $label ) {
			$wp_admin_bar->add_node(
				array(
					'id'     => 'nobash-backup-' . sanitize_key( $view ),
					'parent' => 'nobash-backup',
					'title'  => esc_html( $label ) . ( 'backups' === $view ? $count_html : '' ),
					'href'   => $this->admin_page_url( $view ),
				)
			);
		}
	}

	/**
	 * Build a URL to a Nobash Backup admin view.
	 *
	 * @param string $view View slug.
	 * @return string
	 */
	private function admin_page_url( $view = 'dashboard' ) {
		$entry = is_multisite() && is_network_admin() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );

		return add_query_arg(
			array(
				'page' => 'nobash-backup',
				'view' => sanitize_key( $view ),
			),
			$entry
		);
	}

	/**
	 * Register top-level + submenu pages.
	 *
	 * @return void
	 */
	public function register_menu() {
		$cap  = Capabilities::required();
		$icon = 'dashicons-backup';

		add_menu_page(
			__( 'Nobash Backup', 'nobash-backup' ),
			__( 'Nobash Backup', 'nobash-backup' ),
			$cap,
			'nobash-backup',
			array( $this, 'render' ),
			$icon,
			81
		);
	}

	/**
	 * Current screen key for the unified application.
	 *
	 * @return string
	 */
	private function current_screen() {
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( self::SCREENS[ $view ] ) ? self::SCREENS[ $view ] : 'dashboard';
	}

	/**
	 * Whether the current request is a plugin admin screen.
	 *
	 * @param string $hook Hook from admin_enqueue_scripts.
	 * @return bool
	 */
	private function is_plugin_screen( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		/* Enqueue the dashboard only on its own registered admin page. */
		if ( 'nobash-backup' === $page ) {
			return true;
		}

		/* Defensive fallback for the registered top-level menu hook only. */
		return '' === $page && 'toplevel_page_nobash-backup' === (string) $hook;
	}


	/**
	 * Enqueue assets on plugin pages.
	 *
	 * @param string $hook Hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( ! $this->is_plugin_screen( $hook ) ) {
			return;
		}
		if ( ! apply_filters( 'nobash_backup_enqueue_dashboard', true ) ) {
			return;
		}

		$asset_file = NOBASH_BACKUP_PATH . 'assets/dist/admin.asset.php';
		$asset      = file_exists( $asset_file )
			? include $asset_file
			: array(
				'dependencies' => array(),
				'version'      => NOBASH_BACKUP_VERSION,
			);

		$deps = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array();

		$version = isset( $asset['version'] ) ? (string) $asset['version'] : NOBASH_BACKUP_VERSION;
		$script  = NOBASH_BACKUP_URL . 'assets/dist/admin.js';
		$style   = NOBASH_BACKUP_URL . 'assets/dist/admin.css';
		$script_path = NOBASH_BACKUP_PATH . 'assets/dist/admin.js';
		$style_path  = NOBASH_BACKUP_PATH . 'assets/dist/admin.css';

		/* Always include the installed file timestamp in the asset version. This
		 * prevents an updated recovery loop from being hidden behind a browser,
		 * proxy or CDN cache without changing the public plugin version. */
		$script_version = file_exists( $script_path ) ? $version . '.' . (string) filemtime( $script_path ) : $version;
		$style_version  = file_exists( $style_path ) ? $version . '.' . (string) filemtime( $style_path ) : $version;

		if ( file_exists( $script_path ) ) {
			wp_enqueue_script( 'nobash-backup-admin', $script, $deps, $script_version, true );
			wp_set_script_translations( 'nobash-backup-admin', 'nobash-backup' );

			wp_add_inline_script(
				'nobash-backup-admin',
				'if(!Object.hasOwn){Object.hasOwn=function(o,p){return Object.prototype.hasOwnProperty.call(o,p);}};window.addEventListener("error",function(e){var el=document.getElementById("nobash-backup-app");if(!el||!e||!e.message){return;}while(el.firstChild){el.removeChild(el.firstChild);}var notice=document.createElement("div");notice.className="notice notice-error";var message=document.createElement("p");message.textContent="Nobash Backup UI error: "+String(e.message);notice.appendChild(message);el.appendChild(notice);});',
				'before'
			);
		}

		if ( file_exists( NOBASH_BACKUP_PATH . 'assets/dist/admin.css' ) ) {
			wp_enqueue_style( 'nobash-backup-admin', $style, array(), $style_version );
		}


		$admin_entry = is_network_admin() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );
		$page_url = static function ( $view = '' ) use ( $admin_entry ) {
			$args = array( 'page' => 'nobash-backup' );
			if ( '' !== $view ) { $args['view'] = $view; }
			return add_query_arg( $args, $admin_entry );
		};

		$current_user = wp_get_current_user();

		$config = array(
			'root'      => esc_url_raw( rest_url( 'nobash-backup/v1' ) ),
			'restRouteMode' => false,
			'restRouteBase' => '',
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'downloadUrl' => admin_url( 'admin-post.php' ),
			'downloadNonce' => wp_create_nonce( 'nobash_backup_download' ),
			'homeUrl'   => home_url(),
			'siteUrl'   => site_url(),
			'version'   => NOBASH_BACKUP_VERSION,
			'pluginUrl' => NOBASH_BACKUP_URL,
			'logoUrl'   => NOBASH_BACKUP_URL . 'assets/img/icon-128.png',
			'brandWordmarkUrl' => NOBASH_BACKUP_URL . 'assets/img/nobash-backup-final-logo.png',
			'currentUser' => array(
				'name'  => sanitize_text_field( (string) $current_user->display_name ),
				'email' => sanitize_email( (string) $current_user->user_email ),
			),
			'screen'    => $this->current_screen(),
			'pages'     => array(
				'dashboard'   => $page_url(),
				'backup'      => $page_url( 'backup' ),
				'backups'     => $page_url( 'backups' ),
				'restore'     => $page_url( 'restore' ),
				'import'      => $page_url( 'import' ),
				'automation'  => $page_url( 'automation' ),
				'reports'     => $page_url( 'reports' ),
				'settings'    => $page_url( 'settings' ),
				'help'        => $page_url( 'help' ),
			),
		);
		$config = apply_filters( 'nobash_backup_admin_config', $config );

		wp_localize_script( 'nobash-backup-admin', 'nobashBackup', $config );
	}



	/**
	 * Render page mount point.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! Capabilities::current_user_can() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'nobash-backup' ) );
		}
		echo '<div class="wrap nbh-wrap"><div id="nobash-backup-app" class="nbh-mount"><div class="nbh-boot" role="status">' . esc_html__( 'Loading Nobash Backup…', 'nobash-backup' ) . '</div></div></div>';
	}
}
