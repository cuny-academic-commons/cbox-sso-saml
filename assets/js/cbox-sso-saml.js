(function(){
	const emailField = document.querySelector( '.email-section input[name="email"]' );
	const { allowEmailChange, allowPasswordChange } = window.CBOXSSOSAML || {};
	if ( emailField && ! allowEmailChange ) {
		emailField.setAttribute( 'readonly', 'readonly' );
		emailField.classList.add( 'readonly' );
	}

	const hidePasswordSection = ( section ) => {
		section.style.display = 'none';
		section.querySelectorAll('input').forEach( input => {
			input.setAttribute( 'readonly', 'readonly' );
			input.classList.add( 'readonly' );
		});
	}

	if ( ! allowPasswordChange ) {
		const currentPasswordSection = document.querySelector( '.current-pw-section' );
		const changePasswordSection = document.querySelector( '.change-pw-section' );

		if ( currentPasswordSection ) {
			hidePasswordSection( currentPasswordSection );
		}

		if ( changePasswordSection ) {
			hidePasswordSection( changePasswordSection );
		}
	}
})();
