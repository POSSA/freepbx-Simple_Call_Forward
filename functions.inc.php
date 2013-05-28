<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

function persistentcf_get_config($engine) {
	$modulename = 'persistentcf';
	
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
/*
			// Create hints context for CF codes so a device can subscribe to the DND state
			//
			$fcc = new featurecode($modulename, 'pcf_toggle');
			$cf_code = $fcc->getCodeActive();
			unset($fcc);

			if ($amp_conf['USEDEVSTATE'] && $cf_code != '') {
//				$ext->addInclude('from-internal-additional','ext-cf-hints');
				$contextname = 'ext-cf-hints';
				$device_list = core_devices_list("all", 'full', true);
        $base_offset = strlen($cf_code);
				foreach ($device_list as $device) {
          if ($device['tech'] == 'sip' || $device['tech'] == 'iax2') {
            $offset = $base_offset + strlen($device['id']);
					  $ext->add($contextname, $cf_code.$device['id'], '', new ext_goto("1",$cf_code,"app-pcf-toggle"));
					  $ext->add($contextname, '_'.$cf_code.$device['id'].'.', '', new ext_set("toext",'${EXTEN:'.$offset.'}'));
					  $ext->add($contextname, '_'.$cf_code.$device['id'].'.', '', new ext_goto("setdirect",$cf_code,"app-pcf-toggle"));
					  $ext->addHint($contextname, $cf_code.$device['id'], "Custom:DEVCF".$device['id']);
          }
				}
			}
*/
		break;
	}
}

// Persistent Call Forwarding Toggle
function persistentcf_pcf_toggle($c) {
	global $ext;
	global $amp_conf;
  global $version;
  $ast_ge_16 = version_compare($version, "1.6", "ge");

	$id = "app-pcf-toggle"; // The context to be included

	$ext->addInclude('from-internal-additional', $id); // Add the include from from-internal

	$ext->add($id, $c, '', new ext_answer(''));
	$ext->add($id, $c, '', new ext_wait('1'));
	$ext->add($id, $c, '', new ext_macro('user-callerid'));
	$ext->add($id, $c, '', new ext_setvar('fromext', '${AMPUSER}'));

	$ext->add($id, $c, '', new ext_gotoif('$["${DB(CF/${fromext})}" = ""]', 'activate', 'deactivate'));

  
	$ext->add($id, $c, 'activate', new ext_setvar('toext', '${DB(PCF/${fromext})}'));
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
	  $ext->add($id, $c, 'hook_on', new ext_playback('call-fwd-unconditional&for&extension'));
	  $ext->add($id, $c, '', new ext_saydigits('${fromext}'));
	  $ext->add($id, $c, '', new ext_playback('is-set-to'));
	  $ext->add($id, $c, '', new ext_saydigits('${toext}'));
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

// Define PCF Destination
function persistentcf_pcf_define($c) {
	global $ext;
	global $amp_conf;
  global $version;
  $ast_ge_16 = version_compare($version, "1.6", "ge");

	$id = "app-pcf-define"; // The context to be included

	$ext->addInclude('from-internal-additional', $id); // Add the include from from-internal

	$ext->add($id, $c, '', new ext_answer('')); // $cmd,1,Answer
	$ext->add($id, $c, '', new ext_wait('1')); // $cmd,n,Wait(1)
	$ext->add($id, $c, '', new ext_macro('user-callerid')); // $cmd,n,Macro(user-callerid)


	$ext->add($id, $c, '', new ext_setvar('fromext', '${AMPUSER}'));	
	$ext->add($id, $c, '', new ext_wait('1')); // $cmd,n,Wait(1)
	$ext->add($id, $c, 'startread', new ext_read('toext', 'ent-target-attendant&then-press-pound'));
	
	$ext->add($id, $c, '', new ext_gotoif('$["foo${toext}"="foo"]', 'startread'));
	$ext->add($id, $c, '', new ext_wait('1')); // $cmd,n,Wait(1)
	$ext->add($id, $c, '', new ext_setvar('DB(PCF/${fromext})', '${toext}')); 
	if ($amp_conf['FCBEEPONLY']) {
		$ext->add($id, $c, 'hook_1', new ext_playback('beep')); // $cmd,n,Playback(...)
	} else {
	  $ext->add($id, $c, 'hook_1', new ext_playback('call-fwd-unconditional&for&extension'));
	  $ext->add($id, $c, '', new ext_saydigits('${fromext}'));
	  $ext->add($id, $c, '', new ext_playback('is-set-to'));
	  $ext->add($id, $c, '', new ext_saydigits('${toext}'));
	}
	$ext->add($id, $c, '', new ext_macro('hangupcall')); // $cmd,n,Macro(user-callerid)
}






