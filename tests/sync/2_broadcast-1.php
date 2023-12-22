<?php declare(strict_types=1);
namespace SM;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
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
      echo 'reader['.$id.']: retransmission';
      echo $info ? ' ('.$info.')' : '';
      break;
    case 3:
      echo 'reader['.$id.']: signal: '.$info;
      break;
    case 4:
      echo 'complete: N='.$info;
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
cli_set_process_title(
  $I = 'SyncBroadcastMaster•'.proc_id()
);
echo $I." started\n";
echo "[1] to broadcast a message\n";
echo "[q] to quit\n\n";
while (1)
{
  switch (Conio::getch()) {
  case 'q':
    echo "> quit\n";
    break 2;
  case '1':
    echo '> write: '.$I."\n";
    if (!$o->write($I, $e)) {
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
$o->close($e);
error_dump($e);

