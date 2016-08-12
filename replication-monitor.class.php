<?php

include_once(dirname(__FILE__) . '/Libs/PHPMailer/PHPMailerAutoload.php');

/**
 * Derived from:
 * PHP MySQL Master/Slave Replication Monitor
 * http://code.google.com/p/php-mysql-master-slave-replication-monitor
 *
 * Copyright (c) 2010 Alan Blount (alan[at]zeroasterisk[dot]com)
 * MIT Licensed.
 * http://www.opensource.org/licenses/mit-license.php
 *
 * @version: 1.0
 * Date: 2010.01.07 18:48 -0500
 *
 * ABOUT
 * http://code.google.com/p/php-mysql-master-slave-replication-monitor/
 * INSTALLATION
 * http://code.google.com/p/php-mysql-master-slave-replication-monitor/wiki/InstallationAndStandardUseCase
 */
class Replication
{
    /**
     * @var array
     */
    var $cfg = array();

    var $conn = array();
    var $debugs = array();
    var $errors = array();
    var $queries = array();

    var $detectedLogDB = false;

    /**
     * This is the setup method
     * @param mixed $config (optional) setup the config here
     */
    function startup($cfg = null)
    {
        $this->cfg = $cfg;

        // We are here -> $cfg has something -> check that it has required components
        foreach ($this->cfg['Servers'] As $server) {
            // Check whether you can connect to that server
            if (!$this->mysqlConnect($server['unique_id'])) {
                $this->addToErrors("Cannot connect to " . $server['unique_id'] . " using provided credentials. Please check them to continue. Thank you.");

                return false;
            }
        }

        // Check whether log DB is present and writable
        if (!$this->mysqlCheckLogDB()) {
            return false;
        }


        if (is_null($cfg)) {

            $this->addToErrors("config.php should have connection credentials and topologies. Thank you.");

            return false;
        }

        if (is_null($cfg['Topologies'])) {

            $this->addToErrors("config.php should have at least one topology. Thank you.");

            return false;
        }

        if (is_null($cfg['Servers'])) {

            $this->addToErrors("config.php should have at least one connection credential. Thank you.");

            return false;
        }

        foreach ($cfg['Topologies'] As $topology) {

            if (isset($topology['master_server_unique_id'])) {

                if (!isset($this->conn[$topology['master_server_unique_id']])) {
                    $this->addToErrors("While resolving {$topology['group']}: Cannot connect to MASTER {$topology['master_server_unique_id']}. Thank you.");

                    return false;
                }

            } else {

                $this->addToErrors("config.php: It should have at least one MASTER. Thank you.");

                return false;
            }

            if (isset($topology['slave_server_unique_id'])) {

                if (!isset($this->conn[$topology['slave_server_unique_id']])) {
                    $this->addToErrors("While resolving {$topology['group']}: Cannot connect to SLAVE {$topology['slave_server_unique_id']}. Thank you.");

                    return false;
                }

            } else {

                $this->addToErrors("config.php: It should have at least one SLAVE. Thank you.");

                return false;
            }

            if (isset($topology['active_check'])) {
                if ($topology['active_check']) {
                    // Perform check -> should be present or writable test db
                    if (!$this->mysqlCheckTestDB($topology['master_server_unique_id'])) {
                        return false;
                    }

                    if (!$this->mysqlCheckTestDB($topology['slave_server_unique_id'])) {
                        return false;
                    }
                }
            }

        }

        return true;
    }

    function addToErrors($newError, $lazy = false)
    {

        $compositeError = implode("|", array(date("Y-m-d H:i:s"), $newError));

        $this->errors[$compositeError] = $compositeError;

        if ($this->detectedLogDB
            && !$lazy
        ) {

            $logDB = $this->cfg['Log']['db'];
            $serverId = $this->cfg['Log']['unique_id'];

            foreach ($this->errors AS $error) {

                $compositeError = explode("|", $error);

                // Write accumulated errors
                $sql = "INSERT INTO `{$logDB}`.`timeline` (`timestamp`, `is_error`, `event`) VALUES ('{$compositeError[0]}', 1, '{$compositeError[1]}')";
                $result = $this->query($serverId, $sql, $logDB);

                if (!$result) {

                    $this->addToErrors("Cannot write to {$logDB}.");
                    return;
                }
            }

            $this->errors = array();
        }

    }


    function addToDebugs($newDebug, $lazy = false)
    {

        if ($this->cfg['Log']['record_debugs']) {

            $compositeDebug = implode("|", array(date("Y-m-d H:i:s"), $newDebug));

            $this->debugs[$compositeDebug] = $compositeDebug;

            if ($this->detectedLogDB
                && !$lazy
            ) {

                $logDB = $this->cfg['Log']['db'];
                $serverId = $this->cfg['Log']['unique_id'];

                foreach ($this->debugs AS $debug) {

                    $compositeDebug = explode("|", $debug);

                    // Write accumulated errors
                    $sql = "INSERT INTO `{$logDB}`.`timeline` (`timestamp`, `is_error`, `event`) VALUES ('{$compositeDebug[0]}', 0, '{$compositeDebug[1]}')";
                    $result = $this->query($serverId, $sql, $logDB);

                    if (!$result) {

                        $this->addToErrors("Cannot write to {$logDB}.");
                        return;
                    }
                }

                $this->debugs = array();
            }
        }
    }

    # ===========================================================
    # tests and automation controller
    # ===========================================================

    /**
     * @param $slaveServerId
     * @param null $status
     * @return bool
     */
    function checkSlaveStatus($slaveServerId, $status = null)
    {
        if (is_null($status)) {
            $slaveStatus = $this->query($slaveServerId, "SHOW SLAVE STATUS;");
        } else {
            $slaveStatus = $status;
        }

        $result = true;
        $result = true;

        if ($slaveStatus[0]['Slave_IO_Running'] != 'Yes') {
            $this->addToErrors("Slave_IO_Running@<b>{$slaveServerId}</b>: {$slaveStatus[0]['Slave_IO_Running']}");

            $result = false;
        }

        if ($slaveStatus[0]['Slave_SQL_Running'] != 'Yes') {
            $this->addToErrors("Slave_SQL_Running@<b>{$slaveServerId}</b>: {$slaveStatus[0]['Slave_SQL_Running']}");

            $result = false;
        }

        if ($result) {
            $this->addToDebugs('checkSlaveStatus()==true');
        }

        return $result;
    }

    /**
     * tests the replication by inserting a row on master and checking on slave
     * @return bool
     */
    function testActiveReplication($masterKey, $slaveKey)
    {
        // Check whether master has test DB

        // Check whether slave has test DB
        $results = array();
        $created = date("Y-m-d H:i:s");
        $data = md5(time() . rand(0, 100));

        $startTimer = microtime(true);

        $testDB = $this->cfg['TestDB'];

        // insert new row on master
        $sql = "INSERT INTO `{$testDB}`.`test` (`master_slave`, `created`, `data`) VALUES ('{$masterKey}_{$slaveKey}', '{$created}', '{$data}');";

        $this->query($masterKey, $sql, $testDB, false);

        // verify: select row on master
        $sql = "SELECT * FROM `{$testDB}`.`test` WHERE `master_slave` = '{$masterKey}_{$slaveKey}' AND `created` = '{$created}' AND `data` = '{$data}'";

        $rowsMaster = $results[] = $this->query($masterKey, $sql, $testDB, false);

        // Analyse results from master
        if (!is_array($rowsMaster)
            || empty($rowsMaster)
        ) {
            $this->addToErrors("Could Not Query Master@<b>{$masterKey}</b>");

            return -1;
        }

        if (count($rowsMaster) !== 1) {
            $this->addToErrors("Master@<b>{$masterKey}</b> Query Returned " . count($rowsMaster) . " rows");

            return -1;
        }

        // Need some time to let replication propagate (work)
        // select row on slave
        while (microtime(true) - $startTimer < $this->cfg['SecondsToFail']) {

            $rowsSlave = $this->query($slaveKey, $sql, $testDB, false);

            if ($rowsSlave) {
                break;
            } else {

                usleep(10000);
            }
        }

        $elapsedTime = microtime(true) - $startTimer;

        $results[] = $rowsSlave;

        // Analyse results
        if (!is_array($rowsSlave)
            || empty($rowsSlave)
        ) {
            $this->addToErrors("Could Not Query Slave@<b>{$slaveKey}</b>");

            return -1;
        }

        if (count($rowsSlave) !== 1) {
            $this->addToErrors("Slave@<b>{$slaveKey}</b> Query Returned " . count($rowsSlave) . " rows");

            return -1;
        }

        if ($rowsMaster[0]['master_slave'] != $rowsSlave[0]['master_slave']) {
            $this->addToErrors("Mismatched master_slave: master={$rowsMaster[0]['master_slave']} slave={$rowsSlave[0]['master_slave']}");

            return -1;
        }

        if ($rowsMaster[0]['created'] != $rowsSlave[0]['created']
            || $rowsMaster[0]['created'] != $created
        ) {
            $this->addToErrors("Mismatched created: master={$rowsMaster[0]['created']} slave={$rowsSlave[0]['created']} set={$created}");

            return -1;
        }

        if ($rowsMaster[0]['data'] != $rowsSlave[0]['data']
            || $rowsMaster[0]['data'] != $data
        ) {
            $this->addToErrors("Mismatched data: master={$rowsMaster[0]['data']} slave={$rowsSlave[0]['data']} set={$data}");

            return -1;
        }

        $this->addToDebugs('testReplication()==true');

        if ($elapsedTime > $this->cfg['SecondsToFail']) {
            $this->addToErrors("Finished with exceeding time: {$elapsedTime} > {$this->cfg['SecondsToFail']} sec(s)");
        }

        // Tidy up -> remove passed records
        $sql = "DELETE FROM `{$testDB}`.`test` WHERE `master_slave` = '{$masterKey}_{$slaveKey}';";

        $this->query($masterKey, $sql, $testDB);

        return round($elapsedTime, 3);
    }

