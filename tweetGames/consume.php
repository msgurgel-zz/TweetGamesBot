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

/**
 *
 */
class Requester
{

  /**
  * Member Attributes
  */

  // MySQL DB - Constants
  const SERVER_NAME  = "YOUR IP";
  const USERNAME     = "YOUR USERNAME";
  const PASSWORD     = "YOUR PASSWORD";
  const DB_NAME      = "YOUR DATABASE";

  // MySQL DB - Variables
  private $conn;

  // Twitter API - Constants
  const SETTINGS = array(
  'oauth_access_token' => "YOUR TOKEN",
  'oauth_access_token_secret' => "YOUR SECRET",
  'consumer_key' => "YOUR KEY",
  'consumer_secret' => "YOUR SECRET"
  );

  // Twitter API - Variables
  private $urlTwitterUpdate;
  private $urlTwitterMedia;

  private $gifID;
  private $gifIDExpiration;

  // GIPHY API - Constants
  const GIPHY_KEY  = 'YOUR KEY';

  /* Functions */

  /**
  * Construct Requester Object
  */
  function __construct()
  {
    // Initialize variables
    $this->urlTwitterUpdate = "https://api.twitter.com/1.1/statuses/update.json";
    $this->urlTwitterMedia = "https://upload.twitter.com/1.1/media/upload.json";

    $this->gifID = 0;
    $this->gifIDExpiration = 0;
    // Connect to DB
    $this->conn = new mysqli(self::SERVER_NAME, self::USERNAME, self::PASSWORD, self::DB_NAME);
    if ($this->conn->connect_error)
    {
        log2file("MySQL: Failed to connect to the server. ERROR: " . $this->conn->connect_error);
        die("Connection to DB failed: " . $this->conn->connect_error);
    }
  }

  /**
  *
  */
  public function formatTweet($message, $user = NULL, $tweetReplyID = NULL, $mediaID = NULL)
  {
    if ($mediaID != NULL)
    {
      $retval = array('status' => "@$user $message \n\n Time: " . date('h:i:s A'),
                      'in_reply_to_status_id' => $tweetReplyID,
                      'media_ids' => $mediaID
                      );
    }
    else
    {
      $retval = array('status' => "@$user $message \n\n Time: " . date('h:i:s A'),
                      'in_reply_to_status_id' => $tweetReplyID,
                      );
    }




    return $retval;
  }

  /**
  * Post tweet through the twitter-api-php library
  *
  * @see TwitterAPIExchange.php
  * @param $postfields parameters for the Twitter API request
  */
  public function postTweet($postfields)
  {
    $twitter = new TwitterAPIExchange(self::SETTINGS);

    $twitter->buildOauth($this->urlTwitterUpdate, "POST")
    ->setPostfields($postfields)
    ->performRequest();
  }


    public function sqlQuery($query)
    {
      $serverReply = $this->conn->query($query);

      return $serverReply;
    }

    public function closeSQL()
    {
      $this->conn->close();
    }

