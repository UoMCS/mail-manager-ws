<?php

require_once 'config.inc.php';
require_once 'MailManagerWebService.php';

$ws = new MailManagerWebService($db_config, $smtp_config, $mail_config);
