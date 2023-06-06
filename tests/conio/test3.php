<?php
namespace SM;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
require_once DIR_SM_UTILS.'conio.php';
###
echo "press [q] to quit\n";
while (1)
{
  # wait for input
  while (!Conio::kbhit()) {
    echo '.';usleep(100000);
  }
  # print
  $c = Conio::getch();
  echo '['.$c.']';
  # check for termination
  if ($c === 'q') {
    break;
  }
}
echo "\n";
