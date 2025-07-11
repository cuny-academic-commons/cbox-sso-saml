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
		add_action( 'login_form_login', array( __CLASS__, 'redirect_wp_login_attempts' ) );
		add_filter( 'bp_get_signup_page', array( __CLASS__, 'filter_signup_url' ) );
		add_filter( 'login_url', array( __CLASS__, 'filter_login_url' ) );
		add_filter( 'logout_url', array( __CLASS__, 'filter_logout_url' ) );

		add_filter( 'cbox_sso_saml_can_register', array( __CLASS__, 'sps_user_can_register' ), 10, 2 );

		add_action( 'edit_user_profile', array( __CLASS__, 'add_user_meta_field' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_user_meta_field' ) );

		add_filter( 'allow_password_reset', array( __CLASS__, 'filter_show_password_fields' ), 10, 2 );
		add_filter( 'show_password_fields', array( __CLASS__, 'filter_show_password_fields' ), 10, 2 );

		add_action( 'bp_before_sidebar_login_form', array( __CLASS__, 'filter_bp_before_sidebar_login_form' ) );
		add_action( 'wp_footer', array( __CLASS__, 'remove_login_handler' ) );

		if ( defined( 'CBOX_SSO_SAML_DEBUG' ) && CBOX_SSO_SAML_DEBUG ) {
			add_action( 'init', array( __CLASS__, 'setup_debug' ) );
		}
	}

	/**
	 * Handle the SSO login, verify, logout, completed registration, and
	 * service provider metadata endpoints.
	 */
	public static function template_redirect(): void {
		$current_url = trailingslashit( set_url_scheme( 'https://' . $_SERVER['HTTP_HOST'] . wp_unslash( $_SERVER['REQUEST_URI'] ) ) );

		$path = str_replace( home_url(), '', $current_url );
		$path = $path ? untrailingslashit( $path ) : '';

		if ( '/sso/login' === $path ) {
			$auth = new Auth();
			$auth->saml()->login();
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
			if ( isset( $_GET['SAMLResponse'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
				$auth->saml()->logout();
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
	 * Filter the template part used for the registration form if the user
	 * has not authenticated with SSO.
	 *
	 * @param array  $templates Array of templates located.
	 * @param string $slug      Template part slug requested.
	 * @return string[] Array of templates located.
	 */
	public static function filter_template_part( $templates, $slug ): array {
		if ( 'members/register' !== $slug ) {
			return $templates;
		}

		$auth = new Auth();

		if ( false === $auth->is_sso_authorized() ) {
			wp_dequeue_script( 'openlab-registration' );
			return array(
				'members/register-sso-link.php',
			);
		} else {
			return array(
				'members/register-sso.php',
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

		// Prevent BuddyPress from validating the password field, which
		// we are not capturing during SSO-authorized registration.
		if ( ! empty( $bp->signup->errors ) && array_key_exists( 'signup_password', $bp->signup->errors ) ) {
			unset( $bp->signup->errors['signup_password'] );
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

		$user = wpmu_activate_signup( $key );
		$user = new \WP_User( $user['user_id'] );

		$auth = new Auth();

		$cookie_data = $auth->get_cookie_data();

		if ( 4 !== count( $cookie_data ) ) {
			$auth->handle_error( 'Invalid cookie data.' );
		}

		list( $username ) = $cookie_data;

		$signup = $auth->get_temp_signup( $username );

		$wpdb->update(
			$wpdb->signups,
			array(
				'active'    => 1,
				'activated' => current_time( 'mysql', true ),
			),
			array( 'activation_key' => $signup->activation_key )
		);

		$emplid = str_replace( $auth->get_signup_prefix(), '', $signup->user_login );

		// The CUNY SSO EMPLID is used to match SSO users with WP users.
		update_user_meta( $user->ID, 'cuny_sso_emplid', $emplid );

		// CUNY SSO email and original signup ID are stored for debugging.
		update_user_meta( $user->ID, 'cuny_sso_email', $signup->user_email );
		update_user_meta( $user->ID, 'cuny_sso_signup_id', $signup->signup_id );

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
		return Config::login_url();
	}

	/**
	 * Filter the default login URL to go through the SSO login endpoint.
	 *
	 * @return string $login_url The default login URL.
	 */
	public static function filter_login_url(): string {
		return Config::login_url();
	}

	/**
	 * Filter the default logout URL to go through the SSO logout endpoint.
	 *
	 * @param string $logout_url The default logout URL.
	 * @return string $logout_url The default logout URL.
	 */
	public static function filter_logout_url( $logout_url ): string {
		$user = wp_get_current_user();

		if ( self::user_can_use_wp_auth( $user->ID ) ) {
			return $logout_url;
		}

		return Config::logout_url();
	}

	/**
	 * Determine whether a user is allowed to login with WordPress.
	 *
	 * @param int $user_id The ID of the user.
	 * @return bool Whether the user is allowed to login with WordPress.
	 */
	public static function user_can_use_wp_auth( $user_id ): bool {
		$emplid = get_user_meta( $user_id, 'cuny_sso_emplid', true );

		// This user has already authenticated with CUNY SSO.
		if ( $emplid ) {
			return false;
		}

		// Is this specific user allowed to login with WordPress?
		$user_allow_wp_login = get_user_meta( $user_id, 'cuny_sso_allow_wp_login', true );

		// Are all non-SSO users allowed to login with WordPress?
		$site_allow_wp_login = get_option( 'cuny_sso_allow_wp_login', 'no' );

		if ( $user_allow_wp_login || 'yes' === $site_allow_wp_login ) {
			return true;
		}

		return false;
	}

	/**
	 * Filter whether a user can register based on their SPS attributes.
	 *
	 * This code is specific to CUNY SPS and likely does not apply to other
	 * uses of this plugin. See the `cbox_sso_saml_can_register` filter to
	 * customize this behavior.
	 *
	 * @param bool   $can_register Whether the user can register.
	 * @param string $attributes   The available SAML attributes.
	 * @return bool Whether the user can register.
	 */
	public static function sps_user_can_register( $can_register, $attributes ): bool {
		if ( isset( $attributes['SPS-Stu'] ) ) {
			$student = $attributes['SPS-Stu'][0];
		} else {
			$student = '';
		}

		if ( isset( $attributes['SPS-Emp'] ) ) {
			$employee = $attributes['SPS-Emp'][0];
		} else {
			$employee = '';
		}

		if ( isset( $attributes['primaryAffiliation'] ) ) {
			$primary_affiliation = $attributes['primaryAffiliation'][0];
		} else {
			$primary_affiliation = '';
		}

		// If the user has an SPS specific employee or student attribute, or if they have
		// a primary affiliation with SPS01, they are authorized to register.
		if ( '' !== $employee || '' !== $student || 'SPS01' === $primary_affiliation ) {
			$can_register = true;
		}

		return $can_register;
	}

	/**
	 * Filter the BuddyPress login form on the home page to provide a link
	 * to SSO authentication.
	 *
	 * @return void
	 */
	public static function filter_bp_before_sidebar_login_form(): void {

		// Temporarily remove the login URL filter so that we can capture the
		// non-SSO login URL.
		remove_filter( 'login_url', array( __CLASS__, 'filter_login_url' ) );
		$non_sso_login_url = wp_login_url();
		add_filter( 'login_url', array( __CLASS__, 'filter_login_url' ) );

		?>
		<style>
			#user-login form {
				display: none;
			}
		</style>

		<p><a class="btn btn-default btn-primary link-btn semibold" href="<?php echo esc_url( Config::login_url() ); ?>"><?php esc_html_e( 'Login with CUNY Login', 'cbox-sso-saml' ); ?></a></p>
		<p><a href="<?php echo esc_url( $non_sso_login_url ); ?>"><?php esc_html_e( 'Login with username and password', 'cbox-sso-saml' ); ?></a></p>
		<?php
	}

	/**
	 * Add information about a user's CUNY SSO connection to the user profile
	 * when edited by an administrator.
	 *
	 * @param \WP_User $profile_user The user being edited.
	 */
	public static function add_user_meta_field( $profile_user ): void {
		$allow_wp_login = get_user_meta( $profile_user->ID, 'cuny_sso_allow_wp_login', true );
		$allow_wp_login = $allow_wp_login ? $allow_wp_login : 'no';

		$emplid = get_user_meta( $profile_user->ID, 'cuny_sso_emplid', true );
		$emplid = $emplid ? $emplid : '';

		wp_nonce_field( 'cuny_sso_allow_wp_login', 'cuny_sso_allow_wp_login_nonce' );
		?>
		<h2><?php esc_html_e( 'CUNY SSO Configuration', 'cbox-sso-saml' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th><label for="sps-can-use-wp-auth"><?php esc_html_e( 'Allow WP auth', 'cbox-sso-saml' ); ?></label></th>
				<td>
					<select name="sps-can-use-wp-auth" id="sps-can-use-wp-auth">
						<option value="no" <?php selected( $allow_wp_login, 'no' ); ?>><?php esc_html_e( 'No', 'cbox-sso-saml' ); ?></option>
						<option value="yes" <?php selected( $allow_wp_login, 'yes' ); ?>><?php esc_html_e( 'Yes', 'cbox-sso-saml' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="sps-cuny-emplid"><?php esc_html_e( 'CUNY SSO Emplid', 'cbox-sso-saml' ); ?></label></th>
				<td>
					<input name="sps-cuny-emplid" type="text" value="<?php echo esc_attr( $emplid ); ?>" />
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
		if ( ! isset( $_POST['sps-can-use-wp-auth'] ) || ! isset( $_POST['cuny_sso_allow_wp_login_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_POST['cuny_sso_allow_wp_login_nonce'], 'cuny_sso_allow_wp_login' ) ) {
			return;
		}

		$allow_wp_login = sanitize_text_field( wp_unslash( $_POST['sps-can-use-wp-auth'] ) );
		$allow_wp_login = 'yes' === $allow_wp_login ? 'yes' : 'no';

		if ( 'yes' === $allow_wp_login ) {
			update_user_meta( $user_id, 'cuny_sso_allow_wp_login', 'yes' );
		} else {
			delete_user_meta( $user_id, 'cuny_sso_allow_wp_login' );
		}

		$emplid = sanitize_text_field( wp_unslash( $_POST['sps-cuny-emplid'] ) );

		if ( $emplid ) {
			update_user_meta( $user_id, 'cuny_sso_emplid', $emplid );
		} else {
			delete_user_meta( $user_id, 'cuny_sso_emplid' );
		}
	}

	/**
	 * Filter whether to show password management fields on the user profile page.
	 *
	 * @param bool    $show_password_fields Whether to show password fields.
	 * @param WP_User $profileuser          The user being edited.
	 */
	public static function filter_show_password_fields( $show_password_fields, $profileuser ): bool {
		$allow_wp_login = get_user_meta( $profileuser->ID, 'cuny_sso_allow_wp_login', true );

		// If the user is allowed to login with WordPress and no other code
		// has already filtered this to false, show the password fields.
		if ( 'yes' === $allow_wp_login && $show_password_fields ) {
			return true;
		}

		return $show_password_fields;
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
}
