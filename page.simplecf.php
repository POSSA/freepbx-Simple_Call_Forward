<?php
//Check if user is "logged in"
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

if (class_exists('cronmanager')) {
	// check to see if user has automatic updates enabled in FreePBX settings
	$cm =& cronmanager::create($db);
	$online_updates = $cm->updates_enabled() ? true : false;

	// check dev site to see if new version of module is available
	if ($online_updates && $foo = simplecf_vercheck()) {
		print "<br>A <b>new version of this module is available</b> from the <a target='_blank' href='http://pbxossa.org'>PBX Open Source Software Alliance</a><br>";
	}
} else {
	// todo: add version check for ver. 14+
}

// get version number for display in page footer
$module_local = simplecf_xml2array("modules/simplecf/module.xml");


?>
<h2>Simple Call Forwarding</h2>

This module creates two new feature codes that simplify call forwarding for the casual user. One feature code sets the Call Forward Unconditional destination once, and the other feature code toggles CF to that destination on and off. A BLF hint is created for SIP extensions.</p>
Use the Feature Codes page to see or change the Simple CF feature codes.</p></p> 

<p align="center" style="font-size:11px;">Simple Call Forwarding Module version <?php echo  $module_local[module][version];  ?><br>
This module is maintained by the developer community at the <a target="_blank" href="http://pbxossa.org"> PBX Open Source Software Alliance</a>
