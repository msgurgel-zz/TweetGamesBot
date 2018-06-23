<?php
/**
 * Makes requests to outside sources
 *
 * This Class makes/receives requests to/from:
 *    The Twitter API (using TwitterAPIExchange.php)
 *    The GIPHY API (using cURL)
 *    The MySQL database
 */
class Requester
{

  /**
  * Member Attributes
  */

  // MySQL DB - Constants
  const SERVER_NAME  = "localhost";
  const USERNAME     = "root";
  const PASSWORD     = "wxlcba07";
  const DB_NAME      = "tweetgames";

  // MySQL DB - Variables
  private $conn;

  // Twitter API - Constants
  const SETTINGS = array(
  'oauth_access_token' => "927534227897438213-IYjtsugcBMcwIOoNdzLjaGRdUfDg6AJ",
  'oauth_access_token_secret' => "40wrrssBdAjqVAMnYxVsrzdBhIr95Uz0LWHTKCh4uPyTk",
  'consumer_key' => "YW40rfeD4yVEodlMzXlEVjLNF",
  'consumer_secret' => "0LhY7xLf4hY7AC7vMhRD5cXKfIqMSg0SR2eu8MeefPqKixDcXZ"
  );

  // Twitter API - Variables
  private $urlTwitterUpdate;
  private $urlTwitterMedia;

  private $gifID;
  private $gifIDExpiration;

  // GIPHY API - Constants
  const GIPHY_KEY  = 'm9SiKMx2NtOvW3QYvJVA2bdlHsKXBKSm';

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
  * Formats tweet before posting
  *
  * @param string $message        Text that will be turned into a tweet
  * @param string $user           Twitter handle of the user you want your tweet to reply to/mention
  * @param int    $tweetReplyID   ID of the tweet you want to reply to
  * @param int    $mediaID        ID of the media you want to attach to the tweet (image, gif, video)
  *
  * @return array Contains the fields required to post a tweet (message, replyID, etc)
  *
  * @see          Requester::postTweet()          To understand how the returned array is used
  * @see          Requester::requestRandomGIF     To understand how the mediaID is generated
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

