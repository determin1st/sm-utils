<?php declare(strict_types=1);
namespace SM;
use SyncSemaphore;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
###
$o = new SyncSemaphore('sem-lock-unlock', 1, 0);
echo "locking.. ";
if (!$o->lock(-1)) {
  echo "failed!\n";
}
echo "ok, waiting\n";
while (1)
{
  # check for termination
  if (Conio::getch() === 'q') {
    break;
  }
  # take a rest
  echo '.';
  usleep(250000);# 100ms
}
if ($o->unlock($i)) {
  echo "ok($i)\n";
}
else {
  echo "failed($i)\n";
}
echo "\n";
echo "press any key to quit..";
Conio::getch_wait();
echo "\n";
exit(0);