    /**
     * automation contoller, does automated testing, and if failure, attempts to recover and retest
     * @return bool
     */
    function automatedTestAndHeal()
    {
        // Record here steps to heal replications
        $healing = array();

        $testDB = $this->cfg['TestDB'];

        // Topology
        foreach ($this->cfg['Topologies'] As $topology) {

            // Check Slave status
            $slaveStatus = $this->query($topology['slave_server_unique_id'], "SHOW SLAVE STATUS;", $testDB, false);

            if (!$this->checkSlaveStatus($topology['slave_server_unique_id'], $slaveStatus)) {

                // Cannot read status

                // Try to full start slave
                $this->query($topology['slave_server_unique_id'], "START SLAVE;", $testDB, false);

                $slaveStatus = $this->query($topology['slave_server_unique_id'], "SHOW SLAVE STATUS;", $testDB, false);

                if (!$this->checkSlaveStatus($topology['slave_server_unique_id'], $slaveStatus)) {

                    $healing[] = "Cannot get Slave <b>{$topology['slave_server_unique_id']}</b> status -> Trying to start Slave -> No Effect";

                    // Try to restart slave
                    $result = $this->actionRestartSlave($topology['master_server_unique_id'], $topology['slave_server_unique_id']);

                    $result = ($result) ? "Resolved" : "No Effect";

                    $healing[] = "Cannot get Slave <b>{$topology['slave_server_unique_id']}</b> status -> Trying to restart Slave -> {$result}";
                } else {
                    $healing[] = "Cannot get Slave <b>{$topology['slave_server_unique_id']}</b> status -> Trying to start Slave -> Resolved";
                }
            }

            // Note: We cannot automatically resolve replication lag

            // Check active replication if elected
            if ($topology['active_check']) {

                $activeCheckResult = $this->testActiveReplication($topology['master_server_unique_id'], $topology['slave_server_unique_id']);

                if ($activeCheckResult < 0) {

                    $this->actionResetSlave($topology['slave_server_unique_id']);

                    $activeCheckResult = $this->testActiveReplication($topology['master_server_unique_id'], $topology['slave_server_unique_id']);

                    $result = ($activeCheckResult >= 0) ? "Resolved" : "No Effect";

                    $healing[] = "Slave <b>{$topology['slave_server_unique_id']}</b> failed active check with Master <b>{$topology['master_server_unique_id']}</b> -> Trying to reset Slave -> {$result}";

                    if ($activeCheckResult < 0) {

                        $this->actionResetSlaveToMasterPosition($topology['master_server_unique_id'], $topology['slave_server_unique_id']);

                        $activeCheckResult = $this->testActiveReplication($topology['master_server_unique_id'], $topology['slave_server_unique_id']);

                        $result = ($activeCheckResult >= 0) ? "Resolved" : "No Effect";

                        $healing[] = "Slave <b>{$topology['slave_server_unique_id']}</b> failed active check with Master <b>{$topology['master_server_unique_id']}</b> -> Trying to reset Slave to Master-> {$result}";
                    }
                }
            }

            // Note: GTID should be resolved on configuration level
        }

        return $healing;
    }

# ===========================================================
# actions
# ===========================================================

    /**
     * action, restart the slave
     * @return bool [testStatus()]
     */
    function actionRestartSlave($masterServerId,
                                $slaveServerId)
    {
        if (!$this->checkSlaveStatus($slaveServerId)) {

            $this->actionResetSlave($slaveServerId);

            if (!$this->checkSlaveStatus($slaveServerId)) {

                // still failing?  Try some other positions
                $this->actionResetSlave($slaveServerId, null, 'Read_Master_Log_Pos');

                if (!$this->checkSlaveStatus($slaveServerId)) {

                    $this->actionResetSlave($slaveServerId, null, 'Relay_Log_Pos');

                    if (!$this->checkSlaveStatus($slaveServerId)) {

                        $this->actionResetSlave($slaveServerId, 'Relay_Master_Log_File');

                        if (!$this->checkSlaveStatus($slaveServerId)) {
                            // still failing?  Try from the Master
                            $this->actionResetSlaveToMasterPosition($masterServerId, $slaveServerId);
                        }
                    }
                }
            }
        }

        $this->addToDebugs('actionRestartSlave($fileKey,$posKey)==finished');

        $result = $this->checkSlaveStatus($slaveServerId);

        return $result;
    }

