<?php declare(strict_types=1);
namespace SM;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
require_once DIR_SM_UTILS.'sync.php';
###
$o = SyncBroadcast::new([
  'id'       => 'test-broadcast',
  'callback' => (function(int $s0, int $s1, string $info): void
  {
    static $STATUS = [
      -2 => 'closed',
      -1 => 'on hold',
      0  => 'attachment',
      1  => 'attachment',
      2  => 'confirmation',
      3  => 'ready',
      4  => 'retransmission',
      5  => 'retransmission',
    ];
    echo '> status: '.$STATUS[$s0].' => '.$STATUS[$s1];
    echo ($info ? ' ('.$info.')' : '')."\n";
  })
]);
if (ErrorEx::is($o))
{
  var_dump($o);
  exit(1);
}
cli_set_process_title(
  $I = 'SyncBroadcast•'.proc_id()
);
echo $I." started\n";
echo "[1] retransmission\n";
echo "[2] signal\n";
echo "[q] quit\n\n";
while (1)
{
  $e = null;
  switch (Conio::getch()) {
  case 'q':
    echo "> quit\n";
    break 2;
  case '1':
    while (!$o->write($I, $e))
    {
      if ($e)
      {
        if ($e->level)
        {
          echo "> write: FAIL\n";
          break 3;
        }
        echo "> write: SKIP\n";
        break 2;
      }
    }
    echo '> write: '.$I."\n";
    break;
  case '2':
    echo "> signal: ";
    while (!$o->signal($I, $e))
    {
      if ($e)
      {
        if ($e->level)
        {
          echo "FAIL\n";
          break 3;
        }
        echo "SKIP\n";
        break 2;
      }
    }
    echo $I."\n";
    break;
  case '':
    if (($a = $o->read($e)) === null)
    {
      if ($e) {
        break 2;
      }
      usleep(100000);# 100ms
      break;
    }
    echo "> read: ".$a."\n";
    break;
  }
}
# terminate
$o->close($e);
error_dump($e);

