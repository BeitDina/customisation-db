<?php
/**
*
* @package Titania
* @copyright (c) 2008 phpBB Group
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License, version 2
*
*/

/**
* @ignore
*/
if (!defined('IN_TITANIA'))
{
	exit;
}

/**
 * titania class and functions for use within titania pages and apps.
 */
class titania
{
	/**
	 * Current viewing page location
	 *
	 * @var string
	 */
	public static $page;

	/**
	 * Titania configuration member
	 *
	 * @var titania_config
	 */
	public static $config;

	/**
	 * Instance of titania_cache class
	 *
	 * @var titania_cache
	 */
	public static $cache;

	/**
	* Hooks instance
	*
	* @var phpbb_hook
	*/
	public static $hook;

	/**
	 * Request time (unix timestamp)
	 *
	 * @var int
	 */
	public static $time;

	/**
	* Current User's Access level
	*
	* @var int $access_level Check TITANIA_ACCESS_ constants
	*/
	public static $access_level = 2;

	/**
	 * Absolute Titania, Board, Images, Style, Template, and Theme Path
	 *
	 * @var string
	 */
	public static $absolute_path;
	public static $absolute_board;
	public static $images_path;
	public static $style_path;
	public static $template_path;
	public static $theme_path;

	/**
	* Hold our main contribution object for the currently loaded contribution
	*
	* @var titania_contribution
	*/
	public static $contrib;

	/**
	* Hold our main author object for the currently loaded author
	*
	* @var titania_author
	*/
	public static $author;

	/**
	 * Initialise titania:
	 *	Session management, Cache, Language ...
	 *
	 * @return void
	 */
	public static function initialise()
	{
		global $starttime;

		self::$page = htmlspecialchars(phpbb::$user->page['script_path'] . phpbb::$user->page['page_name']);
		self::$time = (int) $starttime;
		self::$cache = phpbb::$container->get('phpbb.titania.cache');

		// Set the absolute titania/board path
		self::$absolute_path = generate_board_url(true) . '/' . self::$config->titania_script_path;
		self::$absolute_board = generate_board_url(true) . '/' . self::$config->phpbb_script_path;

		// Set the style path, template path, and template name
		if (!defined('IN_TITANIA_INSTALL') && !defined('USE_PHPBB_TEMPLATE'))
		{
			self::$images_path = self::$absolute_path . 'images/';
			self::$style_path = self::$absolute_path . 'styles/' . self::$config->style . '/';
			self::$template_path = self::$style_path . 'template';
			self::$theme_path = (self::$config->theme) ?  self::$absolute_path . 'styles/' . self::$config->theme . '/theme' : self::$style_path . 'theme';
		}

		// Setup the Access Level
		self::$access_level = TITANIA_ACCESS_PUBLIC;

		// The user might be in a group with team access even if it's not his default group.
		$group_ids = implode(', ', self::$config->team_groups);

		$sql = 'SELECT group_id, user_id, user_pending FROM ' . USER_GROUP_TABLE . '
				WHERE user_id = ' . phpbb::$user->data['user_id'] . '
				AND user_pending = 0
				AND group_id IN (' . $group_ids . ')';
		$result = phpbb::$db->sql_query_limit($sql, 1);

		if ($group_id = phpbb::$db->sql_fetchfield('group_id'))
		{
			self::$access_level = TITANIA_ACCESS_TEAMS;
		}
		phpbb::$db->sql_freeresult($result);

		// Add common titania language file
		self::add_lang('common');

		// Load the contrib types
		self::_include('types/base');
		titania_types::load_types();

		// Initialise the URL class
		titania_url::$root_url = self::$absolute_path;
		titania_url::decode_url(self::$config->titania_script_path);

		// Load hooks
		self::load_hooks();
	}

	/**
	 * Reads a configuration file with an assoc. config array
	 */
	public static function read_config_file()
	{
		try
		{
			self::$config = titania_get_config(TITANIA_ROOT, PHP_EXT);
		}
		catch(\Exception $e)
		{
			trigger_error($e->getMessage());
		}
	}