    /* ---------------------------- GIPHY API ------------------------------*/
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

function handleCommand($tweetInfo, $commandArray, $requester, $postReply)
{
  $postVals = NULL;
  $tweetFrom = $tweetInfo['user']['screen_name'];
  $tweetID   = $tweetInfo['id'];

  /* ------------------------- DICE COMMANDS ---------------------------- */
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
      $postVals = $requester->formatTweet("Roll the die! D$dieNum result = " . rand(1, $dieNum), $tweetFrom, $tweetID);
        break;

      case "/d4c":
      $postVals = $requester->formatTweet("Did you mean: Filthy Acts at a Reasonable Price?", $tweetFrom, $tweetID);
        break;

      default:
      $postVals = $requester->formatTweet("ERROR 404: Die not found: " . $commandArray[0], $tweetFrom, $tweetID);
        break;
    }
  }

  /* ----------------------- CONNECT 4 COMMANDS ------------------------- */
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
    if ( is_numeric($c4Arg) )
    {
      if ( $c4Arg >= 1 && $c4Arg <= 7 )
      {
        $c4Arg--; // To compensate for zero-based index array
        $isArgValidNumeric = true; // Argument passed is valid and it's a number
      }
      else
      {
        // Argument is a number outside of the column range
        $postVals = $requester->formatTweet("Hey! Give me a value between 1 and 7!", $tweetFrom, $tweetID);
      }
    }
    else // Argument is not a number
    {
      switch ($c4Arg)
      {
        case "new":

          $c4Player2 = $commandArray[2];

          if (substr($c4Player2, 0, 1) == '@' ) // Check if second arg is a mention
          {
            $isArgValidCommand = true;          // Argument is valid and it's a command
            $c4Player2 = substr($c4Player2, 1); // Remove @ from player name
          }
          else
          {
            // User tried to start a new game, but didn't tag a second player
            $postVals = $requester->formatTweet("To start a new game, you have to tag your player 2.\nExample: /c4 new @ Player2", $tweetFrom, $tweetID);
          }
          break;

        default:
          $postVals = $requester->formatTweet("Invalid argument for /c4: $c4Arg", $tweetFrom, $tweetID);
          break;
      }
    }

    if ($isArgValidCommand || $isArgValidNumeric) // User entered a valid command
    {
      // Check if user is in DATABASE
      $sql = "SELECT UserID, Username, Connect4, TweetID, Player2, Turn FROM userbase WHERE Username = '$tweetFrom' OR Player2 = '$tweetFrom'";
      $serverReply = $requester->sqlQuery($sql);
      if ($serverReply->num_rows == 1)
      {
         $wrongReply = false; // Initialize wrongReply

         // Users in the database; grab Connect4 value
         $userInfo = $serverReply->fetch_assoc();

         // Check if user is replying to the right tweet
         if ($userInfo['TweetID'] == $replyID)
         {
           log2file('Replying to the right tweet');
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
           // If the replied to the wrong tweet and does not want to reset the game...
           if ($c4Arg != 'new')
           {
             $postVals = $requester->formatTweet("You're replying to the wrong tweet! Reply to the that tweet with your command or start a new game by mentioning me with /c4 new", $tweetFrom, $tweetID);
             $wrongReply = true;
           }
         }

         if ($isArgValidNumeric && !$wrongReply) // Play Circle arguments
         {
          $playResult = $c4->play($currentPlayer, $c4Arg);
          log2file('Play Result = ' . $playResult);
          $board = $c4->formatBoard();
          $boardStr = $c4->outputBoard();

          if ($playResult == 'successful move')
          {
            // Switch current player for printing turn
            if ($currentPlayer == 'p1')
            {
              $playerTurn = '@' . $userInfo['Player2'];
              $dbTurn = 'p2';
            }
            else // $currentPlayer == 'p2'
            {
              $playerTurn = '@' . $userInfo['Username'];
              $dbTurn = 'p1';
            }
          }
          else
          {
            // Switch current player for printing turn
            if ($currentPlayer == 'p1')
            {
              $playerTurn = '@' . $userInfo['Username'];
            }
            else // $currentPlayer == 'p2'
            {
              $playerTurn = '@' . $userInfo['Player2'];
            }
          }


          switch ($playResult)
          {
            case "successful move":
              $postVals = $requester->formatTweet("$board\n Turn: $playerTurn", $tweetFrom, $tweetID);
              $grabTweetID = true; // Update TweetID in DB

              $sql = "UPDATE userbase SET Turn = '$dbTurn', Connect4 = '$boardStr'  WHERE UserID = " .  $userInfo["UserID"];
              $serverReply = $requester->sqlQuery($sql);
              if ($serverReply === true)
              {
                log2file("MySQL: Successfully updated turn and board of $tweetFrom");
              }
              else
              {
                log2file("MySQL: Failed to update turn and board of $tweetFrom \r\n ERROR: $sql \r\n $this->conn->error");
              }
              break;

            case "win":
              $postVals = $requester->formatTweet("$board\n @$tweetFrom wins! Congratulations!\n\n Thank you for using Tweet Games!", $tweetFrom, $tweetID);
              $grabTweetID = true;
              break;

            case "wrong turn":
              $postVals = $requester->formatTweet("$board\n Hey! It's not your turn!\n\nTurn: $playerTurn", $tweetFrom, $tweetID);
              $grabTweetID = true;
              break;

            case "full column":
              $postVals = $requester->formatTweet("$board\n That column is full. Try another one\n\nTurn: $playerTurn", $tweetFrom, $tweetID);
              $grabTweetID = true;
              break;

            case "max moves":
              $postVals = $requester->formatTweet("$board\n Wow, this is just... sad. YOU BOTH LOSE!\n\nTweet at me with the following command to play again: /c4 new", $tweetFrom, $tweetID);
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
               $postVals = $requester->formatTweet("You can only start a new game on an original tweet, not a reply! Try again by mentioning me with the command /c4 new", $tweetFrom, $tweetID);
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
              $postVals = $requester->formatTweet("You can only start a new game on an original tweet, not a reply! Try again by mentioning me with the command /c4 new", $tweetFrom, $tweetID);
            }
             break;
          }
        }
        else // User entered Valid numeric command, but they are not in the DATABASE
        {
          $postVals = $requester->formatTweet("Hey! You need to start new game before doing that! Try using /c4 new", $tweetFrom, $tweetID);
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

      $serverReply = $requester->sqlQuery($sql);
      if ($serverReply === true)
      {
        log2file("MySQL: Connect4 from $tweetFrom was reset");

        // Reply to original tweet, displaying new game
        $c4         = new ConnectFour();
        $board      =  $c4->formatBoard();
        $playerTurn = $tweetFrom;
        $player1    = '@'. $tweetFrom;
        $player2    = '@'. $c4Player2;
        $postVals    = $requester->formatTweet("$board\nPlayer1: $player1\nPlayer2: $player2\nTurn: $playerTurn\n\n", $tweetFrom, $tweetID);

        $grabTweetID = true;
      }
      else
      {
        log2file("MySQL: Could not start new game for player $tweetFrom \r\n ERROR: $sql \r\n $this->conn->error");
        $postVals = $requester->formatTweet("Failed to start new game! @SomeSeriousSith : You should check your log file...", $tweetFrom, $tweetID);
      }
    }
  }

  /* ------------------------- ADMIN COMMANDS --------------------------- */
  else if ($commandArray[0] == "/stop")
  {
    if ($tweetFrom == 'ToschePC')
    {
      $postReply = 's'; // Setting post reply to "s" signals the program to stop
    }
    else
    {
      $postVals = $requester->formatTweet("Hey, only @ToschePC can use that command!", $tweetFrom, $tweetID);
    }
  }

  else // Invalid command
  {
    $postReply = 'n';
  }
  return $postVals;
}

$req = new requester();
$cArr = array('/d4');
$postReply = 'y';
$tweetInfo = array (
    'user'  => array('screen_name' => 'ToschePC'),
     'id'   => 6969
   );

$arrPost = handleCommand($tweetInfo, $cArr, $req, $postReply);

print_r($postReply);
echo "<br>";
print_r($arrPost);



?>
