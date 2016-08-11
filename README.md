# SRaM
MySQL Simple ReplicAtion Monitor

![alt tag](https://github.com/Co0olCat/SRaM/blob/master/SRaM.png)

# Use it at your own risk!

#KUDOS

This is derived work based on "PHP MySQL Master/Slave Replication Monitor"
http://code.google.com/p/php-mysql-master-slave-replication-monitor
by 2010 Alan Blount (alan[at]zeroasterisk[dot]com). Thank you for your great work.

# Changes from original:

1. Refactored some vars.
2. Added config file.
3. Added multiple topologies.
4. Improved web interface.
5. Updated testing and healing.
6. Removed destructive operations.
7. Updated codebase.

# Functionality
1. Monitor MySQL replication status using web interface.
2. Monitor MySQL replication using cron > Send email if issues are detected.
3. Monitor and heal MySQL replication using cron > Send email if issues are detected > Report progress of healing.

# Installation
1. Get index.php, cron.php, replication-monitor.class.php and config.php to your internal server.
   Note: Web interface is not protected - anyone can stop/break replication using SRaM.
2. Download https://github.com/PHPMailer/PHPMailer and extract to Libs directory.
3. Configure email settings, topologies and other settings in config.php.
4. Add cron task to access cron.php at predefined intervals.
5. Complain, patch and share your improvements 8)

# Use it at your own risk! 
