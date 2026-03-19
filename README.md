# CBOX SSO SAML

Adds SAML single sign-on integration to Commons In A Box.

The plugin is designed around a network-wide SAML service provider (SP) configuration and a BuddyPress/Commons In A Box registration flow. Once enabled, new-account creation is expected to start at the SSO provider, and the plugin uses SAML attributes to decide whether a user may register and how their initial signup data should be populated.

## Overview

### Authentication and registration flow

When this plugin is active, SAML SSO is the expected path for new registrations.

1. A visitor starts at the site login or registration flow.
2. The plugin redirects the visitor to the SAML identity provider (IdP).
3. The IdP posts a SAML response back to the site.
4. The plugin reads the SAML response, checks whether the user is allowed to register, and either:
   - provisions a temporary signup and redirects the user to complete the BuddyPress registration form, or
   - logs in an existing mapped user immediately.

### Managed endpoints

The plugin manages these paths on the main site:

- `/sso/login` starts SSO authentication.
- `/sso/verify` processes the SAML response from the IdP.
- `/sso/logout` starts or completes SSO logout.
- `/sso/metadata.xml` exposes the SP metadata for the site.

These paths are also excluded from several common WordPress cache plugins by default.

## Initial setup

### 1. Activate and configure the plugin

The plugin is intended for multisite/network use.

- Activate it for the network.
- Visit **Network Admin -> Settings -> CBOX SSO SAML**.
- Fill in the IdP settings:
  - IdP Entity ID
  - SSO URL
  - SLO URL, if your IdP supports logout
  - IdP x509 certificate
- Optionally set the SP Entity ID.
- Add an SP certificate and private key if your IdP requires signed requests or encrypted assertions.

If no SP Entity ID is saved, the plugin defaults to the main site URL.

### 2. Share the SP metadata with your IdP

The plugin publishes SP metadata at:

```text
https://example.org/sso/metadata.xml
```

In many deployments, this is the easiest way to hand ACS/logout details to the IdP administrator.

### 3. Generate SP keys if needed

A private key and certificate are required when you want the SP to sign requests or when your IdP expects encrypted traffic. No keys are bundled with the plugin.

```bash
openssl req -new -x509 -key private.key -out certificate.crt -days 3650
wp site option update cbox_sso_saml_x509_certificate "$(cat certificate.crt)"
wp site option update cbox_sso_saml_private_key "$(cat private.key)"
```

You can also provide these values entirely in code via filters; see below.

## Migrating or importing legacy users

If you are enabling SSO on an existing site, one of the most important migration tasks is linking pre-existing WordPress users to the identifier that the plugin will receive from the IdP.

The key requirement is this usermeta value:

```text
cbox_sso_saml_user_identifier
```

When an SSO login succeeds, the plugin looks for a user whose `cbox_sso_saml_user_identifier` meta value matches the resolved SAML identifier. If it finds a match, the user is logged into that existing account. If it does not find a match, the plugin treats the login as a new-user provisioning flow.

### What value should be stored?

Store whatever identifier the plugin ultimately uses after `cbox_sso_saml_user_identifier` has run.

For example:

- if you use the default behavior, store the SAML `NameID`;
- if you use a filter to switch to `employeeNumber`, store the `employeeNumber` value instead;
- if you normalize or rewrite the identifier in code, store the normalized value.

This is why it is a good idea to finalize your `cbox_sso_saml_user_identifier` logic before running a bulk migration.

### Migration checklist

For legacy-user imports, the usual process is:

1. Decide which SAML attribute will be your stable identifier.
2. If needed, implement `cbox_sso_saml_user_identifier` so the plugin resolves that attribute consistently.
3. Export or obtain a mapping between existing WordPress users and that identifier.
4. Write the identifier into each user's `cbox_sso_saml_user_identifier` usermeta.
5. Test with a few known accounts before opening SSO broadly.

### Manual mapping for one user

For one-off repairs or small migrations, an administrator can edit the user in wp-admin and set the **SAML Identifier** field on the profile screen.

That field writes to `cbox_sso_saml_user_identifier`.

### Example: bulk migration in PHP

If you are doing a scripted import, the essential operation is:

```php
update_user_meta( $user_id, 'cbox_sso_saml_user_identifier', $identifier );
```

For example:

