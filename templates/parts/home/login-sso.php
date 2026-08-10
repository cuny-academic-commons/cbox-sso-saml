<?php
/**
 * Login/signup panel on home page.
 *
 * @package cbox-sso-saml
 */

?>

<?php
if ( is_user_logged_in() ) :
	echo '<div id="open-lab-login" class="log-box">';
	printf(
		'<span class="title inline-element semibold">%s</span>',
		sprintf(
			// translators: logged-in user name.
			esc_html__( 'Welcome, %s', 'commons-in-a-box' ),
			esc_html( bp_core_get_user_displayname( bp_loggedin_user_id() ) )
		)
	);
	do_action( 'bp_before_sidebar_me' )
	?>

	<?php
	$brand_pages = cboxol_get_brand_pages();

	$help_link = '';
	if ( isset( $brand_pages['help'] ) ) {
		$help_link = $brand_pages['help']['preview_url'];
	}

	$contact_link = '';
	if ( isset( $brand_pages['contact-us'] ) ) {
		$contact_link = $brand_pages['contact-us']['preview_url'];
	}

	$need_help_text = '';

	if ( $help_link && $contact_link ) {
		// translators: 1. help link, 2. contact link.
		$need_help_text = sprintf( 'Visit the <a class="roll-over-loss" href="%1$s">Help section</a> or <a class="roll-over-loss" href="%2$s">contact us</a> with a question.', esc_attr( $help_link ), esc_attr( $contact_link ) );
	} elseif ( $help_link ) {
		// translators: help link.
		$need_help_text = sprintf( 'Questions? Visit the <a class="roll-over-loss" href="%s">Help section</a>.', esc_attr( $help_link ) );
	} elseif ( $contact_link ) {
		// translators: contact link.
		$need_help_text = sprintf( '<a class="roll-over-loss" href="%s">Contact us</a> with questions.', esc_attr( $contact_link ) );
	}

	$user_avatar = bp_get_loggedin_user_avatar(
		[
			'type' => 'full',
			'html' => false,
		]
	);

	?>

	<div id="sidebar-me" class="clearfix">
		<div id="user-info">
			<a class="avatar" href="<?php echo esc_attr( bp_loggedin_user_url() ); ?>">
				<?php /* translators: user display name */ ?>
				<img class="img-responsive" src="<?php echo esc_attr( $user_avatar ); ?>" alt="<?php echo esc_attr( sprintf( __( 'Avatar for %s', 'commons-in-a-box' ), bp_core_get_user_displayname( bp_loggedin_user_id() ) ) ); ?>" />
			</a>

			<div class="welcome-link-my-profile">
				<a href="<?php echo esc_url( bp_loggedin_user_url() ); ?>"><?php esc_html_e( 'My Profile', 'commons-in-a-box' ); ?></a>
			</div>

			<ul class="content-list">
				<?php /* translators: logged-in user display name */ ?>
				<li class="no-margin no-margin-bottom"><a class="button logout font-size font-12 roll-over-loss" href="<?php echo esc_attr( wp_logout_url( bp_get_root_url() ) ); ?>"><?php printf( esc_html__( 'Not %s?', 'commons-in-a-box' ), esc_html( bp_members_get_user_slug( bp_loggedin_user_id() ) ) ); ?></a></li>
				<li class="no-margin no-margin-bottom"><a class="button logout font-size font-12 roll-over-loss" href="<?php echo esc_attr( wp_logout_url( bp_get_root_url() ) ); ?>"><?php esc_html_e( 'Log Out', 'commons-in-a-box' ); ?></a></li>
			</ul>
			</span><!--user-info-->
		</div>
		<?php do_action( 'bp_sidebar_me' ); ?>
	</div><!--sidebar-me-->

	<?php do_action( 'bp_after_sidebar_me' ); ?>

	<?php echo '</div>'; ?>

	<?php if ( $need_help_text ) : ?>
		<div id="login-help" class="log-box">
			<h2 class="title"><?php esc_html_e( 'Need Help?', 'commons-in-a-box' ); ?></h2>
			<p class="font-size font-14">
				<?php
				echo wp_kses(
					$need_help_text,
					[
						'a' => [
							'class' => true,
							'href'  => true,
						],
					]
				);
				?>
			</p>
		</div><!--login-help-->
	<?php endif; ?>

<?php else : ?>

	<?php if ( bp_get_signup_allowed() ) : ?>
		<?php echo '<div id="open-lab-join" class="log-box">'; ?>
		<?php echo '<h2 class="title"><span class="fa fa-plus-circle flush-left"></span> ' . esc_html__( 'Sign Up', 'commons-in-a-box' ) . '</h2>'; ?>
		<?php
		printf(
			'<p><a class="btn btn-default btn-primary link-btn pull-right semibold" href="%s">%s</a> <span class="font-size font-14">%s<br />%s</span></p>',
			esc_attr( bp_get_signup_page() ),
			esc_html__( 'Sign up', 'commons-in-a-box' ),
			esc_html__( 'Need an account?', 'commons-in-a-box' ),
			esc_html__( 'Sign Up to become a member!', 'commons-in-a-box' )
		);
		?>
		<?php echo '</div>'; ?>
		<?php echo '<div id="open-lab-login" class="log-box">'; ?>
		<?php do_action( 'bp_after_sidebar_login_form' ); ?>
		<?php echo '</div>'; ?>
	<?php endif; ?>

	<div id="user-login" class="log-box">

		<h2 class="title"><span class="fa fa-arrow-circle-right"></span> <?php esc_html_e( 'Log in', 'commons-in-a-box' ); ?></h2>
		<?php do_action( 'bp_before_sidebar_login_form' ); ?>

		<?php
		printf(
			'<p><a class="btn btn-default btn-primary link-btn pull-right semibold" href="%s">%s</a> <span class="font-size font-14">%s</span></p>',
			esc_attr( wp_login_url() ),
			esc_html__( 'Log in', 'commons-in-a-box' ),
			esc_html__( 'Already have an account? Log in using your username and password.', 'commons-in-a-box' )
		);
		?>
	</div>
<?php endif; ?>
