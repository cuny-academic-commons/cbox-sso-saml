(function(){
	const emailField = document.querySelector( 'input[name="email"]' );
	const { allowEmailChange, emailFieldDescription } = window.CBOXSSOSAMLAdmin || {};
	if ( emailField && ! allowEmailChange ) {
		emailField.setAttribute( 'readonly', 'readonly' );
		emailField.classList.add( 'readonly' );

		const emailDescription = document.getElementById( 'email-description' );
		if ( emailDescription ) {
			emailDescription.innerHTML = emailFieldDescription || '';
		}
	}
})();
