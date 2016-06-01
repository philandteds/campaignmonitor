
/* Add an extra index to `mail_logs` to quickly identify sends to a particular address */
ALTER TABLE `mail_logs` ADD INDEX `ndx_mail_logs_receiver_date` (`receivers`, `date`);