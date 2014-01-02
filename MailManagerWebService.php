<?php

require_once 'Zend/Mail.php';
require_once 'Zend/Mail/Transport/Smtp.php';

define('MM_WS_MYSQL_DATE_TIME', 'Y-m-d H:i:s');
define('MM_WS_STUDENT_LOG_SCHEMA_FILE', 'student-log.sql');
define('MM_WS_RATE_LIMIT_MAX_EMAILS', 60);
define('MM_WS_RATE_LIMIT_CUTOFF', '1 hour');

class MailManager_WebService
{
  private $email_lookup_connection = null;
  private $audit_log_connection = null;
  private $student_log_connection = null;
  
  private $student_username;
  private $student_password;
  private $student_host;
  private $student_dbname;
  
  private $recipient = '';
  private $subject = '';
  private $body = '';
  
  private $additional_headers = array();
  
  private $student_email_address;
  
  private $rate_limit_cutoff;
  
  private $db_config;
  private $smtp_config;
  private $mail_config;
  
  public function __construct($db_config, $smtp_config, $mail_config)
  {
    $this->db_config = $db_config;
	$this->smtp_config = $smtp_config;
	$this->mail_config = $mail_config;
  
    $this->authenticate();
	$this->validate();
    $this->open_connections();
	$this->set_student_email_address();
	
	$this->additional_headers['From'] = $this->student_email_address;
	$this->additional_headers['Return-Path'] = $this->mail_config['envelope_from'];
	
	$this->rate_limit_cutoff = date(MM_WS_MYSQL_DATE_TIME, strtotime('-' . MM_WS_RATE_LIMIT_CUTOFF));
  }
  
  private function authenticate()
  {
    $this->student_username = isset($_POST['username']) ? trim($_POST['username']) : null;
	$this->student_password = isset($_POST['password']) ? trim($_POST['password']) : null;
	$this->student_host = isset($_POST['host']) ? trim($_POST['host']) : null;
	$this->student_dbname = isset($_POST['dbname']) ? trim($_POST['dbname']) : null;
	
	// Don't even try to authenticate if we are missing a username/password combination
	if (empty($this->student_username) || empty($this->student_password))
	{
	  error_log('Could not authenticate student');
	  header('HTTP/1.1 403 Forbidden');
	  exit;
	}
	
	$this->student_log_connection = new mysqli($this->student_host, $this->student_username, $this->student_password, $this->student_dbname);
	
	if ($this->student_log_connection->connect_error)
	{
	  error_log('Could not authenticate student');
	  header('HTTP/1.1 403 Forbidden');
	  exit;
	}
  }
  
  /**
   * Create the student copy of the log table if it does not already
   * exist. This is similar to the audit log, but can be accessed by the
   * student and so cannot be used for auditing.
   */
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
  
  private function validate()
  {
    $this->recipient = isset($_POST['recipient']) ? trim($_POST['recipient']) : null;
	$this->subject = isset($_POST['subject']) ? trim($_POST['subject']) : null;
	$this->body = isset($_POST['body']) ? $_POST['body'] : null;
  
    if (empty($this->recipient))
	{
	  error_log('No recipient specified');
	  header('HTTP/1.1 400 Bad Request');
	  exit;
	}
	
	if (empty($this->subject))
	{
	  error_log('No subject specified');
	  header('HTTP/1.1 400 Bad Request');
	  exit;
	}
	
	if (empty($this->body))
	{
	  error_log('No body specified');
	  header('HTTP/1.1 400 Bad Request');
	  exit;
	}
	
	// Check that email address is valid
	if (!filter_var($this->recipient, FILTER_VALIDATE_EMAIL))
	{
	  error_log('Invalid recipient specified');
	  header('HTTP/1.1 400 Bad Request');
	  exit;
	}
	
	// Check that recipient is in list of permitted domains
	// Recipient domains file is relative to the class file, not the executing script
	$recipient_domains_file = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'recipient-domains';
	$recipient_domains_data = file_get_contents($recipient_domains_file);
	$recipient_domains = explode("\n", $recipient_domains_data);
	
	list($user, $domain) = explode('@', $this->recipient);
	
	if (!in_array($domain, $recipient_domains))
	{
	  error_log('Recipient is not in list of permitted domains');
	  header('HTTP/1.1 400 Bad Request');
	  exit;
	}
  }
  
