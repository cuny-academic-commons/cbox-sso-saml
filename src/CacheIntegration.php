<?php
/**
 * Manage integrations with cache plugins.
 *
 * @package cbox-sso-saml
 */

namespace CBOX\SSO\SAML;

/**
 * Manage integrations with cache plugins.
 *
 * This class handles exclusion of SSO paths from various cache plugins
 * to ensure proper authentication flow.
 */
class CacheIntegration {
	/**
	 * SSO paths that should be excluded from caching.
	 *
	 * @var array
	 */
	private static $sso_paths = array(
		'/sso/login',
		'/sso/verify',
		'/sso/logout',
		'/sso/metadata.xml',
	);

	/**
	 * Initialize cache plugin integrations.
	 */
	public static function init(): void {
		// Litespeed Cache plugin.
		add_filter( 'litespeed_optimize_excludes', array( __CLASS__, 'litespeed_exclude_sso_paths' ) );

		// WP Rocket plugin.
		add_filter( 'rocket_cache_reject_uri', array( __CLASS__, 'wp_rocket_exclude_sso_paths' ) );
		add_filter( 'rocket_exclude_defer_js', array( __CLASS__, 'wp_rocket_exclude_sso_paths' ) );

		// W3 Total Cache plugin.
		add_filter( 'w3tc_pagecache_reject_uri', array( __CLASS__, 'w3tc_exclude_sso_paths' ) );

		// WP Super Cache plugin.
		add_filter( 'wp_super_cache_reject_uri', array( __CLASS__, 'wp_super_cache_exclude_sso_paths' ) );

		// WP Fastest Cache plugin.
		add_filter( 'wpfc_exclude_current_page', array( __CLASS__, 'wpfc_exclude_sso_paths' ), 10, 2 );
	}

	/**
	 * Get SSO paths for exclusion.
	 *
	 * @return array Array of SSO paths to exclude from caching.
	 */
	private static function get_sso_paths(): array {
		/**
		 * Filter the list of SSO paths to exclude from caching.
		 *
		 * @param array $sso_paths Array of SSO paths.
		 */
		return apply_filters( 'cbox_sso_saml_cache_exclude_paths', self::$sso_paths );
	}

	/**
	 * Exclude SSO paths from Litespeed Cache optimization.
	 *
	 * @param array $excludes Array of paths to exclude.
	 * @return array Updated array of paths to exclude.
	 */
	public static function litespeed_exclude_sso_paths( $excludes ): array {
		if ( ! is_array( $excludes ) ) {
			$excludes = array();
		}

		return array_merge( $excludes, self::get_sso_paths() );
	}

	/**
	 * Exclude SSO paths from WP Rocket caching.
	 *
	 * @param array $excluded_uris Array of URIs to exclude.
	 * @return array Updated array of URIs to exclude.
	 */
	public static function wp_rocket_exclude_sso_paths( $excluded_uris ): array {
		if ( ! is_array( $excluded_uris ) ) {
			$excluded_uris = array();
		}

		return array_merge( $excluded_uris, self::get_sso_paths() );
	}

	/**
	 * Exclude SSO paths from W3 Total Cache.
	 *
	 * @param array $reject_uris Array of URIs to reject from caching.
	 * @return array Updated array of URIs to reject.
	 */
	public static function w3tc_exclude_sso_paths( $reject_uris ): array {
		if ( ! is_array( $reject_uris ) ) {
			$reject_uris = array();
		}

		return array_merge( $reject_uris, self::get_sso_paths() );
	}

	/**
	 * Exclude SSO paths from WP Super Cache.
	 *
	 * @param array $reject_uris Array of URIs to reject from caching.
	 * @return array Updated array of URIs to reject.
	 */
	public static function wp_super_cache_exclude_sso_paths( $reject_uris ): array {
		if ( ! is_array( $reject_uris ) ) {
			$reject_uris = array();
		}

		// WP Super Cache uses regex patterns.
		$sso_paths = self::get_sso_paths();
		foreach ( $sso_paths as $path ) {
			$reject_uris[] = preg_quote( $path, '/' );
		}

		return $reject_uris;
	}

	/**
	 * Exclude SSO paths from WP Fastest Cache.
	 *
	 * @param bool   $exclude Whether to exclude the current page.
	 * @param string $uri     The current URI.
	 * @return bool Whether to exclude the current page.
	 */
	public static function wpfc_exclude_sso_paths( $exclude, $uri ): bool {
		if ( $exclude ) {
			return $exclude;
		}

		$sso_paths = self::get_sso_paths();
		foreach ( $sso_paths as $path ) {
			if ( false !== strpos( $uri, $path ) ) {
				return true;
			}
		}

		return $exclude;
	}
}
