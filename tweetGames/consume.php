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

    $twResponse = $twitter->buildOauth($this->urlTwitterUpdate, "POST")
    ->setPostfields($postfields)
    ->performRequest();

    return json_decode($twResponse, true);
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
    // Save GIF in tmp folder
    $path = "gifs/$tag.gif";

    // Initialize cURL
    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_RETURNTRANSFER => 1,
      CURLOPT_URL => "api.giphy.com/v1/gifs/random?api_key=" . self::GIPHY_KEY . "&tag=$tag"
    ));

    // Execute Request until GIF size is < 15 MB, then close connection
    do
    {
      $result = curl_exec($curl);
      $data = json_decode($result, TRUE);
      file_put_contents($path, fopen($data["data"]["image_url"],'r'));
    } while (filesize($path) >= ( 15 * (10**6) ) );

    curl_close($curl);

    /*---------------------------- TWITTER API ----------------------------*/
    // Prepare INIT request
    $postfields = array(
      'command'        => 'INIT',
      'total_bytes'    => filesize($path),
      'media_type'     => 'image/gif',
      'media_category' => 'tweet_gif'
     );

    // Make INIT request
    $twitter = new TwitterAPIExchange(self::SETTINGS);
    $response = $twitter->buildOauth($this->urlTwitterMedia, "POST")
    ->setPostfields($postfields)
    ->performRequest();

    log2file("Made INIT request!");
    $data = json_decode($response, true);
    $mediaID = $data["media_id"];

    // APPEND requests

    $rawFileData = file_get_contents($path);
    $chunkStr = chunk_split(base64_encode($rawFileData), ( 2 * (10**6) )); // Each chunk is 2 MB big
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
          log2file("Made STATUS Request");

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
     if ($success)
     {
       $mediaID = $data['media_id'];
       $this->gifID = $mediaID;
       $this->gifIDExpiration = time() + $data['expires_after_secs'];

       log2file("Twitter API: Successfully uploaded $tag.gif");
     }
    // Delete GIF
    // unlink($path);
    return $mediaID;
  }
}

