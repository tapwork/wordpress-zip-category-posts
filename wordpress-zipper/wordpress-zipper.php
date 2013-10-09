<?php
/*
Plugin Name: Wordpress Post & Category ZIP
Plugin URI: https://github.com/tapwork/wordpress-zip-category-posts
Description: This wordpress plugin zips your posts and categories after publishing the post - even downloads external URLs and replaces the URL with the local URL
Author: Christian Menschel
Version: 0.1
Author URI: http://twitter.com/tapworker
*/

define("BASE_PATH", "/wp-content/plugins/wordpress-zipper/zips/");

add_action('init', 'forward_zip_download');
add_action('publish_post', 'tw_zip_content');
add_action('publish_page', 'tw_zip_content');


function forward_zip_download()
{
	$uri = $_SERVER['REQUEST_URI'];
	$path_comps = explode('/', $uri );
	$last_path_component = basename($uri);
	if (substr($last_path_component,-4) == ".zip" &&
 		count($path_comps) > 1 )
	{
		$urlBase = "http://".$_SERVER['HTTP_HOST'].BASE_PATH;
		$path = substr($last_path_component, 0, -4);
		//
		// 1. we try to find a category for the current request
		//
		if ($path_comps[1] == 'category')
		{
			$cat_id = category_id_for_path($path);
			if ($cat_id > 0)
			{
				$url = $urlBase . "category_".$cat_id.".zip";
			}
		}
		else
		{
			//
			// 2. if did not find a category, then we try to find a post 
			//
			$id = post_id_for_path($path);
			if ($id > 0)
			{
				$url = $urlBase . "post_".$id.".zip";
			}
		}

		if (isset($url))
		{
			wp_redirect( $url, "301" );
			exit;
		}
	}
}

function tw_zip_content() {
	global $post;
	$success 			= FALSE;
	$base_zip_path 		= __DIR__."/zips";
	$post_path 			= $base_zip_path."/post_".$post->ID;
	$post_zip_path		= $post_path.".zip";
	$url				= $post->guid;
	
	// create zip basepath folder if not exists 
	if (!is_dir($base_zip_path))
	{
		mkdir($base_zip_path, 0755, true);
	}
	
	// 1. we download and zip all stuff for the post
	$success = download_html_and_sources($url,$post_path);
	zip_dir($post_path,$post_zip_path);

	// 2. we also downlaod the categories of the post
	$cats = wp_get_post_categories($post->ID);
	foreach($cats as $c){
		$cat 				= get_category( $c );
		$id 				= $cat->term_id;
		$cat_path 			= $base_zip_path."/category_".$id;
		$url				= get_category_link($id);
		$success 			= download_html_and_sources($url,$cat_path);
		zip_dir($cat_path,$cat_path.".zip");
	}
	
	return $success;
}


function download_html_and_sources($url,$folder)
{
	global $post;

	// create folder for post if not exists
	if (!is_dir($folder))
	{
		mkdir($folder, 0755, true);
	}
	
	
	$zipfilename 		= "post_".$post->guid.".zip";
	$htmlFilepath 		= $folder."/index.html";
	$html 				= download_from_url($url); 
	$url_to_replace     = array();
		
	libxml_use_internal_errors(true);
	$dom = new DOMDocument();
	$dom->encoding = 'utf-8';
	$dom->loadHTML( utf8_decode( $html ) ); // important!
	
	libxml_clear_errors();
	
	foreach ($dom->getElementsByTagName('a') as $item)
	{
		$url = replace_dom_if_real_url($item,'href');
		if ($url)
			array_push($url_to_replace,$url);
	}
	
	foreach ($dom->getElementsByTagName('link') as $item)
	{
		$url = replace_dom_if_real_url($item,'href');
		if ($url)
			array_push($url_to_replace,$url);
	}
	
	foreach ($dom->getElementsByTagName('img') as $item)
	{
		$url = replace_dom_if_real_url($item,'src');
		if ($url)
			array_push($url_to_replace,$url);
	}
	
	foreach ($dom->getElementsByTagName('script') as $item)
	{
		$url = replace_dom_if_real_url($item,'src');
		if ($url)
			array_push($url_to_replace,$url);
	}
	
	$html = $dom->saveHTML();
	write_data_to_file($html,$htmlFilepath);
		
	foreach($url_to_replace as $anURL)
	{
		$filename  = md5($anURL);
		$urlData = download_from_url($anURL);
		write_data_to_file($urlData,$folder."/".$filename);
	}

	return $success;
}

