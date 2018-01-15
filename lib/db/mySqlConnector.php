<?php

require_once('../lib/support/logging.php');
/**
* Handles communications with MySQL Database.
*/
class mySqlConnector
{

  /**
  * Member Attributes
  */

  // Class Constants
  const SERVER_NAME  = "YOUR IP";
  const USERNAME     = "YOUR USERNAME";
  const PASSWORD     = "YOUR PASSWORD";
  const DB_NAME      = "YOUR DATABASE";

  // Class variables
  private $conn; // Objects that is handles communications with database.

  /**
  * Default Constructor; Connects to the MySQL database.
  */
  function __construct()
  {

    // Connect to DB
    $this->conn = new mysqli(self::SERVER_NAME, self::USERNAME, self::PASSWORD, self::DB_NAME);
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


?>