	/**
	 * Autoload any objects, tools, or overlords.
	 * This autoload function does not handle core classes right now however it will once the naming of them is the same.
	 *
	 * @param $class_name
	 *
	 */
	public static function autoload($class_name)
	{
		// Remove titania/overlord from the class name
		$file_name = str_replace(array('titania_', '_overlord'), '', $class_name);

		// Overlords always have _overlord in and the file name can conflict with objects
		if (strpos($class_name, '_overlord') !== false)
		{
			if (file_exists(TITANIA_ROOT . 'includes/overlords/' . $file_name . '.' . PHP_EXT))
			{
				include(TITANIA_ROOT . 'includes/overlords/' . $file_name . '.' . PHP_EXT);
				return;
			}
		}

		$directories = array(
			'objects',
			'tools',
			'core',
		);

		foreach ($directories as $dir)
		{
			if (file_exists(TITANIA_ROOT . 'includes/' . $dir . '/' . $file_name . '.' . PHP_EXT))
			{
				include(TITANIA_ROOT . 'includes/' . $dir . '/' . $file_name . '.' . PHP_EXT);
				return;
			}
		}

		// No error if file cant be found!
	}

	/**
	 * Add a Titania language file
	 *
	 * @param mixed $lang_set
	 * @param bool $use_db
	 * @param bool $use_help
	 */
	public static function add_lang($lang_set, $use_db = false, $use_help = false)
	{
		static $included = array();

		// Don't include the language file a bunch of times
		if (in_array($lang_set, $included))
		{
			return;
		}

		// Store so we can reset it back
		$old_path = phpbb::$user->lang_path;

		// Set the custom language path to our working language directory
		phpbb::$user->set_custom_lang_path(self::$config->language_path);

		phpbb::$user->add_lang($lang_set, $use_db, $use_help);

		// Reset the custom language path to the original directory
		phpbb::$user->set_custom_lang_path($old_path);

		// Store
		$included[] = $lang_set;
	}

	public static function needs_auth()
	{
		if (!phpbb::$user->data['is_registered'])
		{
			login_box(build_url());
		}

		trigger_error('NO_AUTH');
	}

	/**
	 * Titania page_header
	 *
	 * @param string $page_title
	 * @param bool $display_online_list
	 */
	public static function page_header($page_title = '')
	{
		// Titania must be initialized in order for page_header() to function properly.
		// If it's not initialized at this point, it's most likely because phpBB called trigger_error() (which calls page_header()) while being initialized.
		if (!isset(self::$time))
		{
			self::initialise();
		}

		if (!empty(titania::$hook) && titania::$hook->call_hook(array(__CLASS__, __FUNCTION__), $page_title))
		{
			if (titania::$hook->hook_return(array(__CLASS__, __FUNCTION__)))
			{
				return titania::$hook->hook_return_result(array(__CLASS__, __FUNCTION__));
			}
		}

		if (defined('HEADER_INC'))
		{
			return;
		}

		define('HEADER_INC', true);

		// Do the phpBB page header stuff first
		page_header($page_title);

		phpbb::$template->assign_vars(array(
			'PHPBB_ROOT_PATH'			=> self::$absolute_board,
			'TITANIA_ROOT_PATH'			=> self::$absolute_path,

			'U_BASE_URL'				=> self::$absolute_path,
			'U_SITE_ROOT'				=> self::$absolute_board,
			'U_MANAGE'					=> (sizeof(titania_types::find_authed()) || phpbb::$auth->acl_get('u_titania_mod_contrib_mod') || phpbb::$auth->acl_get('u_titania_mod_post_mod')) ? titania_url::build_url('manage') : '',
			'U_ALL_CONTRIBUTIONS'		=> titania_url::build_url('contributions/all'),
			'U_ALL_SUPPORT'				=> (titania::$config->support_in_titania) ? titania_url::build_url('support/all') : false,
			'U_MY_CONTRIBUTIONS'		=> (phpbb::$user->data['is_registered'] && !phpbb::$user->data['is_bot']) ? titania_url::build_url('author/' . htmlspecialchars_decode(phpbb::$user->data['username_clean']) . '/contributions/') : '',
			'U_SEARCH'					=> titania_url::build_url('search'),
			'U_FIND_CONTRIBUTION'		=> titania_url::build_url('find-contribution'),
			'U_FAQ'						=> titania_url::build_url('faq'),
			'U_TITANIA_SEARCH_SELF'		=> (titania::$config->support_in_titania && !phpbb::$user->data['is_bot'] && phpbb::$user->data['is_registered']) ? titania_url::build_url('search', array('u' => phpbb::$user->data['user_id'], 'type' => TITANIA_SUPPORT)) : '',

			'S_DISPLAY_SEARCH'			=> true,

			'T_TITANIA_TEMPLATE_PATH'	=> self::$template_path,
			'T_TITANIA_THEME_PATH'		=> self::$theme_path,
			'T_TITANIA_IMAGES_PATH'		=> self::$images_path,
			'T_TITANIA_STYLESHEET'		=> self::$absolute_path . 'styles/' . rawurlencode(self::$config->style) . '/theme/stylesheet.css',
		));

		// Header hook
		self::$hook->call_hook('titania_page_header', $page_title);
	}

