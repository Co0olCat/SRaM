<?php
/**
 * Same as index.php, but default to TestAndHeal action
 *
 */

include_once 'config.php';

require 'replication-monitor.class.php';

$replication = new Replication;

$replication->startup($cfg);

echo $replication->html('TestAndHeal');