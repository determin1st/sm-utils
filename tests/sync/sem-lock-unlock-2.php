<?php declare(strict_types=1);
namespace SM;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
###
$o = new \SyncSemaphore('sem-lock-unlock', 1, 0);
echo "SyncSemaphore object test\n";
echo "press [l]ock, [u]nlock or [q]uit\n";
echo "\n";
while (1)
{
  switch (await(Conio::readch())->value) {
  case 'l':
    if ($o->lock(0)) {
      echo "> lock: ok\n";
    }
    else {
      echo "> lock: failed\n";
    }
    break;
  case 'u':
    $i = -1;
    if ($o->unlock($i)) {
      echo "> unlock: ok($i)\n";
    }
    else {
      echo "> unlock: failed($i)\n";
    }
    break;
  case 'q':
    echo "> quit\n";
    break 2;
  }
}
###
