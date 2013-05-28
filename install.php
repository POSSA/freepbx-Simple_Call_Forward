<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }


// Define module feature codes
$fcc = new featurecode('simplecf', 'scf_toggle');
$fcc->setDescription('Toggle Simple Call Forward');
$fcc->setDefault('*741');
$fcc->update();
unset($fcc);

$fcc = new featurecode('simplecf', 'scf_define');
$fcc->setDescription('Set Simple Call Forward Destination');
$fcc->setDefault('*742');
$fcc->update();
unset($fcc);



?>
