<?php
/**
 * Configuration settings for the OneLogin Saml2 library.
 *
 * @package cbox-sso-saml
 */

namespace CBOX\SSO\SAML;

/**
 * Configuration settings for the OneLogin Saml2 library.
 */
class Config {
	/**
	 * Provide the entity ID for this client.
	 *
	 * @return string
	 */
	public static function entity_id(): string {
		$saved = get_site_option( 'cbox_sso_saml_entity_id', '' );
		if ( empty( $saved ) ) {
			// Default to the main site URL if no entity ID is set.
			$saved = get_home_url( get_main_site_id() );
		}

		$saved = apply_filters( 'cbox_sso_saml_entity_id', $saved );
		return $saved;
	}

	/**
	 * Provide the URL a user will visit to initiate SSO authentication.
	 *
	 * @return string
	 */
	public static function login_url(): string {
		return get_home_url( get_main_site_id(), 'sso/login' );
	}

	/**
	 * Provide the URL to which an IdP response will be returned.
	 *
	 * @return string
	 */
	public static function verification_url(): string {
		return get_home_url( get_main_site_id(), 'sso/verify' );
	}

	/**
	 * Provide the URL to which a user will be redirected to log out.
	 *
	 * @return string
	 */
	public static function logout_url(): string {
		return get_home_url( get_main_site_id(), 'sso/logout' );
	}

	/**
	 * Determines whether to force the use of email address as returned by SAML.
	 *
	 * When true, the email address fields will not be shown during registration,
	 * and will be hidden from the user profile.
	 *
	 * @return bool
	 */
	public static function force_saml_email_address(): bool {
		$saved = get_site_option( 'cbox_sso_saml_force_saml_email_address', '1' );

		return apply_filters( 'cbox_sso_saml_force_saml_email_address', (bool) $saved );
	}

	/**
	 * Provide the X.509 certificate used to verify SAML responses.
	 *
	 * @return string
	 */
	public static function get_x509_certificate(): string {
		$x509_cert = get_site_option( 'cbox_sso_saml_x509_certificate', '' );
		$x509_cert = apply_filters( 'cbox_sso_saml_x509_certificate', $x509_cert );
		$x509_cert = str_replace( array( "\n", "\r" ), '', $x509_cert );

		return (string) $x509_cert;
	}

	/**
	 * Provide the IdP entity ID.
	 *
	 * This is the unique identifier for the Identity Provider (IdP).
	 *
	 * @return string
	 */
	public static function idp_entity_id(): string {
		$default = 'https://ssologin.cuny.edu/oam/fed';
		$raw     = get_site_option( 'cbox_sso_saml_idp_entity_id', $default );
		return apply_filters( 'cbox_sso_saml_idp_entity_id', $raw );
	}

	/**
	 * Provide the IdP SSO URL.
	 *
	 * This is the URL where the Service Provider (SP) will send SAML authentication requests.
	 *
	 * @return string
	 */
	public static function idp_sso_url(): string {
		$default = 'https://ssologin.cuny.edu/oamfed/idp/samlv20';
		$raw     = get_site_option( 'cbox_sso_saml_idp_sso_url', $default );
		return apply_filters( 'cbox_sso_saml_idp_sso_url', $raw );
	}

	/**
	 * Provide the IdP SLO URL.
	 *
	 * This is the URL where the Service Provider (SP) will send SAML logout requests.
	 *
	 * @return string
	 */
	public static function idp_slo_url(): string {
		$default = 'https://ssologin.cuny.edu/oamfed/idp/samlv20';
		$raw     = get_site_option( 'cbox_sso_saml_idp_slo_url', $default );
		return apply_filters( 'cbox_sso_saml_idp_slo_url', $raw );
	}

	/**
	 * Provide the X.509 certificate used by the IdP.
	 *
	 * This certificate is used to verify the authenticity of SAML responses from the IdP.
	 *
	 * @return string
	 */
	public static function idp_x509_certificate(): string {
		$default = ''; // Leave blank if none provided.
		$raw     = get_site_option( 'cbox_sso_saml_idp_x509_certificate', $default );
		$clean   = str_replace( array( "\n", "\r" ), '', $raw );
		return apply_filters( 'cbox_sso_saml_idp_x509_certificate', $clean );
	}

	/**
	 * Provide the private key used to sign SAML requests.
	 *
	 * @return string
	 */
	public static function get_private_key(): string {
		$private_key = get_site_option( 'cbox_sso_saml_private_key', '' );
		$private_key = apply_filters( 'cbox_sso_saml_private_key', $private_key );
		$private_key = str_replace( array( "\n", "\r" ), '', $private_key );

		return (string) $private_key;
	}

	/**
	 * Provide the settings required for SAML integration.
	 *
	 * @return array
	 */
	public static function saml_settings(): array {
		$settings = array(
			'strict'  => true,
			'debug'   => false,
			'baseurl' => null,

			'sp'      => array(
				'entityId'                 => self::entity_id(),
				'assertionConsumerService' => array(
					'url'     => self::verification_url(),
					'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
				),
				'singleLogoutService'      => array(
					'url'     => self::logout_url(),
					'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
				),
				'NameIDFormat'             => 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified',
				'x509cert'                 => self::get_x509_certificate(),
				'privateKey'               => self::get_private_key(),
			),

			'idp'     => array(
				'entityId'            => self::idp_entity_id(),
				'singleSignOnService' => array(
					'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
					'url'     => self::idp_sso_url(),
				),
				'singleLogoutService' => array(
					'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
					'url'     => self::idp_slo_url(),
				),
				'NameIDFormat'        => array(
					'urn:oasis:names:tc:SAML:2.0:nameid-format:transient',
				),
				'x509cert'            => self::idp_x509_certificate(),
			),
		);

		$private_key = self::get_private_key();

		$security = array(
			'authnRequestsSigned'   => false,
			'logoutRequestSigned'   => false,
			'logoutResponseSigned'  => false,
			'requestedAuthnContext' => false,
		);

		if ( ! empty( $private_key ) ) {
			$security['authnRequestsSigned']  = true;
			$security['logoutRequestSigned']  = true;
			$security['logoutResponseSigned'] = true;
		}

		$settings['security'] = apply_filters( 'cbox_sso_saml_security_settings', $security );

		return apply_filters( 'cbox_sso_saml_saml_settings', $settings );
	}
}
