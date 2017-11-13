<?php
/**
 * Tweet Games Bot : Play games on Twitter (Tweet Reader and Replier)
 *
 * Using libraries:
 *    Twitter-API-PHP by j7mbo  - https://github.com/J7mbo/twitter-api-php
 *
 * PHP version 7
 *
 * @author   Mateus Gurgel <mateus@comecodewith.me>
 * @version  0.1
 * @license  MIT
 * @link     http://github.com/msgurgel/
 * @see      Twitter-API-PHP
 */

require_once('../lib/TwitterAPIExchange.php');

class QueueConsumer
{

  // Member attribs
  protected $queueDir;
  protected $filePattern;
  protected $checkInterval;

  // MySQL DB variables
  private $servername;
  private $username;
  private $password;
  private $dbname;
  private $conn;


  // Authorization Keys from apps.twitter.com
  private $settings = array(
  'oauth_access_token' => "YOUR TOKEN",
  'oauth_access_token_secret' => "YOUR SECRET",
  'consumer_key' => "YOUR KEY",
  'consumer_secret' => "YOUR SECRET"
  );

   // URL for posting tweets - check https://developer.twitter.com/en/docs for more
   private $url = "https://api.twitter.com/1.1/statuses/update.json";
   private $requestMethod = "POST";

  /**
   * Construct the consumer and start processing
   */
  public function __construct($queueDir = './tmp', $filePattern = 'phirehose-queue*.queue', $checkInterval = 5)
  {
    $this->queueDir = $queueDir;
    $this->filePattern = $filePattern;
    $this->checkInterval = $checkInterval;

    // MySQL DB variables
    $this->servername = "YOUR SERVER IP";
    $this->username = "YOUR USERNAME";
    $this->password = "YOUR PASSWORD";
    $this->dbname = "YOUR DATABASE NAME";

    // Create connection
    $this->conn = new mysqli($this->servername, $this->username, $this->password, $this->dbname);
    // Check connection
    if ($this->conn->connect_error)
    {
        die("Connection to DB failed: " . $this->conn->connect_error);
    }
    
    // Sanity checks
    if (!is_dir($queueDir))
    {
      throw new ErrorException('Invalid directory: ' . $queueDir);
    }
  }

  /**
   * Method that actually starts the processing task (never returns).
   */
  public function process()
  {
    // Init some things
    $lastCheck = 0;
    // Loop infinitely
    while (TRUE)
    {
      // Get a list of queue files
      $queueFiles = glob($this->queueDir . '/' . $this->filePattern);
      $lastCheck = time();

      $this->myLog('Found ' . count($queueFiles) . ' queue files to process...');

      // Iterate over each file (if any)
      foreach ($queueFiles as $queueFile)
      {
        $this->processQueueFile($queueFile);
      }

      // Wait until ready for next check
      $this->myLog('Sleeping...');
      while (time() - $lastCheck < $this->checkInterval)
      {
        sleep(1);
      }
    } // Infinite loop
  } // End process()

