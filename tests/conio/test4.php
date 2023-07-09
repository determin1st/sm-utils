<?php
namespace SM;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
require_once DIR_SM_UTILS.'conio.php';
###
echo "===\n";
var_dump(ConioWin::$error);
//var_dump(Conio::setvbuf());
var_dump(Conio::getch_wait());
echo "===\n";
exit(0);
###
echo "press [t] to type, [q] to quit\n";
while (1)
{
  switch (Conio::getch()) {
  case 'q':
    break 2;
  case 't':
    echo "\nTODO: ";
    break;
  default:
    echo '.';
    usleep(100000);
    break;
  }
}
echo "\n";
