<?php
/**
 * Email field for the signup form.
 *
 * Intentionally blank.
 *
 * @package cbox-sso-saml
 */

$signup       = CBOX\SSO\SAML\Init::get_temp_signup();
$signup_email = $signup->meta['user_email'] ?? '';

printf(
	'<input type="hidden" name="signup_email" value="%s">',
	esc_attr( $signup_email )
);
