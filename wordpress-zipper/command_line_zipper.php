<?php


$start = new WordpressTWZipper($argv);
/**
* 
*/
class WordpressTWZipper 
{

	private $mysqli;
	private $zip_folder_name;

	function __construct($arguments) {
		if (count($arguments) < 6)
		{
			// Bail out - 
			printf("Not enough arguments! We need: \n
				1. database name\n
				2. database user\n
				3. database pw\n
				4. database host\n
				5. zip foldername\n\n
				6. (optional) Specific post id to zip");
	   	 	exit();
		}

		$db_name 				= $arguments[1];
		$db_user 				= $arguments[2];
		$db_pw 					= $arguments[3];
		$db_host 				= $arguments[4];
		$this->zip_folder_name  = $arguments[5];

		// optional 
		$post_id  				= $arguments[6];
		
		if ($db_host == 'localhost')
		{
			$db_host = '127.0.0.1';
		}

		// get all post and pages that are published and zip them
		// if you got a no such file warning when running this script
		// have a look here http://stackoverflow.com/questions/4219970/warning-mysql-connect-2002-no-such-file-or-directory-trying-to-connect-vi
		$this->mysqli = new mysqli($db_host,$db_user,$db_pw,$db_name);
		/* check connection */
		if (mysqli_connect_errno()) {
	  	  	printf("Connect failed: %s\n", mysqli_connect_error());
	   	 	exit();
		}

		$sql = "SELECT * FROM wp_posts WHERE post_status LIKE 'publish' AND post_type IN ('page','post')";
		if ($post_id > 0)
		{
			$sql .= ' AND ID = '.(int)$post_id;
		}
		
		if ($result = mysqli_query($this->mysqli, $sql)) {

		    /* fetch associative array */
		    while ($obj = mysqli_fetch_object($result)) {
		        $this->tw_zip_content($obj);
		    }

		    /* free result set */
		    mysqli_free_result($result);
		}
	
		$this->mysqli->close();	
		
	}

	

	private function tw_zip_content($post) {
		$success 			= FALSE;
		$dir				= dirname(__FILE__);
		$base_zip_path 		= $dir.$this->zip_folder_name;
		$post_path 			= $base_zip_path."/post_".$post->ID;
		$post_zip_path		= $post_path.".zip";
		$url				= $post->guid;
		
		// create zip basepath folder if not exists 
		if (!is_dir($base_zip_path))
		{
			mkdir($base_zip_path, 0755, true);
		}

		// 1. we download and zip all stuff for the post
		$success = $this->download_html_and_sources($post,$url,$post_path);
		$this->zip_dir($post_path,$post_zip_path);

		//2. we also downlaod the categories of the post
		$cats = $this->tw_get_post_categories($post->ID);
		foreach($cats as $cat){
			$id 				= $cat->term_id;
			$cat_path 			= $base_zip_path."/category_".$id;
			$url				= $cat->url;
			$success 			= $this->download_html_and_sources($post,$url,$cat_path);
			$this->zip_dir($cat_path,$cat_path.".zip");
		}
	
		return $success;
	}

	private function download_html_and_sources($post,$url, $folder, $filename = "index.html", $isHTML = true) {
		echo 'download from URL: '.$url.'\n<br>';
		
		// create folder for post if not exists
		if (!is_dir($folder))
		{
			if (!mkdir($folder, 0755, true))
			{
		        echo "could not create local folder for zip files at ".$folder;
			  	die();	
			}
		}
		

		$sourceFilepath 		= $folder."/".$filename;
		$source 				= $this->download_from_url($url); 

		if (empty($source))
		{

			return;
		}
		
		$urls_to_replace     = array();	
		libxml_use_internal_errors(true);
		//$dom = new DOMDocument();
		// $dom->encoding = 'UTF-8';
		// $dom->loadHTML(  $source  ); 
		$source = mb_convert_encoding($source, 'utf-8', mb_detect_encoding($source));
		// if you have not escaped entities use
		$source = mb_convert_encoding($source, 'html-entities', 'utf-8'); 
		$dom = new DOMDocument();
		$dom->loadHTML($source);
		
		libxml_clear_errors();
		

		foreach ($dom->getElementsByTagName('link') as $item)
		{		
			////////////////////////////////////////////////////////////////////////////////////
			//
			// 		we need to parse the stylesheet files and need to download URLs there too
			//
			$rel = $item->getAttribute('rel');
			$styleURL = $item->getAttribute('href'); 
			if ($rel == 'stylesheet')
			{
				$url = $this->replace_dom_if_real_url($item,'href');
				$this->download_html_and_sources($post,$url,$folder,md5($styleURL), false); 
			}
			// special CSS handling end
			///////////////////////////////////////////////////////////////////////////////////
			else
			{
				$url = $this->replace_dom_if_real_url($item,'href');
				if ($url)
				{
					array_push($urls_to_replace,$url);
				}
			}
		}
		
		
		foreach ($dom->getElementsByTagName('img') as $item)
		{
			$url = $this->replace_dom_if_real_url($item,'src');
			if ($url)
			{
				array_push($urls_to_replace,$url);
			}
		}
		
		foreach ($dom->getElementsByTagName('script') as $item)
		{
			$url = $this->replace_dom_if_real_url($item,'src');
			if ($url)
			{
				array_push($urls_to_replace,$url);
			}
		}
		

		
		//
		//
		//   set the dom back to our html string
		if ($isHTML == true)
		{
			$source = $dom->saveHTML();
		}
		

		//
		// 
		// CSS Stylesheet special parsing now
		// download all URLs there too
		$rootURL = $url;
		$source = $this->parse_CSS_replace_full_URL($source,$folder,$rootURL);
		
		//
		//  write the HTML file
		$this->write_data_to_file($source,$sourceFilepath);
			
		//
		//
		//   Now download all the URLs and replace it in the dom
		$success = false;
		foreach($urls_to_replace as $anURL)
		{
			 $filename  = md5($anURL);
			 $urlData = $this->download_from_url($anURL);
			 $success = $this->write_data_to_file($urlData,$folder."/".$filename);
		}
		
		return $success;
	}

	private function replace_dom_if_real_url($item,$attr) {
		$getURL = $item->getAttribute($attr);
		$urlparts = parse_url($getURL);
		
		if (array_key_exists('scheme',$urlparts) &&
			substr($urlparts['scheme'],0,4) == 'http')
		{
		   	 $item->setAttribute($attr, md5($getURL));
			 return $getURL;
		}
		
		return NULL;
	}


	private function parse_CSS_replace_full_URL($source,$folder,$rootURL)
	{
		$reg_exUrl1 = "/url\((('|\").*?('|\"))\)/";
		preg_match_all($reg_exUrl1, $source, $urlnodes);
		foreach ($urlnodes[1] as $urlnode)
		{
			$urlnode = str_replace("'", "", $urlnode);
			$urlnode = str_replace("\"", "", $urlnode);
			
			if (strpos($urlnode, "http") === false) {
				$path_parts = pathinfo($rootURL);
				$baseURL = $path_parts['dirname'];
    			$url = $baseURL . '/'. $urlnode;
			}
			else
			{
				$url = $urlnode;
			}
			$source = str_replace($urlnode, md5($url), $source);
			$urlData = $this->download_from_url($url);
			$this->write_data_to_file($urlData,$folder."/".md5($url));	
		}
		
		return $source;
	}


	/* gets the data from a URL */
	private function download_from_url($url)
	{
		$url = $this->url_encode($url);
		$parameters = array();
	  	foreach ($_GET as $key => $value)
	   	{
			$parameters[] = $key.'='.$value;
		}

		$url = $url."?".implode('&', $parameters);  	
		
	  	$ch = curl_init();
	  	$timeout = 10;
		curl_setopt($ch, CURLOPT_HEADER, 0);
	  	curl_setopt($ch,CURLOPT_URL,$url);
	  	curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
	  	curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,$timeout);
		curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (X11; U; Linux i686; en-US; rv:1.9.1.3) Gecko/20090909 Fedora/3.5.3-1.fc11 Firefox/3.5.3");
	  	$data = curl_exec($ch);
	  	curl_close($ch);
		
	  	return $data;
	}

