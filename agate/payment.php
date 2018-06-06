<?php


include(dirname(__FILE__).'/../../config/config.inc.php');
include(dirname(__FILE__).'/agate.php');

$agate = new agate();

Tools::redirect(Context::getContext()->link->getModuleLink('agate', 'payment'));

?>
