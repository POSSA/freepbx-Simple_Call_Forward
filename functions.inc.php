<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

function simplecf_get_config($engine) {
	$modulename = 'simplecf';
	
	// This generates the dialplan
	global $ext;  
	global $amp_conf;  
	global $version;
	switch($engine) {
		case "asterisk":
			// If Using CF then set this so AGI scripts can determine
			//
			if ($amp_conf['USEDEVSTATE']) {
				$ext->addGlobal('CFDEVSTATE','TRUE');
			}
			if (is_array($featurelist = featurecodes_getModuleFeatures($modulename))) {
				foreach($featurelist as $item) {
					$featurename = $item['featurename'];
					$fname = $modulename.'_'.$featurename;
					if (function_exists($fname)) {
						$fcc = new featurecode($modulename, $featurename);
						$fc = $fcc->getCodeActive();
						unset($fcc);
						
						if ($fc != '')
							$fname($fc);
					} else {
						$ext->add('from-internal-additional', 'debug', '', new ext_noop($modulename.": No func $fname"));
						var_dump($item);
					}	
				}
			}

			// Create hints context for CF codes so a device can subscribe to the DND state
			//
			$fcc = new featurecode($modulename, 'scf_toggle');
			$cf_code = $fcc->getCodeActive();
			unset($fcc);

			if ($amp_conf['USEDEVSTATE'] && $cf_code != '') {
				$ext->addInclude('from-internal-additional','ext-cf-hints');
				$contextname = 'ext-cf-hints';
				$device_list = core_devices_list("all", 'full', true);
        $base_offset = strlen($cf_code);
				foreach ($device_list as $device) {
          if ($device['tech'] == 'sip' || $device['tech'] == 'iax2') {
            $offset = $base_offset + strlen($device['id']);
					  $ext->add($contextname, $cf_code.$device['id'], '', new ext_goto("1",$cf_code,"app-scf-toggle"));
					  $ext->add($contextname, '_'.$cf_code.$device['id'].'.', '', new ext_set("toext",'${EXTEN:'.$offset.'}'));
					  $ext->add($contextname, '_'.$cf_code.$device['id'].'.', '', new ext_goto("setdirect",$cf_code,"app-scf-toggle"));
					  $ext->addHint($contextname, $cf_code.$device['id'], "Custom:DEVCF".$device['id']);
          }
				}
			}

		break;
	}
}

