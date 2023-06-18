<?php
namespace SM;
use SyncSemaphore;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
require_once DIR_SM_UTILS.'conio.php';
###
$o = new SyncSemaphore('sem-lock-unlock', 1, false);
echo "press [l] to lock, [u] to unlock, [q] to quit..\n";
while (1)
{
  # check for termination
  switch (Conio::getch()) {
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
  case 'q':
    echo "\n";
    exit(0);
    break;
  }
}

