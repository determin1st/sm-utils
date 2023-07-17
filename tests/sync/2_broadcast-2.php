<?php declare(strict_types=1);
namespace SM;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
require_once DIR_SM_UTILS.'conio.php';
require_once DIR_SM_UTILS.'sync.php';
###
$o = SyncBroadcast::new([
  'id'       => 'test-broadcast',
  'callback' => (function(int $s0, int $s1, string $info): void
  {
    static $STATUS = [
      -2 => 'closed',
      -1 => 'on hold',
      0  => 'registration',
      1  => 'activation',
      2  => 'confirmation',
      3  => 'ready',
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
$I = 'SyncBroadcast•'.proc_id();
cli_set_process_title($I);
echo $I." started\n";
echo "[1] to send signal\n";
echo "[q] to quit\n\n";
while (1)
{
  $e = null;
  switch (Conio::getch()) {
  case 'q':
    echo "> quit\n";
    break 2;
  case '1':
    $a = 'a message from '.$I;
    echo '> write: ';
    while (!$o->write($a, $e))
    {
      if ($e)
      {
        if ($e->level)
        {
          echo "FAIL\n";
          break 3;
        }
        echo "SKIP (data is pending)\n";
        break 2;
      }
      usleep(50000);# 50ms
    }
    echo $a."\n";
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
if ($e)
{
  echo "=ERROR=\n";
  var_dump($e);
  echo "\npress any key to quit..";
  Conio::getch_wait();
}

