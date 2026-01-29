<?php
/**
 * Manage authorization with SSO.
 *
 * @package cbox-sso-saml
 */

namespace CBOX\SSO\SAML;

use CBOX\SSO\SAML\Config;
use OneLogin\Saml2\Auth as SAML2_Auth;
use WP_User;

/**
 * Manage authorization with SSO.
 */
class Auth {
	/**
	 * The Saml2 Auth object.
	 *
	 * @var SAML2_Auth|false
	 */
	private $saml = false;

	/**
	 * The prefixed used to identify temporary signups.
	 *
	 * @var string
	 */
	private $signup_prefix = 'cbox_sso_saml_';

	/**
	 * The name of the cookie used to store SSO authorization.
	 *
	 * @var string
	 */
	private $cookie_name = 'cbox_sso_saml_authorization';

	/**
	 * Provide access to the Saml2 Auth object.
	 *
	 * @return SAML2_Auth
	 */
	public function saml() {
		if ( $this->saml ) {
			return $this->saml;
		}

		$config = Config::saml_settings();

		try {
			$this->saml = new SAML2_Auth( $config );
		} catch ( \Exception $e ) {
			die( wp_kses_post( $e->getMessage() ) );
		}

		return $this->saml;
	}

	/**
	 * Get the signup prefix.
	 *
	 * @return string
	 */
	public function get_signup_prefix(): string {
		return $this->signup_prefix;
	}

	/**
	 * Get cookie data from the SSO authorization cookie.
	 *
	 * @return array
	 */
	public function get_cookie_data(): array {
		$cookie = $_COOKIE[ $this->cookie_name ] ?? '';

		if ( ! $cookie ) {
			return array();
		}

		$cookie_data = explode( '|', $cookie );

		if ( 4 !== count( $cookie_data ) ) {
			return array();
		}

		return array(
			'username'   => $cookie_data[0] ?? '',
			'expiration' => $cookie_data[1] ?? '',
			'token'      => $cookie_data[2] ?? '',
			'hash'       => $cookie_data[3] ?? '',
		);
	}

	/**
	 * Check if the visiting user is authorized to register via SSO.
	 *
	 * @return bool
	 */
	public function is_sso_authorized(): bool {
		$cookie_data = $this->get_cookie_data();

		if ( 4 !== count( $cookie_data ) ) {
			return false;
		}

		if ( ! $cookie_data['username'] ) {
			return false;
		}

		$user = $this->get_user( $cookie_data['username'] );

		if ( $user ) {
			$fragment = substr( $user->user_pass, 8, 4 );
		} else {
			$signup   = $this->get_temp_signup( $cookie_data['username'] );
			$fragment = $signup ? $signup->activation_key : '';
		}

		if ( ! $fragment ) {
			$this->clear_sso_authorization_cookie();
			return false;
		}

		$key = wp_hash( $cookie_data['username'] . '|' . $fragment . '|' . $cookie_data['expiration'] . '|' . $cookie_data['token'], 'auth' );

		$hash_expected = hash_hmac( 'sha256', $cookie_data['username'] . '|' . $cookie_data['expiration'] . '|' . $cookie_data['token'], $key );

		if ( ! hash_equals( $cookie_data['hash'], $hash_expected ) ) {
			$this->clear_sso_authorization_cookie();
			return false;
		}

		return true;
	}

	/**
	 * Handle an error condition.
	 *
	 * @param string $error    The error message.
	 * @param int    $response The HTTP response code.
	 */
	public function handle_error( string $error, int $response = 500 ): void {

		$message = sprintf(
			'<p>%s</p><p><a href="%s">%s</a></p>',
			esc_html( $error ),
			esc_url( Config::logout_url() ),
			esc_html__( 'Log out of SSO connection.', 'cbox-sso-saml' )
		);

		wp_die(
			$message, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Error authorizing with SSO', 'cbox-sso-saml' ),
			array(
				'response' => $response, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			)
		);
	}

	/**
	 * Determine whether the user is authorized to register.
	 *
	 * @return bool Whether the user is authorized to register.
	 */
	private function can_register(): bool {
		$attributes = $this->saml()->getAttributes();

		/**
		 * Filter whether a user is authorized to register via SSO.
		 *
		 * @param bool   $can_register Whether the user is authorized to register.
		 * @param array  $attributes   The SAML attributes for the user.
		 */
		return apply_filters( 'cbox_sso_saml_can_register', true, $attributes );
	}

