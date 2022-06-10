<?php
/**
 * Constant Contact API Server-Side Integration
 *
 * Custom functions for interacting and syncing with Constant Contact lists.
 *
 * @author     Jason Shaw
 * @version    1.6
 */
class ConstantContact {
	
	function getAPIKey() {
		// Use base64 of API_KEY:CLIENT_SECRET for credentials
		return 'REDACTED';
	}
	
	function getAuthEndpoint() {
		return 'https://authz.constantcontact.com/oauth2/default/v1/token';
	}
	
	function getCreateUpdateEndpoint() {
		return 'https://api.cc.email/v3/contacts/sign_up_form';
	}
	
	function getRemoveContactFromListEndpoint() {
		return 'https://api.cc.email/v3/activities/remove_list_memberships';
	}
	
	function getRedirectURI() {
		return "https://".$_SERVER[HTTP_HOST].strtok($_SERVER["REQUEST_URI"], '?')."";
	}
	
	function getContactsEndpoint() {
		return "https://api.cc.email/v3/contacts/";
	}
	
	function getAuthorizationURL() {
		// Generate authorization URL
		// 'state' variable is an arbitrary value which is returned by the response for security verification purposes... which we don't need to do.
		$baseURL = "https://authz.constantcontact.com/oauth2/default/v1/authorize";
		$authURL = $baseURL . "?client_id=" . js_CC_APIKEY . "&scope=contact_data+offline_access&response_type=code" . "&redirect_uri=" . $this->getRedirectURI();
		return $authURL;
	}
	
	function storeNewTokens($refreshToken, $accessToken) {
		global $wpdb;
		if ( !empty($refreshToken) && !empty($accessToken) ) {
			$wpdb->query($wpdb->prepare("UPDATE js_constantcontact_auth SET refresh_token = '$refreshToken' "));
			$wpdb->query($wpdb->prepare("UPDATE js_constantcontact_auth SET access_token = '$accessToken', access_token_last_used = NOW(), access_token_created = NOW() "));
		}
	}
	
	function convertAuthCodeToAccessTokens($authToken) {
		// Use cURL
		$ch = curl_init();
	
		// Define base URL
		$base = $this->getAuthEndpoint();
	
		// Create full request URL
		$url = $base . '?code=' . $authToken . '&redirect_uri=' . $this->getRedirectURI() . '&grant_type=authorization_code';
		curl_setopt($ch, CURLOPT_URL, $url);
	
		// Set the Authorization header to use the encoded credentials
		$authorization = 'Authorization: Basic ' . $this->getAPIKey();
		curl_setopt($ch, CURLOPT_HTTPHEADER, array($authorization, 'Content-Type: application/x-www-form-urlencoded'));
	
		// Set method and to expect response
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		
		// Make the call
		$response = curl_exec($ch);
		curl_close($ch);
		
		// Decode JSON response
		$decodedResponse = json_decode($response);
		
		//update the db
		$this->storeNewTokens($decodedResponse->refresh_token, $decodedResponse->access_token);
		
		//return the refresh token
		return $decodedResponse->refresh_token;
	}
	
