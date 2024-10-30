<?php
// Webhooks / Zapier management and integration
class ArigatoWebhooks {
	public static function manage() {
		global $wpdb;
		$action = empty($_GET['action']) ? 'list' : $_GET['action'];
				
		switch($action) {
			case 'add':
				if(!empty($_POST['ok']) and check_admin_referer('arigato_webhooks')) {
					$payload_config = self :: payload_config();
					$wpdb->query($wpdb->prepare("INSERT INTO ".BFT_WEBHOOKS." SET hook_url = %s, action=%s, payload_config=%s",
					esc_url_raw($_POST['hook_url']), sanitize_text_field($_POST['action']), serialize($payload_config) ));
					
					bft_redirect("admin.php?page=bft_webhooks");
				}
				
				require(BFT_PATH."/views/webhook.html.php");
			break;
			
			case 'edit':
				if(!empty($_POST['test'])	and check_admin_referer('arigato_webhooks')) {
					list($data, $result) = self :: test($_GET['id']);
				}		
			
				if(!empty($_POST['ok']) and check_admin_referer('arigato_webhooks')) {
					$payload_config = self :: payload_config();
					$wpdb->query($wpdb->prepare("UPDATE ".BFT_WEBHOOKS." SET hook_url = %s, action=%s, payload_config=%s WHERE ID=%d",
					esc_url_raw($_POST['hook_url']), sanitize_text_field($_POST['action']), serialize($payload_config), intval($_GET['id'])) );
				}
				
				// select hook
				$hook = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".BFT_WEBHOOKS." WHERE id=%d", intval($_GET['id'])));
				$payload_config = unserialize(stripslashes($hook->payload_config));
				
				require(BFT_PATH."/views/webhook.html.php");
			break;
			
			case 'list':
			default: 
				if(!empty($_GET['delete']) and wp_verify_nonce($_GET['arigato_hook_nonce'], 'delete_hook')) {
					$wpdb->query($wpdb->prepare("DELETE FROM ".BFT_WEBHOOKS." WHERE id=%d", intval($_GET['id'])));
					bft_redirect("admin.php?page=bft_webhooks");
				}			
			
				// select hooks join grades
				$hooks = $wpdb->get_results("SELECT tH.id as id, tH.hook_url as hook_url, tH.action as action
					FROM ".BFT_WEBHOOKS." tH ORDER BY tH.id");		
					
				// depending if there are hooks, set the option
				update_option('bft_webhooks', count($hooks));		
			
				require(BFT_PATH."/views/webhooks.html.php");
			break;
		}
	} // end manage
	
	// called on subscribe or unsibscribe action, figures out whether any webhooks should be sent
	public static function dispatch($user_id, $action = 'subscribe') {
		global $wpdb;
		
		// to avoid unnecessary queries this option is set to 1 only if there are webhooks in the system
		if(get_option('bft_webhooks') <= 0) return false;
		
	   // when unsubscribing, the user_id is actuall an obkect and we should not re-select			
		if($action == 'unsubscribe') $user = $user_id;
		else $user = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".BFT_USERS." WHERE id=%d", $user_id));
	
		$hooks = $wpdb -> get_results($wpdb->prepare("SELECT * FROM ".BFT_WEBHOOKS." WHERE action=%s ORDER BY id", $action));
			
		foreach($hooks as $hook) {
			// prepare data
			$config = unserialize(stripslashes($hook->payload_config));
			$data = [];
			
			foreach($config as $key => $setting) {				
				if(empty($setting) or !is_array($setting)) continue;
				
				// all keys are predefined. $setting['name'] is the customizable param name
				// $setting['value'] is empty and should come from $taking data except on the custom pre-filled keys
				switch($key) {
					case 'email':
						$data[$setting['name']] = $user->email;
					break;
					case 'name':
						$data[$setting['name']] = $user->name;
					break;				
					case 'custom_key1':
					case 'custom_key2':
					case 'custom_key3':
						$data[$setting['name']] = stripslashes($setting['value']);
					break;
				} // end switch
			} // end foreach config param
			
			self :: send($hook->hook_url, $data);			
			
		} // end foreach hook	
	} // end dispatch
	
	public static function dispatch_subscribe($status, $user_id) {
		if(!$status) return false;
		
		return self :: dispatch($user_id, 'subscribe');
	}
	
	public static function dispatch_confirmed($user_id) {		
		return self :: dispatch($user_id, 'subscribe');
	}
	
	public static function dispatch_unsubscribe($user) {
		return self :: dispatch($user, 'unsubscribe');
	}
	
	// send webhook
	public static function send($url, $data) {
		$args = array(
	        'headers' => array(
	            'Content-Type' => 'application/json',
	        ),
	        'body' => json_encode( $data )
	    );

	    //$return = wp_remote_post( $url, $args );
	    
	    // probably make includings headers optional?
	    $headers = ['Content-Type' => 'application/json',];
	    
	    $args = ['body' => $data];
	    $return = wp_remote_post( $url, $args);
		if(is_wp_error($return)) {
			$error_string = $return->get_error_message();
   		echo '<div id="message" class="error"><p>' . sprintf(__('Webhook error: %s', 'broadfast'), $error_string) . '</p></div>';
   		return false;
		}
	   return true;
	} // end send

	// test a hook	
	public static function test($hook_id) {
		global $wpdb;
		
		$hook = $wpdb -> get_row($wpdb->prepare("SELECT * FROM ".BFT_WEBHOOKS." WHERE id=%d", intval($hook_id)));
		$config = unserialize(stripslashes($hook->payload_config));
		$data = [];
		
		foreach($config as $key => $setting) {				
				if(empty($setting) or !is_array($setting)) continue;
				
				// all keys are predefined. $setting['name'] is the customizable param name
				// $setting['value'] is empty and should come from $taking data except on the custom pre-filled keys
				switch($key) {
					case 'email':
						$data[$setting['name']] = get_option('admin_email');
					break;
					case 'name':
						$data[$setting['name']] = "Test Name";
					break;				
					case 'custom_key1':
					case 'custom_key2':
					case 'custom_key3':
						$data[$setting['name']] = stripslashes($setting['value']);
					break;
				} // end switch
			} // end foreach config param
			
			$args = array(

	        'headers' => array(
	            'Content-Type' => 'application/json',
	        ),
	        'body' => json_encode( $data )
	    );
			
		  $return = wp_remote_post( $hook->hook_url, $args );
		  
		  return [$data, $return];
	} // end test
	
	// helper to prepare the payload_config array
	private static function payload_config() {
		$payload_config = [];
		if(!empty($_POST['email_name']))	$payload_config['email'] = ['name' => sanitize_text_field($_POST['email_name'])];
		if(!empty($_POST['name_name']))	$payload_config['name'] = ['name' => sanitize_text_field($_POST['name_name'])];		
		if(!empty($_POST['custom_key1_name']))	$payload_config['custom_key1'] = ['name' => sanitize_text_field($_POST['custom_key1_name']), "value" => sanitize_text_field($_POST['custom_key1_value'])];
		if(!empty($_POST['custom_key2_name']))	$payload_config['custom_key2'] = ['name' => sanitize_text_field($_POST['custom_key2_name']), "value" => sanitize_text_field($_POST['custom_key2_value'])];
		if(!empty($_POST['custom_key3_name']))	$payload_config['custom_key3'] = ['name' => sanitize_text_field($_POST['custom_key3_name']), "value" => sanitize_text_field($_POST['custom_key_value'])];
		
		return $payload_config;
	} // end payload_config
}