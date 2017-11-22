<?php
/**
 * Tweet Games Bot : Play games on Twitter (Tweet Collector)
 *
 * Code for this collector is based of fennb's example: ghetto-queue-collect.php
 *
 * Using libraries:
 *    Phirehose by fennb - https://github.com/fennb/phirehose
 *
 * PHP version 7
 *
 * @author   Mateus Gurgel <mateus@comecodewith.me>
 * @version  0.1
 * @license  MIT
 * @link     http://github.com/msgurgel/
 * @see      Twitter-API-PHP, Phirehose
 */

require_once('../lib/Phirehose.php');
require_once('../lib/OauthPhirehose.php');


class QueueCollector extends OauthPhirehose
{

  // Subclass specific constants
  const QUEUE_FILE_PREFIX = 'phirehose-queue';
  const QUEUE_FILE_ACTIVE = '.phirehose-queue.current';


  // Member attributes specific to this subclass
  protected $queueDir;
  protected $rotateInterval;
  protected $streamFile;
  protected $statusStream;
  protected $lastRotated;

  /**
   * Overidden constructor to take class-specific parameters
   *
   * @param string $username OAuth Access Token
   * @param string $password OAuth Access Token Secret
   * @param string $queueDir Directory where queue files are stored. Default = ./tmp
   * @param integer $rotateInterval Time in sec that determines when to rotate current queue file. Default = 5
   */
  public function __construct($username, $password, $queueDir = './tmp', $rotateInterval = 10)
  {
    // Sanity check
    if ($rotateInterval < 5)
    {
      throw new Exception('Rotate interval set too low - Must be >= 5 seconds');
    }

    // Set subclass parameters
    $this->queueDir = $queueDir;
    $this->rotateInterval = $rotateInterval;

    // Call parent constructor
    return parent::__construct($username, $password, Phirehose::METHOD_FILTER);
  }

  /**
   * Enqueue each status
   *
   * @param string $status
   */
  public function enqueueStatus($status)
  {

    // Write the status to the stream (must be via getStream())
    fputs($this->getStream(), $status .PHP_EOL);

    /*
     * As of version 0.1, we rotate the current queue file every time the Twitter
     * Streaming API return a result. If the bot gets popular a some point in the
     * future, we can use the code below to avoid overwhelming the server.
     */

     /*
     $now = time();
     if (($now - $this->lastRotated) > $this->rotateInterval)
     {
      // Mark last rotation time as now
      $this->lastRotated = $now;

      // Rotate it
      $this->rotateStreamFile();
    }
    */

    $this->rotateStreamFile();
  }

  /**
   * Returns a stream resource for the current file being written/enqueued to
   *
   * @return resource
   */
  private function getStream()
  {
    // If we have a valid stream, return it
    if (is_resource($this->statusStream))
    {
      return $this->statusStream;
    }

    // If it's not a valid resource, we need to create one
    if (!is_dir($this->queueDir) || !is_writable($this->queueDir))
    {
      throw new Exception('Unable to write to queueDir: ' . $this->queueDir);
    }

    // Construct stream file name, log and open
    $this->streamFile = $this->queueDir . '/' . self::QUEUE_FILE_ACTIVE;
    $this->log2File('Opening new active status stream: ' . $this->streamFile);
    $this->statusStream = fopen($this->streamFile, 'a'); // Append if present (crash recovery)

    if (!is_resource($this->statusStream))
    {
      throw new Exception('Unable to open stream file for writing: ' . $this->streamFile);
    }

    // If we don't have a last rotated time, it's effectively now
    // As of version 0.1, this really doesn't mean anything.
    if ($this->lastRotated == NULL)
    {
      $this->lastRotated = time();
    }

    // return the resource
    return $this->statusStream;
  }

  /**
   * Rotates the stream file if due
   */
  private function rotateStreamFile()
  {
    // Close the stream
    fclose($this->statusStream);

    // Create queue file with timestamp so they're both unique and naturally ordered
    $queueFile = $this->queueDir . '/' . self::QUEUE_FILE_PREFIX . '.' . date('Ymd-His') . '.queue';

    // Do the rotate
    rename($this->streamFile, $queueFile);

    // Did it work?
    if (!file_exists($queueFile))
    {
      throw new Exception('Failed to rotate queue file to: ' . $queueFile);
    }

    // At this point, all looking good - the next call to getStream() will create a new active file
    $this->log2File('Successfully rotated active stream to queue file: ' . $queueFile);
  }

  /**
   * Basic log function.
   *
   *
   * @param string $messages
   */
  protected function log2File($message)
  {
    $myFile = fopen("collect.log", "a") or die("Unable to open collect.log file!");
    $timeNow = date('Y-m-d H:i:s');
    $txt = $timeNow . '--' . $message . "\r\n";
    fwrite($myFile, $txt);
    fclose($myFile);
  }

} // End of class

// The OAuth credentials from apps.twitter.com
define("TWITTER_CONSUMER_KEY", "YOUR KEY");
define("TWITTER_CONSUMER_SECRET", "YOUR SECRET");


// The OAuth data for the twitter account
define("OAUTH_TOKEN", "YOUR TOKEN");
define("OAUTH_SECRET", "YOUR SECRET");

// Start streaming/collecting
$sc = new QueueCollector(OAUTH_TOKEN, OAUTH_SECRET);
$sc->setTrack(array('TweetGamesBot'));
$sc->consume();
