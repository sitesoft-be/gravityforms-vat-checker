<?php

require 'plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/sitesoft-be/gravityforms-vat-checker',
    __FILE__,
    'sitesoft-gravityforms-vat-checker',
);

$myUpdateChecker->setBranch('main');

$myUpdateChecker->getVcsApi()->enableReleaseAssets();
