<?php
/**
 * Minimal PSR-4 style autoloader for the plugin namespace.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock;

defined( 'ABSPATH' ) || exit;

final class Autoloader {
	private const PREFIX = 'BorisSystems\\Stock\\';

	/**
	 * Register the autoloader.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'autoload' ) );
	}

	/**
	 * Load a class from src/.
	 *
	 * @param string $class_name Fully-qualified class name.
	 */
	private static function autoload( string $class_name ): void {
		if ( ! str_starts_with( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $relative ) ) {
			return;
		}
		$file     = BORIS_STOCK_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
