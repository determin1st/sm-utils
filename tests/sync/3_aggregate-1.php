<?php declare(strict_types=1);
namespace SM;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
require_once DIR_SM_UTILS.'conio.php';
require_once DIR_SM_UTILS.'sync.php';
###
exit(0);
$o = SyncAggregateMaster::new([
  'id'       => 'test-aggregate',
  'callback' => (function(): void
  {
  })
]);
if (ErrorEx::is($o))
{
  var_dump($o);
  exit(1);
}
$I = 'SyncAggregateMaster•'.proc_id();
cli_set_process_title($I);
echo $I." started\n";
echo "[1] to enable/disable writing\n";
echo "[q] to quit\n\n";
while (1)
{
  switch (Conio::getch()) {
  case 'q':
    echo "> quit\n";
    break 2;
  case '1':
    break;
  case '':
    if (!$o->flush($e)) {
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

