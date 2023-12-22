<?php declare(strict_types=1);
namespace SM;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
###
$o = SyncAggregate::new([
  'id'=>'test-aggregate',
  'size'=>100,
]);
if (ErrorEx::is($o))
{
  var_dump($o);
  exit(1);
}
$I = 'SyncAggregate•'.($PID = proc_id());
cli_set_process_title($I);
echo $I." started\n";
echo "[1] write once\n";
echo "[2] write periodically (enable/disable)\n";
echo "[q] to quit\n\n";
$writeFlag = false;
$writeRate = 3;# 1=100ms
$writeNum  = 0;
while (1)
{
  $e = null;
  switch (Conio::getch()) {
  case 'q':
    if ($writeFlag) {
      echo "\n";
    }
    echo "> quit\n";
    break 2;
  case '1':
    if ($writeFlag) {
      break;# ignore when periodic writes enabled
    }
    echo "> write: ";
    $a = '{'.$I.'}';
    if (!$o->write($a, $e))
    {
      echo "FAIL\n";
      break 2;
    }
    while (!$o->flush($e) && !$e) {
      usleep(50000);# 50ms
    }
    if ($e)
    {
      if ($e->level)
      {
        echo "FAIL\n";
        break 2;
      }
      echo "SKIP\n";
      break;
    }
    echo $a."\n";
    break;
  case '2':
    if ($writeFlag)
    {
      echo "\n";
      $writeFlag = false;
    }
    else
    {
      echo "> write: ";
      $writeFlag = true;
      $writeNum  = 0;
    }
    break;
  case '':
    if ($writeFlag && ++$writeNum > $writeRate)
    {
      $a = '['.$PID.']';
      if (!$o->write($a, $e)) {
        break 2;
      }
      echo "_";
      $writeNum = 0;
    }
    if ($o->isPending() && $o->flush($e))
    {
      if ($e) {
        echo "|";# skip/timeout
      }
      else {
        echo "/\\";# flushed
      }
    }
    elseif ($e) {
      break 2;
    }
    usleep(100000);# 100ms
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