```php
$rows = array(
	array(
		'user_login' => 'jsmith',
		'identifier' => '12345678',
	),
	array(
		'user_login' => 'adoe',
		'identifier' => '23456789',
	),
);

foreach ( $rows as $row ) {
	$user = get_user_by( 'login', $row['user_login'] );

	if ( ! $user ) {
		continue;
	}

	update_user_meta(
		$user->ID,
		'cbox_sso_saml_user_identifier',
		(string) $row['identifier']
	);
}
```

### Example: WP-CLI / eval-file import

For larger migrations, a WP-CLI script is often the safest approach because it uses WordPress APIs instead of writing directly to the database.

```php
<?php
// save as import-sso-identifiers.php and run with:
// wp eval-file import-sso-identifiers.php

$rows = array(
	array( 'email' => 'jsmith@example.edu', 'identifier' => '12345678' ),
	array( 'email' => 'adoe@example.edu', 'identifier' => '23456789' ),
);

foreach ( $rows as $row ) {
	$user = get_user_by( 'email', $row['email'] );

	if ( ! $user ) {
		WP_CLI::warning( sprintf( 'No user found for %s', $row['email'] ) );
		continue;
	}

	update_user_meta( $user->ID, 'cbox_sso_saml_user_identifier', (string) $row['identifier'] );
	WP_CLI::log( sprintf( 'Mapped user %s to %s', $user->user_login, $row['identifier'] ) );
}
```

### Migration pitfalls to avoid

- Do not populate `cbox_sso_saml_user_identifier` until you are sure which SAML attribute is authoritative.
- Do not assume email address is the correct identifier unless your IdP guarantees it is stable and your `cbox_sso_saml_user_identifier` filter agrees.
- Do not bulk-write identifiers without testing a few real SSO logins first.
- If two users are accidentally given the same identifier, account matching will become ambiguous and login behavior may be incorrect.

### Related hooks and admin tools

The most relevant customization points for migrations are:

- `cbox_sso_saml_user_identifier` to decide which SAML value becomes the canonical identifier;
- `cbox_sso_saml_get_user` if account lookup needs custom matching logic beyond the default usermeta query;
- the user profile **SAML Identifier** field for manual corrections after the initial import.

## User profile fields for SSO administration

When an administrator edits a user profile, the plugin adds a **CBOX SSO Configuration** section with two fields:

- **Allow WP auth**
- **SAML Identifier**

These fields are intended for day-to-day administration after SSO has been enabled, especially for support workflows, exception handling, and legacy-account cleanup.

### Allow WP auth

This field controls the usermeta key:

```text
cbox_sso_saml_allow_wp_login
```

When set to `yes`, the user is allowed to log in with standard WordPress authentication instead of being forced through SSO.

This is most useful for:

- service or test accounts that do not exist in the IdP;
- emergency admin access;
- users who have not yet been migrated to SSO;
- special-case accounts that must retain local WordPress credentials.

When the field is set to `no`, that user-specific override is removed.

One important caveat: if the user already has a `cbox_sso_saml_user_identifier` value, the plugin treats them as an SSO-linked user and will not allow normal WordPress authentication. In other words, **Allow WP auth** is primarily for users who are not currently mapped to an SSO identifier.

### SAML Identifier

This field controls the usermeta key:

```text
cbox_sso_saml_user_identifier
```

This is the canonical value used to match an incoming SSO login to an existing WordPress account.

In practice, this field is used to:

- connect a legacy WordPress account to an SSO identity;
- repair a broken mapping after an import or IdP change;
- manually set the identifier for a user who should bypass the automatic signup/provisioning flow.

The value entered here must match whatever the plugin resolves through `cbox_sso_saml_user_identifier`. That means:

- if you use default behavior, enter the SAML `NameID`;
- if you override the identifier in code, enter the post-filter value instead.

Because this field determines account matching, it should be changed carefully. An incorrect value can prevent a user from logging in through SSO or can cause the wrong account to be matched.

### How these two fields work together

These fields serve different purposes:

- **SAML Identifier** links an account to SSO.
- **Allow WP auth** allows local WordPress login for accounts that are not linked to SSO.

In general:

- set **SAML Identifier** when you want the account to authenticate through SSO;
- set **Allow WP auth** when you want the account to keep using local WordPress login;
- avoid treating them as parallel flags for the same account state.

### Typical admin workflows

Common cases include:

- migrating a legacy user by entering their SSO identifier;
- temporarily removing the SSO identifier to disconnect SSO from an account;
- allowing local WordPress auth for a non-SSO admin or service account;
- correcting a bad identifier after testing against the IdP.

## Configuration from code or environment variables

All configuration values are read from saved options first and then passed through filters. That means you can keep production secrets out of the database and inject them from a mu-plugin, a site-specific plugin, or `wp-config.php`-loaded code.

### Common configuration filters

The most commonly used filters are:

- `cbox_sso_saml_entity_id`
- `cbox_sso_saml_idp_entity_id`
- `cbox_sso_saml_idp_sso_url`
- `cbox_sso_saml_idp_slo_url`
- `cbox_sso_saml_idp_x509_certificate`
- `cbox_sso_saml_x509_certificate`
- `cbox_sso_saml_private_key`
- `cbox_sso_saml_security_settings`
- `cbox_sso_saml_saml_settings`

### Example: load secrets and metadata from environment variables

```php
<?php
/**
 * Plugin Name: CBOX SSO SAML local config
 */

add_filter(
	'cbox_sso_saml_idp_entity_id',
	function ( $value ) {
		return getenv( 'CBOX_SSO_SAML_IDP_ENTITY_ID' ) ?: $value;
	}
);

add_filter(
	'cbox_sso_saml_idp_sso_url',
	function ( $value ) {
		return getenv( 'CBOX_SSO_SAML_IDP_SSO_URL' ) ?: $value;
	}
);

add_filter(
	'cbox_sso_saml_idp_slo_url',
	function ( $value ) {
		return getenv( 'CBOX_SSO_SAML_IDP_SLO_URL' ) ?: $value;
	}
);

add_filter(
	'cbox_sso_saml_idp_x509_certificate',
	function ( $value ) {
		return getenv( 'CBOX_SSO_SAML_IDP_X509_CERTIFICATE' ) ?: $value;
	}
);

add_filter(
	'cbox_sso_saml_x509_certificate',
	function ( $value ) {
		return getenv( 'CBOX_SSO_SAML_SP_X509_CERTIFICATE' ) ?: $value;
	}
);

add_filter(
	'cbox_sso_saml_private_key',
	function ( $value ) {
		return getenv( 'CBOX_SSO_SAML_SP_PRIVATE_KEY' ) ?: $value;
	}
);
```

This pattern is especially useful when:

- you do not want private keys stored in WordPress options;
- you deploy the same codebase to multiple environments with different IdP settings;
- your infrastructure already manages secrets through environment variables.

### Example: tweak low-level OneLogin settings

For changes that do not map cleanly to one of the single-value filters, use `cbox_sso_saml_security_settings` or `cbox_sso_saml_saml_settings`.

```php
add_filter(
	'cbox_sso_saml_security_settings',
	function ( $security ) {
		$security['wantAssertionsSigned'] = true;
		$security['wantMessagesSigned']   = true;

		return $security;
	}
);

add_filter(
	'cbox_sso_saml_saml_settings',
	function ( $settings ) {
		$settings['sp']['NameIDFormat'] = 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress';

		return $settings;
	}
);
```

Use these advanced filters when your IdP requires OneLogin/php-saml options that are not exposed directly by the plugin UI.

## Attribute mapping and signup customization

Most real-world SAML integrations need two kinds of customization:

- deciding whether a user is allowed to be provisioned; and
- mapping SAML attributes into the signup data used by WordPress/BuddyPress.

The plugin exposes dedicated filters for both.

### A note about SAML attributes

The OneLogin library returns attributes as an associative array, where each key is an attribute name and each value is usually an array of one or more values.

For example:

```php
array(
	'mail'                 => array( 'person@example.edu' ),
	'givenName'            => array( 'Pat' ),
	'sn'                   => array( 'Lee' ),
	'eduPersonAffiliation' => array( 'faculty', 'member' ),
)
```

Most filters shown below therefore read from `$attributes['attributeName'][0]` or use `in_array()` against the attribute's value array.

### Restrict who can be provisioned with `cbox_sso_saml_can_register`

By default, the plugin allows registration once a SAML response has been accepted. To add your own eligibility rules, use `cbox_sso_saml_can_register`.

Signature:

```php
apply_filters( 'cbox_sso_saml_can_register', true, $attributes );
```

Example: only allow users whose SAML response includes both a campus code and an allowed affiliation.