// Simple Call Forwarding Toggle
function simplecf_scf_toggle($c) {
	global $ext;
	global $amp_conf;
	global $version;

	$id = "app-scf-toggle"; // The context to be included

	$ext->addInclude('from-internal-additional', $id); // Add the include from from-internal
	$ext->add($id, $c, '', new ext_answer(''));
	$ext->add($id, $c, '', new ext_wait('1'));
	$ext->add($id, $c, '', new ext_macro('user-callerid'));
	$ext->add($id, $c, '', new ext_setvar('fromext', '${AMPUSER}'));
	$ext->add($id, $c, '', new ext_gotoif('$["${DB(SCF/${fromext})}"!=""]', 'continue'));
	$ext->add($id, $c, '', new ext_playback('call-forward&number&for&extension'));
	$ext->add($id, $c, '', new ext_saydigits('${fromext}'));
	$ext->add($id, $c, '', new ext_playback('not-yet-assigned&goodbye'));
	$ext->add($id, $c, '', new ext_macro('hangupcall'));
	$ext->add($id, $c, 'continue', new ext_gotoif('$["${DB(CF/${fromext})}" = ""]', 'activate', 'deactivate'));
	$ext->add($id, $c, 'activate', new ext_setvar('toext', '${DB(SCF/${fromext})}'));
	$ext->add($id, $c, '', new ext_gotoif('$["${toext}"=""]', 'activate'));
	$ext->add($id, $c, '', new ext_wait('1')); // $cmd,n,Wait(1)
	$ext->add($id, $c, 'toext', new ext_setvar('DB(CF/${fromext})', '${toext}')); 
	if ($amp_conf['USEDEVSTATE']) {
		$ext->add($id, $c, '', new ext_setvar('STATE', 'BUSY'));
		$ext->add($id, $c, '', new ext_gosub('1', 'sstate', $id));
	}
	if ($amp_conf['FCBEEPONLY']) {
		$ext->add($id, $c, 'hook_on', new ext_playback('beep')); // $cmd,n,Playback(...)
	} else {
	  $ext->add($id, $c, 'hook_on', new ext_playback('call-fwd-unconditional&activated&for&extension'));
	  $ext->add($id, $c, '', new ext_saydigits('${fromext}'));
	  $ext->add($id, $c, '', new ext_playback('is-set-to'));
	  $ext->add($id, $c, '', new ext_saydigits('${toext}'));
	  $ext->add($id, $c, '', new ext_playback('goodbye'));
	}
	$ext->add($id, $c, '', new ext_macro('hangupcall'));
	$ext->add($id, $c, 'setdirect', new ext_answer(''));
	$ext->add($id, $c, '', new ext_wait('1'));
	$ext->add($id, $c, '', new ext_macro('user-callerid'));
	$ext->add($id, $c, '', new ext_goto('toext'));
	$ext->add($id, $c, 'deactivate', new ext_dbdel('CF/${fromext}')); 
	if ($amp_conf['USEDEVSTATE']) {
		$ext->add($id, $c, '', new ext_setvar('STATE', 'NOT_INUSE'));
		$ext->add($id, $c, '', new ext_gosub('1', 'sstate', $id));
	}
	if ($amp_conf['FCBEEPONLY']) {
		$ext->add($id, $c, 'hook_off', new ext_playback('beep')); // $cmd,n,Playback(...)
	} else {
	  $ext->add($id, $c, 'hook_off', new ext_playback('call-fwd-unconditional&de-activated')); // $cmd,n,Playback(...)
	}
	$ext->add($id, $c, '', new ext_macro('hangupcall'));

	if ($amp_conf['USEDEVSTATE']) {
		$c = 'sstate';
		$ext->add($id, $c, '', new ext_setvar($amp_conf['AST_FUNC_DEVICE_STATE'].'(Custom:CF${fromext})', '${STATE}'));
		$ext->add($id, $c, '', new ext_dbget('DEVICES','AMPUSER/${fromext}/device'));
		$ext->add($id, $c, '', new ext_gotoif('$["${DEVICES}" = "" ]', 'return'));
		$ext->add($id, $c, '', new ext_setvar('LOOPCNT', '${FIELDQTY(DEVICES,&)}'));
		$ext->add($id, $c, '', new ext_setvar('ITER', '1'));
		$ext->add($id, $c, 'begin', new ext_setvar($amp_conf['AST_FUNC_DEVICE_STATE'].'(Custom:DEVCF${CUT(DEVICES,&,${ITER})})','${STATE}'));
		$ext->add($id, $c, '', new ext_setvar('ITER', '$[${ITER} + 1]'));
		$ext->add($id, $c, '', new ext_gotoif('$[${ITER} <= ${LOOPCNT}]', 'begin'));
		$ext->add($id, $c, 'return', new ext_return());
	}
}

// Simple Call Forwarding destination
function simplecf_scf_define($c) {
	global $ext;
	global $amp_conf;
	global $version;

	$id = "app-scf-define"; // The context to be included

	$ext->addInclude('from-internal-additional', $id); // Add the include from from-internal
	$ext->add($id, $c, '', new ext_answer('')); // $cmd,1,Answer
	$ext->add($id, $c, '', new ext_wait('1')); // $cmd,n,Wait(1)
	$ext->add($id, $c, '', new ext_macro('user-callerid')); // $cmd,n,Macro(user-callerid)
	$ext->add($id, $c, '', new ext_setvar('fromext', '${AMPUSER}'));	
	$ext->add($id, $c, '', new ext_wait('1')); // $cmd,n,Wait(1)
	$ext->add($id, $c, 'startread', new ext_read('toext', 'ent-target-attendant&then-press-pound'));
	$ext->add($id, $c, '', new ext_gotoif('$["foo${toext}"="foo"]', 'startread'));
	$ext->add($id, $c, '', new ext_wait('1')); // $cmd,n,Wait(1)
	$ext->add($id, $c, '', new ext_setvar('DB(SCF/${fromext})', '${toext}')); 
	if ($amp_conf['FCBEEPONLY']) {
		$ext->add($id, $c, 'hook_1', new ext_playback('beep')); // $cmd,n,Playback(...)
	} else {
		$ext->add($id, $c, 'hook_1', new ext_playback('call-fwd-unconditional&for&extension'));
		$ext->add($id, $c, '', new ext_saydigits('${fromext}'));
		$ext->add($id, $c, '', new ext_playback('is-set-to'));
		$ext->add($id, $c, '', new ext_saydigits('${toext}'));
		$ext->add($id, $c, '', new ext_playback('goodbye'));
	}

$ext->add($id, $c, '', new ext_gotoif('$["${DB(CF/${fromext})}"=""]', 'done'));
$ext->add($id, $c, '', new ext_setvar('DB(CF/${fromext})', '${DB(SCF/${fromext})}'));

	$ext->add($id, $c, 'done', new ext_macro('hangupcall')); // $cmd,n,Macro(user-callerid)
}