	/**
	 * Titania page_footer
	 *
	 * @param cron $run_cron
	 * @param bool|string $template_body For those lazy like me, send the template body name you want to load (or leave default to ignore and assign it yourself)
	 */
	public static function page_footer($run_cron = true, $template_body = false)
	{
		// Because I am lazy most of the time...
		if ($template_body !== false)
		{
			phpbb::$template->set_filenames(array(
				'body' => $template_body,
			));
		}

		if (!empty(titania::$hook) && titania::$hook->call_hook(array(__CLASS__, __FUNCTION__), $run_cron))
		{
			if (titania::$hook->hook_return(array(__CLASS__, __FUNCTION__)))
			{
				return titania::$hook->hook_return_result(array(__CLASS__, __FUNCTION__));
			}
		}

		// Output page creation time (can not move phpBB side because of a hack we do in here)
		if (defined('DEBUG'))
		{
			global $starttime;
			$mtime = explode(' ', microtime());
			$totaltime = $mtime[0] + $mtime[1] - $starttime;

			if (phpbb::$request->variable('explain', 0) && phpbb::$auth->acl_get('a_') && defined('DEBUG_EXTRA') && method_exists(phpbb::$db, 'sql_report'))
			{
				// gotta do a rather nasty hack here, but it works and the page is killed after the display output, so no harm to anything else
				$GLOBALS['phpbb_root_path'] = self::$absolute_board;
				phpbb::$db->sql_report('display');
			}

			$debug_output = sprintf('Time : %.3fs | ' . phpbb::$db->sql_num_queries() . ' Queries | GZIP : ' . ((phpbb::$config['gzip_compress'] && @extension_loaded('zlib')) ? 'On' : 'Off') . ((phpbb::$user->load) ? ' | Load : ' . phpbb::$user->load : ''), $totaltime);

			if (phpbb::$auth->acl_get('a_') && defined('DEBUG_EXTRA'))
			{
				if (function_exists('memory_get_usage'))
				{
					if ($memory_usage = memory_get_usage())
					{
						global $base_memory_usage;
						$memory_usage -= $base_memory_usage;
						$memory_usage = get_formatted_filesize($memory_usage);

						$debug_output .= ' | Memory Usage: ' . $memory_usage;
					}
				}

				$debug_output .= ' | <a href="' . titania_url::append_url(titania_url::$current_page, array_merge(titania_url::$params, array('explain' => 1))) . '">Explain</a>';
			}
		}

		phpbb::$template->assign_vars(array(
			'DEBUG_OUTPUT'			=> (defined('DEBUG')) ? $debug_output : '',
			'U_PURGE_CACHE'			=> (phpbb::$auth->acl_get('a_')) ? titania_url::append_url(titania_url::$current_page, array_merge(titania_url::$params, array('cache' => 'purge'))) : '',
		));

		// Footer hook
		self::$hook->call_hook('titania_page_footer', $run_cron, $template_body);

		// Call the phpBB footer function
		phpbb::page_footer($run_cron);
	}

	/**
	 * Show the errorbox or successbox
	 *
	 * @param string $l_title message title - custom or user->lang defined
	 * @param mixed $l_message message string or array of strings
	 * @param int $error_type TITANIA_SUCCESS or TITANIA_ERROR constant
	 * @param int $status_code an HTTP status code
	 */
	public static function error_box($l_title, $l_message, $error_type = TITANIA_SUCCESS, $status_code = NULL)
	{
		if ($status_code)
		{
			self::set_header_status($status_code);
		}

		$block = ($error_type == TITANIA_ERROR) ? 'errorbox' : 'successbox';

		if ($l_title)
		{
			$title = (isset(phpbb::$user->lang[$l_title])) ? phpbb::$user->lang[$l_title] : $l_title;

			phpbb::$template->assign_var(strtoupper($block) . '_TITLE', $title);
		}

		if (!is_array($l_message))
		{
			$l_message = array($l_message);
		}

		foreach ($l_message as $message)
		{
			if (!$message)
			{
				continue;
			}

			phpbb::$template->assign_block_vars($block, array(
				'MESSAGE'	=> (isset(phpbb::$user->lang[$message])) ? phpbb::$user->lang[$message] : $message,
			));
		}

		// Setup the error box to hide.
		phpbb::$template->assign_vars(array(
			'S_HIDE_ERROR_BOX'		=> true,
			'ERRORBOX_CLASS'		=> $block,
		));
	}

