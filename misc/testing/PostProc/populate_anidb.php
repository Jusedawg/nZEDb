<?php
require_once dirname(__FILE__) . '/../../../www/config.php';

use nzedb\db\Settings;
use nzedb\db\populate\PopulateAniDB;

$pdo = new Settings();

if (isset($argv[1]) && $argv[1] == 'true') {

	// next get the title list and populate the DB, update animetitles once a week
	(new \nzedb\db\populate\AniDB(['Settings' => $pdo, 'Echo' => true]))->_populateType('full');

} else {
	$pdo->log->doEcho(PHP_EOL . $pdo->log->error(
				"To execute this script you must provide a boolean argument." . PHP_EOL .
				"Argument1: true|false to run this script or not"), true
	);
}