    /**
     * action, reset the slave - file and position from slave status
     * @param string $fileKey [Master_Log_File]
     * @param string $posKey [Exec_Master_Log_Pos]
     * @return bool [testStatus()]
     */
    function actionResetSlave($serverId,
                              $fileKey = null,
                              $posKey = null)
    {

        $fileKey = (!empty($fileKey) ? $fileKey : 'Master_Log_File');

        $posKey = (!empty($posKey) ? $posKey : 'Exec_Master_Log_Pos');

        $this->query($serverId, "STOP SLAVE;");

        $this->query($serverId, "RESET SLAVE;");

        // get info from slave
        $slaveStatus = $this->query($serverId, "SHOW SLAVE STATUS;");

        if (isset($slaveStatus[0][$fileKey])
            && !empty($slaveStatus[0][$fileKey])
            && isset($slaveStatus[0][$posKey]) && !empty($slaveStatus[0][$posKey])
        ) {
            $this->query($serverId, "CHANGE MASTER TO 
                    MASTER_LOG_FILE='{$slaveStatus[0][$fileKey]}', 
                    MASTER_LOG_POS={$slaveStatus[0][$posKey]}
                    ;");
        }

        $this->query($serverId, "START SLAVE;");

        $this->query($serverId, "START SLAVE IO_THREAD;");

        $this->query($serverId, "START SLAVE SQL_THREAD;");

        // sometimes there's a stuck record, a duplicate...
        $this->actionSkipSlaveError($serverId);

        $this->addToDebugs('actionResetSlave($fileKey,$posKey)==finished');

        $result = $this->checkSlaveStatus($serverId);

        return $result;
    }

    /**
     * action, restart the slave - file and position from master status
     * @param $masterServerId
     * @param $slaveServerId
     * @return bool [testStatus()]
     */
    function actionResetSlaveToMasterPosition($masterServerId,
                                              $slaveServerId)
    {
        $this->query($slaveServerId, "STOP SLAVE;");

        $this->query($slaveServerId, "RESET SLAVE;");

        // Get info from master
        $this->query($masterServerId, "FLUSH PRIVILEGES;");

        $this->query($masterServerId, "FLUSH TABLES WITH READ LOCK;");

        $status_master = $this->query($masterServerId, "SHOW MASTER STATUS;");

        $this->query($masterServerId, "UNLOCK TABLES;");

        if (isset($status_master[0]['File'])
            && !empty($status_master[0]['File'])
            && isset($status_master[0]['Position'])
            && !empty($status_master[0]['Position'])
        ) {
            $this->query($slaveServerId, "CHANGE MASTER TO 
                    MASTER_LOG_FILE='{$status_master[0]['File']}', 
                    MASTER_LOG_POS={$status_master[0]['Position']}
                    ;");
        }

        $this->query($slaveServerId, "START SLAVE;");
        $this->query($slaveServerId, "START SLAVE IO_THREAD;");
        $this->query($slaveServerId, "START SLAVE SQL_THREAD;");

        // Sometimes there's a stuck record, a duplicate...
        $this->actionSkipSlaveError($slaveServerId);

        $this->addToDebugs('actionResetSlaveToMasterPosition()==finished');

        $result = $this->checkSlaveStatus($slaveServerId);

        return $result;
    }

    /**
     * action, if there's a bad status, "skip one" and restart
     *    loop through this function 10 times attempting to start replication again
     *        (breaks out of loop if status is good)
     * @return bool [testStatus()]
     */
    function actionSkipSlaveError($slaveServerId)
    {

        $loop = 0;

        while (!$this->checkSlaveStatus($slaveServerId)
            && $loop < 10) {

            $this->query($slaveServerId, "FLUSH PRIVILEGES;");

            $this->query($slaveServerId, "STOP SLAVE;");

            $this->query($slaveServerId, "RESET SLAVE;");

            $this->query($slaveServerId, "SET GLOBAL SQL_SLAVE_SKIP_COUNTER=1;");

            $this->query($slaveServerId, "START SLAVE;");

            $this->query($slaveServerId, "START SLAVE IO_THREAD;");

            $this->query($slaveServerId, "START SLAVE SQL_THREAD;");

            $loop++;
            usleep(100000);
        }

        return $this->checkSlaveStatus($slaveServerId);
    }

# ===========================================================
# HTML
# ===========================================================
    /**
     * HTML user interface
     * @param string $action
     *                monitor - returns just success or failure
     *                automate - returns just success or failure, but if failure, it tries to restart and recover and re-test
     *                RestartSlave
     *                SkipStartSlaveIfBadStatus
     *                LoadDataFromMaster
     * @return string $HTML
     */
    function html($action = null)
    {
        $action = (empty($action) && isset($_GET['action']) ? $_GET['action'] : $action);

        $return = array();

        switch ($action) {
            // Set item with id to hide status
            case 'hide':
                if (isset($_GET['id'])) {
                    $timelineId = $_GET['id'];

                    $logDB = $this->cfg['Log']['db'];

                    $sql = "UPDATE `{$logDB}`.`timeline` SET `is_hidden` = 1 WHERE `id` = {$timelineId}";

                    $this->query($this->cfg['Log']['unique_id'], $sql, $logDB);

                    return;
                }
                break;

            case 'Test':

                $errors = $this->findErrors();

                $errorsCount = count($errors);

                if ($errorsCount > 0) {
                    // Send email

                    $this->reportErrorsViaEmail($errors);
                }

                return $errorsCount;

                break;

            case 'TestAndHeal':

                $errors = $this->findErrors();

                $errorsCount = count($errors);

                if ($errorsCount > 0) {

                    // Send email
                    $this->reportErrorsViaEmail($errors);

                    // Check whether there are still left attemps for current window
                    $logDB = $this->cfg['Log']['db'];
                    $serverId = $this->cfg['Log']['unique_id'];

                    $backWindow = $this->cfg['Log']['email_freq_minutes'];

                    // Count number of emails sent within # last minutes
                    $sql = "SELECT count(1) FROM `{$logDB}`.`emails` 
                            WHERE `timestamp` >= now() - INTERVAL {$backWindow} MINUTE
                            AND `message_type` = 'healing'; ";

                    $emailsCount = $this->query($serverId, $sql, $logDB, false);

                    $emailsCount = $emailsCount[0]['count(1)'] + 1;

                    $maxHealingCount = $this->cfg['Log']['max_healing_per_email_freq'];

                    if ($emailsCount <= $maxHealingCount) {

                        // Attemp to heal
                        $healing = $this->automatedTestAndHeal();

                        $errors = $this->findErrors();

                        $errorsCount = count($errors);

                        $this->reportHealingViaEmail($healing, $errors, $emailsCount);
                    }
                }

                return $errorsCount;

                break;

            case 'FullStopSlave':

                if (isset($_GET['slave'])) {
                    $slaveServerId = $_GET['slave'];

                    $this->query($slaveServerId, "STOP SLAVE;");
                } else {
                    $this->addToErrors("To stop slave it needs slave credentials");
                }

                break;

            case 'FullStartSlave':

                if (isset($_GET['slave'])) {
                    $slaveServerId = $_GET['slave'];

                    $this->query($slaveServerId, "START SLAVE;");
                } else {
                    $this->addToErrors("To start slave it needs slave credentials");
                }

                break;

            case 'SkipSlaveError':

                if (isset($_GET['slave'])) {
                    $slaveServerId = $_GET['slave'];

                    $this->actionSkipSlaveError($slaveServerId);
                } else {
                    $this->addToErrors("To stop slave it needs slave credentials");
                }

                break;

            case 'RestartSlave':

                if (isset($_GET['slave'])
                    && isset($_GET['master'])
                ) {

                    $masterServerId = $_GET['master'];
                    $slaveServerId = $_GET['slave'];

                    $this->actionRestartSlave($masterServerId, $slaveServerId);
                } else {
                    $this->addToErrors("To restart slave it needs both master and slave");
                }

                break;

            case 'ResetSlave':

                if (isset($_GET['slave'])) {
                    $slaveServerId = $_GET['slave'];

                    $this->actionResetSlave($slaveServerId);
                } else {
                    $this->addToErrors("To reset slave it needs slave credentials");
                }

                break;

            case 'ResetSlaveToMaster':

                if (isset($_GET['slave'])
                    && isset($_GET['master'])
                ) {

                    $masterServerId = $_GET['master'];
                    $slaveServerId = $_GET['slave'];

                    $this->actionResetSlaveToMasterPosition($masterServerId, $slaveServerId);
                } else {
                    $this->addToErrors("To reset slave to master it needs both master and slave");
                }

                break;

        }

        $return[] = $this->htmlRenderHeader();

        $return[] = $this->htmlRenderBody();

        $return[] = $this->htmlRenderFooter();


        if (!is_null($action)) {

            $url = $_SERVER['REQUEST_SCHEME'] . "://" . $_SERVER['SERVER_NAME'] . $_SERVER['PHP_SELF'];

            session_start();

            if (!empty($_GET)) {

                $_SESSION['got'] = $_GET;

                header("Location: {$url}");

                die;
            } else {
                if (!empty($_SESSION['got'])) {
                    $_GET = $_SESSION['got'];
                    unset($_SESSION['got']);
                }
            }
        }

        return implode("\n", $return);
    }

    function htmlRenderHeader()
    {
        return '
            <!DOCTYPE html>
                <html lang="en">
                    <head>
                        <meta charset="utf-8">
                        <meta http-equiv="X-UA-Compatible" content="IE=edge">
                        <meta name="viewport" content="width=device-width, initial-scale=1">
                        <meta name="description" content="Simple ReplicAtion Monitor">
                        <meta name="author" content="Co0olCat">
                        
                        <link rel="icon" href="favicon.ico" type="image/x-icon" />
                        <link rel="shortcut icon" href="favicon.ico" type="image/x-icon" />
                
                        <title>Simple ReplicAiton Monitor</title>
                        
                        <link rel="stylesheet" 
                              href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css" 
                              integrity="sha384-1q8mTJOASx8j1Au+a5WDVnPi2lkFfwwEAa8hDDdjZlpLegxhjVME1fgjWPGmkzs7" 
                              crossorigin="anonymous">
                              
                        
                
                    </head>   
                      
                      
                    <body>
        ';
    }

    function htmlRenderFooter()
    {
        return '                
                <script src="https://code.jquery.com/jquery-1.12.4.min.js"   
                        integrity="sha256-ZosEbRLbNQzLpnKIkEdrPv7lOy9C27hHQ+Xp8a4MxAQ="   
                        crossorigin="anonymous"></script>
                
                <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/js/bootstrap.min.js" 
                        integrity="sha384-0mSbJDEHialfmuBBQP6A4Qrprq5OVfW37PRR3j5ELqxss1yVqOtnepnHVP9aJ7xS" 
                        crossorigin="anonymous"></script>
                        
                <script>
                    $(document).ready(function(){
                        $("#right-panel-button").click(function(){
                            if ($("#right-panel").hasClass("show")) {                            
                                
                                $("#right-panel").hide();
                                
                                $("#right-panel").removeClass("show");
                                $("#right-panel").addClass("hide");
                                
                                $("#right-panel-button").removeClass("open-arrow");
                                $("#right-panel-button").addClass("close-arrow");
                            } else {                                
                                $("#right-panel").show();
                                
                                $("#right-panel").removeClass("hide");
                                $("#right-panel").addClass("show");
                                
                                $("#right-panel-button").removeClass("close-arrow");
                                $("#right-panel-button").addClass("open-arrow");
                            }                            
                        });
                    });
                </script>
        
                <style>
                    .panel {
                        width: 300px;                        
                        float:right;                        
                        background:#d9dada;
                        position:relative;                        
                        display: none;
                        margin-right: -25px;
        
                    }
                        
                    .slider-arrow {
                        padding:0px;
                        width:0px;
                        float: right;
                        /*background:#d9dada;*/
                        color:#555;
                        text-decoration:none;
                        position:relative;
                        left:0px;
                    }
                    
                    .open-arrow {
                        left: -305px;
                        display: inline-block;
                        float: right;
                    }
                    
                    .close-arrow {
                        left: 0px;
                        float: right;                        
                    }
                </style>
                
            </body>
        </html>
        ';
    }

    function htmlRenderSettings()
    {

        $htmlContent = '
            <div class="row">
				<div class="col-md-12">					
					<table class="table">
						<thead>
							<tr>
								<th>
									#
								</th>
								<th>
									Setting
								</th>
								<th>
									Value
								</th>								
							</tr>
						</thead>
						<tbody>';

        $i = 0;

        $i++;
        $htmlContent .= "<tr>
                            <td>{$i}</td>
							<td>TestDB</td>
							<td>{$this->cfg['TestDB']}</td>								
                        </tr>";

        $i++;
        $htmlContent .= "<tr>
                            <td>{$i}</td>
							<td>SecondsToFail</td>
							<td>{$this->cfg['SecondsToFail']}</td>								
                        </tr>";

        // PHPMailer
        foreach ($this->cfg['PHPMailer'] As $key => $value) {
            $i++;

            $shownValue = $value;
            if ($key == "Password") {
                $shownValue = "*****";
            } else {
                if (is_bool($value)) {
                    $shownValue = ($value) ? "true" : "false";
                } else {
                    if (is_array($value)) {
                        $shownValue = implode(", ", $value);
                    }
                }
            }

            $htmlContent .= "<tr class='info'>
                                <td>{$i}</td>
                                <td>{$key}</td>                                
                                <td>{$shownValue}</td>								
                            </tr>";
        }

        // Servers
        foreach ($this->cfg['Servers'] As $server) {
            $i++;
            $htmlContent .= "<tr class='success'>
                                <td>{$i}</td>
                                <td>Server: {$server['unique_id']}</td>
                                <td>{$server['user']}@{$server['host']}:{$server['port']}</td>								
                            </tr>";
        }

        // Topology
        foreach ($this->cfg['Topologies'] As $topology) {
            $i++;

            $checkType = ($topology['active_check']) ? "AC" : "PC";

            $htmlContent .= "<tr class='active'>
                                <td>{$i}</td>
                                <td>$checkType: {$topology['group']}</td>
                                <td>{$topology['master_server_unique_id']} >> {$topology['slave_server_unique_id']}</td>								
                            </tr>";
        }


        $htmlContent .= '
						</tbody>
					</table>
				</div>
			</div>
        ';

        return $htmlContent;
    }


    function htmlRenderBody()
    {
        $settings = $this->htmlRenderSettings();

        $mainTable = $this->htmlRenderMainTable();

        if ($this->cfg['Log']['record_debugs']) {

            $debugs = $this->htmlRenderDebugs();
        }

        $errors = $this->htmlRenderErrors();

        $htmlContent = '                        
            <!-- Modal Functions -->
            <div class="modal fade" id="modal-container-617587" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">
				<div class="modal-dialog">
					<div class="modal-content">
						<div class="modal-header">
							 
							<button type="button" class="close" data-dismiss="modal" aria-hidden="true">
								×
							</button>
							<h4 class="modal-title" id="myModalLabel">Settings</h4>
						</div>
						<div class="modal-body">' . $settings . '</div>
						<div class="modal-footer">
							 
							<button type="button" class="btn btn-default" data-dismiss="modal">
								Close
							</button>
							
						</div>
					</div>
					
				</div>
				
			</div>
			
            <div class="container-fluid">        
        
                <nav class="navbar navbar-default navbar-fixed-top" role="navigation">
                    <div class="col-md-2"></div>
                    <div class="col-md-8">
                        <div class="navbar-header">
                        
                            <a class="">
                                <img alt="SRaM" 
                                     src="sram.50.png" 
                                     href=""
                                     title="Simple ReplicAtion Monitor">
                            </a>
                        </div>                
				
				    <div class="collapse navbar-collapse" id="bs-example-navbar-collapse-1">
					    <ul class="nav navbar-nav">
						<li>
							<a href="#modal-container-617587" 
							   data-toggle="modal"
							   title="Click to see settings">Settings</a>
						</li>
						<li>						    
							<a href="https://github.com/Co0olCat/SRaM#readme"
							   target="_blank"
							   title="Click to see help @GitHub">Help</a>
						</li>						
					</ul>			
			        
			            
			            <ul class="nav navbar-nav navbar-right" style="padding-right: 15px">
                            <ul class="nav navbar-nav">
                                <li>
                                    <a href=""
                                    title="Click to refresh now">
                                        <span class=\'glyphicon glyphicon-refresh\' aria-hidden=\'true\'></span>
                                        Refresh in <div id="countdown" style="display:inline-block"></div> second(s)
                                    </a>
                                    <script>
                                    (function countdown(remaining) {
                                            if(remaining === 0)
                                                location.reload(true);
                                            document.getElementById(\'countdown\').innerHTML = remaining;
                                            setTimeout(function(){ countdown(remaining - 1); }, 1000);
                                        })(59);
                                    </script>
                                </li>                            
                            </ul>                            
                        </ul>                        
                        
					</div>
				</div>
                    <div class="col-md-2"></div>				
			    </nav>        
        
                <div class="row" style="padding-top: 72px">
                    <div class="col-md-2">                    
                    </div>
                    
                    <div class="col-md-8">
                        
                        <!-- Table -->
                        ' . $mainTable . '
                
                    </div>
                    <div class="col-md-2">
                    <!-- Right Slider -->
                        <!-- Left Slider -->
                        <span id="right-panel-button" 
                              href="javascript:void(0);" 
                              class="glyphicon glyphicon-inbox show slider-arrow" 
                              style="font-size: 25px; padding-right:25px"
                              title="Click to Show/Hide Debug and Error messages"></span>
        
                        <div class="panel" id="right-panel">';

        if ($this->cfg['Log']['record_debugs']) {
            $htmlContent .= '
                            <div class="tabbable" id="tabs-debugs">
                                <ul class="nav nav-tabs">
                                <li class="active">
                                    <a href="#panel-debug" data-toggle="tab">DEBUG</a>
                                </li>
                                <li>
                                    <a href="#panel-errors" data-toggle="tab">ERRORS</a>
                                </li>
                                </ul>
                                <div class="tab-content">
                                    <div class="tab-pane active" id="panel-debug">
                                        <p>
                                         <!-- Debugs -->
                                        ' . $debugs . '
                                        </p>
                                    </div>
                                    <div class="tab-pane" id="panel-errors">
                                        <p>
                                        <!-- Errors -->
                                        ' . $errors . '
                                        </p>
                                    </div>
                                </div>
                            </div>';
        } else {
            $htmlContent .= '
                            <div>
                                <p>
                                <!-- Errors -->
                                ' . $errors . '
                                </p>
                            </div>';
        }
        $htmlContent .= '
                        </div>                              
                    </div>
                </div>
            </div>
        ';

        $htmlContent .= '
        
    ';

        return $htmlContent;

    }

    function htmlRenderDebugs()
    {
        $htmlAlerts = '
            <div class="row">
				<div class="col-md-12">';

        $allDebugs = array();

        $suffix = 1;

        foreach ($this->debugs As $debug) {
            $complexDebug = explode("|", $debug);

            if (isset($allDebugs[$complexDebug[0]])) {
                $allDebugs[$complexDebug[0] . "." . $suffix] = $complexDebug[1];

                $suffix++;
            } else {
                $allDebugs[$complexDebug[0]] = $complexDebug[1];
            }
        }

        $logDB = $this->cfg['Log']['db'];
        $serverId = $this->cfg['Log']['unique_id'];

        $sql = "SELECT * FROM `{$logDB}`.`timeline` WHERE `is_hidden` = 0  AND `is_error` = 0 ORDER BY `timestamp` DESC";

        $storedDebugs = $this->query($serverId, $sql, $logDB);

        foreach ($storedDebugs As $debug) {

            $event = $debug['id'] . "|" . $debug['event'];

            if (isset($allDebugs[$debug['timestamp']])) {
                $allDebugs[$debug['timestamp'] . "." . $suffix] = $event;

                $suffix++;
            } else {
                $allDebugs[$debug['timestamp']] = $event;
            }
        }

        // Check whether there are debug messages
        if (count($allDebugs) > 0) {
            foreach ($allDebugs As $key => $item) {

                $compexDebug = explode("|", $item);

                if ($compexDebug[0] == "") {
                    $htmlAlerts .= '
                                        <div class="alert">					 
                                            
                                            ' . $key . ': ' . $item . '
                                        </div >
                                        ';
                } else {
                    $htmlAlerts .= '
                                        <div class="alert alert-dismissable">						 
                                            <button 
                                                type="button"
                                                class="close glyphicon glyphicon-eye-close" 
                                                data-dismiss="alert" 
                                                aria-hidden="true"
                                                onclick="$.get(\'index.php?action=hide&id=' . $compexDebug[0] . '\')"></button>
                                            ' . $key . ': ' . $compexDebug[1] . '
                                        </div >
                                        ';
                }
            }
        }

        $htmlAlerts .= '					
				</div>
			</div>
        ';

        return $htmlAlerts;
    }

    function htmlRenderErrors()
    {
        $htmlAlerts = '
            <div class="row">
				<div class="col-md-12">';

        $allErrors = array();

        $suffix = 1;

        foreach ($this->errors As $error) {
            $complexError = explode("|", $error);

            $event = "|" . $complexError[1];

            if (isset($allErrors[$complexError[0]])) {
                $allErrors[$complexError[0] . "." . $suffix] = $event;

                $suffix++;
            } else {
                $allErrors[$complexError[0]] = $event;
            }
        }

        $logDB = $this->cfg['Log']['db'];
        $serverId = $this->cfg['Log']['unique_id'];

        $sql = "SELECT * FROM `{$logDB}`.`timeline` WHERE `is_hidden` = 0  AND `is_error` = 1 ORDER BY `timestamp` DESC";

        $storedErrors = $this->query($serverId, $sql, $logDB);

        foreach ($storedErrors As $error) {

            $event = $error['id'] . "|" . $error['event'];

            if (isset($allErrors[$error['timestamp']])) {
                $allErrors[$error['timestamp'] . "." . $suffix] = $event;

                $suffix++;
            } else {
                $allErrors[$error['timestamp']] = $event;
            }
        }

        // Check whether there are error messages
        if (count($allErrors) > 0) {
            foreach ($allErrors As $key => $item) {

                $complexError = explode("|", $item);

                if ($complexError[0] == "") {
                    $htmlAlerts .= '
                                        <div class="alert">					 
                                            
                                            ' . $key . ': ' . $item . '
                                        </div >
                                        ';
                } else {
                    $htmlAlerts .= '
                                        <div class="alert alert-dismissable">						 
                                            <button 
                                                type="button"
                                                class="close glyphicon glyphicon-eye-close" 
                                                data-dismiss="alert" 
                                                aria-hidden="true"
                                                onclick="$.get(\'index.php?action=hide&id=' . $complexError[0] . '\')"></button>
                                            ' . $key . ': ' . $complexError[1] . '
                                        </div >
                                        ';
                }
            }
        }



        $htmlAlerts .= '					
				</div>
			</div>
        ';

        return $htmlAlerts;
    }

    function htmlRenderModalScript($uid, $modalTitle, $code)
    {

        $htmlContent = '
			<div class="modal fade" id="modal-container-' . $uid . '" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">
				<div class="modal-dialog">
					<div class="modal-content">
						<div class="modal-header">							 
							<button type="button" class="close" data-dismiss="modal" aria-hidden="true">
                                ×
							</button>
							<h4 class="modal-title" id="myModalLabel">' . $modalTitle . '</h4>
						</div>
						<div class="modal-body">' . $code . '</div>
						<div class="modal-footer">							 
							<button type="button" class="btn btn-default" data-dismiss="modal">
							    Close
							</button>
						</div>
					</div>					
				</div>				
			</div>
			';

        return $htmlContent;
    }

    function htmlRenderStatusTable($status = array())
    {

        $htmlContent = '';

        if (empty($status)) {
            $htmlContent .= 'Status is empty';
        } else {

            $htmlContent .= '
                					
                        <table class="table">
                            <thead>
                                <tr>								
                                    <th style="text-align: right">Item</th>
                                    <th>Value</th>								
                                </tr>
                            </thead>
                            <tbody>';

            foreach ($status As $key => $value) {

                $htmlContent .= "<tr>                                
                                    <td style='text-align: right'>{$key}</td>
                                    <td>{$value}</td>                                
                                </tr>";
            }


            $htmlContent .= '
                            </tbody>
                        </table>                
            ';
        }

        return $htmlContent;
    }

    function findErrors()
    {
        $errors = array();

        // Topology
        foreach ($this->cfg['Topologies'] As $topology) {

            // Get Slave status
            $slaveStatus = $this->query($topology['slave_server_unique_id'], "SHOW SLAVE STATUS;");

            if (!$this->checkSlaveStatus($topology['slave_server_unique_id'], $slaveStatus)) {
                $errors[] = "Cannot get Slave <b>{$topology['slave_server_unique_id']}</b> status.";
            }

            if (!empty($slaveStatus)) {

                if (!is_null($slaveStatus[0]['Seconds_Behind_Master'])) {
                    if ($slaveStatus[0]['Seconds_Behind_Master'] > $this->cfg['SecondsToFail']) {

                        $behindInSeconds = $slaveStatus[0]['Seconds_Behind_Master'] - $this->cfg['SecondsToFail'];

                        $errors[] = "Slave <b>{$topology['slave_server_unique_id']}</b> exceeds by {$behindInSeconds} sec(s) threshold of {$this->cfg['SecondsToFail']} to be behind master.";
                    }
                }
            }

            if ($topology['active_check']) {

                // Check active replication
                $activeCheckResult = $this->testActiveReplication($topology['master_server_unique_id'], $topology['slave_server_unique_id']);

                if ($activeCheckResult < 0) {
                    $errors[] = "Slave <b>{$topology['slave_server_unique_id']}</b> failed active check with Master <b>{$topology['master_server_unique_id']}</b>.";
                }
            }

            $masterGlobalGTIDMode = $this->query($topology['master_server_unique_id'], "SELECT `variable_value` FROM `information_schema`.`global_variables` WHERE VARIABLE_NAME = 'gtid_mode';");
            $slaveGlobalGTIDMode = $this->query($topology['slave_server_unique_id'], "SELECT `variable_value` FROM `information_schema`.`global_variables` WHERE VARIABLE_NAME = 'gtid_mode';");


            if ($masterGlobalGTIDMode[0]["variable_value"] != $slaveGlobalGTIDMode[0]["variable_value"]) {
                $errors[] = "Master <b>{$topology['master_server_unique_id']}</b> and Slave <b>{$topology['slave_server_unique_id']}</b> have different GTID modes: {$masterGlobalGTIDMode} != {$slaveGlobalGTIDMode}.";
            }
        }

        return $errors;
    }

    function htmlRenderMainTable()
    {
        $htmlContent = '
            <div class="row">
				<div class="col-md-12">
					<h3>Replications</h3>					
					<table class="table">
						<thead>
							<tr>
								<th>#</th>
								<th style="text-align: center">Group</th>
								<th style="text-align: center">Check</th>								
								<th style="text-align: center">Master</th>
								<th style="text-align: center">Health</th>
								<th style="text-align: center">(P)Behind</th>
								<th style="text-align: center">(A)Check Lag</th>
								<th style="text-align: center">GTID</th>								
								<th style="text-align: center">Slave</th>								
								<th style="text-align: center">Actions</th>
							</tr>
						</thead>
						<tbody>';

        $i = 0;

        $htmlModalPart = '';

        // Topology
        foreach ($this->cfg['Topologies'] As $topology) {
            $i++;

            // Get Master status
            $masterStatus = $this->query($topology['master_server_unique_id'], "SHOW MASTER STATUS;");
            $masterButtonUid = uniqid();

            $htmlModalPart .= $this->htmlRenderModalScript($masterButtonUid,
                "Master <strong style='text-transform: uppercase'>{$topology['master_server_unique_id']}</strong> Status",
                $this->htmlRenderStatusTable($masterStatus[0]));

            // Get Slave status
            $slaveStatus = $this->query($topology['slave_server_unique_id'], "SHOW SLAVE STATUS;");
            $slaveButtonUid = uniqid();

            $htmlModalPart .= $this->htmlRenderModalScript($slaveButtonUid,
                "Slave <strong style='text-transform: uppercase'>{$topology['slave_server_unique_id']}</strong> Status",
                $this->htmlRenderStatusTable($slaveStatus[0]));

            if ($this->checkSlaveStatus($topology['slave_server_unique_id'], $slaveStatus)) {
                $buttonStyle = "btn-success";
            } else {
                $buttonStyle = "btn-danger";
            }

            $replicationHealth = "";
            $replicationBehind = "";
            $replicationLag = "";

            if (empty($slaveStatus)) {
                $replicationHealth .= "<span class='glyphicon glyphicon-ban-circle alert-danger' 
                                             aria-hidden='true'
                                             title='There are issues with replication'></span>";
            } else {

                if (!is_null($slaveStatus[0]['Seconds_Behind_Master'])) {
                    $replicationHealth .= "<span class='glyphicon glyphicon-heart alert-success' 
                                                 aria-hidden='true'
                                                 title='Replication looks to be healthy'></span>";

                    $replicationBehind .= " {$slaveStatus[0]['Seconds_Behind_Master']} sec(s)";
                } else {
                    $replicationHealth .= "<span class='glyphicon glyphicon-heart alert-danger' 
                                                 aria-hidden='true'
                                                 title='There are issues with replication'></span>";
                }
            }

            if ($topology['active_check']) {
                $checkType = "<div title='Active Check'><span style='color: #1dc116'>A</span>C</div>";

                // Check active replication
                $activeCheckResult = $this->testActiveReplication($topology['master_server_unique_id'], $topology['slave_server_unique_id']);

                if ($activeCheckResult < 0) {
                    $replicationHealth = "<span class='glyphicon glyphicon-ban-circle alert-danger' 
                                                aria-hidden='true'
                                                title='Replication has failed active check'></span>";
                } else {
                    $replicationHealth = "<span class='glyphicon glyphicon-heart alert-success' 
                                                aria-hidden='true'
                                                title='Replication has passed active check'></span>";
                    $replicationLag .= "{$activeCheckResult} sec(s)";
                }

            } else {
                $checkType = "<div title='Passive Check'><span style='color: #4a8cdb'>P</span>C</div>";
            }

            $gtidMode = "";

            $masterGlobalGTIDMode = $this->query($topology['master_server_unique_id'], "SELECT `variable_value` FROM `information_schema`.`global_variables` WHERE VARIABLE_NAME = 'gtid_mode';");
            $slaveGlobalGTIDMode = $this->query($topology['slave_server_unique_id'], "SELECT `variable_value` FROM `information_schema`.`global_variables` WHERE VARIABLE_NAME = 'gtid_mode';");

            // Parse GTID modes

            if (isset($masterGlobalGTIDMode[0]["variable_value"])) {
                if ($masterGlobalGTIDMode[0]["variable_value"] == "ON") {
                    $gtidMode .= "<a
                                        type='button'
                                        style='color: green;'
                                        class='btn btn-default btn-xs' 
                                        aria-label='Left Align' 
                                        title='GTID@{$topology['master_server_unique_id']} is ON.'>
                                        
                                        <span class='glyphicon glyphicon-globe' aria-hidden='true'></span>
                                    </a>";
                } else {
                    $gtidMode .= "<a 
                                        type='button' 
                                        style='color: red;'
                                        class='btn btn-default btn-xs' 
                                        aria-label='Left Align' 
                                        title='GTID@{$topology['master_server_unique_id']} is OFF.'>
                                        
                                        <span class='glyphicon glyphicon-globe' aria-hidden='true'></span>
                                    </a>";
                }
            }

            if (isset($slaveGlobalGTIDMode[0]["variable_value"])) {
                if ($slaveGlobalGTIDMode[0]["variable_value"] == "ON") {
                    $gtidMode .= "<a
                                        type='button'
                                        style='color: green;'
                                        class='btn btn-default btn-xs' 
                                        aria-label='Left Align' 
                                        title='GTID@{$topology['slave_server_unique_id']} is ON.'>
                                        
                                        <span class='glyphicon glyphicon-globe' aria-hidden='true'></span>
                                    </a>";
                } else {
                    $gtidMode .= "<a 
                                        type='button' 
                                        style='color: red;'
                                        class='btn btn-default btn-xs' 
                                        aria-label='Left Align' 
                                        title='GTID@{$topology['slave_server_unique_id']} is OFF.'>
                                        
                                        <span class='glyphicon glyphicon-globe' aria-hidden='true'></span>
                                    </a>";
                }
            }

            $htmlContent .= "<tr class='active'>
                                <td>{$i}</td>
                                <td style='text-align: center'>{$topology['group']}</td>
                                <td style='text-align: center'>{$checkType}</td>                                
                                <td>
                                    <a id='modal-{$masterButtonUid}' 
                                       href='#modal-container-{$masterButtonUid}' 
                                       role='button' 
                                       class='btn btn-default btn-xs' 
                                       style='width: 100%'
                                       title='Click to see status'
                                       data-toggle='modal'>{$topology['master_server_unique_id']}</a>
                                </td>
                                <td style='text-align: center'>{$replicationHealth}</td>
                                <td style='text-align: center'>{$replicationBehind}</td>
                                <td style='text-align: center'>{$replicationLag}</td>
                                <td style='text-align: center'>{$gtidMode}</td>
                                <td style='text-align: center'>
                                    <a id='modal-{$slaveButtonUid}' 
                                       href='#modal-container-{$slaveButtonUid}' 
                                       role='button' 
                                       class='btn btn-default btn-xs {$buttonStyle}'
                                       style='width: 100%'
                                       title='Click to see status'
                                       data-toggle='modal'>{$topology['slave_server_unique_id']}</a>
                                </td>                                
                                <td style='text-align: center'>
                                
                                    <!-- Stop Slave -->
                                    <a 
                                        type='button' 
                                        class='btn btn-default btn-xs' 
                                        aria-label='Left Align' 
                                        title='Full Stop Slave'
                                        href='?action=FullStopSlave&slave={$topology['slave_server_unique_id']}'>
                                        
                                        <span class='glyphicon glyphicon-off' aria-hidden='true'></span>
                                    </a>
                                    
                                    <!-- Start Slave -->
                                    <a 
                                        type='button' 
                                        class='btn btn-default btn-xs' 
                                        aria-label='Left Align' 
                                        title='Full Start Slave'
                                        href='?action=FullStartSlave&slave={$topology['slave_server_unique_id']}'>
                                        
                                        <span class='glyphicon glyphicon-fire' aria-hidden='true'></span>
                                    </a>
                                    
                                    <!-- Restart Slave -->
                                    <a 
                                        type='button' 
                                        class='btn btn-default btn-xs' 
                                        aria-label='Left Align' 
                                        title='Restart Slave'
                                        href='?action=RestartSlave&master={$topology['master_server_unique_id']}&slave={$topology['slave_server_unique_id']}'>
                                        
                                        <span class='glyphicon glyphicon-repeat' aria-hidden='true'></span>
                                    </a>
                                    
                                    <!-- Skip Replication Error -->
                                    <a 
                                        type='button' 
                                        class='btn btn-default btn-xs' 
                                        aria-label='Left Align' 
                                        title='Skip Slave Replication Error'
                                        href='?action=SkipSlaveError&master={$topology['master_server_unique_id']}&slave={$topology['slave_server_unique_id']}'>
                                        
                                        <span class='glyphicon glyphicon-forward' aria-hidden='true'></span>
                                    </a>
                                                                       
                                    <!-- Reset Slave -->
                                    <a 
                                        type='button' 
                                        class='btn btn-default btn-xs' 
                                        aria-label='Left Align' 
                                        title='Reset Slave'
                                        href='?action=ResetSlave&slave={$topology['slave_server_unique_id']}'>
                                        
                                        <span class='glyphicon glyphicon-flash' aria-hidden='true'></span>
                                    </a>
                                    
                                    <!-- Reset Slave -->
                                    <a 
                                        type='button' 
                                        class='btn btn-default btn-xs' 
                                        aria-label='Left Align' 
                                        title='Reset Slave to Master'
                                        href='?action=ResetSlaveToMaster&master={$topology['master_server_unique_id']}&slave={$topology['slave_server_unique_id']}'>
                                        
                                        <span class='glyphicon glyphicon-transfer' aria-hidden='true'></span>
                                    </a>
                                    
                                </td>
                            </tr>";
        }

        $htmlContent .= '
						</tbody>
					</table>
				</div>
			</div>
        ';

        $htmlContent .= $htmlModalPart;

        return $htmlContent;
    }


    /**
     * HTML returns logged errors in an easy to read manner... also returns debugs
     * @return string $HTML
     */
    function htmReturnErrors()
    {
        if (!empty($this->errors)) {
            $return = array("<hr/>ERRORS: <pre>");
            foreach ($this->errors as $error) {
                if (is_string($error)) {
                    $return[] = $error;
                } else {
                    $return[] = var_export($error, true);
                }
            }
            $return[] = "</pre>";
            $return[] = $this->htmReturnDebugs();
            return implode("\n\n", $return);
        }
        return '';
    }

    /**
     * HTML returns logged debugs in an easy to read manner...
     * @return string $HTML
     */
    function htmReturnDebugs()
    {
        if (!empty($this->debugs)) {
            $return = array("<hr/>DEBUGS: <pre style='font-size:80%;'>");
            foreach ($this->debugs as $debug) {
                if (is_string($debug)) {
                    $return[] = $debug;
                } else {
                    $return[] = var_export($debug, true);
                }
            }
            $return[] = "</pre>";
            return implode("\n\n", $return);
        }
        return '';
    }

# ===========================================================
# helpers
# ===========================================================
    function mysqlConnect($serverId)
    {
        // Get credentials from config

        $bThereIsMatch = false;

        $currentServer = array();

        foreach ($this->cfg['Servers'] As $server) {
            if ($server['unique_id'] == $serverId) {
                $currentServer = $server;
                $bThereIsMatch = true;
                break;
            }
        }

        if (!$bThereIsMatch) {

            $this->addToErrors("Cannot find credentials for $serverId");

            return false;
        }

        $this->conn[$serverId] = mysqli_connect($currentServer['host'], $currentServer['user'], $currentServer['pwd'], '', $currentServer['port']);

        if (!$this->conn[$serverId]) {

            $this->addToErrors("Cannot connect to $serverId: {$currentServer['user']}@{$currentServer['host']}:{$currentServer['port']} " . mysqli_error() . "[" . mysqli_errno() . "]");

            var_export($this->conn[$serverId]);

            return false;
        }

        $this->addToDebugs("Connected to <b>{$serverId}</b> using {$currentServer['user']}@{$currentServer['host']}:{$currentServer['port']}.");

        return true;
    }

    function mysqlCheckLogDB()
    {

        if (!isset($this->cfg['Log']['db'])) {
            $this->addToErrors("Name of Log DB is not defined.");

            return false;
        }

        if (!isset($this->cfg['Log']['unique_id'])) {
            $this->addToErrors("Unique_id of host for Log DB is not defined.");

            return false;
        }

        $logDB = $this->cfg['Log']['db'];
        $serverId = $this->cfg['Log']['unique_id'];

        // Check whether you can connect to server hosting log db
        if (!$this->mysqlConnect($serverId)) {
            $this->addToErrors("Cannot connect to {$serverId} using provided credentials. Please check them to continue. Thank you.");

            return false;
        }

        $result = $this->query($serverId, "SELECT COUNT(*) AS `exists` FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMATA.SCHEMA_NAME='{$logDB}'");

        // Check whether Log DB is present
        if ($result[0]['exists'] != 1) {

            // Create TestDB
            $result = $this->query($serverId, "CREATE DATABASE `{$logDB}`;");

            if (!is_array($result)) {
                $this->addToErrors("Unable to create TestDB {$logDB} [" . mysqli_errno() . "]: " . mysqli_error());

                return false;
            }
        }

        $this->addToDebugs("Detected at <b>{$serverId}</b> log DB {$logDB}.");

        // Check whether TIMELINE table is present
        $result = $this->query($serverId, "SELECT * FROM `{$logDB}`.`timeline`;");

        if (!is_array($result)) {
            $sql = "
                CREATE TABLE IF NOT EXISTS `{$logDB}`.`timeline` 
                    (`id` int(11) NOT NULL AUTO_INCREMENT,                    
                     `timestamp` datetime NOT NULL,
                     `is_error` INT(1) NOT NULL,
                     `is_hidden` INT(1) DEFAULT 0,
                     `event` VARCHAR (500) NOT NULL,                     
                     PRIMARY KEY (id)
                     ) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;";

            $result = $this->query($serverId, $sql, $logDB);

            if (!is_array($result)) {

                $this->addToErrors("Unable to create table `{$logDB}`.`timeline` [" . mysqli_errno() . "]: " . mysqli_error());

                return false;
            }
        }

        $this->detectedLogDB = true;

        $this->addToDebugs("Detected at <b>{$serverId}</b> table `{$logDB}`.`timeline`.");

        // Check emails table is present
        $result = $this->query($serverId, "SELECT * FROM `{$logDB}`.`emails`;");

        if (!is_array($result)) {
            $sql = "
                CREATE TABLE IF NOT EXISTS `{$logDB}`.`emails` 
                    (`id` int(11) NOT NULL AUTO_INCREMENT,                    
                     `timestamp` datetime NOT NULL,
                     `status` VARCHAR(512) NOT NULL,
                     `message_type` VARCHAR(32),
                     `subject` VARCHAR(512) NOT NULL,
                     `message` LONGTEXT,
                     `email_hash` VARCHAR(32),
                     PRIMARY KEY (id)
                     ) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;";

            $result = $this->query($serverId, $sql, $logDB);

            if (!is_array($result)) {

                $this->addToErrors("Unable to create table `{$logDB}`.`emails` [" . mysqli_errno() . "]: " . mysqli_error());

                return false;
            }
        }

        $this->detectedLogDB = true;

        $this->addToDebugs("Detected at <b>{$serverId}</b> table `{$logDB}`.`emails`.");

        return true;

    }

    function mysqlCheckTestDB($serverId)
    {

        if (!isset($this->conn[$serverId])) {
            $this->addToErrors("Cannot find credentials for $serverId.");

            return false;
        }

        if (!isset($this->cfg['TestDB'])) {
            $this->addToErrors("Name of TestDB is not defined.");

            return false;
        }

        $testDB = $this->cfg['TestDB'];

        $result = $this->query($serverId, "SELECT COUNT(*) AS `exists` FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMATA.SCHEMA_NAME='{$testDB}'");

        // Check whether Log DB is present
        if ($result[0]['exists'] != 1) {

            // Create TestDB
            $result = $this->query($serverId, "CREATE DATABASE `{$testDB}`;");

            if (!is_array($result)) {
                $this->addToErrors("Unable to create TestDB {$testDB} [" . mysqli_errno() . "]: " . mysqli_error());

                return false;
            }
        }

        $this->addToDebugs("Detected at <b>{$serverId}</b> service DB {$testDB}.");

        $result = $this->query($serverId, "SELECT * FROM `{$testDB}`.`test`;");

        if (!is_array($result)) {
            $sql = "
                CREATE TABLE IF NOT EXISTS `{$testDB}`.`test` 
                    (`master_slave` varchar(50) NOT NULL,
                     `created` datetime NOT NULL,
                     `data` varchar(32) NOT NULL
                     ) ENGINE=InnoDB  DEFAULT CHARSET=latin1 AUTO_INCREMENT=1;";

            $result = $this->query($serverId, $sql);

            if (!is_array($result)) {

                $this->addToErrors("Unable to create table Test in {$testDB} [" . mysqli_errno() . "]: " . mysqli_error());

                return false;
            }
        }

        $this->addToDebugs("Detected at <b>{$serverId}</b> table `{$testDB}`.`test`.");

        return true;

    }

    function query($key, $sql, $db = null, $lazy = true)
    {

        if ($lazy) {
            // Check map
            if (isset($this->queries[$key . ":" . $sql])) {
                return $this->queries[$key . ":" . $sql];
            }
        }

        if (is_null($db)) {
            $db = $this->cfg['TestDB'];
        }

        mysqli_select_db($this->conn[$key], $db);

        $result = mysqli_query($this->conn[$key], $sql);

        if (!$result) {
            $this->addToErrors("Could not successfully run query [$key] {{$sql}} - " . mysqli_error() . "[" . mysqli_errno() . "]");

            return false;
        }

        if (strtolower(substr($sql, 0, 6)) == "insert") {

            $return = mysqli_insert_id($this->conn[$key]);


            return $return;
        }

        if (in_array(strtolower(substr($sql, 0, 6)), array("update", "change")) ||
            in_array(strtolower(substr($sql, 0, 5)), array("start", "reset", "stop ", "set g", "load "))
        ) {
            $return = mysqli_affected_rows($this->conn[$key]);

            return $return;
        }

        if (mysqli_num_rows($result) == 0) {

            return array();
        }

        $return = array();

        while ($row = mysqli_fetch_assoc($result)) {
            $return[] = $row;
        }

        mysqli_free_result($result);

        return $return;
    }

    function reportErrorsViaEmail($errors) {

        $errorsCount = count($errors);

        $subject = "Detected {$errorsCount} error(s) in SRaM";

        $message = "<h2>{$errorsCount} Error(s) were Detected:</h2>";

        foreach ($errors As $error) {
            $message .= "<p>{$error}</p>";
        }

        $emailHash = md5($subject . $message);

        // Get logged messages for last # minutes

        $backWindow = $this->cfg['Log']['email_freq_minutes'];

        $logDB = $this->cfg['Log']['db'];
        $serverId = $this->cfg['Log']['unique_id'];

        $sql = "SELECT * FROM `{$logDB}`.`timeline` 
                WHERE `timestamp` >= now() - INTERVAL {$backWindow} MINUTE                
                ORDER BY `timestamp` DESC;";

        $logs = $this->query($serverId, $sql, $logDB, false);

        $logsCount = count($logs);

        $message .= "<h3>{$logsCount} Record(s) from Logs for Last {$backWindow} Minute(s):</h3>";

        foreach ($logs As $log) {
            $style = ($log['is_error']) ? "style=\"color:red\"" : "style=\"color:black\"";

            $message .= "<p {$style}>{$log['timestamp']} > {$log['event']}</p>";
        }

        // Count number of emails sent within # last minutes
        $sql = "SELECT count(1) FROM `{$logDB}`.`emails` 
                WHERE `timestamp` >= now() - INTERVAL {$backWindow} MINUTE
                AND `message_type` = 'errors'; ";

        $emailsCount = $this->query($serverId, $sql, $logDB, false);

        if ($emailsCount[0]['count(1)'] == "0") {
            // Ok -> no emails in the last # minutes
            // Check whether similar email was already sent in 2*# minutes
            $doubleBackWindow = 2 * $backWindow;

            $sql = "SELECT count(1) FROM `{$logDB}`.`emails` 
                    WHERE `email_hash` = '{$emailHash}' 
                    AND `timestamp` >= now() - INTERVAL {$doubleBackWindow} MINUTE
                    AND `message_type` = 'errors'; ";

            $matchingEmailsCount = $this->query($serverId, $sql, $logDB, false);

            if ($matchingEmailsCount[0]['count(1)'] == "0") {

                // Looks to be a new one -> send it
                $this->sendMailSSL("errors", $subject, $message, $emailHash);
            }
        }
    }

    function reportHealingViaEmail($healing, $errors, $emailsCount) {

        $healingCount = count($healing);
        $errorsCount = count($errors);

        $logDB = $this->cfg['Log']['db'];
        $serverId = $this->cfg['Log']['unique_id'];

        $backWindow = $this->cfg['Log']['email_freq_minutes'];

        $maxHealingCount = $this->cfg['Log']['max_healing_per_email_freq'];

        $subject = "Healing ({$emailsCount}/{$maxHealingCount}/{$backWindow}) with {$healingCount} step(s) > Now detected {$errorsCount} error(s) in SRaM";

        $message = "<h2>{$healingCount} Step(s) were Taken to Heal:</h2>";

        foreach ($healing As $healStep) {
            $message .= "<p>{$healStep}</p>";
        }

        $message .= "<h2>Now {$errorsCount} Error(s) were Detected:</h2>";

        foreach ($errors As $error) {
            $message .= "<p>{$error}</p>";
        }

        $emailHash = md5($subject . $message);

        // Get logged messages for last # minutes

        $sql = "SELECT * FROM `{$logDB}`.`timeline` 
                WHERE `timestamp` >= now() - INTERVAL {$backWindow} MINUTE                
                ORDER BY `timestamp` DESC;";

        $logs = $this->query($serverId, $sql, $logDB, false);

        $logsCount = count($logs);

        $message .= "<h3>{$logsCount} Record(s) from Logs for Last {$backWindow} Minute(s):</h3>";

        foreach ($logs As $log) {
            $style = ($log['is_error']) ? "style=\"color:red\"" : "style=\"color:black\"";

            $message .= "<p {$style}>{$log['timestamp']} > {$log['event']}</p>";
        }

        if ($emailsCount <= $maxHealingCount) {

            // Ok -> There is at least one attemp left

            $this->sendMailSSL("healing", $subject, $message, $emailHash);
        }
    }

    function sendMailSSL($messageType, $subject, $message, $emailHash = null)
    {

        $mail = new PHPMailer;

        $mail->isSMTP();                                      // Set mailer to use SMTP
        $mail->Host = $this->cfg['PHPMailer']['Host'];
        $mail->SMTPAuth = $this->cfg['PHPMailer']['SMTPAuth'];
        $mail->Username = $this->cfg['PHPMailer']['Username'];
        $mail->Password = $this->cfg['PHPMailer']['Password'];
        $mail->SMTPSecure = $this->cfg['PHPMailer']['SMTPSecure'];
        $mail->Port = $this->cfg['PHPMailer']['Port'];
        $mail->setFrom($this->cfg['PHPMailer']['setFrom'][0], $this->cfg['PHPMailer']['setFrom'][1]);
        $mail->addAddress($this->cfg['PHPMailer']['addAddress'][0], $this->cfg['PHPMailer']['addAddress'][1]);
        $mail->isHTML($this->cfg['PHPMailer']['isHTML']);
        $mail->Subject = $subject;
        $mail->Body = $message;
        $mail->AltBody = trim(strip_tags($message));

        if (!$mail->send()) {
            $status = 'Mailer Error: ' . $mail->ErrorInfo;

            $this->addToErrors($status);
        } else {
            $status = 'Sent';

            $this->addToDebugs($status);
        }

        // Add to DB record
        $logDB = $this->cfg['Log']['db'];
        $serverId = $this->cfg['Log']['unique_id'];

        $currentTimestamp = date("Y-m-d H:i:s");

        if (is_null($emailHash)) {
            $emailHash = md5($subject . $message);
        }

        $sql = "INSERT INTO `{$logDB}`.`emails` (`timestamp`, `status`, `message_type`, `subject`, `message`, `email_hash`) 
                VALUES ('{$currentTimestamp}', '{$status}', '{$messageType}','{$subject}', '{$message}', '{$emailHash}');";

        $this->query($serverId, $sql, $logDB, false);
    }
}

?>
