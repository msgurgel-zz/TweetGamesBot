<?php

require_once('../lib/support/logging.php');

  class GiphyComms
  {

    /**
    * Class Attributes
    */

    // Constants
    const GIPHY_KEY  = 'YOUR KEY';

    // Variables
    private $gifID;
    private $gifIDExpiration;

    /**
    * Initialize class variables
    */
    function __construct()
    {
      $this->gifID = 0;
      $this->gifIDExpiration = 0;
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

      return $path;
    }
  }

?>
