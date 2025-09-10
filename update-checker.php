<?php

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

require 'plugin-update-checker/plugin-update-checker.php';

$myUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/sitesoft-be/gravityforms-vat-checker',
	__FILE__,
	'sitesoft-gravityforms-vat-checker',
);

$myUpdateChecker->setBranch( 'main' );

$myUpdateChecker->getVcsApi()->enableReleaseAssets();
