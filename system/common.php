<?php
/* ====================
Seditio - Website engine
Copyright Neocrome
http://www.neocrome.net
==================== */

/**
 * @package Cotonti
 * @version 0.7.0
 * @author Neocrome, Cotonti Team
 * @copyright Copyright (c) Cotonti Team 2008-2010
 * @license BSD
 */

defined('COT_CODE') or die('Wrong URL');

/* ======== First... ======== */

if (version_compare(PHP_VERSION, '6.0.0', '<='))
{
	if (get_magic_quotes_gpc())
	{
		function cot_disable_mqgpc(&$value, $key)
		{
			$value = stripslashes($value);
		}
		$gpc = array(&$_GET, &$_POST, &$_COOKIE, &$_REQUEST);
		array_walk_recursive($gpc, 'cot_disable_mqgpc');
	}
}
define('MQGPC', FALSE);
error_reporting(E_ALL ^ E_NOTICE);
if (COT_DEBUG) require_once $cfg['system_dir'].'/debug.php';

register_shutdown_function('cot_shutdown');

$sys['day'] = @date('Y-m-d');
$sys['now'] = time();
$sys['now_offset'] = $sys['now'] - $cfg['servertimezone']*3600;
$site_id = 'ct'.substr(md5(empty($cfg['site_id']) ? $cfg['mainurl'] : $cfg['site_id']), 0, 16);
$sys['site_id'] = $site_id;

session_start();

/* =========== Early page cache ==========*/
if ($cfg['cache'] && !$cfg['devmode'])
{
	require_once $cfg['system_dir'].'/cache.php';
	$cache = new Cache();
	if ($_SERVER['REQUEST_METHOD'] == 'GET' && empty($_COOKIE[$site_id]) && empty($_SESSION[$site_id]))
	{
		if ($cfg['cache_' . $env['ext']])
		{
			$cache->page->init($env['ext'], $cfg['defaulttheme']);
			$cache->page->read();
		}
	}
}
else
{
	$cache = false;
}

/* ======== Connect to the SQL DB======== */

require_once $cfg['system_dir'].'/database.php';
try
{
	$db = new CotDB('mysql:host='.$cfg['mysqlhost'].';dbname='.$cfg['mysqldb'], $cfg['mysqluser'], $cfg['mysqlpassword']/*, array(PDO::ATTR_PERSISTENT => true)*/);
}
catch (PDOException $e)
{
	cot_diefatal('Could not connect to database !<br />
		Please check your settings in the file datas/config.php<br />
		MySQL error : '.$e->getMessage());
}
unset($cfg['mysqlhost'], $cfg['mysqluser'], $cfg['mysqlpassword']);

$cache && $cache->init();

/* ======== Configuration settings ======== */

if ($cache && $cot_cfg)
{
	$cfg = array_merge($cot_cfg, $cfg);
}
else
{
	// Part 1: Load main configuration
	$sql_config = $db->query("SELECT * FROM $db_config");
	while ($row = $sql_config->fetch())
	{
		if ($row['config_owner'] == 'core')
		{
			$cfg[$row['config_name']] = $row['config_value'];
		}
		elseif ($row['config_owner'] == 'module')
		{
			if (empty($row['config_subcat']))
			{
				$cfg[$row['config_cat']][$row['config_name']] = $row['config_value'];
			}
			else
			{
				$cfg[$row['config_cat']][$row['config_subcat']][$row['config_name']] = $row['config_value'];
			}
		}
		else
		{
			$cfg['plugin'][$row['config_cat']][$row['config_name']] = $row['config_value'];
		}
	}
	$sql_config->closeCursor();
}
// Mbstring options
mb_internal_encoding('UTF-8');

/* ======== Extra settings (the other presets are in functions.php) ======== */

if ($cfg['clustermode'])
{
	if (isset($_SERVER['HTTP_X_CLUSTER_CLIENT_IP'])) $usr['ip'] = $_SERVER['HTTP_X_CLUSTER_CLIENT_IP'];
	elseif (isset($_SERVER['HTTP_X_REAL_IP'])) $usr['ip'] = $_SERVER['HTTP_X_REAL_IP'];
	elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) $usr['ip'] = $_SERVER['HTTP_X_FORWARDED_FOR'];
	else $usr['ip'] = $_SERVER['REMOTE_ADDR'];
}
else
{
	$usr['ip'] = $_SERVER['REMOTE_ADDR'];
}
$sys['unique'] = cot_unique(16);

