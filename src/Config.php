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
		return get_home_url();
	}

	/**
	 * Provide the URL a user will visit to initiate SSO authentication.
	 *
	 * @return string
	 */
	public static function login_url(): string {
		return get_home_url( null, 'sso/login' );
	}

	/**
	 * Provide the URL to which an IdP response will be returned.
	 *
	 * @return string
	 */
	public static function verification_url(): string {
		return get_home_url( null, 'sso/verify' );
	}

	/**
	 * Provide the URL to which a user will be redirected to log out.
	 *
	 * @return string
	 */
	public static function logout_url(): string {
		return get_home_url( null, 'sso/logout' );
	}

	/**
	 * Provide the X.509 certificate used to verify SAML responses.
	 *
	 * @return string
	 */
	public static function get_x509_certificate(): string {
		$x509_cert = get_option( 'cbox_sso_saml_x509_certificate', '' );
		$x509_cert = apply_filters( 'cbox_sso_saml_x509_certificate', $x509_cert );
		$x509_cert = str_replace( array( "\n", "\r" ), '', $x509_cert );

		return (string) $x509_cert;
	}

	/**
	 * Provide the private key used to sign SAML requests.
	 *
	 * @return string
	 */
	public static function get_private_key(): string {
		$private_key = get_option( 'cbox_sso_saml_private_key', '' );
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

			/**
			 * Service provider (SP) configuration.
			 *
			 * This is the configuration for the site the plugin is active on.
			 */
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

			/**
			 * CUNY SSO IDP configuration.
			 *
			 * @see https://ssologin.cuny.edu/idp/metadata/oam-saml-metadata.xml
			 */
			'idp'     => array(
				'entityId'            => 'https://ssologin.cuny.edu/oam/fed',
				'singleSignOnService' => array(
					'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
					'url'     => 'https://ssologin.cuny.edu/oamfed/idp/samlv20',
				),
				'singleLogoutService' => array(
					'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
					'url'     => 'https://ssologin.cuny.edu/oamfed/idp/samlv20',
				),
				'NameIDFormat'        => array(
					'urn:oasis:names:tc:SAML:2.0:nameid-format:transient',
				),
				'x509cert'            => 'MIIC1zCCAb+gAwIBAgIEe1NtojANBgkqhkiG9w0BAQsFADAcMRowGAYDVQQDExFzc29sb2dpbi5jdW55LmVkdTAeFw0yMzAzMzAwMDMxMjVaFw0zMzAzMjcwMDMxMjVaMBwxGjAYBgNVBAMTEXNzb2xvZ2luLmN1bnkuZWR1MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAn849Br9l/HSNy4AudqR4P6lYm30vHmUZ5SVNO91OGRnEeqsX6sPhDrZwqDHdmWjmM3GZCRPGw0sVN7oKeWemsNGJ7wSlSEyBXtKTxEwJ6AXIkPz0HGmVCQsBuIugdCojj9N8CufltktiGxYoZVHnf0ra3JlY7lNB1l/ORxWL0THVkn5eszCQTaSUoq321tGQv0gqrZFC9yexyls2kPH2A3lp86pwRpBp8ZXVh5PltXTJygLxbOMC84IlcM72RJUfh4yM3rF+VfudEB7LwguJYDdmAvl81JQ3EYTSQLFLEKKWnRxljwsnEbx3x6z5/Kry5iBjhfltKDW7xS0h/qwJzwIDAQABoyEwHzAdBgNVHQ4EFgQUQ98fxrn00UD4LvounfGTsvPCKQ0wDQYJKoZIhvcNAQELBQADggEBAAKCeCol7PBKjcBuHWSUcvor31a6aA6DO3k01cgFfOzT392WuvwqNzfMV7XSbV1Rk3Xi/weX2Xpp8J2ZfNjZ3f9NLu+6SSvuuAx+1GPn5/2D+N211WRQt8SsTqEET1J2qQHaJR97Iw1RTmCM0PFe4RHLi2frVwAG0djaIK8xqVdml44IYNy8kLUkBvM9zuraU/1+s42Uf5IuMo4+i7RvxAc4SHJUDoTY1wOZk2QlbHsyaJxsE372KW9QnD9beV6Rb0197HDbfCS1wbpIEV0gzybeWJ06PQvdrKUmuuccATCZuCO5nHCUoT2K17EV1HK365jN334YsXS9/K43eQwP9NY=',
			),
		);

		/**
		 * Filters the SAML settings.
		 *
		 * @param array $settings SAML settings.
		 */
		return apply_filters( 'cbox_sso_saml_saml_settings', $settings );
	}
}