```php
add_filter(
	'cbox_sso_saml_can_register',
	function ( $can_register, $attributes ) {
		$campus_codes = $attributes['campusCode'] ?? array();
		$affiliations = $attributes['eduPersonAffiliation'] ?? array();

		if ( ! in_array( 'BMCC', $campus_codes, true ) ) {
			return false;
		}

		if ( ! array_intersect( $affiliations, array( 'faculty', 'staff', 'student' ) ) ) {
			return false;
		}

		return $can_register;
	},
	10,
	2
);
```

This is the right place to enforce rules based on arbitrary SAML attributes such as:

- campus or institution code;
- department or school;
- affiliation or role;
- group membership;
- entitlement flags released by the IdP.

### Populate signup data with `cbox_sso_saml_signup_user_data`

When the plugin provisions a new signup, it builds a data array like this:

```php
array(
	'user_login' => 'derived-from-user-identifier',
	'user_email' => 'placeholder@example.com',
	'meta'       => array(),
)
```

That array is then passed through `cbox_sso_saml_signup_user_data` before the temporary signup is created.

Signature:

```php
apply_filters(
	'cbox_sso_saml_signup_user_data',
	$signup_user_data,
	$user_identifier,
	$auth
);
```

The third argument is the plugin's `Auth` instance, which means your callback can inspect the full SAML response with `$auth->saml()->getAttributes()`.

Example: copy common SAML attributes into signup meta and, importantly, set the real `user_email` value.

```php
add_filter(
	'cbox_sso_saml_signup_user_data',
	function ( $signup_user_data, $user_identifier, $auth ) {
		$attributes = $auth->saml()->getAttributes();

		$email      = $attributes['mail'][0] ?? '';
		$first_name = $attributes['givenName'][0] ?? '';
		$last_name  = $attributes['sn'][0] ?? '';
		$department = $attributes['department'][0] ?? '';

		$signup_user_data['user_login'] = sanitize_user( $user_identifier );

		if ( $email ) {
			$signup_user_data['user_email'] = sanitize_email( $email );
		}

		$signup_user_data['meta']['first_name'] = $first_name;
		$signup_user_data['meta']['last_name']  = $last_name;
		$signup_user_data['meta']['department'] = $department;

		return $signup_user_data;
	},
	10,
	3
);
```

Setting `user_email` here is important because the plugin's default value is only a placeholder. In most real integrations, you will want to replace it with the actual email attribute released by the IdP.

In addition to `user_email`, this filter is a good place to map:

- first and last names;
- department or division;
- institutional role labels;
- other signup metadata consumed later by BuddyPress, CBOX, or custom code.

### Use a different stable identifier with `cbox_sso_saml_user_identifier`

By default, the plugin uses the SAML `NameID` as the internal identifier that links SSO accounts to WordPress users. That works well when the IdP sends a stable identifier in `NameID`, but it is not universal.

If your IdP uses a transient or email-based `NameID`, you will usually want to switch to a stable attribute instead.

```php
add_filter(
	'cbox_sso_saml_user_identifier',
	function ( $user_identifier, $saml ) {
		$attributes = $saml->getAttributes();

		if ( ! empty( $attributes['employeeNumber'][0] ) ) {
			return (string) $attributes['employeeNumber'][0];
		}

		return $user_identifier;
	},
	10,
	2
);
```

This is one of the most important customization points for IdPs that do not guarantee a stable `NameID`.

### Control whether the SAML email is authoritative

The implemented hook name is:

```php
cbox_sso_saml_force_saml_email_address
```

When this setting is `true`:

- the BuddyPress registration email field is hidden;
- signup email validation errors are suppressed;
- the SAML-provided email is carried through the signup flow instead;
- SSO users are prevented from changing their email address in profile/settings screens unless they are allowed to use local WordPress authentication.

The plugin defaults this behavior to enabled.

Example: allow users to enter or manage their own site email instead of forcing the IdP-provided email.

```php
add_filter( 'cbox_sso_saml_force_saml_email_address', '__return_false' );
```

You would usually keep the default (`true`) when the IdP is the authoritative source of record for email addresses.

You would usually set it to `false` when:

- the IdP does not release an email attribute at all;
- the IdP releases a technical or forwarding address that should not become the WordPress account email;
- users need to maintain a separate site-specific email address.

