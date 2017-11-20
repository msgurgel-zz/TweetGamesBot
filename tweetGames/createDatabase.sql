/*
*
* File			: twGamesCreate.sql
* By			: Mateus Gurgel (Matt)
* Date			: November 11th, 2017
* Description	: Creates the @TweetGamesBot database and its tables
*
*/

CREATE DATABASE IF NOT EXISTS tweetgames;
USE tweetgames;

DROP TABLE IF EXISTS userbase;
CREATE TABLE userbase 
(
  UserID 		INT UNSIGNED NOT NULL AUTO_INCREMENT,
  PRIMARY KEY 	(UserID),
  Username 		MEDIUMTEXT NOT NULL,
  Connect4 		VARCHAR(49) DEFAULT NULL,
  TweetID		BIGINT UNSIGNED,
  Player2		MEDIUMTEXT NOT NULL,
  Turn			MEDIUMTEXT NOT NULL,
  Expiration 	DATETIME DEFAULT '0000-00-00 00:00:00'
);