	function getAccessToken() {
		//check if access token is still valid (access tokens expire 2 hours after last use, and 24 hours after creation)
		global $wpdb;
		$result = $wpdb->get_row($wpdb->prepare("SELECT access_token FROM js_constantcontact_auth WHERE TIMESTAMPDIFF(MINUTE, access_token_last_used, NOW()) < 115 AND TIMESTAMPDIFF(HOUR, access_token_created, NOW()) < 23 LIMIT 1"));
		if ( !empty($result->access_token) ) {
			//update the last used time
			$wpdb->query($wpdb->prepare("UPDATE js_constantcontact_auth SET access_token_last_used = NOW() "));
			//return the valid access token
			return $result->access_token;
		}
		
		//otherwise, if the access token has expired, fetch a new one...
		
		// Get refresh token from db
		$result = $wpdb->get_row($wpdb->prepare("SELECT refresh_token FROM js_constantcontact_auth LIMIT 1"));
		$refreshToken = trim($result->refresh_token);
		
		// Use cURL to get a new access token and refresh token
		$ch = curl_init();
	
		// Define base URL
		$base = $this->getAuthEndpoint();
	
		// Create full request URL
		$url = $base . '?refresh_token=' . $refreshToken . '&grant_type=refresh_token';
		curl_setopt($ch, CURLOPT_URL, $url);
	
		// Set the Authorization header to use the encoded credentials
		$authorization = 'Authorization: Basic ' . $this->getAPIKey();
		curl_setopt($ch, CURLOPT_HTTPHEADER, array($authorization, 'Content-Type: application/x-www-form-urlencoded'));
	
		// Set method and to expect response
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		
		// Make the call
		$response = curl_exec($ch);
		curl_close($ch);
		
		//check for errors
		if( strpos($response, "error") !== false ) {
			$errText = "Problem with Constant Contact API call: ".json_decode( json_encode($response), true);;
			error_log($errText);
			
			//send alert
			$to = 'REDACTED';
			$subject = 'REDACTED';
			$body = $errText;
			$headers = array('Content-Type: text/html; charset=UTF-8');
			$headers[] = 'From: REDACTED';
			wp_mail( $to, $subject, $body, $headers );
			
		} else {
			// Decode JSON response
			$decodedResponse = json_decode($response);
			// Store the new refresh token
			$this->storeNewTokens($decodedResponse->refresh_token, $decodedResponse->access_token);
			return $decodedResponse->access_token;
		}
	}
	
	function getAuthAndContactIdFromEmail($contactEmail) {
		// Use cURL
		$ch = curl_init();
		$url = $this->getContactsEndpoint();
		$url .= "?email=".$contactEmail;
		curl_setopt($ch, CURLOPT_URL, $url);
		
		// Set the headers to use the access token and json
		$auth = 'Authorization: Bearer ' . $this->getAccessToken();
		curl_setopt($ch, CURLOPT_HTTPHEADER, array($auth, 'Content-Type: application/json'));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLINFO_HEADER_OUT, true);
		
		// Make the call
		$response = curl_exec($ch);
		curl_close($ch);
		$decodedResponse = json_decode($response, true);
		