	private function write_data_to_file($data,$file)
	{
		$success = false;
		if (strlen($file) > 0)
		{
			$fh = fopen($file, 'w') or die("can't open file");
			
			if (file_exists($file))
			{
				$fh = fopen($file, 'r+b') or die("can't open file");
			}
			fwrite($fh, $data);
			fclose($fh);
			$success = true;
		}
		
		return $success;
	}



	private function rmdir_r($dir)
	{
		if (is_dir($dir)) 
	  	{
	    	$files = scandir($dir);
	    	foreach ($files as $file)
	    	{
				if ($file != "." && $file != "..")
				{
					$this->rmdir_r("$dir/$file");
				}
			}
	    	rmdir($dir);
	  	}
	  	else if (file_exists($dir))
		{
			unlink($dir);
		}
	}

	private function url_encode($url)
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


	/** 
	   * Add files and sub-directories in a folder to zip file. 
	   * @param string $folder 
	   * @param ZipArchive $zipFile 
	   * @param int $exclusiveLength Number of text to be exclusived from the file path. 
	   */ 
	private function folder_to_zip($folder, &$zipFile, $exclusiveLength)
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
	          		$this->folder_to_zip($filePath, $zipFile, $exclusiveLength); 
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
	private function zip_dir($sourcePath, $outZipPath) 
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

	private function tw_get_post_categories($post_ID)
	{
		$cats = array();
		$sql = "SELECT *
		FROM wp_terms
		INNER JOIN wp_term_taxonomy ON wp_terms.term_id = wp_term_taxonomy.term_id
		INNER JOIN wp_term_relationships wpr ON wpr.term_taxonomy_id = wp_term_taxonomy.term_taxonomy_id
		INNER JOIN wp_posts p ON p.ID = wpr.object_id
		WHERE taxonomy =  'category'
		AND p.post_type =  'post'
		AND p.ID =  $post_ID
		AND p.post_status =  'publish'";
		
		if ($result = mysqli_query($this->mysqli, $sql)) {

		    /* fetch associative array */
		    while ($obj = mysqli_fetch_object($result)) {
				//
				// 1. build the category URL
				$url = preg_replace('/\?.*/', '', $obj->guid);
				$obj->url =  $url.'?c='.$obj->term_id;
				array_push($cats,$obj);
		    }

		    /* free result set */
		    mysqli_free_result($result);
		}

		
		return $cats;
	}
}

?>
