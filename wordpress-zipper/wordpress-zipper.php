<?
/*
Plugin Name: Wordpress Post & Category ZIP
Plugin URI: https://github.com/tapwork/wordpress-zip-category-posts
Description: This wordpress plugin zips your posts, pages and categories after publishing the post or page - it even downloads external URLs and replaces the URL with the local URL
Author: Christian Menschel
Version: 0.1
Author URI: http://twitter.com/tapworker
*/

// ini_set('display_errors', 'On');
// error_reporting(E_ALL ^ E_NOTICE);

// CONSTANTS HERE
define("ZIP_FOLDER", "/zip_downloads");
define("ZIP_SCRIPT", "command_line_zipper.php");

$phpPath = '/usr/local/bin/php5-53LATEST-CLI -f';
// check if this command is possible
$returnVal = shell_exec("which ".$phpPath);
if (empty($returnVal)) {
	$phpPath = exec("which php"); // used as fallback
}
define("PHP_PATH", $phpPath);

add_action('init', 'de_tapwork_perform_zip_download');
add_action('publish_post', 'de_tapwork_wp_download_and_zip');
add_action('publish_page', 'de_tapwork_wp_download_and_zip');
add_action('edit_page', 'de_tapwork_wp_download_and_zip');
add_action('edit_post', 'de_tapwork_wp_download_and_zip');
add_action('admin_menu', 'de_tapwork_wp_wordpress_zipper_plugin_settings');

function de_tapwork_wp_wordpress_zipper_plugin_settings() {
    add_menu_page('Wordpress Zipper Settings',
    			 'Wordpress Zipper Settings',
    			 'edit_posts',
    			 'de_tapwork_wordpress_zipper_settings',
    			 'de_tapwork_wp_wordpress_zipper_html_settings');
}

function de_tapwork_wp_download_and_zip() {
	global $wpdb;
	global $post;

	$post_id = $post->ID;
	$db_name = $wpdb->dbname;
	$db_user = $wpdb->dbuser;
	$db_pw = $wpdb->dbpassword;
	$db_host = $wpdb->dbhost;
	

	$dir = dirname(__FILE__);
	$cmd_path = $dir.'/'.ZIP_SCRIPT;
	$cmd = PHP_PATH.' '. $cmd_path .'  '.$db_name.' '.$db_user.' '.$db_pw.' '.$db_host.' '.ZIP_FOLDER.' '.$post_id;
	exec($cmd . " > /dev/null &");  
}

function de_tapwork_perform_zip_download() {		
	$uri = $_SERVER['REQUEST_URI'];
	$path_comps = explode('/', $uri );
	$last_path_component = basename($uri);
	if (substr($last_path_component,-4) == ".zip" &&
		count($path_comps) > 1 ) {
		$urlBase = plugins_url( ZIP_FOLDER , __FILE__ );
		$path = substr($last_path_component, 0, -4);
		//
		// 1. we try to find a category for the current request
		//
		if ($path_comps[1] == 'category') {
			$cat_id = category_id_for_path($path);
			if ($cat_id > 0)
			{
				$url = $urlBase . "/category_".$cat_id.".zip";
			}
		} else {
			//
			// 2. if did not find a category, then we try to find a post 
			//
			$id = post_id_for_path($path);
			if ($id > 0) {
				$url = $urlBase . "/post_".$id.".zip";
			}
		}

		if (isset($url)) {
			wp_redirect( $url, "301" );
			exit();
		}
	}
}

function post_id_for_path($path) {
   global $wpdb;
   $slug = str_replace("/","",$path);
   $sql = "
      SELECT
         ID
      FROM
         $wpdb->posts
      WHERE
        post_name = \"$slug\"
   ";
   return $wpdb->get_var($sql);
}

function category_id_for_path($path) {
	global $wpdb;
	$slug = str_replace("/","",$path);
	$sql = "
	SELECT wp_terms.term_id AS ID
 	FROM wp_term_taxonomy, wp_terms
 	WHERE wp_term_taxonomy.taxonomy = 'category' 
		AND wp_term_taxonomy.term_id = wp_terms.term_id 
		AND wp_terms.slug = '$slug'";
	
	return $wpdb->get_var($sql);
}

function de_tapwork_wp_wordpress_zipper_html_settings() {
  	$url = "http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
   	$html = '</pre>
   			 	<div class="wrap"><form action="'.$url.'&updateAll=true" method="post" name="options">
				<h2>Wordpress Zipper Admin</h2>
				' . wp_nonce_field('update-options') . '
				<br><br>
 				<input type="submit" name="Submit" value="Update All Zip files" /></form></div>
			<pre>';

    echo $html;

    if ($_GET['updateAll'] == 'true') {
    	de_tapwork_wp_download_and_zip();
    	echo "Creating ZIP Files can take a couple minutes";
    }
}

?>
