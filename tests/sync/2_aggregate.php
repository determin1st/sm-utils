<?php declare(strict_types=1);
namespace SM;
require_once
  __DIR__.DIRECTORY_SEPARATOR.
  '..'.DIRECTORY_SEPARATOR.
  '..'.DIRECTORY_SEPARATOR.
  'autoload.php';
###
ErrorLog::init(['ansi' => Conio::is_ansi()]);
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
error_dump($e);

