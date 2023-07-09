<?php
namespace SM;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
require_once DIR_SM_UTILS.'conio.php';
###
echo "press [q] to quit\n";
while (1)
{
  switch ($c = Conio::getch()) {
  case 'q':
    break 2;
  case '':
    echo '.';
    usleep(100000);
    break;
  default:
    echo '['.$c.']';
    break;
  }
}
echo "\n";
