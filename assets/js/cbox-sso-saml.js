(function(){
	const emailField = document.querySelector( '.email-section input[name="email"]' );
	const { allowEmailChange } = window.CBOXSSOSAML || {};
	if ( emailField && ! allowEmailChange ) {
		emailField.setAttribute( 'readonly', 'readonly' );
		emailField.classList.add( 'readonly' );
	}
})();
