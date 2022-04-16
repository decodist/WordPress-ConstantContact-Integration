<?php
/* Plugin Name: Constant Contact Sync
  Description: Custom plugin to update user subscription preferences in Constant Contact
  Version: 1.5
  Plugin URI: https://www.decodist.com
  Author: Jason Shaw
  Author URI: https://www.decodist.com
  Text Domain: CCSync
 */

require_once plugin_dir_path( __FILE__ ) . 'inc/constantcontact.php';

add_action( 'profile_update', 'js_profile_update', 10, 2 );
function js_profile_update( $user_id, $old_user_data ) {
	
	//get user's name
	$user_fname = get_userdata( $user_id ) -> first_name;
	$user_lname = get_userdata( $user_id ) -> last_name;
	
	//get user's email address
	$user_email = get_userdata( $user_id ) -> user_email;
	
	//get user's updated interests
	$user_interests = $_POST['interests'];
	$user_interests_array = explode("|", $user_interests);
	$user_interests_array = array_filter($user_interests_array); //remove empties
	
	//update the user's subscriptions in Constant Contact based on their interests
	$CC = new ConstantContact();
	if ( strpos($_SERVER['HTTP_HOST'], 'REDACTED') === false ) {
		return $CC->updateContactAndLists($user_email, $user_fname, $user_lname, $user_interests_array); //'success' or false
	}
}
