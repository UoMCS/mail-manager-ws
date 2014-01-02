<?php

date_default_timezone_set('Europe/London');

require_once '../config.inc.php';
require_once '../MailManagerWebService.php';

$ws = new MailManager_WebService($db_config, $smtp_config, $ws_config);
$ws->send();