/**
 *
 */
 class ConnectFour
 {
   /**
   * Class attributes
   */
   private $board = array();
   private $turn;
   private $moves = 0;

   const COL = 7;
   const ROW = 7;

   public function __construct($board = NULL, $turn = NULL)
   {

     // Initialize class attributes
     $this->board = array
     (
       array(0,0,0,0,0,0,0),
       array(0,0,0,0,0,0,0),
       array(0,0,0,0,0,0,0),
       array(0,0,0,0,0,0,0),
       array(0,0,0,0,0,0,0),
       array(0,0,0,0,0,0,0),
       array(0,0,0,0,0,0,0)
     );

     if ($board != NULL)
     {
       for ($i=0; $i < self::ROW ; $i++)
       {
         for ($j=0; $j < self::COL ; $j++)
         {
           $this->board[$i][$j] = substr($board, self::COL * $i + $j, 1);
         }
       }
     }

     if ($turn == NULL)
     {
       $this->turn = 'p1';
     }
     else
     {
       $this->turn = $turn;
     }
   }

   public function formatBoard()
   {
     $emojiEmpty = "\u{26AA}"; // WHITE CIRCLE
     $emojiP1    = "\u{1F369}"; // DOUGHNUT
     $emojiP2    = "\u{1F36A}"; // COOKIE

     //$emojiEmpty = "\u{1F518}";  // Radio Button
     //$emojiP1    = "\u{1F534}"; // Red Circle
     //$emojiP2    = "\u{1F535}"; // Blue Circle

     $formattedStr = "";

     for ($i=0; $i < self::ROW ; $i++)
     {
       for ($j=0; $j < self::COL ; $j++)
       {
         switch ($this->board[$i][$j])
         {
           case 0:
             $formattedStr = $formattedStr . $emojiEmpty;
             break;

           case 1:
             $formattedStr = $formattedStr . $emojiP1;
             break;

           case 2:
             $formattedStr = $formattedStr . $emojiP2;
             break;
         }
         if ( $j == (self::COL - 1) )
         {
           $formattedStr = $formattedStr . "\r\n";
         }
       }
     }

     return $formattedStr;
   }


   public function outputBoard()
   {
     $boardStr = '';

     for ($i=0; $i < self::ROW ; $i++)
     {
       for ($j=0; $j < self::COL ; $j++)
       {
         $boardStr = $boardStr . $this->board[$i][$j];
       }
     }

     return $boardStr;
   }

   public function play($player, $colSelected)
   {
     $retVal = '';
     $win = false;

     if ($player == 'p1') // Player 1
     {
       $circle = 1;
       $otherPlayer = 'p2';
     }
     else // Player 2
     {
       $circle = 2;
       $otherPlayer = 'p1';
     }

     if ($this->turn == $player)
     {
       for ($i=self::ROW - 1; $i >= 0 ; $i--)
       {
         if ($this->board[$i][$colSelected] == 0)
         {
           $this->board[$i][$colSelected] = $circle;
           $this->turn = $otherPlayer;

           $win = $this->checkWin($i, $colSelected);

           if ($win)
           {
             $retVal = 'win';
             break;
           }
           else
           {
             $retVal = 'successful move';
           }

           break;
         }
         if ($i == 0)
         {
           $retVal = 'full column';
         }
       }
     }
     else
     {
       $retVal = 'wrong turn';
     }

     if ($this->moves == self::COL * self::ROW && $retVal != 'win')
     {
       $retVal = 'max moves';
     }
     return $retVal;
   }

   private function checkWin($row, $col)
   {

     $winner = $this->horizontalCheck($row, $col);

     if (!$winner)
     {
       $winner = $this->verticalCheck($row, $col);
     }

     if (!$winner)
     {
       $winner = $this->diagonalCheck($row, $col);
     }

     return $winner;
   }

   private function horizontalCheck($row, $col)
   {
     $player = $this->board[$row][$col];
     $count = 0;

     // RIGHT
     for ($i = $col; $i < self::COL  ; $i++)
     {
       if ($this->board[$row][$i] !== $player || $count == 4 )
       {
         break;
       }
       $count++;
     }

     // LEFT
     if ($count != 4)
     {
       for ($i = $col - 1; $i >= 0 ; $i--)
       {
         if ($this->board[$row][$i] !== $player || $count == 4)
         {
           break;
         }
         $count++;
       }
     }

     return $count>=4 ? true : false;
   }

   private function verticalCheck($row, $col)
   {
     $player = $this->board[$row][$col];
     $count = 0;

     // DOWN
     for ($i = $row; $i < self::ROW  ; $i++)
     {
       if ($this->board[$i][$col] !== $player || $count == 4)
       {
         break;
       }
       $count++;
     }

     // UP
     if ($count != 4)
     {
       for ($i = $row - 1; $i >= 0 ; $i--)
       {
         if ($this->board[$i][$col] !== $player || $count == 4)
         {
           break;
         }
         $count++;
       }
     }
     return $count>=4 ? true : false;
   }

   private function diagonalCheck($row, $col)
   {
     $player = $this->board[$row][$col];
     $count = 0;

     // DOWN - RIGHT
     $i = 0;
     while ($row + $i< self::ROW && $col + $i < self::COL)
     {
       if ($this->board[$row + $i][$col + $i] !== $player || $count == 4 )
       {
         break;
       }
       $count++;
       $i++;
     }

     // UP - RIGHT
     if ($count != 4)
     {
       $i = 1; // Ignore [$row][$col]
       while ($row - $i >= 0 && $col + $i < self::COL)
       {
         if ($this->board[$row - $i][$col + $i] !== $player || $count == 4)
         {
           break;
         }
         $count++;
         $i++;
       }
     }

     // DOWN - LEFT
     if ($count != 4)
     {
       $i = 1;
       while ($row + $i < self::ROW && $col - $i >= 0)
       {
         if ($this->board[$row + $i][$col - $i] !== $player || $count == 4)
         {
           break;
         }
         $count++;
         $i++;
       }
     }

     // UP - LEFT
     if ($count != 4)
     {
       $i = 1;
       while ($row - $i >= 0 && $col - $i >= 0)
       {
         if ($this->board[$row - $i][$col - $i] !== $player || $count == 4)
         {
           break;
         }
         $count++;
         $i++;
       }
     }

     return $count>=4 ? true : false;
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
  public function __construct($requester, $queueDir = './tmp', $filePattern = 'phirehose-queue*.queue', $checkInterval = 10)
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

      if (is_array($data) && isset($data['user']['screen_name']))
      {
        // Got answer from Twitter API; Check if bot was mentioned on tweet
        $gotMentioned = false;

        if (isset($data['entities']['user_mentions']))
        {
          // There are mentions on tweet; Check if bot is one of them
          foreach ($data['entities']['user_mentions'] as $mention)
          {
            if ($mention['screen_name'] == 'TweetGamesBot')
            {
              $gotMentioned = true;
              break;
            }
          }
        }
        if ($gotMentioned)
        {
          // Grab data from tweet
          $tweetFrom = $data['user']['screen_name'];    // Username of the user that mentioned the bot
          $tweetID   = $data['id'];                     // ID of that tweet
          $tweetText = urldecode($data['text']);        // The tweet itself
          $replyID   = $data['in_reply_to_status_id'];  // ID of the tweet it is replying to

          $grabTweetID = false; // Defines if you want to grab the tweetID of the bot's response tweet

          log2file('Bot got mentioned: ' . $tweetFrom . ': ' . $tweetText);
          log2file(var_export($data, true));

          // Look for commands in tweet
          $commands = strchr($tweetText, '/');
          if (!$commands) // Did not find any '/' command
          {
            // Check if tweet is a reply or just a new mention
            if ($replyID == NULL)
            {
              //$gifID = $this->requester->requestRandomGIF("thanks");
              $arrPost = $this->requester->formatTweet("Thanks for mentioning me! \u{1F60D}", $tweetFrom, $tweetID);
            }
            else
            {
              $postReply = false;
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

            // CONNECT 4 COMMANDS
            else if ($commandArray[0] == "/c4")
            {
              // Grab the argurment for the /c4 command
              $c4Arg = $commandArray[1];

              // Initialize Tweet Messages booleans
              $isArgValidNumeric = false;
              $isArgValidCommand = false;
              $startNewGame      = false;
              $resetGame         = false;

              // Check if user game a valid argument
              if (is_numeric($c4Arg))
              {
                if ( $c4Arg >= 1 && $c4Arg <= 7 )
                {
                  $c4Arg--; // To compensate for zero-based index array
                  $isArgValidNumeric = true;
                }
                else
                {
                  $arrPost = $this->requester->formatTweet("Hey! Give me a value between 1 and 7!", $tweetFrom, $tweetID);
                }
              }
              else // Argument is not a number
              {
                switch ($c4Arg)
                {
                  case 'new':

                    $c4Player2 = $commandArray[2];

                    if (substr($c4Player2, 0, 1) == '@' ) // Check if second arg is a mention
                    {
                      $isArgValidCommand = true;
                      $c4Player2 = substr($c4Player2, 1); // Remove @ from player name
                    }
                    else
                    {
                      $arrPost = $this->requester->formatTweet("To start a new game, you have to tag your player 2.\nExample: /c4 new @ Player2", $tweetFrom, $tweetID);
                    }
                    break;

                  default:
                    $arrPost = $this->requester->formatTweet("Invalid argument for /c4: $c4Arg", $tweetFrom, $tweetID);
                    break;
                }
              }

              if ($isArgValidCommand || $isArgValidNumeric)
              {
                // Check if user is in DATABASE
                $sql = "SELECT UserID, Username, Connect4, TweetID, Player2, Turn FROM userbase WHERE Username = '$tweetFrom' OR Player2 = '$tweetFrom'";
                $serverReply = $this->requester->sqlQuery($sql);
                if ($serverReply->num_rows == 1)
                {
				           $wrongReply = false; // Initialize wrongReply

                   // Users in the database; grab Connect4 value
                   $userInfo = $serverReply->fetch_assoc();

                   // Check if user is replying to the right tweet
                   if ($userInfo['TweetID'] == $replyID)
                   {
                     // Replying to the correct tweet; Create new board with info from DB
                     $c4 = new ConnectFour($userInfo['Connect4'], $userInfo['Turn']);

                     if ($tweetFrom == $userInfo['Username'])
                     {
                       $currentPlayer = 'p1';
                     }
                     else // $tweetFrom == $userInfo['Player2']
                     {
                       $currentPlayer = 'p2';
                     }
                   }
                   else // not replying to the correct tweet
                   {
                     $arrPost = $this->requester->formatTweet("You have a game running in another tweet! Reply to the that tweet with your command or start a new game by mentioning me with /c4 new", $tweetFrom, $tweetID);
                     $wrongReply = true;
                   }

                   if ($isArgValidNumeric && !$wrongReply) // Play Circle arguments
                   {

                    $playResult = $c4->play($currentPlayer, $c4Arg);
                    $board = $c4->formatBoard();
                    $boardStr = $c4->outputBoard();

                    if ($playResult == 'successful move')
                    {
                      // Switch current player for printing turn
                      if ($currentPlayer == 'p1')
                      {
                        $playerTurn = $data['Player2'];
                        $dbTurn = 'p2';
                      }
                      else // $currentPlayer == 'p2'
                      {
                        $playerTurn = $data['Username'];
                        $dbTurn = 'p1';
                      }
                    }


                    switch ($playResult)
                    {
                      case 'successful move':
                        $arrPost = $this->requester->formatTweet("$board\n Turn: $playerTurn", $tweetFrom, $tweetID);
                        $grabTweetID = true; // Update TweetID in DB

                        $sql = "UPDATE userbase SET Turn = '$dbTurn', Connect4 = '$boardStr'  WHERE UserID = " .  $userInfo["UserID"];
                        $serverReply = $this->requester->sqlQuery($sql);
                        if ($serverReply === true)
                        {
                          log2file("MySQL: Successfully updated turn and board of $tweetFrom");
                        }
                        else
                        {
                          log2file("MySQL: Failed to update turn and board of $tweetFrom \r\n ERROR: $sql \r\n $this->conn->error");
                        }
                        break;

                      case 'win':
                        $arrPost = $this->requester->formatTweet("$board\n @$tweetFrom wins! Congratulations!\n\n Thank you for using Tweet Games!", $tweetFrom, $tweetID);
                        $grabTweetID = true;
                        break;

                      case 'wrong turn':
                        $arrPost = $this->requester->formatTweet("$board\n Hey! It's not your turn!\n\nTurn: $playerTurn", $tweetFrom, $tweetID);
                        $grabTweetID = true;
                        break;

                      case 'full column':
                        $arrPost = $this->requester->formatTweet("$board\n That column is full. Try another one\n\nTurn: $playerTurn", $tweetFrom, $tweetID);
                        $grabTweetID = true;
                        break;

                      case 'max moves':
                        $arrPost = $this->requester->formatTweet("$board\n Wow, this is just... sad. YOU BOTH LOSE!\n\nTweet at me with the following command to play again: /c4 new", $tweetFrom, $tweetID);
                        $grabTweetID = true;
                        break;
                    }
                   }
                   else if ($isArgValidCommand && !$wrongReply) // Valid Non-Numeric Command
                   {
                     switch ($c4Arg)
                     {
                       case 'new': // RESET game
                       if ($replyID == NULL)
                       {
                         $resetGame = true;
                       }
                       else
                       {
                         $arrPost = $this->requester->formatTweet("You can only start a new game on an original tweet, not a reply! Try again by mentioning me with the command /c4 new", $tweetFrom, $tweetID);
                       }

                        break;
                     }
                   }
                }
                else // User is not in DATABASE
                {
                  if ($isArgValidCommand)
                  {
                    switch ($c4Arg)
                    {
                      case 'new': // Start new game
                      if ($replyID == NULL)
                      {
                        $startNewGame = true;
                      }
                      else
                      {
                        $arrPost = $this->requester->formatTweet("You can only start a new game on an original tweet, not a reply! Try again by mentioning me with the command /c4 new", $tweetFrom, $tweetID);
                      }
                       break;
                    }
                  }
                  else // User entered Valid numeric command, but they are not in the DATABASE
                  {
                    $arrPost = $this->requester->formatTweet("Hey! You need to start new game before doing that! Try using /c4 new", $tweetFrom, $tweetID);
                  }
                }
              }

              if ($startNewGame || $resetGame)
              {
                // Clear Connect4 server variable
                if ($resetGame)
                {
                  $sql = "UPDATE userbase
                  SET Connect4 = '0000000000000000000000000000000000000000000000000',
                  Player2 = '$c4Player2',
                  Turn = 'p1'
                  WHERE UserID = " .  $userInfo["UserID"];
                }
                else // started new game
                {
                  $sql = "INSERT INTO userbase (Username, Connect4, Player2, Turn)
                  VALUES('$tweetFrom',
                         '0000000000000000000000000000000000000000000000000',
                         '$c4Player2',
                         'p1'
                        )";
                }

                $serverReply = $this->requester->sqlQuery($sql);
                if ($serverReply === true)
                {
                  log2file("MySQL: Connect4 from $tweetFrom was reset");

                  // Reply to original tweet, displaying new game
                  $c4         = new ConnectFour();
                  $board      =  $c4->formatBoard();
                  $playerTurn = $tweetFrom;
                  $player1    = '@'. $tweetFrom;
                  $player2    = '@'. $c4Player2;
                  $arrPost    = $this->requester->formatTweet("$board\nPlayer1: $player1\nPlayer2: $player2\nTurn: $playerTurn\n\n", $tweetFrom, $tweetID);

                  $grabTweetID = true;
                }
                else
                {
                  log2file("MySQL: Could not start new game for player $tweetFrom \r\n ERROR: $sql \r\n $this->conn->error");
                  $arrPost = $this->requester->formatTweet("Failed to start new game! @SomeSeriousSith : You should check your log file...", $tweetFrom, $tweetID);
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
              $postReply = false;
            }
          }

          if ($postReply)
          {
            // Post response tweet
            $response = $this->requester->postTweet($arrPost);
            log2file('Tweet posted: ' . $arrPost['status']);
            log2file(var_export($response, true));

            if ($grabTweetID)
            {
              $responseTwID = $response['id'];
              // Update database!
              $sql = "UPDATE userbase SET TweetID = $responseTwID WHERE Username = '$tweetFrom' OR Player2 = '$tweetFrom'";
              $serverReply = $this->requester->sqlQuery($sql);
              if ($serverReply === true)
              {
                log2file("MySQL: Successfully added TweetID to $tweetFrom");
              }
              else
              {
                log2file("MySQL: Failed to add TweetID to $tweetFrom \r\n ERROR: $sql \r\n $this->conn->error");
              }
            }
          }
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