	/**
	 * Process a SSO response from the IdP.
	 */
	public function verify_sso_response(): void {
		try {
			$this->saml()->processResponse();
		} catch ( \Throwable $e ) {
			$this->handle_error( $e->getMessage() );
		} catch ( \Exception $e ) {
			$this->handle_error( $e->getMessage() );
		}

		if ( ! empty( $this->saml()->getErrors() ) ) {
			$this->handle_error(
				sprintf(
					/* translators: %s: comma-separated list of SAML errors */
					__( 'SAML response errors: %s', 'cbox-sso-saml' ),
					implode( ', ', $this->saml()->getErrors() )
				)
			);
		}

		if ( ! $this->saml()->isAuthenticated() ) {
			$this->handle_error( __( 'User is not authenticated.', 'cbox-sso-saml' ), 403 );
		}

		$user_identifier = $this->get_user_identifier();

		if ( defined( 'CBOX_SSO_SAML_DEBUG' ) && CBOX_SSO_SAML_DEBUG ) {
			$attributes = $this->saml()->getAttributes();

			$debug_id = wp_insert_post(
				array(
					'post_title'  => sanitize_text_field( $user_identifier ) . ' SSO Authorization attempt ' . gmdate( 'Y-m-d H:i:s' ),
					'post_status' => 'publish',
					'post_type'   => 'cbox-sso-saml-debug',
				)
			);

			if ( ! is_wp_error( $debug_id ) ) {
				update_post_meta( $debug_id, 'cbox_sso_saml_attributes', $attributes );
			}
		}

		if ( false === $this->can_register() ) {
			$this->handle_error( __( 'User is not authorized to register.', 'cbox-sso-saml' ), 403 );
		}

		$user = $this->get_user( $user_identifier );

		if ( ! $user ) {
			/**
			 * Filters the user data for a new user being signed up via SSO.
			 *
			 * @param array              $signup_user_data The user data for the new user.
			 * @param string             $user_identifier  The user identifier.
			 * @param CBOX\SSO\SAML\Auth $this             The Auth instance.
			 */
			$signup_user_data = apply_filters(
				'cbox_sso_saml_signup_user_data',
				array(
					'user_login' => sanitize_user( $user_identifier ),
					'user_email' => $user_identifier . '@example.com', // Placeholder email.
					'meta'       => array(),
				),
				$user_identifier,
				$this
			);

			$username = $this->signup_prefix . $signup_user_data['user_login'];
			$fragment = $this->create_temp_signup( $signup_user_data );
		} else {
			$username = $user->user_login;
			$fragment = substr( $user->user_pass, 8, 4 );
		}

		if ( ! $fragment ) {
			$this->handle_error( __( 'No signup record found.', 'cbox-sso-saml' ), 403 );
		}

		// The user is now authorized to register.
		$this->set_sso_authorization_cookie( $username, $fragment );

		// If this is still a temporary signup, redirect to the registration page.
		if ( substr( $username, 0, strlen( $this->signup_prefix ) ) === $this->signup_prefix ) {
			wp_safe_redirect( home_url( 'register' ) );
			exit;
		}

		// This is a fully registered user. Log them in.
		$this->set_sso_authentication_cookie( $user );

		// phpcs:disable WordPress.Security.NonceVerification
		$relay_state = isset( $_POST['RelayState'] ) ? wp_unslash( $_POST['RelayState'] ) : '';

		add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'allow_all_multisite_domains' ), 10, 2 );

		wp_safe_redirect( $relay_state );
		exit;
	}

	/**
	 * Clear the SSO authorization cookie.
	 */
	public function clear_sso_authorization_cookie(): void {
		setcookie( $this->cookie_name, '', time() - 3600, PLUGINS_COOKIE_PATH, COOKIE_DOMAIN, true, true );
		setcookie( $this->cookie_name, '', time() - 3600, ADMIN_COOKIE_PATH, COOKIE_DOMAIN, true, true );
	}

	/**
	 * Set a cookie when a user is authorized to register via SSO.
	 *
	 * @param string $username The username of the user.
	 * @param string $fragment A secret salt to use in cookie generation.
	 */
	private function set_sso_authorization_cookie( string $username, string $fragment ): void {

		$expiration = time() + 7 * DAY_IN_SECONDS;

		// We need to specify a token so that the WP session manager does not store
		// something, but we may also want to think about harnessing that. We'll see.
		$token = 'abcdefg';

		$key = wp_hash( $username . '|' . $fragment . '|' . $expiration . '|' . $token, 'auth' );

		$hash = hash_hmac( 'sha256', $username . '|' . $expiration . '|' . $token, $key );

		$cookie_value = $username . '|' . $expiration . '|' . $token . '|' . $hash;

		setcookie( $this->cookie_name, $cookie_value, $expiration, COOKIEPATH, COOKIE_DOMAIN, true, true );
	}

	/**
	 * Set a cookie when a user has registered and authenticated via SSO.
	 *
	 * @param WP_User $user The user for whom to set an authentication cookie.
	 */
	public function set_sso_authentication_cookie( WP_User $user ): void {
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
	}

	/**
	 * Get the user identifier from the SSO response.
	 *
	 * @return string The user identifier (EMPLID).
	 */
	private function get_user_identifier(): string {
		// Default to getNameId().
		$user_identifier = $this->saml()->getNameId();

		/**
		 * Filters the user identifier from the SSO response.
		 *
		 * @param string $user_identifier The user identifier.
		 * @param SAML2_Auth $saml The SAML2 Auth object.
		 */
		return apply_filters( 'cbox_sso_saml_user_identifier', $user_identifier, $this->saml() );
	}

	/**
	 * Get a user by the user identifier provided by SAML SSO.
	 *
	 * @param string $user_identifier User identifier.
	 * @return WP_User|false A matching user, or false if none found.
	 */
	private function get_user( string $user_identifier ) {
		$users = get_users(
			array(
				'meta_key'   => 'cbox_sso_saml_user_identifier',
				'meta_value' => $user_identifier,
			)
		);

		$user = ! empty( $users ) ? $users[0] : false;

		/**
		 * Filters the WP user object retrieved by the SAML user identifier.
		 *
		 * @param WP_User|false $user The user object, or false if none found.
		 * @param string        $user_identifier The user identifier used to retrieve the user.
		 */
		return apply_filters( 'cbox_sso_saml_get_user', $user, $user_identifier );
	}

	/**
	 * Provide a placeholder signup record for a user who is authorized via
	 * SSO, but not yet fully registered on the site.
	 *
	 * If the passed user identifier has already been registered, return the
	 * existing activation key.
	 *
	 * This allows us to generate a secure authorization cookie by using much
	 * of WordPress core's built-in logic and the user account's password as a
	 * secret key.
	 *
	 * Once registration is complete, this signup record will be removed and
	 * the user's associated user identifier will be stored as a full user's meta.
	 *
	 * @param array $signup_data The signup data, including 'user_login', 'user_email', 'meta'.
	 * @return string The activation key.
	 */
	private function create_temp_signup( $signup_data ): string {
		$user_login = $signup_data['user_login'] ?? '';

		$signup       = $this->get_temp_signup( $user_login );
		$existing_key = $signup ? $signup->activation_key : '';

		if ( $existing_key ) {
			return $existing_key;
		}

		// Do not send an email for this signup.
		remove_action( 'after_signup_user', 'wpmu_signup_user_notification' );

		$signup_identifier = $this->signup_prefix . $user_login;

		$meta = array_merge(
			$signup_data['meta'] ?? array(),
			array(
				'user_email' => $signup_data['user_email'] ?? '',
			)
		);

		wpmu_signup_user(
			$signup_identifier,
			$signup_identifier . '@example.com', // Placeholder email.
			$meta,
		);

		// Restore the email notification.
		add_action( 'after_signup_user', 'wpmu_signup_user_notification', 10, 4 );

		$signup = $this->get_temp_signup( $user_login );

		return $signup ? $signup->activation_key : '';
	}

	/**
	 * Retrieve the activation key for a temporary signup record.
	 *
	 * @param string $user_identifier The user identifier.
	 * @return stdClass|false The signup record. False if none found.
	 */
	public function get_temp_signup( string $user_identifier ) {
		global $wpdb;

		if ( 0 !== strpos( $user_identifier, $this->signup_prefix ) ) {
			$username = $this->signup_prefix . $user_identifier;
		} else {
			$username = $user_identifier;
		}

		$signups = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->signups} WHERE user_login = %s",
				$username
			)
		);

		if ( ! $signups ) {
			return false;
		}

		$signup = $signups[0];

		if ( isset( $signup->meta ) && is_serialized( $signup->meta ) ) {
			$signup->meta = maybe_unserialize( $signup->meta );
		} else {
			$signup->meta = array();
		}

		return $signup;
	}

	/**
	 * Add all registered domains in the multisite network to the list of allowed redirect hosts.
	 *
	 * On large networks, avoids loading all sites via get_sites().
	 *
	 * @param array  $hosts          The list of allowed hosts.
	 * @param string $requested_host The requested host to check.
	 * @return array The updated list of allowed hosts.
	 */
	public static function allow_all_multisite_domains( array $hosts, string $requested_host ): array {
		global $wpdb;

		// Query to check if this domain is in the blogs table.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT blog_id FROM {$wpdb->blogs} WHERE domain = %s LIMIT 1",
				$requested_host
			)
		);

		if ( $exists ) {
			$hosts[] = $requested_host;
		}

		return array_unique( $hosts );
	}
}
