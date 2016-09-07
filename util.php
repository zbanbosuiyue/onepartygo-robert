<?php
if (defined('PHP_SAPI') && PHP_SAPI == 'cli') {
	if ($argc < 3) {
		die("must provide two parameters. first param must be the pattern to look for (remember to escape the '.' characters). the second must be the text to replace it with.\n");
	} else {
		$from = $argv[1];
		$to = $argv[2];
	}
} else if (isset($_GET) && is_array($_GET)) {
	if (!isset($_GET['from'], $_GET['to']) || empty($_GET['from']) || empty($_GET['to'])) {
		die("the 'from' and 'to' url params must be specified. from must be the pattern to look for, escaped with slashes. to must be the text to replace the pattern with\n");
	} else {
		$from = $_GET['from'];
		$to = $_GET['to'];
	}
} else {
	die('no urls provided'."\n");
}

$old_patterns = array(
	'/[^/]*'.$from.'[^/]*',
);
$new_url = '/'.$to;

define('WP_CACHE', false);
//define('SHORTINIT', true);
foreach ($old_patterns as &$pat) $pat = '#(http(?:s)?:/|@)('.$pat.')(/?)#';

$steps = array();
function qs_error_handler($errno, $errstr, $errfile, $errline) {
	global $steps;
	$skey = array_pop(array_keys($steps));
	if (!is_null($skey)) {
		$step = $steps[$skey];
		$step['errors'] = isset($step['errors']) && is_array($step['errors']) ? $step['errors'] : array();
		$step['errors'][] = array(
			'no' => $errno,
			'str' => $errstr,
			'file' => $errfile,
			'line' => $errline
		);
		$steps[$skey] = $step;
	}
	if (E_RECOVERABLE_ERROR===$errno) {
		return true;
	}
	return false;
}
set_error_handler('qs_error_handler');

function make_replacements($value) {
	global $old_patterns, $new_url;

	if (is_array($value) || is_object($value)) {
		foreach ($value as $key => &$val)
			$val = make_replacements($val);
	} else {
		$value = preg_replace_callback($old_patterns, '_qs_replace_url', $value);
	}

	return $value;
}

function _qs_replace_url($match) {
	global $new_url;
	return $match[1].$new_url.$match[3];
}

function _prog($ind, $good, $style='color:', $color='black') {
	if (defined('PHP_SAPI') && PHP_SAPI == 'cli') {
		switch (true) {
			case (($ind+1) % 10000 == 0): echo ($good ? 'T' : 'X')."\n"; break;
			case (($ind+1) % 1000 == 0): echo ($good ? 't' : 'x')."\n"; break;
			case (($ind+1) % 100 == 0): echo ($good ? 'H' : 'X')."\n"; break;
			case (($ind+1) % 10 == 0): echo ($good ? '0' : 'X'); break;
			default: echo ($good ? ($ind+1) % 10 : 'x'); break;
		}
	} else {
		switch (true) {
			case (($ind+1) % 10000 == 0): echo '<span style="'.$style.'#FFFF00'.'">'.($good ? 'T' : 'X').'</span> '.($ind+1)."\n"; break;
			case (($ind+1) % 1000 == 0): echo '<span style="'.$style.'#00FF00'.'">'.($good ? 't' : 'x').'</span> '.($ind+1)."\n"; break;
			case (($ind+1) % 100 == 0): echo '<span style="'.$style.'#0000FF'.'">'.($good ? 'H' : 'X').'</span> '.($ind+1)."\n"; break;
			case (($ind+1) % 10 == 0): echo '<span style="'.$style.$color.'">'.($good ? '0' : 'X').'</span>'; break;
			default: echo '<span style="'.$style.$color.'">'.($good ? ($ind+1) % 10 : 'x').'</span>'; break;
		}
	}
	ob_flush(); flush();
}

function _get_post_custom($id) {
	global $wpdb;
	$q = 'select meta_key, meta_value from '.$wpdb->postmeta.' where post_id = %d';
	$all = $wpdb->get_results($wpdb->prepare($q, $id));
	$meta = array();
	foreach ($all as $m) {
		if (isset($meta[$m->meta_key])) continue;
		$meta[$m->meta_key] = maybe_unserialize($m->meta_value);
	}
	return $meta;
}

