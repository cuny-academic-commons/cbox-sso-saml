<?php
/**
 * Initialize the plugin.
 *
 * @package cbox-sso-saml
 */

namespace CBOX\SSO\SAML;

/**
 * Initialize the plugin.
 */
class Init {
	/**
	 * Add hooks.
	 */
	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'template_redirect' ) );
		add_filter( 'bp_get_template_part', array( __CLASS__, 'filter_template_part' ), 10, 2 );
		add_filter( 'bp_get_template_stack', array( __CLASS__, 'filter_template_stack' ) );
		add_action( 'bp_signup_validate', array( __CLASS__, 'bp_signup_validate' ) );
		add_filter( 'bp_registration_needs_activation', '__return_false' );
		add_action( 'login_init', array( __CLASS__, 'redirect_wp_login' ) );
		add_action( 'login_form_login', array( __CLASS__, 'redirect_wp_login_attempts' ) );
		add_filter( 'bp_get_signup_page', array( __CLASS__, 'filter_signup_url' ) );
		add_filter( 'login_url', array( __CLASS__, 'filter_login_url' ) );
		add_filter( 'logout_url', array( __CLASS__, 'filter_logout_url' ), 5, 2 );

		add_filter( 'admin_bar_init', array( __CLASS__, 'set_admin_bar_flag' ) );
		add_filter( 'wp_after_admin_bar_render', array( __CLASS__, 'unset_admin_bar_flag' ) );

		add_action( 'edit_user_profile', array( __CLASS__, 'add_user_meta_field' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_user_meta_field' ) );

		add_filter( 'allow_password_reset', array( __CLASS__, 'filter_show_password_fields' ), 10, 2 );
		add_filter( 'show_password_fields', array( __CLASS__, 'filter_show_password_fields' ), 10, 2 );
		add_filter( 'lostpassword_redirect', array( __CLASS__, 'filter_lostpassword_redirect' ) );

		add_action( 'wp_footer', array( __CLASS__, 'remove_login_handler' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );

		// Allow SSO users to change email without password in BP settings.
		add_action( 'bp_actions', array( __CLASS__, 'bypass_bp_password_check_for_sso_users' ), 5 );

		if ( defined( 'CBOX_SSO_SAML_DEBUG' ) && CBOX_SSO_SAML_DEBUG ) {
			add_action( 'init', array( __CLASS__, 'setup_debug' ) );
		}

		if ( is_network_admin() ) {
			Admin::init();
		}

		// Initialize cache plugin integrations.
		CacheIntegration::init();
	}

	/**
	 * Handle the SSO login, verify, logout, completed registration, and
	 * service provider metadata endpoints.
	 */
	public static function template_redirect(): void {
		$current_url = trailingslashit( set_url_scheme( 'https://' . $_SERVER['HTTP_HOST'] . wp_unslash( $_SERVER['REQUEST_URI'] ) ) );

		$path = str_replace( home_url(), '', $current_url );
		$path = $path ? untrailingslashit( $path ) : '';
		$path = strtok( $path, '?' );

		if ( '/sso/login' === $path ) {
			$redirect_to = rawurldecode( $_GET['redirect_to'] ?? home_url() ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			$auth = new Auth();
			$auth->saml()->login( $redirect_to );
			exit;
		}

		if ( '/sso/verify' === $path ) {
			if ( isset( $_POST['SAMLResponse'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$auth = new Auth();
				$auth->verify_sso_response();
			} else {
				wp_safe_redirect( home_url() );
				exit;
			}
		}

		if ( '/sso/logout' === $path ) {
			$auth = new Auth();
			if ( ! $auth->saml()->isAuthenticated() || isset( $_GET['SAMLResponse'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$auth->clear_sso_authorization_cookie();
				wp_clear_auth_cookie();

				if ( isset( $_REQUEST['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$redirect_to = $_REQUEST['redirect_to']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				} else {
					$redirect_to = home_url();
				}

				wp_safe_redirect( $redirect_to );
				exit;
			} else {
				$current_url = trailingslashit( set_url_scheme( 'https://' . $_SERVER['HTTP_HOST'] . wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
				$auth->saml()->logout( home_url( '/sso/logout?redirect_to=' . rawurlencode( $current_url ) ) );
				exit;
			}
		}

		if ( '/sso/metadata.xml' === $path ) {
			$auth = new Auth();

			http_response_code( 200 );
			header( 'Content-Type: application/xml' );
			echo $auth->saml()->getSettings()->getSPMetadata(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		if ( '/register' === $path && 'completed-confirmation' === bp_get_current_signup_step() ) {
			wp_safe_redirect( home_url() );
			exit;
		}
	}

	/**
	 * Template part filters.
	 *
	 * This plugin provides a few overrides of BP- and CBOX-default template
	 * parts. The overrides use the '-sso' suffix so that they take precedence
	 * over the default templates, while still allowing them to be overridden
	 * from themes.
	 *
	 * @param array  $templates Array of templates located.
	 * @param string $slug      Template part slug requested.
	 * @return string[] Array of templates located.
	 */
	public static function filter_template_part( $templates, $slug ): array {
		switch ( $slug ) {
			case 'members/register':
				$auth = new Auth();

				if ( false === $auth->is_sso_authorized() ) {
					wp_dequeue_script( 'openlab-registration' );
					return array(
						'members/register-sso-link.php',
					);
				}

				break;

			case 'members/register-parts/email':
				if ( Config::force_saml_email_address() ) {
					return array(
						'members/register-parts/email-sso.php',
						'members/register-parts/email.php',
					);
				}

				break;

			case 'members/register-parts/password':
				return array(
					'members/register-parts/password-sso.php',
					'members/register-parts/password',
				);
		}

		return $templates;
	}

	/**
	 * Filter the template stack used for the registration form if the user
	 * has not authenticated with SSO.
	 *
	 * @param array $stack Array of template stack locations.
	 * @return array Array of template stack locations.
	 */
	public static function filter_template_stack( $stack ): array {
		$stack[] = plugin_dir_path( __DIR__ ) . 'templates';

		return $stack;
	}

	/**
	 * Filter BuddyPress signup validataion.
	 */
	public static function bp_signup_validate(): void {
		$bp = buddypress();

		if ( ! empty( $bp->signup->errors ) ) {
			// Prevent BuddyPress from validating the password field.
			// Password is not used for SSO users.
			if ( array_key_exists( 'signup_password', $bp->signup->errors ) ) {
				unset( $bp->signup->errors['signup_password'] );
			}

			// Prevent BuddyPress from validating the email field.
			if ( Config::force_saml_email_address() && array_key_exists( 'signup_email', $bp->signup->errors ) ) {
				unset( $bp->signup->errors['signup_email'] );
			}
		}

		add_action( 'after_signup_user', array( __CLASS__, 'after_signup_user' ), 10, 3 );
	}

	/**
	 * Handle user registration and activation after a successful signup.
	 *
	 * @param string $username   The user's requested login name.
	 * @param string $user_email The user's email address. Unused.
	 * @param string $key        The user's activation key.
	 */
	public static function after_signup_user( $username, $user_email, $key ): void {
		global $wpdb;

		$auth = new Auth();

		$cookie_data = $auth->get_cookie_data();

		if ( empty( $cookie_data['username'] ) ) {
			$auth->handle_error( __( 'Invalid cookie data.', 'cbox-sso-saml' ) );
		}

		$username = $cookie_data['username'];

		$temp_signup = $auth->get_temp_signup( $username );
		if ( ! $temp_signup ) {
			$auth->handle_error( __( 'No signup found for this user.', 'cbox-sso-saml' ) );
		}

		if ( Config::force_saml_email_address() ) {
			// If the SSO email address is being used, we don't need to validate the email.
			// The email address will be set by the SSO response.
			$wpdb->update(
				$wpdb->signups,
				array( 'user_email' => $user_email ),
				array( 'activation_key' => $key )
			);
		}

		// @todo Better error handling.
		$user_id = bp_core_activate_signup( $key );

		$user = new \WP_User( $user_id );

		$wpdb->delete(
			$wpdb->signups,
			array( 'activation_key' => $temp_signup->activation_key )
		);

		$user_identifier = str_replace( $auth->get_signup_prefix(), '', $temp_signup->user_login );

		// Used to match SSO users with WP users.
		update_user_meta( $user->ID, 'cbox_sso_saml_user_identifier', $user_identifier );

		// Email and original signup ID are stored for debugging.
		update_user_meta( $user->ID, 'cbox_sso_saml_email', $temp_signup->user_email );
		update_user_meta( $user->ID, 'cbox_sso_saml_signup_id', $temp_signup->signup_id );

		$auth->set_sso_authentication_cookie( $user );

		remove_action( 'after_signup_user', array( __CLASS__, 'after_signup_user' ) );
	}

	/**
	 * Redirect login attempts to the SSO login page if the user has not been
	 * explicitly authorized to login with WordPress.
	 *
	 * @return void
	 */
	public static function redirect_wp_login_attempts(): void {
		if ( ! isset( $_POST['log'] ) || '' === $_POST['log'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$user_name = sanitize_user( wp_unslash( $_POST['log'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$user      = get_user_by( 'login', $user_name );

		if ( ! $user && strpos( $user_name, '@' ) ) {
			$user = get_user_by( 'email', $user_name );
		}

		if ( $user && ! self::user_can_use_wp_auth( $user->ID ) ) {
			wp_safe_redirect( Config::login_url() );
			exit;
		}
	}

	/**
	 * Filter the default signup URL to go through the SSO login endpoint.
	 *
	 * @return string $signup_url The default signup URL.
	 */
	public static function filter_signup_url(): string {
		return add_query_arg(
			array(
				'redirect_to' => home_url(),
			),
			Config::login_url()
		);
	}

	/**
	 * Filter the default login URL to go through the SSO login endpoint.
	 *
	 * @return string $login_url The default login URL.
	 */
	public static function filter_login_url(): string {
		$login_url = Config::login_url();

		$redirect_to = home_url();
		if ( self::doing_admin_bar() ) {
			// If we're in the admin bar, we need to add a redirect parameter.
			$redirect_to = bp_get_requested_url();
		}

		return add_query_arg(
			array(
				'redirect_to' => rawurlencode( $redirect_to ),
			),
			$login_url
		);
	}

	/**
	 * Filter the default logout URL to go through the SSO logout endpoint.
	 *
	 * @param string $logout_url The default logout URL.
	 * @param string $redirect   The redirect URL after logout.
	 * @return string $logout_url The default logout URL.
	 */
	public static function filter_logout_url( $logout_url, $redirect ): string {
		$user = wp_get_current_user();

		if ( self::user_can_use_wp_auth( $user->ID ) ) {
			return $logout_url;
		}

		return add_query_arg(
			array(
				'redirect_to' => rawurlencode( $redirect ),
			),
			Config::logout_url()
		);
	}

	/**
	 * Get or set the flag indicating whether the admin bar is being displayed.
	 *
	 * @param bool|null $set If provided, sets the flag to this value.
	 * @return bool Whether the admin bar is being displayed.
	 */
	public static function doing_admin_bar( $set = null ): bool {
		static $doing_admin_bar = null;

		if ( ! is_null( $set ) ) {
			$doing_admin_bar = $set;
		}

		return (bool) $doing_admin_bar;
	}

	/**
	 * Set a flag in the admin bar to indicate that the SSO login handler should be used.
	 */
	public static function set_admin_bar_flag(): void {
		self::doing_admin_bar( true );
	}

	/**
	 * Unset the flag in the admin bar to indicate that the SSO login handler should be used.
	 */
	public static function unset_admin_bar_flag(): void {
		self::doing_admin_bar( false );
	}

	/**
	 * Redirect attempts to access wp-login.php.
	 *
	 * Appending the ?normal query parameter will allow access. This
	 * is necessary in particular for users who have the ability to use
	 * local WP login.
	 *
	 * Password reset pages are also allowed through:
	 * - ?action=lostpassword is accessible to all users.
	 * - ?action=rp and ?action=resetpass are accessible only to users who are
	 *   allowed to use WP auth (?login= is checked against usermeta).
	 * - ?checkemail=* is accessible after submitting the lost-password form.
	 */
	public static function redirect_wp_login(): void {
		// POSTs are handled by the `redirect_wp_login_attempts` method.
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

		// Allow all users to access the first step of the password reset flow.
		if ( in_array( $action, array( 'lostpassword', 'retrievepassword' ), true ) ) {
			return;
		}

		// Allow the "check your email" confirmation page shown after submitting the form.
		if ( isset( $_GET['checkemail'] ) ) {
			return;
		}

		// Allow the password reset form only for users who are permitted to use WP auth.
		if ( in_array( $action, array( 'rp', 'resetpass' ), true ) ) {
			$login = isset( $_GET['login'] ) ? sanitize_user( wp_unslash( $_GET['login'] ) ) : '';
			$user  = $login ? get_user_by( 'login', $login ) : false;

			if ( $user && self::user_can_use_wp_auth( $user->ID ) ) {
				return;
			}

			// Redirect unauthorized users back to the lost password form.
			wp_safe_redirect( add_query_arg( 'action', 'lostpassword', site_url( 'wp-login.php', 'login' ) ) );
			exit;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! isset( $_GET['normal'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( Config::login_url() );
			exit;
		}
	}

	/**
	 * Determine whether a user is allowed to login with WordPress.
	 *
	 * @param int $user_id The ID of the user.
	 * @return bool Whether the user is allowed to login with WordPress.
	 */
	public static function user_can_use_wp_auth( $user_id ): bool {
		$user_identifier = get_user_meta( $user_id, 'cbox_sso_saml_user_identifier', true );

		// This user has already authenticated with SSO.
		if ( $user_identifier ) {
			return false;
		}

		// Is this specific user allowed to login with WordPress?
		$user_allow_wp_login = get_user_meta( $user_id, 'cbox_sso_saml_allow_wp_login', true );

		// Are all non-SSO users allowed to login with WordPress?
		$site_allow_wp_login = get_option( 'cbox_sso_saml_allow_wp_login', 'no' );

		if ( $user_allow_wp_login || 'yes' === $site_allow_wp_login ) {
			return true;
		}

		return false;
	}

	/**
	 * Add information about a user's SSO connection to the user profile
	 * when edited by an administrator.
	 *
	 * @param \WP_User $profile_user The user being edited.
	 */
	public static function add_user_meta_field( $profile_user ): void {
		$allow_wp_login = get_user_meta( $profile_user->ID, 'cbox_sso_saml_allow_wp_login', true );
		$allow_wp_login = $allow_wp_login ? $allow_wp_login : 'no';

		$user_identifier = get_user_meta( $profile_user->ID, 'cbox_sso_saml_user_identifier', true );

		wp_nonce_field( 'cbox_sso_saml_allow_wp_login', 'cbox_sso_saml_allow_wp_login_nonce' );
		?>
		<h2><?php esc_html_e( 'CBOX SSO Configuration', 'cbox-sso-saml' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th><label for="cbox-sso-can-use-wp-auth"><?php esc_html_e( 'Allow WP auth', 'cbox-sso-saml' ); ?></label></th>
				<td>
					<select name="cbox-sso-can-use-wp-auth" id="cbox-sso-can-use-wp-auth">
						<option value="no" <?php selected( $allow_wp_login, 'no' ); ?>><?php esc_html_e( 'No', 'cbox-sso-saml' ); ?></option>
						<option value="yes" <?php selected( $allow_wp_login, 'yes' ); ?>><?php esc_html_e( 'Yes', 'cbox-sso-saml' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="cbox-sso-user-identifier"><?php esc_html_e( 'SAML Identifier', 'cbox-sso-saml' ); ?></label></th>
				<td>
					<input name="cbox-sso-user-identifier" type="text" value="<?php echo esc_attr( $user_identifier ); ?>" />
					<p class="description">
						<?php esc_html_e( 'This is the string used to identify this user in the SAML IdP. Use extreme caution when changing this value, as it may prevent the user from logging in with SSO.', 'cbox-sso-saml' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save user meta data when a user profile is updated.
	 *
	 * @param int $user_id The ID of the user being updated.
	 */
	public static function save_user_meta_field( $user_id ): void {
		if ( ! isset( $_POST['cbox-sso-can-use-wp-auth'] ) || ! isset( $_POST['cbox_sso_saml_allow_wp_login_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_POST['cbox_sso_saml_allow_wp_login_nonce'], 'cbox_sso_saml_allow_wp_login' ) ) {
			return;
		}

		$allow_wp_login = sanitize_text_field( wp_unslash( $_POST['cbox-sso-can-use-wp-auth'] ) );
		$allow_wp_login = 'yes' === $allow_wp_login ? 'yes' : 'no';

		if ( 'yes' === $allow_wp_login ) {
			update_user_meta( $user_id, 'cbox_sso_saml_allow_wp_login', 'yes' );
		} else {
			delete_user_meta( $user_id, 'cbox_sso_saml_allow_wp_login' );
		}

		$emplid = sanitize_text_field( wp_unslash( $_POST['cbox-sso-user-identifier'] ) );

		if ( $emplid ) {
			update_user_meta( $user_id, 'cbox_sso_saml_user_identifier', $emplid );
		} else {
			delete_user_meta( $user_id, 'cbox_sso_saml_user_identifier' );
		}
	}

	/**
	 * Filter whether to show password management fields on the user profile page.
	 *
	 * @param bool    $show_password_fields Whether to show password fields.
	 * @param WP_User $profileuser          The user being edited.
	 */
	public static function filter_show_password_fields( $show_password_fields, $profileuser ): bool {
		$allow_wp_login = get_user_meta( $profileuser->ID, 'cbox_sso_saml_allow_wp_login', true );

		if ( 'yes' === $allow_wp_login ) {
			return true;
		}

		return false;
	}

	/**
	 * Filter the redirect URL after the lost password form is submitted.
	 *
	 * When the lost password form is processed, WordPress redirects to
	 * wp_login_url(), which this plugin filters to the SSO login URL. This
	 * filter ensures the confirmation/error page is shown on wp-login.php.
	 *
	 * @param string $redirect_to The redirect URL built by WordPress.
	 * @return string The corrected redirect URL pointing to wp-login.php.
	 */
	public static function filter_lostpassword_redirect( string $redirect_to ): string {
		$parsed       = wp_parse_url( $redirect_to );
		$query_params = array();

		if ( ! empty( $parsed['query'] ) ) {
			parse_str( $parsed['query'], $all_params );
			// Only forward the 'checkemail' parameter that WordPress uses for this redirect.
			if ( isset( $all_params['checkemail'] ) ) {
				$query_params['checkemail'] = sanitize_key( $all_params['checkemail'] );
			}
		}

		return add_query_arg( $query_params, site_url( 'wp-login.php', 'login' ) );
	}

	/**
	 * Setup conditions that allow us to capture debug information.
	 */
	public static function setup_debug(): void {
		register_post_type(
			'cbox-sso-saml-debug',
			array(
				'public'             => true,
				'publicly_queryable' => false,
				'show_in_rest'       => false,
				'show_ui'            => true,
				'supports'           => array( 'title', 'custom-fields' ),
				'label'              => 'SSO Debug',
			)
		);
	}

	/**
	 * Remove the default OpenLab login handler in the admin bar.
	 */
	public static function remove_login_handler(): void {
		?>
		<script>
			jQuery( document ).ready( function( $ ) {
				document.querySelector( '#wp-admin-bar-bp-login > a' ).addEventListener( 'click', () => {
					jQuery( '#wp-admin-bar-bp-login > a' ).off();
				} );
			} );
		</script>
		<?php
	}

	/**
	 * Gets the signup associated with the current cookie data.
	 *
	 * @return object|null The signup object if found, null otherwise.
	 */
	public static function get_temp_signup(): ?object {
		$auth        = new Auth();
		$cookie_data = $auth->get_cookie_data();

		if ( empty( $cookie_data['username'] ) ) {
			return null;
		}

		return $auth->get_temp_signup( $cookie_data['username'] );
	}

	/**
	 * Enqueue frontend assets for the plugin.
	 */
	public static function enqueue_assets(): void {
		wp_register_script(
			'cbox-sso-saml',
			plugins_url( 'assets/js/cbox-sso-saml.js', __DIR__ ),
			array(),
			filemtime( plugin_dir_path( __DIR__ ) . 'assets/js/cbox-sso-saml.js' ),
			true
		);

		$allow_email_change = ! Config::force_saml_email_address() || self::user_can_use_wp_auth( get_current_user_id() );

		wp_add_inline_script(
			'cbox-sso-saml',
			'var CBOXSSOSAML = ' . wp_json_encode(
				array(
					'allowEmailChange'    => $allow_email_change,
					'allowPasswordChange' => self::user_can_use_wp_auth( get_current_user_id() ),
				)
			),
			'before'
		);

		if ( bp_is_settings_component() ) {
			wp_enqueue_script( 'cbox-sso-saml' );
		}
	}

	/**
	 * Enqueue admin assets for the plugin.
	 */
	public static function enqueue_admin_assets(): void {
		global $pagenow;

		wp_register_script(
			'cbox-sso-saml-admin',
			plugins_url( 'assets/js/cbox-sso-saml-admin.js', __DIR__ ),
			array(),
			filemtime( plugin_dir_path( __DIR__ ) . 'assets/js/cbox-sso-saml-admin.js' ),
			true
		);

		$allow_email_change = ! Config::force_saml_email_address() || self::user_can_use_wp_auth( get_current_user_id() );

		$email_field_description = ! $allow_email_change ? __( 'This email address is managed by the SSO provider and cannot be changed.', 'cbox-sso-saml' ) : '';

		wp_add_inline_script(
			'cbox-sso-saml-admin',
			'var CBOXSSOSAMLAdmin = ' . wp_json_encode(
				array(
					'allowEmailChange'      => $allow_email_change,
					'emailFieldDescription' => $email_field_description,
				)
			),
			'before'
		);

		if ( 'profile.php' === $pagenow || 'user-edit.php' === $pagenow ) {
			wp_enqueue_script( 'cbox-sso-saml-admin' );
		}
	}

	/**
	 * Bypass BP password check for SSO users when changing email.
	 *
	 * This allows SSO users to change their email address in BP settings
	 * without needing to provide their current password (which they don't know).
	 *
	 * We apply a JIT filter to check_password that returns true for SSO users
	 * in the BP settings context, allowing them to change their email just like
	 * they can in /wp-admin/profile.php.
	 */
	public static function bypass_bp_password_check_for_sso_users(): void {
		// Only proceed if this is a BP settings POST request.
		if ( ! bp_is_post_request() ) {
			return;
		}

		// Check if we're in the BP settings component and general action.
		if ( ! bp_is_settings_component() || ! bp_is_current_action( 'general' ) ) {
			return;
		}

		// Verify nonce.
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), 'bp_settings_general' ) ) {
			return;
		}

		$user_id = bp_displayed_user_id();
		if ( ! $user_id ) {
			return;
		}

		if ( get_current_user_id() !== $user_id ) {
			return;
		}

		// Only apply for SSO users (those who cannot use WP auth).
		if ( self::user_can_use_wp_auth( $user_id ) ) {
			return;
		}

		// Fake the value of 'pwd' to trigger BP's save routine.
		$_POST['pwd'] = 'password'; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// Apply the JIT filter to bypass password check.
		add_filter( 'check_password', array( __CLASS__, 'bypass_password_check_for_bp_settings' ), 10, 4 );
	}

	/**
	 * Filters check_password to allow SSO users to bypass password check in BP settings.
	 *
	 * This filter is only applied in the specific context of BP settings email changes
	 * for SSO users, ensuring it doesn't create security vulnerabilities elsewhere.
	 *
	 * @param bool   $check    Whether the passwords match.
	 * @param string $password The plaintext password.
	 * @param string $hash     The hashed password.
	 * @param int    $user_id  The user ID.
	 * @return bool Whether to allow the password check to pass.
	 */
	public static function bypass_password_check_for_bp_settings( $check, $password, $hash, $user_id ): bool {
		// Only bypass for SSO users in BP settings context.
		if ( ! bp_is_settings_component() || ! bp_is_current_action( 'general' ) ) {
			return $check;
		}

		// Only bypass for the displayed user (the user being edited).
		$displayed_user_id = bp_displayed_user_id();
		$user_id           = (int) $user_id;
		if ( ! $displayed_user_id || $user_id !== $displayed_user_id ) {
			return $check;
		}

		// Only bypass for SSO users.
		if ( self::user_can_use_wp_auth( $user_id ) ) {
			return $check;
		}

		// Verify this is the current password field being checked (not a new password).
		// BP passes the current password via $_POST['pwd'].
		if ( isset( $_POST['pwd'] ) && $password === wp_unslash( $_POST['pwd'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$check = true;
		}

		// Remove the filter after first use to prevent unintended side effects.
		remove_filter( 'check_password', array( __CLASS__, 'bypass_password_check_for_bp_settings' ), 10 );

		return $check;
	}
}