	/**
	* Build Confirm box
	* @param boolean $check True for checking if confirmed (without any additional parameters) and false for displaying the confirm box
	* @param string $title Title/Message used for confirm box.
	*		message text is _CONFIRM appended to title.
	*		If title cannot be found in user->lang a default one is displayed
	*		If title_CONFIRM cannot be found in user->lang the text given is used.
	* @param string $u_action Form action
	* @param string $post Hidden POST variables
	* @param string $html_body Template used for confirm box
	*/
	public static function confirm_box($check, $title = '', $u_action = '', $post = array(), $html_body = 'common/confirm_body.html')
	{
		$hidden = build_hidden_fields($post);

		if (phpbb::$request->is_set_post('cancel'))
		{
			return false;
		}

		$confirm = false;
		if (phpbb::$request->is_set_post('confirm'))
		{
			$confirm = true;
		}

		if ($check && $confirm)
		{
			$user_id = phpbb::$request->variable('confirm_uid', 0);
			$session_id = phpbb::$request->variable('sess', '');
			$confirm_key = phpbb::$request->variable('confirm_key', '');

			if ($user_id != phpbb::$user->data['user_id'] || $session_id != phpbb::$user->session_id || !$confirm_key || !phpbb::$user->data['user_last_confirm_key'] || $confirm_key != phpbb::$user->data['user_last_confirm_key'])
			{
				return false;
			}

			// Reset user_last_confirm_key
			$sql = 'UPDATE ' . USERS_TABLE . " SET user_last_confirm_key = ''
				WHERE user_id = " . phpbb::$user->data['user_id'];
			phpbb::$db->sql_query($sql);

			return true;
		}
		else if ($check)
		{
			return false;
		}

		// generate activation key
		$confirm_key = gen_rand_string(10);

		$s_hidden_fields = build_hidden_fields(array(
			'confirm_uid'	=> phpbb::$user->data['user_id'],
			'confirm_key'	=> $confirm_key,
			'sess'			=> phpbb::$user->session_id,
			'sid'			=> phpbb::$user->session_id,
		));

		self::page_header((!isset(phpbb::$user->lang[$title])) ? phpbb::$user->lang['CONFIRM'] : phpbb::$user->lang[$title]);

		// If activation key already exist, we better do not re-use the key (something very strange is going on...)
		if (phpbb::$request->variable('confirm_key', ''))
		{
			// This should not occur, therefore we cancel the operation to safe the user
			return false;
		}

		// re-add sid / transform & to &amp; for user->page (user->page is always using &)
		// @todo look into the urls we are generating here
		if ($u_action)
		{
			$u_action = titania_url::build_url($u_action);
		}
		else
		{
			$u_action = titania_url::$current_page_url;
		}

		phpbb::$template->assign_vars(array(
			'MESSAGE_TITLE'		=> (!isset(phpbb::$user->lang[$title])) ? phpbb::$user->lang['CONFIRM'] : phpbb::$user->lang[$title],
			'MESSAGE_TEXT'		=> (!isset(phpbb::$user->lang[$title . '_CONFIRM'])) ? $title : phpbb::$user->lang[$title . '_CONFIRM'],

			'YES_VALUE'			=> phpbb::$user->lang['YES'],
			'S_CONFIRM_ACTION'	=> $u_action,
			'S_HIDDEN_FIELDS'	=> $hidden . $s_hidden_fields,
		));

		$sql = 'UPDATE ' . USERS_TABLE . " SET user_last_confirm_key = '" . phpbb::$db->sql_escape($confirm_key) . "'
			WHERE user_id = " . phpbb::$user->data['user_id'];
		phpbb::$db->sql_query($sql);

		self::page_footer(true, $html_body);
	}