  private function open_connections()
  {
    $this->email_lookup_connection = new mysqli($this->db_config['email_lookup']['host'], $this->db_config['email_lookup']['username'], $this->db_config['email_lookup']['password'], $this->db_config['email_lookup']['dbname']);
	
	if ($this->email_lookup_connection->connect_error)
	{
	  error_log('Could not establish email lookup connection');
	  header('HTTP/1.1 500 Server Error');
	  exit;
	}
	
	$this->audit_log_connection = new mysqli($this->db_config['audit_log']['host'], $this->db_config['audit_log']['username'], $this->db_config['audit_log']['password'], $this->db_config['audit_log']['dbname']);
	
	if ($this->audit_log_connection->connect_error)
	{
	  error_log('Could not establish audit log connection');
	  header('HTTP/1.1 500 Server Error');
	  exit;
	}
  }
  
  private function get_current_date_time()
  {
    return date(MM_WS_MYSQL_DATE_TIME);
  }
  
  private function count_emails_sent()
  {
    $sql = 'SELECT id FROM audit_log WHERE log_time < ?';
	$statement = $this->audit_log_connection->prepare($sql);
	$statement->bind_param('s', $this->rate_limit_cutoff);
	$statement->execute();
	
	$result = $statement->get_result();
	$emails_sent = $result->num_rows;
	$statement->close();
	
	return $emails_sent;
  }
  
  /**
   * Set student email address based on their username. This involves a simple
   * database lookup, although we may be able to replace this with LDAP at a later
   * date.
   */
  private function set_student_email_address()
  {
    $sql = 'SELECT email FROM users WHERE username = ? LIMIT 1';
	$statement = $this->email_lookup_connection->prepare($sql);
	$statement->bind_param('s', $this->student_username);
	$statement->execute();
	
	$result = $statement->get_result();
	
	if ($result !== FALSE)
	{
	  if ($result->num_rows === 1)
	  {
	    $data = $result->fetch_assoc();
		
		if (is_array($data) && isset($data['email']) && !empty($data['email']) && filter_var($data['email'], FILTER_VALIDATE_EMAIL))
		{
		  $this->student_email_address = $data['email'];
		}
		else
		{
		  error_log('Could not find student email address');
	      header('HTTP/1.1 500 Server Error');
	      exit;
		}
	  }
	  else
	  {
		error_log('Could not find student email address');
	    header('HTTP/1.1 500 Server Error');
	    exit;
	  }
	}
	else
	{
	  error_log('Could not find student email address');
	  header('HTTP/1.1 500 Server Error');
	  exit;
	}
  }
  
  private function rate_limit()
  {
    $emails_sent = $this->count_emails_sent();
	
	if ($emails_sent > MM_WS_RATE_LIMIT_MAX_EMAILS)
	{
	  error_log('Rate limit exceeded, can send maximum of ' . MM_WS_RATE_LIMIT_MAX_EMAILS . ' in ' . MM_WS_RATE_LIMIT_CUTOFF);
	  header('HTTP/1.1 429 Too Many Requests');
	  exit;
	}
  }
  
  public function send()
  {
    $this->rate_limit();
  
    $sql = 'INSERT INTO audit_log (username, recipient, subject, body, log_time) VALUES (?, ?, ?, ?, ?)';
	$statement = $this->audit_log_connection->prepare($sql);
	  
	if ($statement !== FALSE)
	{
	  $current_date_time = $this->get_current_date_time();
	  $statement->bind_param('sssss', $this->student_username, $this->recipient, $this->subject, $this->body, $current_date_time);
	  $statement->execute();
	}
	else
	{
	  error_log('Could not prepare audit log SQL query');
	  header('HTTP/1.1 500 Server Error');
	  exit;
	}
  
    $sql = 'INSERT INTO mail_manager_log (recipient, subject, body, log_time) VALUES (?, ?, ?, ?)';
	$statement = $this->student_log_connection->prepare($sql);
	  
	if ($statement !== FALSE)
	{
	  $current_date_time = $this->get_current_date_time();
	  $statement->bind_param('ssss', $this->recipient, $this->subject, $this->body, $current_date_time);
	  $statement->execute();
	}
	else
	{
	  error_log('Could not prepare student log SQL query');
	  header('HTTP/1.1 500 Server Error');
	  exit;
	}
	
	$transport = new Zend_Mail_Transport_Smtp($this->smtp_config['host']);
	Zend_Mail::setDefaultTransport($transport);
	
	$mail = new Zend_Mail();
	$mail->setFrom($this->student_email_address);
	$mail->addTo($this->recipient);
	$mail->setSubject($this->subject);
	$mail->setBodyText($this->body);
	$mail->send();
  }
}