    public function requestRandomGIF($tag)
    {

      // Before getting a new gif, check if there's an active one
      if ( $this->gifID != 0 && (time() - $this->gifIDExpiration  >= 0))
      {
        // Current GIF ID is still valid, return it
        return $this->gifID;
      }

      /* ---------------------------------------- GIPHY API -------------------------------*/
      // Initialize cURL
      $curl = curl_init();
      curl_setopt_array($curl, array(
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_URL => "api.giphy.com/v1/gifs/random?api_key=" . self::GIPHY_KEY . "&tag=$tag"
      ));

      // Execute Request & close connection
      $result = curl_exec($curl);
      curl_close($curl);

      // Decode resulting JSON into associative array
      $data = json_decode($result, TRUE);

      // Save GIF in tmp folder
      $path = "gifs/$tag.gif";
      file_put_contents($path, fopen($data["data"]["image_url"],'r'));

      /*---------------------------- TWITTER API ----------------------------*/
      // Prepare INIT request
      $postfields = array(
        'command'        => 'INIT',
        'total_bytes'    => filesize($path),
        'media_type'     => 'image/gif',
        'media_category' => 'tweet_gif'
       );
       echo "INIT ARRAY: </br>";
       var_dump($postfields);

      // Make INIT request
      $twitter = new TwitterAPIExchange(self::SETTINGS);
      $response = $twitter->buildOauth($this->urlTwitterMedia, "POST")
      ->setPostfields($postfields)
      ->performRequest();

      log2file("Made INIT request!");
      $data = json_decode($response, true);
      echo "INIT : </br>";
      var_dump($data);
      $mediaID = $data["media_id"];

      // APPEND requests

      $rawFileData = file_get_contents($path);
      $chunkStr = chunk_split(base64_encode($rawFileData), 2000000);
      $chunkArr = explode("\r\n", $chunkStr);

      $index = 0;
      foreach ($chunkArr as $value)
      {
        // Prepare APPEND request
        $postfields = array(
          'command'        => 'APPEND',
          'media_id'       => $mediaID,
          'media_data'     => $value,
          'media_type'     => 'image/gif',
          'segment_index'  => $index
         );
         $index++;

         log2file("Prepared REQUEST $index!");

         // Make APPEND request
         $response = $twitter->buildOauth($this->urlTwitterMedia, "POST")
         ->setPostfields($postfields)
         ->performRequest();

         log2file("Made APPEND request #$index!");
      }

      // GIF has been uploaded; Prepare FINALIZE command

      $postfields = array(
        'command' => 'FINALIZE',
        'media_id'=> $mediaID
       );

       // Make FINALIZE request
       $response = $twitter->buildOauth($this->urlTwitterMedia, "POST")
       ->setPostfields($postfields)
       ->performRequest();

       log2file("Made FINALIZE request!");

       $data = json_decode($response, true);
       echo 'FINALIZE: </br>';
       var_dump($data);
       if (isset($data['processing_info']['state']))
       {
         $processing = true;

         // Prepare STATUS request
         $getfield = '?command=STATUS&media_id=' . $mediaID;

         while ($processing)
         {
           // Upload pending; Check again after waiting
           if (isset($data['processing_info']['check_after_secs']))
           {
             $checkAfter = $data['processing_info']['check_after_secs'];
             log2file("Twitter API: Processing GIF upload. Sleeping for $checkAfter seconds...");
             sleep($checkAfter);
           }

            // Make STATUS request
            $twitter2 = new TwitterAPIExchange(self::SETTINGS);
            $response = $twitter2->setGetfield($getfield)
            ->buildOauth($this->urlTwitterMedia, "GET")
            ->performRequest();

            $data = json_decode($response, true);
            echo 'STATUS: </br>';
            var_dump($data);
            if ($data['processing_info']['state'] == 'failed')
            {
              // Something went wrong!
              log2file("Twitter API: Could not upload $tag.gif -- STATUS returned failed");
              $success = false;
              $processing = false;
            }
            else if ($data['processing_info']['state'] == 'succeeded')
            {
              $success = true;
              $processing = false;
            }
          }
       }
       elseif (isset($data['error']))
       {
         $success = false;
         log2file("Twitter API: Failed to upload file $tag.gif Reason: " . $data['error']);
       }
       else
       {
         $success = true;
       }
       // Upload was successful
       if ($success)
       {
         $mediaID = $data['media_id'];
         $this->gifID = $mediaID;
         $this->gifIDExpiration = time() + $data['expires_after_secs'];

         log2file("Twitter API: Successfully uploaded $tag.gif");
       }
      // Delete GIF
      //unlink($path);
      return $mediaID;
    }
}


class QueueConsumer
{

  // Member attribs
  protected $queueDir;
  protected $filePattern;
  protected $checkInterval;
  protected $requester;

