<?php

require_once('../lib/api/TwitterAPIExchange.php');
require_once('../lib/games/Connect4.php');
require_once('../lib/support/logging.php');
require_once('../lib/db/mySqlConnector.php');
require_once('../lib/api/giphy.php');
require_once('../lib/api/customTwitter.php');

/**
 * Tweet Games Bot : Play games on Twitter (Tweet Reader and Replier)
 *
 * Using libraries:
 *    Twitter-API-PHP by j7mbo  - https://github.com/J7mbo/twitter-api-php
 *
 * PHP version 7
 *
 * @author   Mateus Gurgel <mateus@comecodewith.me>
 * @version  0.3
 * @license  MIT
 * @link     http://github.com/msgurgel/
 * @see      Twitter-API-PHP
 */


class QueueConsumer
{

  // Member attribs
  protected $queueDir;
  protected $filePattern;
  protected $checkInterval;

  private   $mySqlConnector;
  private   $giphyComms;
  private   $tweetComms;

  /**
   * Construct the consumer and start processing
   */
  public function __construct($mySqlConnector, $giphyComms, $tweetComms, $queueDir = './tmp', $filePattern = 'phirehose-queue*.queue', $checkInterval = 10)
  {
    $this->queueDir = $queueDir;
    $this->filePattern = $filePattern;
    $this->checkInterval = $checkInterval;
    $this->giphyComms = $giphyComms;
    $this->tweetComms = $tweetComms;
    $this->mySqlConnector = $mySqlConnector;

    // Sanity checks
    if (!is_dir($queueDir))
    {
      log2file('QueueConsumer.__construct", "Invalid directory for queue files: ' . $queueDir);
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

      log2file("QueueConsumer.process",'Found ' . count($queueFiles) . ' queue files to process...');

      // Iterate over each file (if any)
      foreach ($queueFiles as $queueFile)
      {
        $this->processQueueFile($queueFile);
      }

      // Wait until ready for next check
      log2file("QueueConsumer.process",'Sleeping...');
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

    log2file("QueueConsumer.processQueueFile",'Processing file: ' . $queueFile);

    // Open file
    $fp = fopen($queueFile, 'r');

    // Check if something has gone wrong, or perhaps the file is just locked by another process
    if (!is_resource($fp))
    {
      log2file("QueueConsumer.processQueueFile",'WARN: Unable to open file or file already open: ' . $queueFile . ' - Skipping.');
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

          log2file("QueueConsumer.processQueueFile",'Bot got mentioned: ' . $tweetFrom . ': ' . $tweetText);
          //log2file(var_export($data, true));

          // Look for commands in tweet
          $commands = strchr($tweetText, '/');
          if (!$commands) // Did not find any '/' command
          {
            // Check if tweet is a reply or just a new mention
            if ($replyID == NULL)
            {
              //$gifID = $this->giphyComms->requestRandomGIF("thanks");
              $arrPost = $this->tweetComms->formatTweet("Thanks for mentioning me! \u{1F60D}", $tweetFrom, $tweetID);
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

            log2file("QueueConsumer.processQueueFile",'Found command in: ' .  $commands);

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
                $arrPost = $this->tweetComms->formatTweet("Roll the die! D$dieNum result = " . rand(1, $dieNum), $tweetFrom, $tweetID);
                  break;

                case "/d4c":
                $arrPost = $this->tweetComms->formatTweet("Did you mean: Filthy Acts at a Reasonable Price?", $tweetFrom, $tweetID);
                  break;

                default:
                $arrPost = $this->tweetComms->formatTweet("ERROR 404: Die not found: " . $commandArray[0], $tweetFrom, $tweetID);
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
              if ( is_numeric($c4Arg) )
              {
                if ( $c4Arg >= 1 && $c4Arg <= 7 )
                {
                  $c4Arg--; // To compensate for zero-based index array
                  $isArgValidNumeric = true;
                }
                else
                {
                  $arrPost = $this->tweetComms->formatTweet("Hey! Give me a value between 1 and 7!", $tweetFrom, $tweetID);
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
                      $isArgValidCommand = true;
                      $c4Player2 = substr($c4Player2, 1); // Remove @ from player name
                    }
                    else
                    {
                      $arrPost = $this->tweetComms->formatTweet("To start a new game, you have to tag your player 2.\nExample: /c4 new @ Player2", $tweetFrom, $tweetID);
                    }
                    break;

                  default:
                    $arrPost = $this->tweetComms->formatTweet("Invalid argument for /c4: $c4Arg", $tweetFrom, $tweetID);
                    break;
                }
              }

              if ($isArgValidCommand || $isArgValidNumeric)
              {
                // Check if user is in DATABASE
                $sql = "SELECT UserID, Username, Connect4, TweetID, Player2, Turn FROM userbase WHERE Username = '$tweetFrom' OR Player2 = '$tweetFrom'";
                $serverReply = $this->mySqlConnector->sqlQuery($sql);
                if ($serverReply->num_rows == 1)
                {
				           $wrongReply = false; // Initialize wrongReply

                   // Users in the database; grab Connect4 value
                   $userInfo = $serverReply->fetch_assoc();

                   // Check if user is replying to the right tweet
                   if ($userInfo['TweetID'] == $replyID)
                   {
                     log2file("QueueConsumer.processQueueFile",'Replying to the right tweet');
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
                       $arrPost = $this->mySqlConnector->formatTweet("You're replying to the wrong tweet! Reply to the that tweet with your command or start a new game by mentioning me with /c4 new", $tweetFrom, $tweetID);
                       $wrongReply = true;
                     }
                   }

                   if ($isArgValidNumeric && !$wrongReply) // Play Circle arguments
                   {
                    $playResult = $c4->play($currentPlayer, $c4Arg);
                    log2file("QueueConsumer.processQueueFile",'Play Result = ' . $playResult);
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
                        $arrPost = $this->tweetComms->formatTweet("$board\n Turn: $playerTurn", $tweetFrom, $tweetID);
                        $grabTweetID = true; // Update TweetID in DB

                        $sql = "UPDATE userbase SET Turn = '$dbTurn', Connect4 = '$boardStr'  WHERE UserID = " .  $userInfo["UserID"];
                        $serverReply = $this->mySqlConnector->sqlQuery($sql);
                        if ($serverReply === true)
                        {
                          log2file("QueueConsumer.processQueueFile","MySQL: Successfully updated turn and board of $tweetFrom");
                        }
                        else
                        {
                          log2file("QueueConsumer.processQueueFile","MySQL: Failed to update turn and board of $tweetFrom \r\n ERROR: $sql \r\n $this->conn->error");
                        }
                        break;

                      case "win":
                        $arrPost = $this->tweetComms->formatTweet("$board\n @$tweetFrom wins! Congratulations!\n\n Thank you for using Tweet Games!", $tweetFrom, $tweetID);
                        $grabTweetID = true;
                        break;

                      case "wrong turn":
                        $arrPost = $this->tweetComms->formatTweet("$board\n Hey! It's not your turn!\n\nTurn: $playerTurn", $tweetFrom, $tweetID);
                        $grabTweetID = true;
                        break;

                      case "full column":
                        $arrPost = $this->tweetComms->formatTweet("$board\n That column is full. Try another one\n\nTurn: $playerTurn", $tweetFrom, $tweetID);
                        $grabTweetID = true;
                        break;

                      case "max moves":
                        $arrPost = $this->tweetComms->formatTweet("$board\n Wow, this is just... sad. YOU BOTH LOSE!\n\nTweet at me with the following command to play again: /c4 new", $tweetFrom, $tweetID);
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
                         $arrPost = $this->tweetComms->formatTweet("You can only start a new game on an original tweet, not a reply! Try again by mentioning me with the command /c4 new", $tweetFrom, $tweetID);
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
                        $arrPost = $this->tweetComms->formatTweet("You can only start a new game on an original tweet, not a reply! Try again by mentioning me with the command /c4 new", $tweetFrom, $tweetID);
                      }
                       break;
                    }
                  }
                  else // User entered Valid numeric command, but they are not in the DATABASE
                  {
                    $arrPost = $this->tweetComms->formatTweet("Hey! You need to start new game before doing that! Try using /c4 new", $tweetFrom, $tweetID);
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

                $serverReply = $this->mySqlConnector->sqlQuery($sql);
                if ($serverReply === true)
                {
                  log2file("QueueConsumer.processQueueFile", "MySQL: Connect4 from $tweetFrom was reset");

                  // Reply to original tweet, displaying new game
                  $c4         = new ConnectFour();
                  $board      =  $c4->formatBoard();
                  $playerTurn = $tweetFrom;
                  $player1    = '@'. $tweetFrom;
                  $player2    = '@'. $c4Player2;
                  $arrPost    = $this->tweetComms->formatTweet("$board\nPlayer1: $player1\nPlayer2: $player2\nTurn: $playerTurn\n\n", $tweetFrom, $tweetID);

                  $grabTweetID = true;
                }
                else
                {
                  log2file("QueueConsumer.processQueueFile", "MySQL: Could not start new game for player $tweetFrom \r\n ERROR: $sql \r\n $this->conn->error");
                  $arrPost = $this->tweetComms->formatTweet("Failed to start new game! @ToschePC : You should check your log file...", $tweetFrom, $tweetID);
                }
              }
            }

            // ADMIN COMMANDS
            else if ($commandArray[0] == "/stop")
            {
              if ($tweetFrom == 'ToschePC')
              {
                $stop = true;
                break;
              }
              else
              {
                $arrPost = $this->tweetComms->formatTweet("Hey, only @ToschePC can use that command!", $tweetFrom, $tweetID);
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
            $response = $this->tweetComms->postTweet($arrPost);
            log2file('QueueConsumer.processQueueFile','Tweet posted: ' . $arrPost['status']);


            if ($grabTweetID)
            {
              $responseTwID = $response['id'];
              // Update database!
              $sql = "UPDATE userbase SET TweetID = $responseTwID WHERE Username = '$tweetFrom' OR Player2 = '$tweetFrom'";
              $serverReply = $this->mySqlConnector->sqlQuery($sql);
              if ($serverReply === true)
              {
                log2file("QueueConsumer.processQueueFile", "MySQL: Successfully added TweetID to $tweetFrom");
              }
              else
              {
                log2file("QueueConsumer.processQueueFile", "MySQL: Failed to add TweetID to $tweetFrom \r\n ERROR: $sql \r\n $this->conn->error");
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
    log2file("QueueConsumer.processQueueFile", 'Successfully processed ' . $statusCounter . ' tweets from ' . $queueFile . ' - deleting.');
    unlink($queueFile);

    if ($stop)
    {
      $this->mySqlConnector->closeSQL();
      log2file("QueueConsumer.processQueueFile", 'Received admin command "/stop". Stopping script...');
      exit('ADMIN told me to stop!');
    }

  }
}



// Construct consumer and start processing
$mySqlConnector = new mySqlConnector();
$twitterComms = new TwitterComms();
$giphyComms = new GiphyComms();

$qc = new QueueConsumer($mySqlConnector, $giphyComms, $twitterComms);
$qc->process();
