<?php
/*
 * Script to regenerate the API tokens required by Constant Contact.
 *
 * Accessing this page requires that you are logged in to Constant Contact and also WordPress as an Administrator.
 *
 */

if ( !defined('ABSPATH') ) {
    //If WP isn't loaded, load it up.
    $path = $_SERVER['DOCUMENT_ROOT'];
    include_once $path . '/wp-load.php';
}

//check if user is logged in to WP as an Administrator
if( current_user_can('administrator') ) {
	
	require_once "constantcontact.php";
	
	$CC = new ConstantContact();
	$ccURL = $CC->getAuthorizationURL();
	
	echo "Please login to Constant Contact in a separate browser tab, then click the following link to refresh the API Refresh Token used by the website.<br/><br/>";
	echo "<a href='" . $ccURL . "'>Refresh Token</a>";
	
	if (!empty($_GET['code'])) {
		//Auth code received. Now, convert into a Refresh Token
		$rtoken = $CC->convertAuthCodeToAccessTokens( trim($_GET['code']) );
		
		if ($rtoken !== '') {
			echo "<br/><br/>DONE! A new refresh token has been received and saved.<br/><br/>";
		} else {
			echo "<br/><br/>There was a problem. API token not received.<br/><br/>";
		}
  	}
	
} else {
	echo "Please login to WordPress to access this page.";
}

