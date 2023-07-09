<?php
namespace SM;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
require_once DIR_SM_UTILS.'conio.php';
###
echo "press [q] to quit\n";
while(1)
{
  # get character from the console and
  # check it is a keycode
  if (Conio::is_keycode($c = Conio::getch_wait()))
  {
    # convert into array for convinience
    $a = Conio::to_keycode($c);
    # convert into hexademical represenation
    $c = (
      str_pad(dechex($a[0]), 2, '0', STR_PAD_LEFT).
      str_pad(dechex($a[1]), 2, '0', STR_PAD_LEFT)
    );
    $c = 'key:'.strtoupper($c);
  }
  # print obtained character or keycode
  echo '['.$c.']';
  # check for termination
  if ($c === 'q') {
    break;
  }
}
echo "\n";
