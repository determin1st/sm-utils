<?php declare(strict_types=1);
namespace SM;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
require_once DIR_SM_UTILS.'conio.php';
require_once DIR_SM_UTILS.'sync.php';
###
$o = SyncAggregateMaster::new([
  'id'=>'test-aggregate',
  'size'=>100,
]);
if (ErrorEx::is($o))
{
  var_dump($o);
  exit(1);
}
$I = 'SyncAggregateMaster•'.proc_id();
cli_set_process_title($I);
echo $I." started\n";
echo "[1] enable/disable reading\n";
echo "[q] to quit\n\n";
$readFlag = false;
while (1)
{
  switch (Conio::getch()) {
  case 'q':
    echo "> quit\n";
    break 2;
  case '1':
    if ($readFlag)
    {
      echo "\n";
      $readFlag = false;
    }
    else
    {
      echo "> read: ";
      $readFlag = true;
    }
    break;
  case '':
    if (!$readFlag)
    {
      usleep(100000);# 100ms
      break;
    }
    if (($x = $o->read($e)) === null)
    {
      if ($e) {
        break 2;
      }
      usleep(100000);# 100ms
      break;
    }
    echo $x;
    break;
  }
}
$o->close($e);
if ($e)
{
  echo "=ERROR=\n";
  var_dump($e);
  echo "\npress any key to quit..";
  Conio::getch_wait();
}