function replace_dom_if_real_url($item,$attr)
{
	$getURL = $item->getAttribute($attr);
	$urlparts = parse_url($getURL);
	
	if (substr($urlparts['scheme'],0,4) == 'http')
	{
	   	 $item->setAttribute($attr, md5($getURL));
		 return $getURL;
	}
	
	return NULL;
}



/* gets the data from a URL */
function download_from_url($url)
{

  	$url = url_encode($url);
  	$parameters = array();
  	foreach ($_GET as $key => $value)
   	{
		$parameters[] = $key.'='.$value;
	}

	$url = $url."?".implode('&', $parameters);  	
	
  	$ch = curl_init();
  	$timeout = 5;
  	curl_setopt($ch,CURLOPT_URL,$url);
  	curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
  	curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,$timeout);
  	$data = curl_exec($ch);
  	curl_close($ch);

  	return $data;
}

function write_data_to_file($data,$file)
{
	if (strlen($file) > 0)
	{
		$fh = fopen($file, 'w') or die("can't open file");
		
		if (file_exists($file))
		{
			$fh = fopen($file, 'r+b') or die("can't open file");
		}
		fwrite($fh, $data);
		
		fclose($fh);
	}
}



function rmdir_r($dir)
{
	if (is_dir($dir)) 
  	{
    	$files = scandir($dir);
    	foreach ($files as $file)
    	{
			if ($file != "." && $file != "..")
			{
				rmdir_r("$dir/$file");
			}
		}
    	rmdir($dir);
  	}
  	else if (file_exists($dir))
	{
		unlink($dir);
	}
}

function url_encode($url)
{
	$chars = array(" " => "%20",
					"!" =>	"%21",
					"$"	 => "%24",
					"("	 => "%28",
					")"	 => "%29",
					"*" =>	"%2A",
					);
					
	$url = str_replace(array_keys($chars), array_values($chars), $url);

	
	return $url;			
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

/** 
   * Add files and sub-directories in a folder to zip file. 
   * @param string $folder 
   * @param ZipArchive $zipFile 
   * @param int $exclusiveLength Number of text to be exclusived from the file path. 
   */ 
function folder_to_zip($folder, &$zipFile, $exclusiveLength)
{ 
    $handle = opendir($folder); 
    while (false !== $f = readdir($handle))
	{ 
      	if ($f != '.' && $f != '..')
		{ 
        	$filePath = "$folder/$f"; 
        	// Remove prefix from file path before add to zip. 
        	$localPath = substr($filePath, $exclusiveLength); 
        	if (is_file($filePath))
 			{ 
          		$zipFile->addFile($filePath, $localPath); 
        	}
 			elseif (is_dir($filePath))
			{ 
          		// Add sub-directory. 
          		$zipFile->addEmptyDir($localPath); 
          		folder_to_zip($filePath, $zipFile, $exclusiveLength); 
        	} 
      	} 
	} 
	closedir($handle); 
} 

  /** 
   * Zip a folder (include itself). 
   * Usage: 
   *   HZip::zipDir('/path/to/sourceDir', '/path/to/out.zip'); 
   * 
   * @param string $sourcePath Path of directory to be zip. 
   * @param string $outZipPath Path of output zip file. 
   */ 
function zip_dir($sourcePath, $outZipPath) 
{ 
    $pathInfo = pathInfo($sourcePath); 
    $z = new ZipArchive(); 
    $z->open($outZipPath, ZIPARCHIVE::CREATE); 
	
	
	$dir = opendir($sourcePath);
    while(false !== ( $file = readdir($dir)) )
 	{
    	if (( $file != '.' ) && ( $file != '..' ))
 		{
			$z->addFile($sourcePath."/".$file, $file );
        }
	}
	closedir($dir);
    $z->close(); 
}

?>