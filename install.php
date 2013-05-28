<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }


// Persistent Call Forwarding
$fcc = new featurecode('persistentcf', 'pcf_toggle');
$fcc->setDescription('Persistent Call Forward Toggle');
$fcc->setDefault('**740');
$fcc->update();
unset($fcc);

$fcc = new featurecode('persistentcf', 'pcf_define');
$fcc->setDescription('Persistent Call Forward Destination');
$fcc->setDefault('**741');
$fcc->update();
unset($fcc);



?>
