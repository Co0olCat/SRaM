<?php
/**
 * Created by PhpStorm.
 * User: user
 * Date: 24/06/16
 * Time: 4:05 PM
 */

$i = 0;

$cfg = array();

// Name of Log DB for keeping activity logs
$cfg['Log']['db'] = "sram_log";
$cfg['Log']['unique_id'] = 'robin';
$cfg['Log']['record_debugs'] = false;   // Should debug messages be recorded and displayed
$cfg['Log']['email_freq_minutes'] = 60; // Do not send messages more othen than that
$cfg['Log']['max_healing_per_email_freq'] = 3; // Do not run more than # healing processes in # minutes

// Name of Test DB for active replication -> if not present
// it will be created automatically
$cfg['TestDB'] = "util_replication";
$cfg['SecondsToFail'] = 10; // Number of seconds to consider replication failure

// Mail configuration
$cfg['PHPMailer']['Host'] = 'host_name';  // Specify main and backup SMTP servers
$cfg['PHPMailer']['SMTPAuth'] = true;                               // Enable SMTP authentication
$cfg['PHPMailer']['Username'] = 'user_name';                 // SMTP username
$cfg['PHPMailer']['Password'] = 'my_pwd';                           // SMTP password
$cfg['PHPMailer']['SMTPSecure'] = 'ssl';                            // Enable TLS encryption, `ssl` also accepted
$cfg['PHPMailer']['Port'] = 465;                                    // TCP port to connect to
$cfg['PHPMailer']['setFrom'] = array('from@mail.net', 'SRaM');
$cfg['PHPMailer']['addAddress'] = array('to@mail.net', 'Your Name');     // Add a recipient
$cfg['PHPMailer']['isHTML'] = true;

// First server
$i++;

$cfg['Servers'][$i]['unique_id'] = 'robin'; // Should be unique
$cfg['Servers'][$i]['host'] = 'robin';
$cfg['Servers'][$i]['port'] = '3306';
$cfg['Servers'][$i]['user'] = 'user_name';
$cfg['Servers'][$i]['pwd'] = 'user_pwd';

// Second server
$i++;

$cfg['Servers'][$i]['unique_id'] = 'demeter';
$cfg['Servers'][$i]['host'] = 'demeter';
$cfg['Servers'][$i]['port'] = '3306';
$cfg['Servers'][$i]['user'] = 'user_name';
$cfg['Servers'][$i]['pwd'] = 'user_pwd';

$i = 0;
// First Topology
$i++;

$cfg['Topologies'][$i]['group'] = 'Top_1';
$cfg['Topologies'][$i]['master_server_unique_id'] = 'robin';
$cfg['Topologies'][$i]['slave_server_unique_id'] = 'demeter';
$cfg['Topologies'][$i]['active_check'] = true;

// Second Topology
$i++;

$cfg['Topologies'][$i]['group'] = 'Top_1';
$cfg['Topologies'][$i]['master_server_unique_id'] = 'demeter';
$cfg['Topologies'][$i]['slave_server_unique_id'] = 'robin';
$cfg['Topologies'][$i]['active_check'] = true;

// Add more if required



