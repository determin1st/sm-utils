<?php declare(strict_types=1);
namespace SM;
require_once
  __DIR__.DIRECTORY_SEPARATOR.
  '..'.DIRECTORY_SEPARATOR.
  'conio.php';
###
echo "press any key to quit..";
while (!Conio::kbhit())
{
  Conio::putch('.');
  usleep(100000);# 100ms
}
await(Conio::getch());
echo "\nchar: ".Conio::$LAST_CHAR."\n";
###
