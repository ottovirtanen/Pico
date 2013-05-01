<?php

/**
 * Pico
 *
 * @author Gilbert Pellegrom
 * @link http://pico.dev7studios.com/
 * @license http://opensource.org/licenses/MIT
 * @version 0.4.1
 */
class Pico {

	/**
	 * The constructor carries out all the processing in Pico.
	 * Does URL routing, Markdown processing and Twig processing.
	 */
	function __construct()
	{
		// Get request url and script url
		$url = '';
		$request_url = (isset($_SERVER['REQUEST_URI'])) ? $_SERVER['REQUEST_URI'] : '';
		$script_url  = (isset($_SERVER['PHP_SELF'])) ? $_SERVER['PHP_SELF'] : '';

		// Get our url path and trim the / of the left and the right
		if($request_url != $script_url) $url = trim(preg_replace('/'. str_replace('/', '\/', str_replace('index.php', '', $script_url)) .'/', '', $request_url, 1), '/');

		// Get the file path
		if($url) $file = CONTENT_DIR . $url;
		else $file = CONTENT_DIR .'index';

		// Load the file
		if(is_dir($file)) $file = CONTENT_DIR . $url .'/index'. CONTENT_EXT;
		else $file .= CONTENT_EXT;

		if(file_exists($file)) $content = file_get_contents($file);
		else {
			$content = file_get_contents(CONTENT_DIR .'404'. CONTENT_EXT);
			header($_SERVER['SERVER_PROTOCOL'].' 404 Not Found');
		}

		$meta = $this->read_file_meta($content);
		$content = preg_replace('#/\*.+?\*/#s', '', $content); // Remove comments and meta
		$content = $this->parse_content($content);

		// Load the settings
		$settings = $this->get_config();
		$env = array('autoescape' => false);
		if($settings['enable_cache']) $env['cache'] = CACHE_DIR;
		
		// Get all the pages
		$pages = $this->get_pages($settings['base_url']);

		// Load the theme
		Twig_Autoloader::register();
		$loader = new Twig_Loader_Filesystem(THEMES_DIR . $settings['theme']);
		$twig = new Twig_Environment($loader, $env);
		echo $twig->render('index.html', array(
			'config' => $settings,
			'base_dir' => rtrim(ROOT_DIR, '/'),
			'base_url' => $settings['base_url'],
			'theme_dir' => THEMES_DIR . $settings['theme'],
			'theme_url' => $settings['base_url'] .'/'. basename(THEMES_DIR) .'/'. $settings['theme'],
			'site_title' => $settings['site_title'],
			'meta' => $meta,
			'content' => $content,
			'pages' => $pages
		));
	}

	/**
	 * Parses the content using Markdown
	 *
	 * @param string $content the raw txt content
	 * @return string $content the Markdown formatted content
	 */
	function parse_content($content)
	{
		$content = str_replace('%base_url%', $this->base_url(), $content);
		$content = Markdown($content);

		return $content;
	}

	/**
	 * Parses the file meta from the txt file header
	 *
	 * @param string $content the raw txt content
	 * @return array $headers an array of meta values
	 */
	function read_file_meta($content)
	{
		$headers = array(
			'title'       => 'Title',
			'description' => 'Description',
			'robots'      => 'Robots'
		);

	 	foreach ($headers as $field => $regex){
			if (preg_match('/^[ \t\/*#@]*' . preg_quote($regex, '/') . ':(.*)$/mi', $content, $match) && $match[1]){
				$headers[ $field ] = trim(preg_replace("/\s*(?:\*\/|\?>).*/", '', $match[1]));
			} else {
				$headers[ $field ] = '';
			}
		}

		return $headers;
	}

	/**
	 * Loads the config
	 *
	 * @return array $config an array of config values
	 */
	function get_config()
	{
		if(!file_exists(ROOT_DIR .'config.php')) return array();
		
		global $config;
		require_once(ROOT_DIR .'config.php');

		$defaults = array(
			'site_title' => 'Pico',
			'base_url' => $this->base_url(),
			'theme' => 'default',
			'enable_cache' => false
		);

		if(is_array($config)) $config = array_merge($defaults, $config);
		else $config = $defaults;

		return $config;
	}
	
	/**
	 * Get a list of pages
	 *
	 * @param string $base_url the base URL of the site
	 * @return array $pages an array of pages
	 */
	function get_pages($base_url)
	{
		$pages = $this->glob_recursive(CONTENT_DIR .'*'. CONTENT_EXT);
		foreach($pages as $key=>$page){
			// Skip 404
			if(basename($page) == '404'. CONTENT_EXT){
				unset($pages[$key]);
				continue;
			}
			
			// Get title and format $page
			$page_content = file_get_contents($page);
			$page_meta = $this->read_file_meta($page_content);
			$url = str_replace(CONTENT_DIR, $base_url .'/', $page);
			$url = str_replace('index'. CONTENT_EXT, '', $url);
			$url = str_replace(CONTENT_EXT, '', $url);
			$pages[$key] = array(
				'title' => $page_meta['title'],
				'url' => $url
			);
		}
		
		return $pages;
	}

	/**
	 * Helper function to work out the base URL
	 *
	 * @return string the base url
	 */
	function base_url()
	{
		global $config;
		if(isset($config['base_url']) && $config['base_url']) return $config['base_url'];

		$url = '';
		$request_url = (isset($_SERVER['REQUEST_URI'])) ? $_SERVER['REQUEST_URI'] : '';
		$script_url  = (isset($_SERVER['PHP_SELF'])) ? $_SERVER['PHP_SELF'] : '';
		if($request_url != $script_url) $url = trim(preg_replace('/'. str_replace('/', '\/', str_replace('index.php', '', $script_url)) .'/', '', $request_url, 1), '/');

		$protocol = $this->get_protocol();
		return rtrim(str_replace($url, '', $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']), '/');
	}

	/**
	 * Tries to guess the server protocol. Used in base_url()
	 *
	 * @return string the current protocol
	 */
	function get_protocol()
	{
		preg_match("|^HTTP[S]?|is",$_SERVER['SERVER_PROTOCOL'],$m);
		return strtolower($m[0]);
	}
	     
	/**
	 * Helper function to make glob recursive
	 *
	 * @param string $pattern glob pattern
	 * @param int $flags glob flags
	 * @return array the matched files/directories
	 */ 
	function glob_recursive($pattern, $flags = 0)
	{
		$files = glob($pattern, $flags);
		foreach(glob(dirname($pattern).'/*', GLOB_ONLYDIR|GLOB_NOSORT) as $dir){
			$files = array_merge($files, $this->glob_recursive($dir.'/'.basename($pattern), $flags));
		}
		return $files;
	}

}

?>