function simplecf_vercheck() {
	$newver = false;
	if ( function_exists(simplecf_xml2array)){
		$module_local = simplecf_xml2array("modules/simplecf/module.xml");
		$module_remote = simplecf_xml2array("https://raw.github.com/POSSA/freepbx-Simple_Call_Forward/master/module.xml");
		if ( $module_remote[module][version] > $module_local[module][version])
			{
			$newver = true;
			}
		return ($newver);
		}
	}

//Parse XML file into an array
function simplecf_xml2array($url, $get_attributes = 1, $priority = 'tag')  {
	$contents = "";
	if (!function_exists('xml_parser_create'))
	{
		return array ();
	}
	$parser = xml_parser_create('');
	if(!($fp = @ fopen($url, 'rb')))
	{
		return array ();
	}
	while(!feof($fp))
	{
		$contents .= fread($fp, 8192);
	}
	fclose($fp);
	xml_parser_set_option($parser, XML_OPTION_TARGET_ENCODING, "UTF-8");
	xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
	xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
	xml_parse_into_struct($parser, trim($contents), $xml_values);
	xml_parser_free($parser);
	if(!$xml_values)
	{
		return; //Hmm...
	}
	$xml_array = array ();
	$parents = array ();
	$opened_tags = array ();
	$arr = array ();
	$current = & $xml_array;
	$repeated_tag_index = array ();
	foreach ($xml_values as $data)
	{
		unset ($attributes, $value);
		extract($data);
		$result = array ();
		$attributes_data = array ();
		if (isset ($value))
		{
			if($priority == 'tag')
			{
				$result = $value;
			}
			else
			{
				$result['value'] = $value;
			}
		}
		if(isset($attributes) and $get_attributes)
		{
			foreach($attributes as $attr => $val)
			{
				if($priority == 'tag')
				{
					$attributes_data[$attr] = $val;
				}
				else
				{
					$result['attr'][$attr] = $val; //Set all the attributes in a array called 'attr'
				}
			}
		}
		if ($type == "open")
		{
			$parent[$level -1] = & $current;
			if(!is_array($current) or (!in_array($tag, array_keys($current))))
			{
				$current[$tag] = $result;
				if($attributes_data)
				{
					$current[$tag . '_attr'] = $attributes_data;
				}
				$repeated_tag_index[$tag . '_' . $level] = 1;
				$current = & $current[$tag];
			}
			else
			{
				if (isset ($current[$tag][0]))
				{
					$current[$tag][$repeated_tag_index[$tag . '_' . $level]] = $result;
					$repeated_tag_index[$tag . '_' . $level]++;
				}
				else
				{
					$current[$tag] = array($current[$tag],$result);
					$repeated_tag_index[$tag . '_' . $level] = 2;
					if(isset($current[$tag . '_attr']))
					{
						$current[$tag]['0_attr'] = $current[$tag . '_attr'];
						unset ($current[$tag . '_attr']);
					}
				}
				$last_item_index = $repeated_tag_index[$tag . '_' . $level] - 1;
				$current = & $current[$tag][$last_item_index];
			}
		}
		else if($type == "complete")
		{
			if(!isset ($current[$tag]))
			{
				$current[$tag] = $result;
				$repeated_tag_index[$tag . '_' . $level] = 1;
				if($priority == 'tag' and $attributes_data)
				{
					$current[$tag . '_attr'] = $attributes_data;
				}
			}
			else
			{
				if (isset ($current[$tag][0]) and is_array($current[$tag]))
				{
					$current[$tag][$repeated_tag_index[$tag . '_' . $level]] = $result;
					if ($priority == 'tag' and $get_attributes and $attributes_data)
					{
						$current[$tag][$repeated_tag_index[$tag . '_' . $level] . '_attr'] = $attributes_data;
					}
					$repeated_tag_index[$tag . '_' . $level]++;
				}
				else
				{
					$current[$tag] = array($current[$tag],$result);
					$repeated_tag_index[$tag . '_' . $level] = 1;
					if ($priority == 'tag' and $get_attributes)
					{
						if (isset ($current[$tag . '_attr']))
						{
							$current[$tag]['0_attr'] = $current[$tag . '_attr'];
							unset ($current[$tag . '_attr']);
						}
						if ($attributes_data)
						{
							$current[$tag][$repeated_tag_index[$tag . '_' . $level] . '_attr'] = $attributes_data;
						}
					}
					$repeated_tag_index[$tag . '_' . $level]++; //0 and 1 index is already taken
				}
			}
		}
		else if($type == 'close')
		{
			$current = & $parent[$level -1];
		}
	}
	return ($xml_array);
}