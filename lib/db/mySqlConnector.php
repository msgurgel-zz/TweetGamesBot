<?php

require_once('../support/logging.php');
require_once ('../../tweetGames/TheKeys.php');
/**
* Handles communications with MySQL Database.
*/
class mySqlConnector
{

  /**
  * Member Attributes
  */
  // Class variables
  private $conn; // Objects that is handles communications with database.

  /**
  * Default Constructor; Connects to the MySQL database.
  */
  function __construct()
  {
    // Connect to DB
    $this->conn = new mysqli(SERVER_NAME, USERNAME, PASSWORD, DB_NAME);
    if ($this->conn->connect_error)
    {
        log2file("mySqlConnector.__construct", "MySQL: Failed to connect to the server. ERROR: " . $this->conn->connect_error);
        die("Connection to DB failed: " . $this->conn->connect_error);
    }
  }

  /**
  * Sends a query to the connected MySQL database.
  *
  * @return string $serverReply The result of the query.
  */
  public function sqlQuery($query)
  {
    $serverReply = $this->conn->query($query);

    return $serverReply;
  }

  /**
  * Closes connection to MySQL database
  */
  public function closeSQL()
  {
    $this->conn->close();
  }
}