  /**
   * Processes a queue file and does something with it (example only)
   * @param string $queueFile The queue file
   */
  protected function processQueueFile($queueFile)
  {
    $post = true;

    $this->myLog('Processing file: ' . $queueFile);

    // Open file
    $fp = fopen($queueFile, 'r');

    // Check if something has gone wrong, or perhaps the file is just locked by another process
    if (!is_resource($fp))
    {
      $this->myLog('WARN: Unable to open file or file already open: ' . $queueFile . ' - Skipping.');
      return FALSE;
    }

    // Lock file
    flock($fp, LOCK_EX);

    // Loop over each line (1 line per status)
    $statusCounter = 0;
    while ($rawStatus = fgets($fp, 8192))
    {
      $statusCounter ++;
      $stop = false;

      $data = json_decode($rawStatus, true);

      if (is_array($data) && isset($data['user']['screen_name']) && $data['entities']['user_mentions'][0]['screen_name'] == 'TweetGamesBot')
      {
        $tweetFrom = $data['user']['screen_name'];    // Username of the user that mentioned the bot
        $tweetID   = $data['id'];                     // ID of that tweet
        $tweetText = urldecode($data['text']);        // The tweet itself
        $isReply   = $data['in_reply_to_status_id'];  // ID of the tweet it is replying to

        $this->myLog('Bot got mentioned: ' . $tweetFrom . ': ' . $tweetText);
        //$this->myLog(var_export($data, true));

        // Look for commands in tweet
        $commands = strchr($tweetText, '/');
        if (!$commands) // Did not find any '/' command
        {
          // Check if tweet is a reply or just a new mention
          if ($isReply == NULL)
          {
            $arrPost = array('status' => '@' . $tweetFrom . ' Thanks for mentioning me! ' ."\u{1F60D}" . "\n\n" . 'Time: ' . date('h:i:s A'),
                             'in_reply_to_status_id' => $tweetID
                           );
          }
          else
          {
            $post = false;
          }
        }
        else // Found a '/' command
        {
          $commandArray = explode(" ", $commands);

          $this->myLog('Found command in: ' .  $commands);

          //$v = var_export($commandArray, true);
          //$this->myLog('Var Export: ' . $v);

          // Position 0 holds /command, other position holds possible arguments
          // DICE COMMANDS
          if (!strncmp($commandArray[0], '/d', 2))
          {
            $dieNum = substr($commandArray[0], 2);
            // Dice available: D4, D6, D8, D10, D12, D20, and D100
            switch ($commandArray[0])
            {
              case "/d4":
              case "/d6":
              case "/d8":
              case "/d10":
              case "/d12":
              case "/d20":
              case "/d100":
              $arrPost = array('status' => '@' . $tweetFrom . ' Roll the die! D' . $dieNum . ' Result: ' . rand(1, $dieNum) . "\n\n" . 'Time: ' . date('h:i:s A'),
                               'in_reply_to_status_id' => $tweetID
                             );
                break;

              case "/d4c":
              $arrPost = array('status' => '@' . $tweetFrom . ' Did you mean: Filthy Acts At A Reasonable Price?' . "\n\n" . 'Time: ' . date('h:i:s A'),
                               'in_reply_to_status_id' => $tweetID
                             );
                  break;

              default:
              $arrPost = array('status' => '@' . $tweetFrom . ' ERROR 404: Die not found: ' . $commandArray[0] . "\n\n" .  'Time: ' . date('h:i:s A'),
                               'in_reply_to_status_id' => $tweetID
                             );
                break;
            }
          }

          // COUNTER COMMANDS
          else if ($commandArray[0] == "/counter")
          {
            // Grab the argurment for the /counter command
            $counterArg = $commandArray[1];

            // Get counter info from DB
            $sql = "SELECT UserID, Counter FROM userbase WHERE Username = '$tweetFrom'";
            $serverReply = $this->conn->query($sql);

            // Check if user is in the database
            if ($serverReply->num_rows == 1)
            {
               // Users in the database; grab COUNTER value
               $userInfo = $serverReply->fetch_assoc();

               switch ($counterArg)
               {
                 case "inc": // Increment counter
                 $sql = "UPDATE userbase SET Counter = Counter + 1 WHERE UserID = " .  $userInfo["UserID"];
                 $serverReply = $this->conn->query($sql);

                 if ($serverReply === true)
                 {
                   $this->myLog("MySQL: Counter from $tweetFrom was incremented. Current Value = " . ($userInfo["Counter"] + 1) );

                   // Reply to original tweet, displaying COUNTER
                   $arrPost = $this->formatTweet("Incrementing counter! New value = " . ($userInfo["Counter"] + 1), $tweetFrom, $tweetID);
                 }
                 else
                 {
                   $this->myLog("MySQL: Could not increment counter from $tweetFrom \r\n ERROR: $sql \r\n $this->conn->error");
                   $arrPost = $this->formatTweet("Failed to increment counter! @SomeSeriousSith : You should check your log file...", $tweetFrom, $tweetID);
                 }
                 break;

                 case "dec": // Decrement counter

                 $sql = "UPDATE userbase SET Counter = Counter - 1 WHERE UserID = " . $userInfo["UserID"];
                 $serverReply = $this->conn->query($sql);

                 if ($serverReply === true)
                 {
                   $this->myLog("MySQL: Counter from $tweetFrom was decremented. Current Value = " . ($userInfo["Counter"] - 1) );

                   // Reply to original tweet, displaying COUNTER
                   $arrPost = $this->formatTweet("Decrementing counter! New value = " . ($userInfo["Counter"] - 1), $tweetFrom, $tweetID);
                 }
                 else
                 {
                   $this->myLog("MySQL: Could not decrement counter from $tweetFrom \r\n ERROR: $sql \r\n $this->conn->error");
                   $arrPost = $this->formatTweet("Failed to decrement counter! @SomeSeriousSith : You should check your log file...", $tweetFrom, $tweetID);
                 }
                 break;

                  case "reset": // reset counter

                  $sql = "UPDATE userbase SET Counter = 0 WHERE UserID = " . $userInfo["UserID"];
                  $serverReply = $this->conn->query($sql);
                  if ($serverReply === true)
                  {
                    $this->myLog("MySQL: Counter from $tweetFrom was set to 0");

                    // Reply to original tweet, displaying COUNTER
                    $arrPost = $this->formatTweet("Resetting counter! New value = 0", $tweetFrom, $tweetID);
                  }
                  else
                  {
                    $this->myLog("MySQL: Could not reset counter from $tweetFrom \r\n ERROR: $sql \r\n $this->conn->error");
                    $arrPost = $this->formatTweet("Failed to reset counter! @SomeSeriousSith : You should check your log file...", $tweetFrom, $tweetID);
                  }
                  break;

                  case "show": // Display counter
                  $arrPost = $this->formatTweet("Here you go! Counter = " . $userInfo["Counter"], $tweetFrom, $tweetID);
                  break;

                  case "delete": // Delete counter - As of now, that removes the user from the DB
                  $sql = "DELETE FROM userbase WHERE UserID = " . $userInfo["UserID"];
                  $serverReply = $this->conn->query($sql);
                  if ($serverReply === true)
                  {
                    $this->myLog("MySQL: Deleted $tweetFrom from DB");

                    // Reply to original tweet, displaying COUNTER
                    $arrPost = $this->formatTweet("Your counter has been deleted! Thank you for using this bot!", $tweetFrom, $tweetID);
                  }
                  else
                  {
                    $this->myLog("MySQL: Could not decrement counter from $tweetFrom \r\n ERROR: $sql \r\n $this->conn->error");
                    $arrPost = $this->formatTweet("Failed to delete counter! @SomeSeriousSith : You should check your log file...", $tweetFrom, $tweetID);
                  }
                  break;


                 default:    // Invalid argument
                  $arrPost = $this->formatTweet("Invalid argument for /counter: $counterArg", $tweetFrom, $tweetID);
                  break;
               }
            }
            else // User not in the database
            {
              switch ($counterArg)
              {
                case "new": // Add user to database, creating a new COUNTER

                // $expirationDate = time() + (1 * 24 * 60 * 60);
                                          // 1 day; 24 hours; 60 mins; 60 secs
                $expirationDate = time() + (10 * 60);
                                          // 10 mins; 60 secs
                $expirationDate = date('Y-m-d H:i:s', $expirationDate);
                $sql = "INSERT INTO userbase (Username, Counter, Expiration) VALUES('$tweetFrom', 0, '$expirationDate')";

                // Check if successfully added new user to DB
                if ($this->conn->query($sql) === true)
                {
                  $this->myLog("MySQL: $tweetFrom was added to the DB!");

                  // Reply to original tweet, displaying COUNTER
                  $arrPost = $this->formatTweet("Created new counter! Value = 0", $tweetFrom, $tweetID);
                }
                else
                {
                  $this->myLog("MySQL: Could not add $tweetFrom to database \r\n ERROR: $sql \r\n $this->conn->error");
                  $arrPost = $this->formatTweet("Failed to create new counter! @SomeSeriousSith : You should check your log file...", $tweetFrom, $tweetID);
                }
                  break;

                case "inc":
                case "dec":
                case "reset":
                case "show":
                  // User tried to use the COUNTER before creating it
                  $arrPost = $this->formatTweet("Woah there! You need to create a counter (/counter new) before doing that command!", $tweetFrom, $tweetID);
                  break;

                default: // Unknown argument for /command
                  $arrPost = $this->formatTweet("Invalid argument for /counter: $counterArg", $tweetFrom, $tweetID);
                  break;
              }
            }
          }

          // ADMIN COMMANDS
          else if ($commandArray[0] == "/stop")
          {
            if ($tweetFrom == 'SomeSeriousSith')
            {
              $stop = true;
              break;
            }
            else
            {
              $arrPost = $this->formatTweet("Hey, only @SomeSeriousSith can use that command!", $tweetFrom, $tweetID);
            }
          }
          else // Invalid command
          {
            $post = false;
          }
        }

        if ($post)
        {
          // Post response tweet
          $this->postTweet($arrPost);
          $this->myLog('Tweet: ' . $arrPost['status']);
        }
      }
    } // End while

    // Release lock and close
    flock($fp, LOCK_UN);
    fclose($fp);

    // All done with this file
    $this->myLog('Successfully processed ' . $statusCounter . ' tweets from ' . $queueFile . ' - deleting.');
    unlink($queueFile);

    if ($stop)
    {
      $this->conn->close();
      exit('Mateus told me to stop!');
    }

  }

  /**
   * Basic log function.
   *
   * @see error_log()
   * @param string $messages
   */
  protected function myLog($message)
  {
    $myFile = fopen("logConsume.log", "a") or die("Unable to open file!");
    $timeNow = date('c');
    $txt = $timeNow . '--' . $message . "\r\n";
    fwrite($myFile, $txt);
    fclose($myFile);
  }


  private function formatTweet($message, $tweetFrom, $tweetID)
  {
    $retval = array('status' => "@$tweetFrom $message \n\n Time: " . date('h:i:s A'),
                     'in_reply_to_status_id' => $tweetID
                   );
    return $retval;
  }

  /**
  * Post tweet through the twitter-api-php library
  *
  * @see TwitterAPIExchange.php
  * @param $postfields parameters for the Twitter API request
  */
  private function postTweet($postfields)
  {
    $twitter = new TwitterAPIExchange($this->settings);

    $twitter->buildOauth($this->url, $this->requestMethod)
    ->setPostfields($postfields)
    ->performRequest();
  }
}

// Construct consumer and start processing
$gqc = new QueueConsumer();
$gqc->process();
