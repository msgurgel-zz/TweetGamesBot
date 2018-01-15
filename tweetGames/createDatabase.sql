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

DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS games;
DROP TABLE IF EXISTS game_room;
DROP TABLE IF EXISTS connect_four;



/*
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
*/

CREATE TABLE users
(
	UserID			INT UNSIGNED NOT NULL AUTO_INCREMENT,
    PRIMARY KEY		(UserID),
    Username		MEDIUMTEXT NOT NULL
);


CREATE TABLE games
(
	GameID			INT UNSIGNED NOT NULL AUTO_INCREMENT,
    PRIMARY KEY 	(GameID),
    TweetID			LONGTEXT NOT NULL,
    Expiration		DATETIME DEFAULT '0000-00-00 00:00:00'
);


CREATE TABLE game_room
(
	PlayerOneID		INT UNSIGNED NOT NULL,
    GameID			INT UNSIGNED NOT NULL,
    PRIMARY KEY		(PlayerOneID, GameID),
    FOREIGN KEY		(PlayerOneID) REFERENCES users (UserID),
    FOREIGN KEY		(GameID) REFERENCES games (GameID)
);

CREATE TABLE connect_four
(
	PlayerTwoID		INT UNSIGNED NOT NULL,
    FOREIGN KEY		(PlayerTwoID) REFERENCES users (UserID),
    Board			VARCHAR(49) DEFAULT NULL,
    Turn			BOOLEAN
);

