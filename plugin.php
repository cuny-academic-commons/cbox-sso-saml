<?php
/**
 * Plugin Name:  CBOX SSO SAML
 * Description:  Add SAML SSO integration to Commons In A Box
 * Version:      0.1
 * Plugin URI:   https://github.com/cuny-academic-commons/cbox-sso-saml
 * Author:       CUNY Academic Commons
 * Author URI:   https://commons.gc.cuny.edu
 * Text Domain:  cbox-sso-saml
 * Requires PHP: 7.4
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * @package cbox-sso-saml
 */

namespace CBOX\SSO\SAML;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once __DIR__ . '/vendor/autoload.php';

// Initialize the plugin.
Init::init();
