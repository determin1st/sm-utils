<?php declare(strict_types=1);
namespace SM;
require_once
  __DIR__.DIRECTORY_SEPARATOR.
  '..'.DIRECTORY_SEPARATOR.
  'conio.php';
###
echo "press [q] to quit\n";
while (1)
{
  # get a character
  $r = await(Conio::readch());
  if (!$r->ok)
  {
    echo "\n".ErrorLog::render($r, true);
    break;
  }
  # check it is a keycode
  if (Conio::is_keycode($c = $r->value))
  {
    # convert into array for convinience
    $a = [ord($c[0]), ord($c[1])];
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
###
