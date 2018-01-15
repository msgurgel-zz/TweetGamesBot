<?php
/**
 * Connect 4 game logic contained in a class.
 *
 *
 * Used by TweetGamesBot to allow 2 users to play
 * connect 4 on Twitter.
 *
 * PHP version 7
 *
 * @author   Mateus Gurgel <mateus@comecodewith.me>
 * @version  1.0
 * @license  MIT
 * @link     http://github.com/msgurgel/
 * @see      TweetGamesBot - https://github.com/msgurgel/TweetGamesBot
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

   /**
   * Default constructor; Initializes the board and the current turn.
   *
   * If no arguments are passed, the constructor will consider this the
   * start of a new game, and will initialize the board and turn approapriately.
   *
   * @param string $board Contains a connect 4 board formatted as a 49 char (7 * 7) long string.
   * @param string $player Identifies the current player turn.
   *
   * @return void
   */
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
           if ($this->board[$i][$j] != 0)
           {
             $this->moves++;
           }
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

   /**
   * Returns a populated string with the current Connect 4 board,
   * ready to be printed/displayed (Emojis).
   *
   * @return string $formattedStr A 49-char long string containing a formatted Connect 4 board.
   */
   public function formatBoard()
   {
     $emojiEmpty = "\u{26AA}";  // WHITE CIRCLE
     $emojiP1    = "\u{1F369}"; // DOUGHNUT
     $emojiP2    = "\u{1F36A}"; // COOKIE

     // Number Emojis
     $emojiOne    = "\u{0031}" . "\u{20E3}";
     $emojiTwo    = "\u{0032}" . "\u{20E3}";
     $emojiThree  = "\u{0033}" . "\u{20E3}";
     $emojiFour   = "\u{0034}" . "\u{20E3}";
     $emojiFive   = "\u{0035}" . "\u{20E3}";
     $emojiSix    = "\u{0036}" . "\u{20E3}";
     $emojiSeven  = "\u{0037}" . "\u{20E3}";

     $formattedStr = "";

     // Add column's index to the top of the board
     $formattedStr = $emojiOne . $emojiTwo . $emojiThree . $emojiFour . $emojiFive . $emojiSix . $emojiSeven . "\n";

     // Populate the board string
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

   /**
   * Outputs the current Connect 4 board with no formatting whatsoever.
   *
   * This string will most likely be saved to the TweetGamesBot database,
   * as an easy way to retrieve a running game's current status.
   *
   * @return string $boardStr A 49-char long string holding the values of each field of the current games board.
   */
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

   /**
   * Makes a move in the current Connect 4 game.
   *
   *
   * @param string $player Player that is trying to make a move (p1 or p2).
   * @param int $colSelected A integer between 0 and 6 that ids the column the player is trying to drop a token.
   *
   * @return string $retVal The results of the performed move attempt.
   */
   public function play($player, $colSelected)
   {
     $retVal = '';
     $win = false;

     // Check which player is making a move
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

     // If it is that player's turn...
     if ($this->turn == $player)
     {
       for ($i = self::ROW - 1; $i >= 0 ; $i--)
       {
         if ($this->board[$i][$colSelected] == 0)
         {
           $this->board[$i][$colSelected] = $circle;
           $this->turn = $otherPlayer;

           // Check the results of the performed move
           $win = $this->checkWin($i, $colSelected);

           if ($win) // Move connected 4 tokens
           {
             $retVal = 'win';
           }
           else // Move did not win the game, but was a valid move.
           {
             $retVal = 'successful move';

           }
           break;
         }
         if ($i == 0) // Player tried to put a token in a column that is full
         {
           $retVal = 'full column';
         }
       }
     }
     else // Player is trying to play during the opponent's turn
     {
       $retVal = 'wrong turn';
     }

     // If the last move did not win the game AND there are no more empty spaces in the board...
     if ($this->moves == self::COL * self::ROW && $retVal != 'win')
     {
       $retVal = 'max moves';
     }
     return $retVal;
   }

   /**
   * Checks if a token in a specifc row and column would win the current game.
   *
   * @param int $row Row of the move
   * @param int $col Column of the move
   *
   * @see ConnectFour::horizontalCheck()    For logic on horizontal 4 token check.
   * @see ConnectFour::verticalCheck()      For logic on vertical 4 token check.
   * @see ConnectFour::diagonalCheck()      For logic on diagonal 4 token check.
   *
   * @return bool $winner True if the move would win the game, false otherwise.
   */
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

   /**
   * Checks if a token in a specifc row and column would connect four in a HORIZONTAL
   * line within the current game.
   *
   * @param int $row Row of the move
   * @param int $col Column of the move
   *
   * @return bool $winner True if the move would win the game, false otherwise.
   */
   private function horizontalCheck($row, $col)
   {
     $player = $this->board[$row][$col];
     $count = 0;

     // RIGHT
     for ($i = $col; $i < self::COL  ; $i++)
     {
       if ($this->board[$row][$i] != $player || $count == 4 )
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
         if ($this->board[$row][$i] != $player || $count == 4)
         {
           break;
         }
         $count++;
       }
     }

     return $count>=4 ? true : false;
   }

   /**
   * Checks if a token in a specifc row and column would connect four in a VERTICAL
   * line within the current game.
   *
   * @param int $row Row of the move
   * @param int $col Column of the move
   *
   * @return bool $winner True if the move would win the game, false otherwise.
   */
   private function verticalCheck($row, $col)
   {
     $player = $this->board[$row][$col];
     $count = 0;

     // DOWN
     for ($i = $row; $i < self::ROW  ; $i++)
     {
       if ($this->board[$i][$col] != $player || $count == 4)
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
         if ($this->board[$i][$col] != $player || $count == 4)
         {
           break;
         }
         $count++;
       }
     }
     return $count>=4 ? true : false;
   }

   /**
   * Checks if a token in a specifc row and column would connect four in a DIAGONAL
   * line within the current game.
   *
   * @param int $row Row of the move
   * @param int $col Column of the move
   *
   * @return bool $winner True if the move would win the game, false otherwise.
   */
   private function diagonalCheck($row, $col)
   {
     $player = $this->board[$row][$col];
     $count = 0;

     // DOWN - RIGHT
     $i = 0;
     while ($row + $i< self::ROW && $col + $i < self::COL)
     {
       if ($this->board[$row + $i][$col + $i] != $player || $count == 4 )
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
         if ($this->board[$row - $i][$col + $i] != $player || $count == 4)
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
       // Changing angle; reset count
       $count = 0;
       $i = 0;
       while ($row + $i < self::ROW && $col - $i >= 0)
       {
         if ($this->board[$row + $i][$col - $i] != $player || $count == 4)
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
         if ($this->board[$row - $i][$col - $i] != $player || $count == 4)
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
?>
