<?php
namespace SM;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
###
echo "press any key to quit..";
while (!Conio::kbhit())
{
  Conio::putch('.');
  usleep(100000);# 100ms
}
Conio::getch_wait();# discard character
echo "\n";

