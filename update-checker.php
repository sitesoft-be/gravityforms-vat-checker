<?php

require_once SITESOFT_GF_DIR . 'plugin-update-checker/plugin-update-checker.php';

$myUpdateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/sitesoft-be/gravityforms-vat-checker',
	__FILE__,
	'sitesoft-gravityforms-vat-checker',
);

$myUpdateChecker->setBranch( 'main' );

$myUpdateChecker->getVcsApi()->enableReleaseAssets();