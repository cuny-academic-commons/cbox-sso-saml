<?php
/**
 * Template for the SSO registration form.
 *
 * @package cbox-sso-saml
 */

use CBOX\SSO\SAML\Config;

?>
<div class="col-sm-18">

	<div class="page" id="register-page">

		<div id="openlab-main-content"></div>

		<div class="entry-title">
			<h1><?php esc_html_e( 'Create an Account', 'commons-in-a-box' ); ?></h1>
		</div>

		<p><?php esc_html_e( 'To create an account, please login with your SSO credentials.', 'cbox-sso-saml' ); ?></p>

		<a class="btn btn-primary" href="<?php echo esc_url( Config::login_url() ); ?>"><?php esc_html_e( 'Login', 'cbox-sso-saml' ); ?></a>

	</div>

</div>
