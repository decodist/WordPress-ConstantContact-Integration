<?php
/*
 * A lightweight public-facing interface for users to update their Constant Contact email list choices.
 * 
 * This will trigger an automatic sync with CONSTANT CONTACT.
 */

//load WP
$path = $_SERVER['DOCUMENT_ROOT'];
include_once $path . '/wp-load.php';

//setup Constant Contact
$CC = new ConstantContact();

//logic flags
$userLoggedIn = is_user_logged_in(); //the page visitor is currently logged in
$userRegisteredInWP = false; //is the user registered in WP
$userSubscribedInCC = false; //does the user have existing subscriptions in Constant Contact
$userID = null; //the WP user ID
$subscriberData = null; //the user's Constant Contact data
$listMembership = null; //the user's Constant Contact subscriptions

/* 
 * Identify the user and check their subscriptions in Constant Contact
*/

//if an email address is provided as a query string, check if the corresponding user is registered in WP.
//(this needs to be the first step, in case a logged-in Admin is updating a user's preferences on their behalf)
if (isset($_GET['em']) && filter_var($_GET['em'], FILTER_VALIDATE_EMAIL)) {
	$user = get_user_by('email', $_GET['em']);
	if ($user) {
		$userID = $user->ID;
		$userRegisteredInWP = true;
	}
	//check if the user is in Constant Contact
	$subscriberData = $CC->getContactSubscriptionsFromEmail($_GET['em']);
	if ($subscriberData['contact_id']) {
		$userSubscribedInCC = true;
		if ($subscriberData['list_memberships']) {
			$listMembership = $CC->getInterestMappingForDB($subscriberData['list_memberships']);
		}
	}
} else {
	//if no email address is provided, check if visitor is logged-in to WP
	if ($userLoggedIn) {
		$current_user = wp_get_current_user();
		$userID = $current_user->ID;
		$userRegisteredInWP = true;
		
		//in case the user's subscriptions are out of sync, fetch their Constant Contact subs and update their account in WP
		$result = $CC->syncSubsDownFromConstantContact($userID, $current_user->user_email);
		if ($result) {
			$userSubscribedInCC = true;
		}
	}
}

/*
 * Generate the UI depending on what we know about the user and their subscriptions
*/
echo "
	<!DOCTYPE html>
	<html>
    <head>
        <title>Update User Preferences</title>
        <meta name='robots' content='noindex'>
    </head>
  	<body>
			<a href='https://redacted.com'><img src='https://redacted.com/wp-content/uploads/xxx/xxx.png' alt='redacted'></a>
			
			<b>Stay informed about redacted</b>
";

//process the form if it's in a submitted state
if ( isset($_POST['submit']) && isset($_POST['interests']) ) {
	
	//bot honeypot
	if (isset($_POST['website']) && $_POST['website'] !== '' ) {
		die();
	}
	
	//check for illegal email list names
	$allowed_responses = array(	"LIST 1",
								"LIST 2",
								"LIST 3",
								"LIST 4");
	
	$posted_responses = explode('|',$_POST['interests']);
	$posted_responses = array_filter($posted_responses); //trim any empties
	
	if ( !array_diff($posted_responses, $allowed_responses) ) {
		//if user is registered in WP, update their profile, which also triggers a create/update in Constant Contact.
		if ($userRegisteredInWP) {
			$result = js_profile_update($userID, null); //$result = 'success' or false
			if ($result === 'success') {
				//update users's interests in WP
				update_user_meta( $userID, 'student_interests_list', sanitize_text_field($_POST['interests']) );
			}
		} else {
			//user is not registered as a WP user, so just update their Constant Contact preferences
			$result = $CC->updateContactAndLists(sanitize_text_field($_POST['em']), '', '', $posted_responses); //'success' or false
		}
		
		//provide user feedback
		if ($result === 'success') {
			echo "	<div>
						Done! Your preferences have been updated.<br/><br/><a href='https://redacted.com'>Continue to redacted.com</a>
					</div>";
			die();
		} else {
			//send alert
			$to = 'REDACTED';
			$subject = 'redacted - User prefs update failed';
			$body = '';
			$headers = array('Content-Type: text/html; charset=UTF-8');
			$headers[] = 'From: REDACTED';
			wp_mail( $to, $subject, $body, $headers );
			
			echo "Sorry! We're experiencing a temporary issue and our team has been notified.<br/><br/>Please try again soon.<br/><br/><a href='https://redacted.com'>Continue to redacted.com</a>";
			die();
		}
	}
}

//if the user is registered in WP, or Constant Contact, display the preferences form...
if ($userRegisteredInWP || $userSubscribedInCC) {
	echo "		<form id='interestform' name='interestform' method='post' onchange='update()'>
				";
					if ($userRegisteredInWP) {
						js_interests_profile_fields($userID);
					} elseif ($userSubscribedInCC) {
						js_interests_profile_fields($_GET['em'], $listMembership);
					}
					echo "
					<br/>
					<input type='hidden' name='em' value=".$_GET['em'].">
					<input type ='submit' name ='submit' value ='Update'>
				</form>
			</div>
			<script>
			document.getElementById('website').style.display = 'none';
			
			theform = document.getElementById('interestform');
			function update() {
				theform.elements['interests'].value = '';
				for (var i = 0; i < theform.elements.length; i++ ) {
					if (theform.elements[i].type == 'checkbox') {
						if (theform.elements[i].checked == true) {
							theform.elements['interests'].value += theform.elements[i].value + '|';
						}
					}
				}
			}
			</script>
	";
} else {
		//user is not registered in WP, and has no Constant Contact subs, so diSplay the Constant Contact subscribe form
		echo "
			<!-- Begin Constant Contact Active Forms -->
			<script>  </script>
			<!-- End Constant Contact Active Forms -->
			
			<!-- Begin Constant Contact Inline Form Code -->
			<div class=\"ctct-inline-form\" data-form-id=\"XXX\"></div>
			<!-- End Constant Contact Inline Form Code -->
			";
}

//close the page
echo "
		</body>
	</html>
";