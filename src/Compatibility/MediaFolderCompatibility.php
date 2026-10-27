<?php
/**
 * Compatibility checks for optional logical media folders.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Detect media-folder plugins without changing their state or data.
 */
final class MediaFolderCompatibility {
	/**
	 * Register the runtime check and narrowly scoped notices.
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
		add_action( 'network_admin_notices', array( self::class, 'render_notice' ) );
	}

	/**
	 * Recheck active plugins and store a small diagnostic snapshot.
	 *
	 * @return array{overlaps: array<string, string>, conflicts: array<string, string>, checked_at_gmt: string}
	 */
	public static function refresh_snapshot(): array {
		$active      = self::active_plugin_directories();
		$recognized  = self::recognized_plugins();
		$overlaps    = array_intersect_key( $recognized, array_flip( $active ) );
		$conflicts   = array();
		$conflicting = (array) apply_filters( 'boris_stock_media_folder_conflicting_plugins', array() );

		foreach ( $conflicting as $directory => $label ) {
			if ( is_int( $directory ) ) {
				$directory = sanitize_key( (string) $label );
				$label     = $recognized[ $directory ] ?? $directory;
			} else {
				$directory = sanitize_key( (string) $directory );
				$label     = sanitize_text_field( (string) $label );
			}
			if ( in_array( $directory, $active, true ) ) {
				$conflicts[ $directory ] = $label;
			}
		}

		$runtime_conflicts = (array) apply_filters( 'boris_stock_media_folder_runtime_conflicts', array() );
		foreach ( $runtime_conflicts as $key => $label ) {
			$conflicts[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $label );
		}

		$overlaps = array_diff_key( $overlaps, $conflicts );
		$snapshot = array(
			'overlaps'       => $overlaps,
			'conflicts'      => $conflicts,
			'checked_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
		);
		return $snapshot;
	}

	/**
	 * Whether the optional BORIS logical-folder layer may be enabled.
	 */
	public static function folders_available(): bool {
		$snapshot = self::refresh_snapshot();

		return empty( $snapshot['conflicts'] );
	}

	/**
	 * Render a notice only on the Plugins screen and BORIS Stock screens.
	 */
	public static function render_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) && ! current_user_can( 'boris_stock_manage_settings' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by Activator.
			return;
		}
		if ( ! self::is_relevant_admin_screen() ) {
			return;
		}

		$snapshot = self::refresh_snapshot();
		if ( ! empty( $snapshot['conflicts'] ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s is a comma-separated list of conflicting plugin or component names. */
						__( 'BORIS Stock detected a media-folder conflict with %s. BORIS photo folders are disabled. Deactivate the conflicting plugin before using BORIS photo folders. All other BORIS Stock functions remain available.', 'boris-stock-inventory-management' ),
						implode( ', ', $snapshot['conflicts'] )
					)
				)
			);
			return;
		}

		if ( ! empty( $snapshot['overlaps'] ) ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s is a comma-separated list of active media-folder plugin names. */
						__( 'BORIS Stock detected another media-folder plugin: %s. No confirmed conflict is known. BORIS does not change that plugin\'s folders, attachment data or media files.', 'boris-stock-inventory-management' ),
						implode( ', ', $snapshot['overlaps'] )
					)
				)
			);
		}
	}

	/**
	 * Known plugins that may overlap with the future BORIS logical-folder UI.
	 * Recognition alone is not treated as a confirmed conflict.
	 *
	 * @return array<string, string>
	 */
	private static function recognized_plugins(): array {
		return array(
			'filebird'                => 'FileBird',
			'folderify'               => 'Folderify',
			'folders'                 => 'Folders',
			'grafucci-folders'        => 'Grafucci Folders',
			'happyfiles'              => 'HappyFiles',
			'mediamatic'              => 'Mediamatic',
			'real-media-library-lite' => 'Real Media Library',
			'wicked-folders'          => 'Wicked Folders',
		);
	}

	/**
	 * Return directory names for normal and network-active plugins.
	 *
	 * @return string[]
	 */
	private static function active_plugin_directories(): array {
		$plugins = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$directories = array();
		foreach ( $plugins as $plugin ) {
			$plugin = plugin_basename( (string) $plugin );
			$parts  = explode( '/', $plugin, 2 );
			if ( 2 === count( $parts ) ) {
				$directories[] = sanitize_key( $parts[0] );
			}
		}

		return array_values( array_unique( $directories ) );
	}

	/**
	 * Keep compatibility messaging away from unrelated admin pages.
	 */
	private static function is_relevant_admin_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && in_array( $screen->id, array( 'plugins', 'plugins-network' ), true ) ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing; no state is changed.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return str_starts_with( $page, 'boris-stock' );
	}
}