		//return both auth and contact_id to save re-authentication trips
		$authAndContactId = array("auth" => $auth, "contact_id" => $decodedResponse['contacts'][0]['contact_id']);
		return $authAndContactId;
	}
	
	function getContactSubscriptionsFromEmail($contactEmail) {
		
		$authAndContactId = $this->getAuthAndContactIdFromEmail($contactEmail);
		$contact_id = $authAndContactId['contact_id'];
		$auth = $authAndContactId['auth'];
		
		if ($contact_id === '') {
			return false;
		}
		
		// Use cURL
		$ch = curl_init();
		$url = $this->getContactsEndpoint();
		$url .= $contact_id."?include=list_memberships";
		curl_setopt($ch, CURLOPT_URL, $url);
		
		// Set the headers to use the access token and json
		curl_setopt($ch, CURLOPT_HTTPHEADER, array($auth, 'Content-Type: application/json'));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLINFO_HEADER_OUT, true);
		
		// Make the call
		$response = curl_exec($ch);
		curl_close($ch);
		$decodedResponse = json_decode($response, true);
		
		//return both list memberships and contact_id (list_memberships is an array)
		$listsAndContactId = array(
			"list_memberships"	=> $decodedResponse['list_memberships'],
			"contact_id"		=> $contact_id
		);
		return $listsAndContactId;
	}
	
	function getInterestMapping() {
		/* list names and ID's from Constant Contact */
		$interest_mapping = array (
			"LIST 1"	=>	"XXX-8cb0-11eb-XXX",
			"LIST 2"	=>	"XXX-8cb0-11eb-XXX",
			"LIST 3"	=>	"XXX-8cb0-11eb-XXX",
			"LIST 4"	=>	"XXX-8cb0-11eb-XXX"
		);
		return $interest_mapping;
	}
	
	function getInterestMappingForDB($valuesToMap) {
		/*
		 * Helper function to convert a user's subscriptions into a string ready for the WP database.
		 * Accepts the user's current subscriptions from Constant Contact, and updates the user's subs
		 * to the latest set of valid lists.
		*/
		$interest_mapping = $this->getInterestMapping();
		$valuesForDB = '';
		foreach ($valuesToMap as $value) {
			$valuesForDB .= array_search($value, $interest_mapping) ."|";
		}
		return $valuesForDB;
	}
	
	function syncSubsDownFromConstantContact($userID, $email) {
		//update WP with the user's current subscription information from CC
		$subscriberData = $this->getContactSubscriptionsFromEmail($email);
		if ($subscriberData['list_memberships']) {
			$dbString = $this->getInterestMappingForDB($subscriberData['list_memberships']);
			update_user_meta( $userID, 'student_interests_list', sanitize_text_field($dbString) );
			return true;
		} else {
			return false;
		}
	}
	
	function updateContactAndLists($contactEmail, $contactFirstName, $contactLastName, $contactInterests) {
		/*
		* ADD/UPDATE CONTACT and ADD LIST MEMBERSHIPS
		* Note, updating a contact's list memberships is a three-step process:
		* 	1) Add contact to subscribed lists using mapping below
		* 	2) Get contact's ID from the above transaction response
		* 	3) Remove contact from not-subscribed lists (there is no remove-all)
		*/
		
		//find out what the user is interested in, and not interested in
		$interest_mapping = $this->getInterestMapping();
		$all_interests = array_keys($interest_mapping);
		$user_interests = [];
		$user_non_interests = [];
		foreach ($all_interests as $interestItem) {
			if (in_array($interestItem, $contactInterests)) {
				$user_interests[] = $interest_mapping[$interestItem];
			} else {
				$user_non_interests[] = $interest_mapping[$interestItem];
			}
		}
		
		// Use cURL
		$ch = curl_init();
		$url = $this->getCreateUpdateEndpoint();
		curl_setopt($ch, CURLOPT_URL, $url);
		
		// Set the headers to use the access token and json
		$auth = 'Authorization: Bearer ' . $this->getAccessToken();
		curl_setopt($ch, CURLOPT_HTTPHEADER, array($auth, 'Content-Type: application/json'));
		
		// 1) Add to subscribed lists
		if ( empty($user_interests) ) {
			//user interests can't be empty, so use a temporary value to generate an API response
			$user_interests[] = array_values($interest_mapping)[0];
		}
		$body = (object) [
				'email_address' => $contactEmail,
				'first_name' => $contactFirstName,
				'last_name' => $contactLastName,
				'list_memberships' => $user_interests,
		];
		$bodyJSON = json_encode($body);
		
		curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyJSON);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLINFO_HEADER_OUT, true);
		
		// Make the call
		$response = curl_exec($ch);
		curl_close($ch);
		
		$decodedResponse = json_decode($response);
		$contactId = $decodedResponse->contact_id;
		
		// 2) Remove from not-interested lists using the returned contactID
		$ch = curl_init();
		$url = $this->getRemoveContactFromListEndpoint();
		curl_setopt($ch, CURLOPT_URL, $url);
		
		// Set the headers to use the access token and json
		curl_setopt($ch, CURLOPT_HTTPHEADER, array($auth, 'Content-Type: application/json'));
		
		// Build body from contact info and list(s) NOT subscribed
		$contacts = (object) [
				'contact_ids' => $contactId
		];
		$body = (object) [
				'source' => $contacts,
				'list_ids' => $user_non_interests
		];
		$bodyJSON = json_encode($body);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyJSON);
		
		// Set method and to expect response
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		
		// Make the call
		$response = curl_exec($ch);
		curl_close($ch);
		
		//check for errors
		if( strpos($response, "error_message") !== false ) {
			$errText = "Problem with Constant Contact API call: ".json_decode( json_encode($response), true);;
			error_log("CC API error text = ".$errText);
			
			//send alert
			$to = 'REDACTED';
			$subject = 'REDACTED';
			$body = $errText;
			$headers = array('Content-Type: text/html; charset=UTF-8');
			$headers[] = 'From: REDACTED';
			wp_mail( $to, $subject, $body, $headers );
			
		} else {
			return "success";
		}
		return false; //something went wrong
	}
}
