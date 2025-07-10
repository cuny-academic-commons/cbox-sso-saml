 # CBOX SSO SAML

Add SAML SSO CUNY integration to Commons In A Box

## Authorization and Authentication

When this plugin is activated, authorization via SAML SSO is **required** for a user to register.

The visitor's browser session is redirected to SAML SSO for authentication. When authentication with SAML SSO is successful, information about that session is sent back to the Commons In A Box site, and the SSO attributes are checked to determine if the user is authorized to register. If they are, the user can continue with registration.

### Paths

The plugin manages the following paths:

* `/sso/login` will initiate alogin request through SAML.
* `/sso/verify` handles the SAML response from SAML.
* `/sso/logout` will initiate a logout request through SAML.
* `/sso/metadata.xml` provides the SP metadata for the site.

## Configuration

### IdP and SP Metadata

The plugin has a default configuration for SAML identiy provider (IdP) and service provider (SP) metadata.

The CUNY SSO IdP configuration was based on [the metadata file provided by CUNY IT](https://ssologin.cuny.edu/idp/metadata/oam-saml-metadata.xml).

Both IdP and SP configurations can be overridden or modified with the `cbox_sso_saml_saml_settings` filter.

### SAML Attributes

The plugin manages authorization via the SAML attributes expected by CUNY SPS OpenLab.

The `cbox_sso_saml_can_register` filter can be used to override this behavior based on the available SAML attributes.

### Users

Once active, SSO is required for new user regitration. If needed, site admins can add users manually and allow them to login with standard WordPress authentication.

Site admins can also add a user's CUNY SSO EMPLID to a user's profile to connect an existing user account with CUNY SSO.

Site admins can also remove a user's CUNY SSO EMPLID to disconnect a user account from CUNY SSO.

If the `cuny_sso_allow_wp_login` option is set to `yes` on the site, any user without an EMPLID can login with their WordPress credentials.

### Certificates

A private key and certificate are required for the plugin to sign and verify SAML requests and responses. These can be stored as options in WordPress or filtered in code. No keys are provided by default with the plugin.

```
openssl req -new -x509 -key private.key -out certificate.crt -days 3650
wp option set cbox_sso_saml_x509_certificate $(cat certificate.crt)
wp option set cbox_sso_saml_private_key $(cat private.key)
```

Or, filter the keys with `cbox_sso_saml_private_key` and `cbox_sso_saml_x509_certificate`.

## Build and distribution

The plugin relies on a composer configuration for autoloading and the [underlying `onelogin/php-saml` library](https://github.com/SAML-Toolkits/php-saml). WP-CLI's [dist-archive command](https://github.com/wp-cli/dist-archive-command) can be used to build a versioned zip file for distribution.

```
composer install --no-progress --no-dev
composer dump-autoload
wp dist-archive ./
```