function _update_post_meta($post_id, $meta_key, $meta_value='') {
	global $wpdb;
	$q = 'update '.$wpdb->postmeta.' set meta_value = %s where post_id = %d and meta_key = %s limit 1';
	return $wpdb->query($wpdb->prepare($q, maybe_serialize($meta_value), $post_id, $meta_key));
}

require_once 'wp-load.php';
if (defined('SHORTINIT') && SHORTINIT) {
	include 'wp-includes/formatting.php';
	include 'wp-includes/kses.php';
	include 'wp-includes/rewrite.php';
	include 'wp-includes/comment.php';
}
ini_set('max_exection_time', 300);
ini_set('memory_limit', '512M');

ob_start();
echo '<pre>';

echo "FIXING OPTIONS:\n"; ob_flush(); flush();
$q = 'select * from '.$wpdb->options;
$allopts = $wpdb->get_results($q);
echo "- found (".count($allopts).")\n"; ob_flush(); flush();
foreach ($allopts as $ind => &$opt) {
	$steps[$opt->option_name] = array(
		'name' => $opt->option_name,
		'value' => $opt->option_value,
		'type' => 'option',
		'errors' => array(),
	);
	$opt->option_value = maybe_unserialize($opt->option_value);
	$steps[$opt->option_name]['unser'] = $opt->option_value;
	$opt->option_value = make_replacements($opt->option_value);
	$steps[$opt->option_name]['replaced'] = $opt->option_value;
	if (!empty($opt->option_value) && count($steps[$opt->option_name]['errors']) == 0) {
		update_option($opt->option_name, $opt->option_value);
	}
	$good = true;
	$style = 'font-weight:bold; color:';
	$color = '#444444';
	_prog($ind, $good, $style, $color);
}
echo "\n-- OPTIONS FIXED\n\n"; ob_flush(); flush();

$q = 'select * from '.$wpdb->posts.' where post_type = %s';
$upq = 'update '.$wpdb->posts.' set post_content = %s, post_title = %s, guid = %s where id = %d';

echo "FIXING NAV ITEMS:\n"; ob_flush(); flush();
$posts = $wpdb->get_results($wpdb->prepare($q, 'nav_menu_item'));
foreach ($posts as $post) {
	$steps[$post->post_title] = array(
		'name' => $post->post_title,
		'value' => $post->post_content,
		'type' => 'navitem',
		'errors' => array(),
	);
	$title = preg_replace_callback($old_patterns, '_qs_replace_url', $post->post_title);
	$content = preg_replace_callback($old_patterns, '_qs_replace_url', $post->post_content);
	$guid = preg_replace_callback($old_patterns, '_qs_replace_url', $post->guid);
	$wpdb->query($wpdb->prepare($upq, $content, $title, $guid, $post->ID));
	$meta = _get_post_custom($post->ID);
	$steps[$post->post_title]['meta'] = $meta;
	foreach ($meta as $k => &$m) {
		$m = make_replacements($m);
		_update_post_meta($post->ID, $k, $m);
	}
	$steps[$post->post_title]['name-replaced'] = $title;
	$steps[$post->post_title]['value-replaced'] = $content;
	$steps[$post->post_title]['guid-replaced'] = $guid;
	$steps[$post->post_title]['meta-replaced'] = $meta;
	$good = true;
	$style = 'font-weight:bold; color:';
	$color = '#444444';
	_prog($ind, $good, $style, $color);
}
echo "\n-- NAV ITEMS FIXED\n\n"; ob_flush(); flush();

echo "\n+++ REPORT +++\n";
foreach ($steps as $name => $step) {
	if (is_array($step['errors']) && count($step['errors']) > 0) {
		print_r($step);
		echo "NAME: {$step['name']}\n";
		foreach ($step['errors'] as $ind => $error) {
			echo "ERROR[{$ind}]: {$error['file']}:{$error['line']} | {$error['no']} | {$error['str']}\n";
		}
		echo "\n";
	}
}
echo "\n--- end REPORT ---\n\n";

echo "\nDONE!".'</pre>';
ob_flush(); flush(); ob_end_clean();
