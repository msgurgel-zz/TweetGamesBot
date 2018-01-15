<?php

/**
 * Basic log function.
 * @param string $event class.method that called the function.
 * @param string $messages Message to be logged.
 */
function log2file($event, $message)
{
  $myFile = fopen("tg.log", "a") or die("Unable to open tg.log file!");
  $timeNow = date('Y-m-d H:i:s');
  $txt = $timeNow . ' [' . $event . '] ' . $message . "\r\n";
  fwrite($myFile, $txt);
  fclose($myFile);
}

?>