// Getting the server-relative path
$url = parse_url($cfg['mainurl']);
$sys['secure'] = $url['scheme'] == 'https' ? true : false;
$sys['scheme'] = $url['scheme'];
$sys['site_uri'] = $url['path'];
$sys['domain'] = preg_replace('#^www\.#', '', $url['host']);
if (empty($cfg['cookiedomain'])) $cfg['cookiedomain'] = $sys['domain'];
if ($sys['site_uri'][mb_strlen($sys['site_uri']) - 1] != '/') $sys['site_uri'] .= '/';
define('COT_SITE_URI', $sys['site_uri']);
if (empty($cfg['cookiepath'])) $cfg['cookiepath'] = $sys['site_uri'];
// Absolute site url
$sys['host'] = preg_match('`^(.+\.)?'.preg_quote($sys['domain']).'$`i', $_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST']
	: $sys['domain'];
$sys['abs_url'] = $url['scheme'].'://'.$sys['host']. $sys['site_uri'];
define('COT_ABSOLUTE_URL', $sys['abs_url']);
// URI redirect appliance
$sys['uri_curr'] = (mb_stripos($_SERVER['REQUEST_URI'], $sys['site_uri']) === 0) ?
	mb_substr($_SERVER['REQUEST_URI'], mb_strlen($sys['site_uri'])) : ltrim($_SERVER['REQUEST_URI'], '/');
$sys['uri_redir'] = base64_encode($sys['uri_curr']);
$sys['url_redirect'] = 'redirect='.$sys['uri_redir'];
$redirect = cot_import('redirect','G','SLU');
$out['uri'] = str_replace('&', '&amp;', $sys['uri_curr']);

define('COT_AJAX', !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || !empty($_SERVER['X-Requested-With']));

/* ======== Plugins ======== */

if (!$cot_plugins)
{
	$sql = $db->query("SELECT pl_code, pl_file, pl_hook, pl_module FROM $db_plugins
		WHERE pl_active = 1 ORDER BY pl_hook ASC, pl_order ASC");
	if ($sql->rowCount() > 0)
	{
		while ($row = $sql->fetch())
		{
			$cot_plugins[$row['pl_hook']][] = $row;
		}
        $sql->closeCursor();
	}
	$cache && $cache->db->store('cot_plugins', $cot_plugins, 'system');
}

if (!is_array($cot_urltrans))
{
	cot_load_urltrans();
	$cache && $cache->db->store('cot_urltrans', $cot_urltrans, 'system', 1200);
}

if (!$cot_modules)
{
    $sql = $db->query("SELECT ct_code, ct_title FROM $db_core
		WHERE ct_state = 1 AND ct_lock = 0");
	if ($sql->rowCount() > 0)
	{
		while ($row = $sql->fetch())
		{
			$cot_modules[$row['ct_code']] = array(
                'code' => $row['ct_code'],
                'title' => $row['ct_title']
            );
		}
        $sql->closeCursor();
	}
	$cache && $cache->db->store('cot_modules', $cot_modules, 'system');
}

/* ======== Gzip and output filtering ======== */

if ($cfg['gzip'])
{
	@ob_start('ob_gzhandler');
}
else
{
	ob_start();
}

ob_start('cot_outputfilters');

/* ======== Groups ======== */

if (!$cot_groups )
{
	$sql = $db->query("SELECT * FROM $db_groups WHERE grp_disabled=0 ORDER BY grp_level DESC");

	if ($sql->rowCount() > 0)
	{
		while ($row = $sql->fetch())
		{
			$cot_groups[$row['grp_id']] = array(
				'id' => $row['grp_id'],
				'alias' => $row['grp_alias'],
				'level' => $row['grp_level'],
   				'disabled' => $row['grp_disabled'],
   				'hidden' => $row['grp_hidden'],
				'state' => $row['grp_state'],
				'title' => htmlspecialchars($row['grp_title']),
				'desc' => htmlspecialchars($row['grp_desc']),
				'icon' => $row['grp_icon'],
				'pfs_maxfile' => $row['grp_pfs_maxfile'],
				'pfs_maxtotal' => $row['grp_pfs_maxtotal'],
				'ownerid' => $row['grp_ownerid']
			);
		}
	}
	else
	{
		cot_diefatal('No groups found.'); // TODO: Need translate
	}

	$cache && $cache->db->store('cot_groups', $cot_groups, 'system');
}

/* ======== User/Guest ======== */

$usr['id'] = 0;
$usr['sessionid'] = '';
$usr['name'] = '';
$usr['level'] = 0;
$usr['lastvisit'] = 30000000000;
$usr['lastlog'] = 0;
$usr['timezone'] = $cfg['defaulttimezone'];
$usr['newpm'] = 0;
$usr['messages'] = 0;

if (!defined('COT_MESSAGE'))
{
	$_SESSION['s_run_admin'] = defined('COT_ADMIN');
}

if (!empty($_COOKIE[$site_id]) || !empty($_SESSION[$site_id]))
{
	$u = empty($_SESSION[$site_id]) ? explode(':', $_COOKIE[$site_id]) : explode(':', $_SESSION[$site_id]);
	$u_id = (int) cot_import($u[0], 'D', 'INT');
	$u_sid = cot_import($u[1], 'D', 'ALP');
	if ($u_id > 0)
	{
		$sql = $db->query("SELECT * FROM $db_users WHERE user_id = $u_id AND user_sid = '$u_sid'");

		if ($row = $sql->fetch())
		{
			if ($row['user_maingrp'] > 3
				&& ($cfg['ipcheck'] == FALSE || $row['user_lastip'] == $usr['ip']))
			{
				$usr['id'] = (int) $row['user_id'];
				$usr['name'] = $row['user_name'];
				$usr['maingrp'] = $row['user_maingrp'];
				$usr['lastvisit'] = $row['user_lastvisit'];
				$usr['lastlog'] = $row['user_lastlog'];
				$usr['timezone'] = $row['user_timezone'];
				$usr['theme'] = ($cfg['forcedefaulttheme']) ? $cfg['defaulttheme'] : $row['user_theme'];
				$usr['scheme'] = $row['user_scheme'];
				$usr['lang'] = ($cfg['forcedefaultlang']) ? $cfg['defaultlang'] : $row['user_lang'];
				$usr['newpm'] = $row['user_newpm'];
				$usr['auth'] = unserialize($row['user_auth']);
				$usr['level'] = $cot_groups[$usr['maingrp']]['level'];
				$usr['profile'] = $row;

				$sys['xk'] = $row['user_token'];

				if (!isset($_SESSION['cot_user_id']))
				{
					$_SESSION['cot_user_id'] = $usr['id'];
				}

				if ($usr['lastlog'] + $cfg['timedout'] < $sys['now_offset'])
				{
					$sys['comingback'] = TRUE;
					if ($usr['lastlog'] > $usr['lastvisit'])
					{
						$usr['lastvisit'] = $usr['lastlog'];
						$update_lastvisit = ", user_lastvisit = " . $usr['lastvisit'];
					}

					// Generate new security token
					$token = cot_unique(16);
					$sys['xk_prev'] = $sys['xk'];
					$sys['xk'] = $token;
					$update_token = ", user_token = '$token'";
				}


				if (!$cfg['authcache'] || empty($row['user_auth']))
				{
					$usr['auth'] = cot_auth_build($usr['id'], $usr['maingrp']);
					if($cfg['authcache']) $update_auth = ", user_auth='".serialize($usr['auth'])."'";
				}

				$db->query("UPDATE $db_users
					SET user_lastlog = {$sys['now_offset']} $update_lastvisit $update_token $update_auth
					WHERE user_id='{$usr['id']}'");

				unset($u, $passhash, $oldhash, $hashsalt, $hashsaltprev, $update_auth, $update_hashsalt,
					$update_lastvisit, $update_sid);
			}
		}
	}
	else
	{
		$usr['theme'] = cot_import($u[0], 'D', 'ALP');
		$usr['scheme'] = cot_import($u[1], 'D', 'ALP');
		$usr['lang'] = cot_import($u[2], 'D', 'ALP');
	}
}

if ($usr['id'] == 0)
{
	if (!$cot_guest_auth)
	{
		$cot_guest_auth = cot_auth_build(0);
		$cache && $cache->db->store('cot_guest_auth', $cot_guest_auth, 'system');
	}
	$usr['auth'] = $cot_guest_auth;
	unset($cot_guest_auth);
	$usr['theme'] = empty($usr['theme']) ? $cfg['defaulttheme'] : $usr['theme'];
	$usr['scheme'] = empty($usr['scheme']) ? $cfg['defaultscheme'] : $usr['scheme'];
	$usr['lang'] = empty($usr['lang']) ? $cfg['defaultlang'] : $usr['lang'];
	$sys['xk'] = mb_strtoupper(dechex(crc32($sys['abs_url']))); // Site related key for guests
}

/* === Hook === */
foreach (cot_getextplugins('input') as $pl)
{
	include $pl;
}
/* ======================== */


/* ======== Maintenance mode ======== */

if ($cfg['maintenance'])
{
	$sqll = $db->query("SELECT grp_maintenance FROM $db_groups WHERE grp_id='".$usr['maingrp']."' ");
	$roow = $sqll->fetch();

	if (!$roow['grp_maintenance'] && !defined('COT_AUTH'))
	{
		cot_redirect(cot_url('users', 'm=auth', '', true));
	}
}

/* ======== Zone variables ======== */

$m = cot_import('m', 'G', 'ALP', 24);
$n = cot_import('n', 'G', 'ALP', 24);
$a = cot_import('a', 'G', 'ALP', 24);
$b = cot_import('b', 'G', 'ALP', 24);

/* ======== Language ======== */
$lang = $usr['lang'];
require_once cot_langfile('main', 'core');

/* ======== Category Structure ======== */
if (!$structure)
{
	cot_load_structure();
	$cache && $cache->db->store('structure', $structure, 'system');
}
$cot_cat = $structure['page'];

if (!$cache || !$cot_cfg)
{
	// Fill missing options with default values
	foreach ($structure as $module => $mod_struct)
	{
		if (is_array($cfg[$module]['__default']))
		{
			foreach ($mod_struct as $cat => $row)
			{
				foreach ($cfg[$module]['__default'] as $key => $val)
				{
					if (!isset($cfg[$module][$cat][$key]))
					{
						$cfg[$module][$cat][$key] = $val;
					}
				}
			}
		}
	}

	// Save configuration at this point
	$cache && $cache->db->store('cot_cfg', $cfg, 'system');
}
unset($cot_cfg);

/* ======== Who's online (part 1) and shield protection ======== */

if (!$cfg['disablewhosonline'] || $cfg['shieldenabled'])
{
	if ($usr['id'] > 0)
	{
		$sql = $db->query("SELECT * FROM $db_online WHERE online_userid=".$usr['id']);

		if ($row = $sql->fetch())
		{
			$online_count = 1;
			$sys['online_location'] = $row['online_location'];
			$sys['online_subloc'] = $row['online_subloc'];
			if ($cfg['shieldenabled'] && (!cot_auth('admin', 'a', 'A') || COT_SHIELD_FORCE))
			{
				$shield_limit = $row['online_shield'];
				$shield_action = $row['online_action'];
				$shield_hammer = cot_shield_hammer($row['online_hammer'], $shield_action, $row['online_lastseen']);
				$sys['online_hammer'] = $shield_hammer;
			}
		}
	}
	else
	{
		$sql = $db->query("SELECT * FROM $db_online WHERE online_ip='".$usr['ip']."'");
		$online_count = $sql->rowCount();

		if ($online_count > 0)
		{
			if ($row = $sql->fetch())
			{
				$sys['online_location'] = $row['online_location'];
				$sys['online_subloc'] = $row['online_subloc'];
				if ($cfg['shieldenabled'])
				{
					$shield_limit = $row['online_shield'];
					$shield_action = $row['online_action'];
					$shield_hammer = cot_shield_hammer($row['online_hammer'], $shield_action, $row['online_lastseen']);
					$sys['online_hammer'] = $shield_hammer;
				}
			}
		}
	}
}

/* ======== Theme / color scheme ======== */

$mtheme = './themes/'.$usr['theme'].'/header.tpl';
if (!file_exists($mtheme))
{
	$out['notices'] .= $L['com_themefail'].'<br />';
	$usr['theme'] = $cfg['defaulttheme'];
	$mtheme = './themes/'.$usr['theme'].'/header.tpl';
	if (!file_exists($mtheme))
	{
		cot_diefatal('Default theme not found.'); // TODO: Need translate
	}
}

$mscheme = cot_schemefile();
if (!$mscheme)
{
	cot_diefatal('Default scheme not found.'); // TODO: Need translate
}

$usr['def_theme_lang'] = './themes/'.$usr['theme'].'/'.$usr['theme'].'.en.lang.php';
$usr['theme_lang'] = './themes/'.$usr['theme'].'/'.$usr['theme'].'.'.$usr['lang'].'.lang.php';
if ($usr['theme_lang'] != $usr['def_theme_lang'] && @file_exists($usr['theme_lang']))
{
	require_once $usr['theme_lang'];
}
elseif (@file_exists($usr['def_theme_lang']))
{
	require_once $usr['def_theme_lang'];
}

$theme = $usr['theme'];
$scheme = $usr['scheme'];

// Resource strings
require_once $cfg['system_dir'].'/resources.php';
// Theme resources
$themeR = cot_require_rc_theme();
$R = array_merge($R, $themeR);
// Iconpack
if (empty($cfg['defaulticons']))
{
	$cfg['defaulticons'] = 'default';
}
if (empty($usr['icons']))
{
	$usr['icons'] = $cfg['defaulticons'];
}
require_once './images/icons/' . $usr['icons'] . '/resources.php';

$out['copyright'] = "<a href=\"http://www.cotonti.com\">".$L['foo_poweredby']." Cotonti</a>";

/* ======== Various ======== */

$cot_yesno[0] = $L['No'];
$cot_yesno[1] = $L['Yes'];

/* ======== Local/GMT time ======== */

$usr['timetext'] = cot_build_timezone($usr['timezone']);
$usr['gmttime'] = @date($cfg['dateformat'], $sys['now_offset']).' GMT';

/* ======== Anti-XSS protection ======== */

$x = cot_import('x', 'P', 'ALP');
if (empty($x) && COT_AJAX && $_SERVER['REQUEST_METHOD'] == 'POST')
{
	$x = cot_import('x', 'G', 'ALP');
}
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !defined('COT_NO_ANTIXSS') && !defined('COT_AUTH')
	&& $x != $sys['xk'] && (empty($sys['xk_prev']) || $x != $sys['xk_prev']))
{
	$cot_error = true;
	cot_redirect(cot_url('message', 'msg=950', '', true));
}

/* ============ Head Resources ===========*/
if (!$cot_headrc)
{
	cot_headrc_consolidate();
}

/* ======== Global hook ======== */

foreach (cot_getextplugins('global') as $pl)
{
	include $pl;
}

?>
