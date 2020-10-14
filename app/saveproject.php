<?php
	//YOU CAN HANDLE SESSIONING HERE....
	require_once('../../../../wp-load.php');
	
	function clearProjects($productId) {
		global $wpdb;
		$sessId = $_COOKIE['pitchprint_sessId'];
		$wpdb->delete($wpdb->prefix . 'pitchprint_projects', array('id' => $sessId, 'product_id' => $productId) );
	}
	
	if (isset($_REQUEST['clear'])) {
		if ( isset($_COOKIE['pitchprint_sessId']) ) {
			clearProjects($_GET['productId']);
		} else {
			session_start();
			if (isset($_SESSION['pp_projects'])) {		
				if (isset($_SESSION['pp_projects'][$_GET['productId']])) {
					unset($_SESSION['pp_projects'][$_GET['productId']]);
				} elseif ( isset($_COOKIE['pitchprint_sessId']) ) {
					clearProjects($_GET['productId']);
				}
			}
		} 
		exit();
	}
	
	if ( isset($_COOKIE['pitchprint_sessId']) ) {
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'pitchprint_projects';
		$sessId = $_COOKIE['pitchprint_sessId'];
		$value = $_POST['values'];
		$productId = $_GET['productId'];
		
		// Delete old
		$wpdb->delete($table_name, array('id' => $sessId, 'product_id' => $productId) );
		
		// Insert new
		$date = date('Y-m-d H:i:s', time()+60*60*24*30);
		$sql = "INSERT INTO `$table_name` VALUES ('$sessId', $productId, '$value', '$date')";
		
		dbDelta($sql);
	} else {
		session_start();
		if (!isset($_SESSION['pp_projects'])) {
			$_SESSION['pp_projects'] = array();
			$_SESSION['pp_projects'][$_GET['productId']] = array();
		} else if (!isset($_SESSION['pp_projects'][$_GET['productId']])) {
			$_SESSION['pp_projects'][$_GET['productId']] = array();
		}
			
		$_SESSION['pp_projects'][$_GET['productId']] = $_POST['values'];
	}
	
	if (isset($_POST['clone'])) {
		echo get_permalink($_GET['productId']);
	}
?>