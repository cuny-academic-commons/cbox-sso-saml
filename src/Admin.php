<?php
/**
 * Admin.
 *
 * @package cbox-sso-saml
 */

namespace CBOX\SSO\SAML;

/**
 * Admin class for handling the SAML SSO settings page.
 */
class Admin {
	/**
	 * Add hooks.
	 */
	public static function init(): void {
		add_action( 'network_admin_menu', array( __CLASS__, 'add_admin_menu' ) );
		add_action( 'network_admin_edit_cbox_sso_saml_save_settings', array( __CLASS__, 'handle_form_submit' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'register_scripts' ) );
	}

	/**
	 * Enqueue admin scripts.
	 */
	public static function register_scripts(): void {
		wp_register_script(
			'cbox-sso-saml-network-admin',
			plugins_url( 'assets/js/cbox-sso-saml-network-admin.js', __DIR__ ),
			array( 'jquery' ),
			'1.0',
			true
		);
	}

	/**
	 * Add the SAML SSO settings page to the network admin menu.
	 */
	public static function add_admin_menu(): void {
		add_submenu_page(
			'settings.php',
			__( 'CBOX SSO SAML', 'cbox-sso-saml' ),
			__( 'CBOX SSO SAML', 'cbox-sso-saml' ),
			'manage_network_options',
			'cbox-sso-saml',
			array( __CLASS__, 'settings_page' )
		);
	}

	/**
	 * Render the settings page for the SAML SSO plugin.
	 */
	public static function settings_page(): void {
		wp_enqueue_script( 'cbox-sso-saml-network-admin' );

		$fields = array(
			'entity_id'            => '',
			'idp_entity_id'        => '',
			'idp_sso_url'          => '',
			'idp_slo_url'          => '',
			'idp_x509_certificate' => '',
			'x509_certificate'     => '',
			'private_key'          => '',
		);

		foreach ( $fields as $field => &$value ) {
			$value = get_site_option( 'cbox_sso_saml_' . $field, '' );
		}

		?>
		<div class="wrap">
			<h2><?php esc_html_e( 'CBOX SSO SAML Settings', 'cbox-sso-saml' ); ?></h2>

			<form method="post" action="edit.php?action=cbox_sso_saml_save_settings">
				<?php wp_nonce_field( 'cbox_sso_saml_save_settings' ); ?>

				<h3><?php esc_html_e( 'Identity Provider (IdP) Configuration', 'cbox-sso-saml' ); ?></h3>
				<table class="form-table">
					<tr>
						<th><label for="idp_entity_id"><?php esc_html_e( 'IdP Entity ID', 'cbox-sso-saml' ); ?></label></th>
						<td><input type="text" name="idp_entity_id" id="idp_entity_id" class="regular-text" value="<?php echo esc_attr( $fields['idp_entity_id'] ); ?>" /></td>
					</tr>

					<tr>
						<th><label for="idp_sso_url"><?php esc_html_e( 'SSO URL', 'cbox-sso-saml' ); ?></label></th>
						<td><input type="url" name="idp_sso_url" id="idp_sso_url" class="regular-text code" value="<?php echo esc_attr( $fields['idp_sso_url'] ); ?>" /></td>
					</tr>

					<tr>
						<th><label for="idp_slo_url"><?php esc_html_e( 'SLO URL (optional)', 'cbox-sso-saml' ); ?></label></th>
						<td><input type="url" name="idp_slo_url" id="idp_slo_url" class="regular-text code" value="<?php echo esc_attr( $fields['idp_slo_url'] ); ?>" /></td>
					</tr>

					<tr>
						<th><label for="idp_x509_certificate"><?php esc_html_e( 'IdP x509 Certificate', 'cbox-sso-saml' ); ?></label></th>
						<td><textarea name="idp_x509_certificate" id="idp_x509_certificate" rows="8" class="large-text code"><?php echo esc_textarea( $fields['idp_x509_certificate'] ); ?></textarea></td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'Service Provider (SP) Configuration', 'cbox-sso-saml' ); ?></h3>
				<p><?php esc_html_e( 'These fields are optional. Certificate and private key are required only if you want to sign requests or receive encrypted assertions.', 'cbox-sso-saml' ); ?></p>

				<table class="form-table">
					<tr>
						<th><label for="idp_entity_id"><?php esc_html_e( 'SP Entity ID', 'cbox-sso-saml' ); ?></label></th>
						<td><input type="text" name="entity_id" id="entity_id" class="regular-text" value="<?php echo esc_attr( $fields['entity_id'] ); ?>" /></td>
					</tr>

					<tr>
						<th><?php esc_html_e( 'SP x509 Certificate (Public)', 'cbox-sso-saml' ); ?></th>
						<td>
							<button type="button" class="button toggle-sp-cert"><?php esc_html_e( 'Show/Hide', 'cbox-sso-saml' ); ?></button>
							<div class="sp-cert-field" style="display:none;">
								<textarea name="x509_certificate" rows="8" class="large-text code"><?php echo esc_textarea( $fields['x509_certificate'] ); ?></textarea>
							</div>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'SP Private Key', 'cbox-sso-saml' ); ?></th>
						<td>
							<button type="button" class="button toggle-private-key"><?php esc_html_e( 'Show/Hide', 'cbox-sso-saml' ); ?></button>
							<div class="private-key-field" style="display:none;">
								<textarea name="private_key" rows="8" class="large-text code"><?php echo esc_textarea( $fields['private_key'] ); ?></textarea>
							</div>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'cbox-sso-saml' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the form submission for saving SAML SSO settings.
	 */
	public static function handle_form_submit(): void {
		check_admin_referer( 'cbox_sso_saml_save_settings' );

		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'cbox-sso-saml' ) );
		}

		$fields = array(
			'entity_id',
			'idp_entity_id',
			'idp_sso_url',
			'idp_slo_url',
			'idp_x509_certificate',
			'x509_certificate',
			'private_key',
		);

		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_site_option( 'cbox_sso_saml_' . $field, wp_unslash( $_POST[ $field ] ) );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'cbox-sso-saml',
					'updated' => 'true',
				),
				network_admin_url( 'settings.php' )
			)
		);
		exit;
	}
}