## Existing users and operational notes

The plugin also checks the `cbox_sso_saml_allow_wp_login` option. If that option is set to `yes`, users without an SSO identifier can use standard WordPress login.

### Password reset behavior

The plugin redirects most `wp-login.php` requests to SSO, but it intentionally allows the WordPress password reset flow (`lostpassword`, `retrievepassword`, `rp`, and `resetpass`) to continue.

This matters primarily for accounts that are still allowed to use local WordPress authentication.

### Debug mode

If you define `CBOX_SSO_SAML_DEBUG` as `true`, the plugin registers a `cbox-sso-saml-debug` post type and stores SAML attribute payloads from authorization attempts there.

That can be very helpful while:

- confirming attribute names released by the IdP;
- writing `cbox_sso_saml_can_register` logic;
- building `cbox_sso_saml_signup_user_data` mappings.

Be cautious with this mode on production sites because SAML attributes often contain personally identifying information.

## Advanced customization reference

This plugin's public extension surface is primarily filter-based.

| Hook | Arguments | Typical use |
| --- | --- | --- |
| `cbox_sso_saml_entity_id` | `$entity_id` | Override the SP entity ID. |
| `cbox_sso_saml_force_saml_email_address` | `$force` | Decide whether the IdP email should be treated as authoritative. |
| `cbox_sso_saml_x509_certificate` | `$certificate` | Load the SP public certificate from code or secret storage. |
| `cbox_sso_saml_private_key` | `$private_key` | Load the SP private key from code or secret storage. |
| `cbox_sso_saml_idp_entity_id` | `$entity_id` | Override IdP metadata fields from code. |
| `cbox_sso_saml_idp_sso_url` | `$url` | Override the IdP login endpoint. |
| `cbox_sso_saml_idp_slo_url` | `$url` | Override the IdP logout endpoint. |
| `cbox_sso_saml_idp_x509_certificate` | `$certificate` | Override the IdP verification certificate. |
| `cbox_sso_saml_security_settings` | `$security` | Modify OneLogin security flags. |
| `cbox_sso_saml_saml_settings` | `$settings` | Modify the full OneLogin/php-saml settings array. |
| `cbox_sso_saml_can_register` | `$can_register, $attributes` | Allow or deny provisioning based on SAML attributes. |
| `cbox_sso_saml_signup_user_data` | `$signup_user_data, $user_identifier, $auth` | Map SAML data into WordPress/BuddyPress signup fields and meta. |
| `cbox_sso_saml_user_identifier` | `$user_identifier, $saml` | Replace `NameID` with another stable identifier. |
| `cbox_sso_saml_get_user` | `$user, $user_identifier` | Override how an SSO identifier is matched to a WordPress user. |
| `cbox_sso_saml_handle_error_message` | `$message, $response_code` | Rewrite plain-text error text shown to users. |
| `cbox_sso_saml_handle_error_message_markup` | `$markup, $message, $response_code` | Replace the full rendered error markup. |
| `cbox_sso_saml_cache_exclude_paths` | `$paths` | Add or replace cache-excluded paths for custom SSO endpoints. |

### A few common patterns

#### Customize the rendered error screen

```php
add_filter(
	'cbox_sso_saml_handle_error_message',
	function ( $message, $response_code ) {
		if ( 403 === $response_code ) {
			return 'Your SSO account is authenticated, but it is not currently allowed to create an account on this site.';
		}

		return $message;
	},
	10,
	2
);
```

#### Add extra cache exclusions

```php
add_filter(
	'cbox_sso_saml_cache_exclude_paths',
	function ( $paths ) {
		$paths[] = '/my-custom-sso-proxy-endpoint';

		return array_unique( $paths );
	}
);
```

#### Override account lookup

`cbox_sso_saml_get_user` is an advanced hook for installations that need something other than the default `cbox_sso_saml_user_identifier` user-meta lookup. Most sites should not need it, but it is available if account matching needs to be delegated to custom logic.

## Build and distribution

The plugin relies on Composer for autoloading and for the underlying [`onelogin/php-saml`](https://github.com/SAML-Toolkits/php-saml) library. WP-CLI's [`dist-archive`](https://github.com/wp-cli/dist-archive-command) command can be used to build a versioned zip file for distribution.

```bash
composer install --no-progress --no-dev
composer dump-autoload
wp dist-archive ./
```
