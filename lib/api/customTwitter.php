<?php

require_once('../support/logging.php');

class TwitterComms
{
  const urlUPDATE = "https://api.twitter.com/1.1/statuses/update.json";
  const urlMEDIA = "https://upload.twitter.com/1.1/media/upload.json";

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
  * @see          TwitterComms::postTweet()          To understand how the returned array is used
  * @see          GiphyComms::requestRandomGIF()     To understand how the mediaID is generated
  *
  */
  public function formatTweet($message, $user = NULL, $tweetReplyID = NULL, $mediaID = NULL)
  {
    if ($mediaID != NULL)
    {
      $retVal = array('status' => "@$user $message \n\n Time: " . date('h:i:s A'),
                      'in_reply_to_status_id' => $tweetReplyID,
                      'media_ids' => $mediaID
                      );
    }
    else
    {
      $retVal = array('status' => "@$user $message \n\n Time: " . date('h:i:s A'),
                      'in_reply_to_status_id' => $tweetReplyID,
                      );
    }

    return $retVal;
  }

    /**
     * Post tweet through the twitter-api-php library
     *
     * @see TwitterAPIExchange.php
     * @param $postFields Array for the Twitter API request
     * @return mixed
     * @throws Exception
     */
  public function postTweet($postFields)
  {
    $twitter = new TwitterAPIExchange(SETTINGS);

    $twResponse = $twitter->buildOauth(self::urlUPDATE, "POST")
    ->setPostfields($postFields)
    ->performRequest();

    return json_decode($twResponse, true);
  }

  public function generateMediaID($path)
  {

    // Prepare INIT request
    $postFields = array(
      'command'        => 'INIT',
      'total_bytes'    => filesize($path),
      'media_type'     => 'image/gif',
      'media_category' => 'tweet_gif'
     );

    // Make INIT request
    $twitter = new TwitterAPIExchange(self::SETTINGS);
    $response = $twitter->buildOauth(self::urlMEDIA, "POST")
    ->setPostfields($postFields)
    ->performRequest();

    log2file("TwitterComms.requestRandomGIF","Made INIT request!");
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
      $postFields = array(
        'command'        => 'APPEND',
        'media_id'       => $mediaID,
        'media_data'     => $value,
        'media_type'     => 'image/gif',
        'segment_index'  => $index
       );
       $index++;

       log2file("TwitterComms.requestRandomGIF", "Prepared REQUEST $index!");

       // Make APPEND request
       $response = $twitter->buildOauth(self::urlMEDIA, "POST")
       ->setPostfields($postFields)
       ->performRequest();

       log2file("TwitterComms.requestRandomGIF", "Made APPEND request #$index!");
    }


    // GIF has been uploaded; Prepare FINALIZE command
    $postFields = array(
      'command' => 'FINALIZE',
      'media_id'=> $mediaID
     );

     // Make FINALIZE request
     $response = $twitter->buildOauth(self::urlMEDIA, "POST")
     ->setPostfields($postFields)
     ->performRequest();

     log2file("TwitterComms.requestRandomGIF", "Made FINALIZE request!");

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
           log2file("TwitterComms.requestRandomGIF", "Twitter API: Processing GIF upload. Sleeping for $checkAfter seconds...");
           sleep($checkAfter);
         }

          // Make STATUS request
          $twitter2 = new TwitterAPIExchange(self::SETTINGS);
          $response = $twitter2->setGetfield($getfield)
          ->buildOauth(self::urlMEDIA, "GET")
          ->performRequest();

          $data = json_decode($response, true);
          log2file("TwitterComms.requestRandomGIF", "Made STATUS Request");

          if ($data['processing_info']['state'] == 'failed')
          {
            // Something went wrong!
            log2file("TwitterComms.requestRandomGIF", "Twitter API: Could not upload $tag.gif -- STATUS returned failed");
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
       log2file("TwitterComms.requestRandomGIF", "Twitter API: Failed to upload file $tag.gif Reason: " . $data['error']);
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

       log2file("TwitterComms.requestRandomGIF", "Twitter API: Successfully uploaded $tag.gif");
     }
    // Delete GIF
    // unlink($path);
    return $mediaID;
  }

}

?>
