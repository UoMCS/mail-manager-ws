<?php

define('MM_WS_MYSQL_DATE_TIME', 'Y-m-d H:i:s');
define('MM_WS_STUDENT_LOG_SCHEMA_FILE', 'student-log.sql');

class MailManager_WebService
{
  private $email_lookup_connection = null;
  private $audit_log_connection = null;
  private $student_log_connection = null;
  
  private $student_username;
  private $student_password;
  private $student_host;
  private $student_dbname;
  
  public function __construct($db_config)
  {
    $this->authenticate();
    $this->open_connections();
  }
  
  private function authenticate()
  {
    $this->student_username = isset($_POST['username']) ? trim($_POST['username']) : null;
	$this->student_password = isset($_POST['password']) ? trim($_POST['password']) : null;
	$this->student_host = isset($_POST['host']) ? trim($_POST['host']) : null;
	$this->student_dbname = isset($_POST['dbname']) ? trim($_POST['dbname']) : null;
	
	$this->student_log_connection = new mysqli($this->student_host, $this->student_username, $this->student_password, $this->student_dbname);
	
	if ($this->student_log_connection->connect_error)
	{
	  throw new Exception('Could not authenticate student');
	}
  }
  
  private function create_student_log_table()
  {
    // First check if table exists
    $sql = 'SELECT id FROM mail_message_log';
    $result = $this->student_log_connection->query($sql);

    // Table does not exist, so create it
    if ($result === FALSE)
    {
      $schema = file_get_contents(MM_WS_STUDENT_LOG_SCHEMA_FILE);

      if (!empty($schema))
      {
        $result = $this->student_log_connection->query($schema);
      }
    }
  }
  
  private function open_connections()
  {
    $this->email_lookup_connection = new mysqli($db_config['email_lookup']['host'], $db_config['email_lookup']['username'], $db_config['email_lookup']['password'], $db_config['email_lookup']['dbname']);
	
	if ($this->email_lookup_connection->connect_error)
	{
	  throw new Exception('Could not establish email lookup connection');
	}
	
	$this->audit_log_connection = new mysqli($db_config['audit_log']['host'], $db_config['audit_log']['username'], $db_config['audit_log']['password'], $db_config['audit_log']['dbname']);
	
	if ($this->audit_log_connection)
	{
	  throw new Exception('Could not establish audit log connection');
	}
  }
  
  private function get_current_date_time()
  {
    return date(MM_WS_MYSQL_DATE_TIME);
  }
}