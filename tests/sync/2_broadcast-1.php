<?php declare(strict_types=1);
namespace SM;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
require_once DIR_SM_UTILS.'conio.php';
require_once DIR_SM_UTILS.'sync.php';
###
$o = SyncBroadcastMaster::new([
  'id'       => 'test-broadcast',
  'callback' => (function(
    int $case, string $id, string $info=''
  ):void
  {
    echo '> ';
    switch ($case) {
    case 0:
      echo 'reader['.$id.']: detached';
      echo $info ? ' ('.$info.')' : '';
      break;
    case 1:
      echo 'reader['.$id.']: attached';
      echo $info ? ' ('.$info.')' : '';
      break;
    case 2:
      echo 'reader['.$id.'].signal: '.$info;
      break;
    case 4:
      echo 'broadcast complete, ';
      echo 'readers('.$info.') have read the message';
      break;
    }
    echo "\n";
  })
]);
if (ErrorEx::is($o))
{
  var_dump($o);
  exit(1);
}
$I = 'SyncBroadcastMaster•'.proc_id();
cli_set_process_title($I);
echo $I." started\n";
echo "press [w] to write, [q] to quit\n";
$m = 'a message from '.$I;
while (1)
{
  switch (Conio::getch()) {
  case 'q':
    echo "> quit\n";
    break 2;
  case 'w':
    echo '> write: '.$m."\n";
    if (!$o->write($m, $e)) {
      break 2;
    }
    break;
  case '':
    if (!$o->flush($e)) {
      break 2;
    }
    usleep(100000);# 100ms
    break;
  }
}
# terminate
$o->close($e);
if ($e)
{
  echo "=ERROR=\n";
  var_dump($e);
  echo "\npress any key to quit..";
  Conio::getch_wait();
}