  /**
   * Construct the consumer and start processing
   */
  public function __construct($requester, $queueDir = './tmp', $filePattern = 'phirehose-queue*.queue', $checkInterval = 5)
  {
    $this->queueDir = $queueDir;
    $this->filePattern = $filePattern;
    $this->checkInterval = $checkInterval;
    $this->requester = $requester;

    // Sanity checks
    if (!is_dir($queueDir))
    {
      log2file('QueueConsumer: Invalid directory for queue files: ' . $queueDir);
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

      log2file('Found ' . count($queueFiles) . ' queue files to process...');

      // Iterate over each file (if any)
      foreach ($queueFiles as $queueFile)
      {
        $this->processQueueFile($queueFile);
      }

      // Wait until ready for next check
      log2file('Sleeping...');
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
    // Initialize Variables
    $postReply = true;  // By default, always post a reply tweet

    log2file('Processing file: ' . $queueFile);

    // Open file
    $fp = fopen($queueFile, 'r');

    // Check if something has gone wrong, or perhaps the file is just locked by another process
    if (!is_resource($fp))
    {
      log2file('WARN: Unable to open file or file already open: ' . $queueFile . ' - Skipping.');
      return FALSE;
    }
    // Lock file
    flock($fp, LOCK_EX);

    // Loop over each line (1 line per status)
    $statusCounter = 0;
    while ($rawStatus = fgets($fp, 8192))
    {
      $statusCounter++;

      $stop = false;

      $data = json_decode($rawStatus, true);

      if (is_array($data) && isset($data['user']['screen_name']) && $data['entities']['user_mentions'][0]['screen_name'] == 'TweetGamesBot')
      {
        // Grab data from tweet
        $tweetFrom = $data['user']['screen_name'];    // Username of the user that mentioned the bot
        $tweetID   = $data['id'];                     // ID of that tweet
        $tweetText = urldecode($data['text']);        // The tweet itself
        $isReply   = $data['in_reply_to_status_id'];  // ID of the tweet it is replying to

        log2file('Bot got mentioned: ' . $tweetFrom . ': ' . $tweetText);
        log2file(var_export($data, true));

        // Look for commands in tweet
        $commands = strchr($tweetText, '/');
        if (!$commands) // Did not find any '/' command
        {
          // Check if tweet is a reply or just a new mention
          if ($isReply == NULL)
          {
            $gifID = $this->requester->requestRandomGIF("thanks");
            $arrPost = $this->requester->formatTweet("Thanks for mentioning me! \u{1F60D}", $tweetFrom, $tweetID, $gifID);
          }
          else
          {
            $post = false;
          }
        }
        else // Found a '/' command
        {
          // Grab commands and possible arguments from string
          $commandArray = explode(" ", $commands);

          log2file('Found command in: ' .  $commands);

          //$v = var_export($commandArray, true);
          //$this->myLog('Var Export: ' . $v);

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
              $arrPost = $this->requester->formatTweet("Roll the die! D$dieNum result = " . rand(1, $dieNum), $tweetFrom, $tweetID);
                break;

              case "/d4c":
              $arrPost = $this->requester->formatTweet("Did you mean: Filthy Acts at a Reasonable Price?", $tweetFrom, $tweetID);
                break;

              default:
              $arrPost = $requester->formatTweet("ERROR 404: Die not found: " . $commandArray[0], $tweetFrom, $tweetID);
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
            $serverReply = $this->requester->sqlQuery($sql);

            // Check if user is in the database
            if ($serverReply->num_rows == 1)
            {
               // Users in the database; grab COUNTER value
               $userInfo = $serverReply->fetch_assoc();

               switch ($counterArg)
               {
                 case "inc": // Increment counter
                 $sql = "UPDATE userbase SET Counter = Counter + 1 WHERE UserID = " .  $userInfo["UserID"];
                 $serverReply = $this->requester->sqlQuery($sql);

                 if ($serverReply === true)
                 {
                   log2file("MySQL: Counter from $tweetFrom was incremented. Current Value = " . ($userInfo["Counter"] + 1) );

                   // Reply to original tweet, displaying COUNTER
                   $arrPost = $this->requester->formatTweet("Incrementing counter! New value = " . ($userInfo["Counter"] + 1), $tweetFrom, $tweetID);
                 }
                 else
                 {
                   log2file("MySQL: Could not increment counter from $tweetFrom \r\n ERROR: $sql \r\n $this->conn->error");
                   $arrPost = $this->requester->formatTweet("Failed to increment counter! @SomeSeriousSith : You should check your log file...", $tweetFrom, $tweetID);
                 }
                 break;

                 case "dec": // Decrement counter

                 $sql = "UPDATE userbase SET Counter = Counter - 1 WHERE UserID = " . $userInfo["UserID"];
                 $serverReply = $this->requester->sqlQuery($sql);

                 if ($serverReply === true)
                 {
                   log2file("MySQL: Counter from $tweetFrom was decremented. Current Value = " . ($userInfo["Counter"] - 1) );

                   // Reply to original tweet, displaying COUNTER
                   $arrPost = $this->requester->formatTweet("Decrementing counter! New value = " . ($userInfo["Counter"] - 1), $tweetFrom, $tweetID);
                 }
                 else
                 {
                   log2file("MySQL: Could not decrement counter from $tweetFrom \r\n ERROR: $sql \r\n $this->conn->error");
                   $arrPost = $this->requester->formatTweet("Failed to decrement counter! @SomeSeriousSith : You should check your log file...", $tweetFrom, $tweetID);
                 }
                 break;

                  case "reset": // reset counter

                  $sql = "UPDATE userbase SET Counter = 0 WHERE UserID = " . $userInfo["UserID"];
                  $serverReply = $this->requester->sqlQuery($sql);
                  if ($serverReply === true)
                  {
                    log2file("MySQL: Counter from $tweetFrom was set to 0");

                    // Reply to original tweet, displaying COUNTER
                    $arrPost = $this->requester->formatTweet("Resetting counter! New value = 0", $tweetFrom, $tweetID);
                  }
                  else
                  {
                    log2file("MySQL: Could not reset counter from $tweetFrom \r\n ERROR: $sql \r\n $this->conn->error");
                    $arrPost = $this->requester->formatTweet("Failed to reset counter! @SomeSeriousSith : You should check your log file...", $tweetFrom, $tweetID);
                  }
                  break;

                  case "show": // Display counter
                  $arrPost = $this->requester->formatTweet("Here you go! Counter = " . $userInfo["Counter"], $tweetFrom, $tweetID);
                  break;

                  case "delete": // Delete counter - As of now, that removes the user from the DB
                  $sql = "DELETE FROM userbase WHERE UserID = " . $userInfo["UserID"];
                  $serverReply = $this->requester->sqlQuery($sql);
                  if ($serverReply === true)
                  {
                    log2file("MySQL: Deleted $tweetFrom from DB");

                    // Reply to original tweet, displaying COUNTER
                    $arrPost = $this->requester->formatTweet("Your counter has been deleted! Thank you for using this bot!", $tweetFrom, $tweetID);
                  }
                  else
                  {
                    log2file("MySQL: Could not decrement counter from $tweetFrom \r\n ERROR: $sql \r\n $this->conn->error");
                    $arrPost = $this->requester->formatTweet("Failed to delete counter! @SomeSeriousSith : You should check your log file...", $tweetFrom, $tweetID);
                  }
                  break;


                 default:    // Invalid argument
                  $arrPost = $this->requester->formatTweet("Invalid argument for /counter: $counterArg", $tweetFrom, $tweetID);
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
                $serverReply = $this->requester->sqlQuery($sql);
                if ($serverReply === true)
                {
                  log2file("MySQL: $tweetFrom was added to the DB!");

                  // Reply to original tweet, displaying COUNTER
                  $arrPost = $this->requester->formatTweet("Created new counter! Value = 0", $tweetFrom, $tweetID);
                }
                else
                {
                  log2file("MySQL: Could not add $tweetFrom to database \r\n ERROR: $sql \r\n $this->conn->error");
                  $arrPost = $this->requester->formatTweet("Failed to create new counter! @SomeSeriousSith : You should check your log file...", $tweetFrom, $tweetID);
                }
                  break;

                case "inc":
                case "dec":
                case "reset":
                case "show":
                  // User tried to use the COUNTER before creating it
                  $arrPost = $this->requester->formatTweet("Woah there! You need to create a counter (/counter new) before doing that command!", $tweetFrom, $tweetID);
                  break;

                default: // Unknown argument for /command
                  $arrPost = $this->requester->formatTweet("Invalid argument for /counter: $counterArg", $tweetFrom, $tweetID);
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
              $arrPost = $this->requester->formatTweet("Hey, only @SomeSeriousSith can use that command!", $tweetFrom, $tweetID);
            }
          }
          else // Invalid command
          {
            $post = false;
          }
        }

        if ($postReply)
        {
          // Post response tweet
          $this->requester->postTweet($arrPost);
          log2file('Tweet posted: ' . $arrPost['status']);
        }
      }
    } // End while

    // Release lock and close
    flock($fp, LOCK_UN);
    fclose($fp);

    // All done with this file
    log2file('Successfully processed ' . $statusCounter . ' tweets from ' . $queueFile . ' - deleting.');
    unlink($queueFile);

    if ($stop)
    {
      $this->requester->closeSQL();
      log2file('Received admin command "/stop". Stopping script...');
      exit('ADMIN told me to stop!');
    }

  }
}

/**
 * Basic log function.
 *
 * @see error_log()
 * @param string $messages
 */
function log2file($message)
{
  $myFile = fopen("consume.log", "a") or die("Unable to open file!");
  $timeNow = date('Y-m-d H:i:s');
  $txt = $timeNow . '--' . $message . "\r\n";
  fwrite($myFile, $txt);
  fclose($myFile);
}

// Construct consumer and start processing
$requester = new Requester();
$qc = new QueueConsumer($requester);
$qc->process();