	/**
	 * Set proper page header status
	 *
	 * @param int $status_code
	 */
	public static function set_header_status($status_code = NULL)
	{
		// Send the appropriate HTTP status header
		static $status = array(
			200 => 'OK',
			201 => 'Created',
			202 => 'Accepted',
			204 => 'No Content',
			205 => 'Reset Content',
			300 => 'Multiple Choices',
			301 => 'Moved Permanently',
			302 => 'Found', // Moved Temporarily
			303 => 'See Other',
			304 => 'Not Modified',
			307 => 'Temporary Redirect',
			400 => 'Bad Request',
			401 => 'Unauthorized',
			403 => 'Forbidden',
			404 => 'Not Found',
			406 => 'Not Acceptable',
			409 => 'Conflict',
			410 => 'Gone',
			500 => 'Internal Server Error',
			501 => 'Not Implemented',
			502 => 'Bad Gateway',
			503 => 'Service Unavailable',
		);

		if ($status_code && isset($status[$status_code]))
		{
			$header = $status_code . ' ' . $status[$status_code];
			header('HTTP/1.1 ' . $header, false, $status_code);
			header('Status: ' . $header, false, $status_code);
		}
	}

	/**
	* Load the hooks
	* Using something very similar to the phpBB hook system
	*/
	public static function load_hooks()
	{
		if (self::$hook)
		{
			return;
		}

		// Add own hook handler
		self::$hook = new titania_hook();

		// Now search for hooks...
		$dh = @opendir(TITANIA_ROOT . 'includes/hooks/');
		if ($dh)
		{
			while (($file = readdir($dh)) !== false)
			{
				if (strpos($file, 'hook_') === 0 && substr($file, -(strlen(PHP_EXT) + 1)) === '.' . PHP_EXT)
				{
					include(TITANIA_ROOT . 'includes/hooks/' . $file);
				}
			}
			closedir($dh);
		}
	}

	/**
	* Include a Titania includes file
	*
	* @param string $file The name of the file
	* @param string|bool $function_check Bool false to ignore; string function name to check if the function exists (and not load the file if it does)
	* @param string|bool $class_check Bool false to ignore; string class name to check if the class exists (and not load the file if it does)
	*/
	public static function _include($file, $function_check = false, $class_check = false)
	{
		if ($function_check !== false)
		{
			if (function_exists($function_check))
			{
				return;
			}
		}

		if ($class_check !== false)
		{
			if (class_exists($class_check))
			{
				return;
			}
		}

		include(TITANIA_ROOT . 'includes/' . $file . '.' . PHP_EXT);
	}

	/**
	* Creates a log file with information that can help with identifying a problem
	*
	* @param int $log_type The log type to record. To be expanded for different types as needed
	* @param string $text The description to add with the log entry
	*/
	public static function log($log_type, $message = false)
	{
		switch ($log_type)
		{
			case TITANIA_ERROR:
				//Append the current server date/time, user information, and URL
				$text = date('d-m-Y @ H:i:s') . ' USER: ' . phpbb::$user->data['username'] . ' - ' . phpbb::$user->data['user_id'] . "\r\n";
				$text .= titania_url::$current_page_url;

				// Append the sent message if any
				$text .= (($message !== false) ? "\r\n" . $message : '');

				$server_keys = phpbb::$request->variable_names('_SERVER');
				$request_keys = phpbb::$request->variable_names('_REQUEST');

				//Let's gather the $_SERVER array contents
				if ($server_keys)
				{
					$text .= "\r\n-------------------------------------------------\r\n_SERVER: ";
					foreach ($server_keys as $key => $var_name)
					{
						$text .= sprintf('%1s = %2s; ', $key, phpbb::$request->server($var_name));
					}
					$text = rtrim($text, '; ');
				}

				//Let's gather the $_REQUEST array contents
				if ($request_keys)
				{
					$text .= "\r\n-------------------------------------------------\r\n_REQUEST: ";
					foreach ($request_keys as $key => $var_name)
					{
						$text .= sprintf('%1s = %2s; ', $key, phpbb::$request->variable($var_name, '', true));
					}
					$text = rtrim($text, '; ');
				}

				//Use PHP's error_log function to write to file
				error_log($text . "\r\n=================================================\r\n", 3, TITANIA_ROOT . "store/titania_log.log");
			break;

			case TITANIA_DEBUG :
				//Append the current server date/time, user information, and URL
				$text = date('d-m-Y @ H:i:s') . ' USER: ' . phpbb::$user->data['username'] . ' - ' . phpbb::$user->data['user_id'] . "\r\n";
				$text .= titania_url::$current_page_url;

				// Append the sent message if any
				$text .= (($message !== false) ? "\r\n" . $message : '');

				//Use PHP's error_log function to write to file
				error_log($text . "\r\n=================================================\r\n", 3, TITANIA_ROOT . "store/titania_debug.log");
			break;

			default:
				// phpBB Log System
				$args = func_get_args();
				call_user_func_array('add_log', $args);
			break;

		}
	}

}
