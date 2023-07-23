<?php declare(strict_types=1);
namespace SM;
use SyncSemaphore;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
###
$o = new SyncSemaphore('sem-lock-unlock', 1, 0);
echo "press [l] to lock, [u] to unlock, [q] to quit..\n";
while (1)
{
  switch (Conio::getch_wait()) {
  case 'q':
    break 2;
  case 'l':
    if ($o->lock(0)) {
      echo "lock: ok\n";
    }
    else {
      echo "lock: failed\n";
    }
    break;
  case 'u':
    $i = -1;
    if ($o->unlock($i)) {
      echo "unlock: ok($i)\n";
    }
    else {
      echo "unlock: failed($i)\n";
    }
    break;
  }
}
echo "\n";

