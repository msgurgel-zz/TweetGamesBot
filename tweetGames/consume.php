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

      $data = json_decode($rawStatus, true);

      if (is_array($data) && isset($data['user']['screen_name']) && $data['entities']['user_mentions'][0]['screen_name'] == 'TweetGamesBot')
      {
        $tweetFrom = $data['user']['screen_name'];    // Username of the user that mentioned the bot
        $tweetID   = $data['id'];                     // ID of that tweet
        $tweetText = urldecode($data['text']);        // The tweet itself
        $isReply   = $data['in_reply_to_status_id'];  // ID of the tweet it is replying to

        $this->myLog('Bot got mentioned: ' . $tweetFrom . ': ' . $tweetText . ' ***Extra info to follow***');
        $this->myLog(var_export($data, true));

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
            $arrPost = array('status' => '@' . $tweetFrom . ' Thanks for replying to my tweet!' . "\n\n" . 'Time: ' . date('h:i:s A'),
                             'in_reply_to_status_id' => $tweetID
                           );
          }
        }
        else // Found a '/' command
        {
          $commandArray = explode(" ", $commands);

          $this->myLog('Found command in: ' .  $commands);

          //$v = var_export($commandArray, true);
          //$this->myLog('Var Export: ' . $v);

          // Position 0 holds /command, other position holds possible arguments
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
        }

        // Post response tweet
        $this->postTweet($arrPost);
        $this->myLog('Tweet: ' . $arrPost['status']);

      }
    } // End while

    // Release lock and close
    flock($fp, LOCK_UN);
    fclose($fp);

    // All done with this file
    $this->myLog('Successfully processed ' . $statusCounter . ' tweets from ' . $queueFile . ' - deleting.');
    unlink($queueFile);

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
