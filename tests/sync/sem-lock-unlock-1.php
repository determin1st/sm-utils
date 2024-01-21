<?php declare(strict_types=1);
namespace SM;
require_once
  __DIR__.DIRECTORY_SEPARATOR.
  '..'.DIRECTORY_SEPARATOR.
  '..'.DIRECTORY_SEPARATOR.
  'autoload.php';
###
$o = new \SyncSemaphore('sem-lock-unlock', 1, 0);
echo "locking.. ";
if (!$o->lock(-1))
{
  echo "failed!\n";
  exit(1);
}
echo "ok\n";
echo "press any key to unlock..";
while (!await(Conio::readch())->value)
{}
echo "\n";
echo "unlocking.. ";
if ($o->unlock($i)) {
  echo "ok($i)";
}
else
{
  echo "failed($i)";
  exit(1);
}
echo "\n";
echo "press any key to quit..";
while (!await(Conio::readch())->value)
{}
echo "\n";
exit(0);

