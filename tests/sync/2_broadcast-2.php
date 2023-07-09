<?php declare(strict_types=1);
namespace SM;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
require_once DIR_SM_UTILS.'conio.php';
require_once DIR_SM_UTILS.'sync.php';
###
# create an instance of broadcast listener,
# <id>entifier is required and must match with broadcast master,
# buffer <size> is optional, it will be negotiated anyway
$o = SyncBroadcast::new([
  'id'       => 'test-broadcast',
  'callback' => (function(int $s0, int $s1, string $info): void
  {
    static $STATUS = [
      -2 => 'closed',
      -1 => 'on hold',
      0  => 'initiation',
      1  => 'registration',
      2  => 'activation',
      3  => 'reading..',
    ];
    echo '> status: '.$STATUS[$s0].' => '.$STATUS[$s1];
    echo ($info ? ' ('.$info.')' : '')."\n";
  })
]);
# next, checking for construction error,
# the result of the new() constructor is
# either <SyncBroadcast> or <ErrorEx> object
if (ErrorEx::is($o))
{
  var_dump($o);
  exit(1);
}
echo "broadcast reader started..\n";
echo "press [w] to write, [q] to quit\n";
$w = 'a message from the reader';
while (1)
{
  # operate
  switch (Conio::getch()) {
  case 'w':
    echo '> write: '.$w."\n";
    if (!$o->write($w, $e)) {
      break 2;
    }
    break;
  case 'q':
    echo "> quit\n";
    break 2;
  }
  # execute periodics
  if ($m = $o->read($e)) {
    echo "> read: ".$m."\n";
  }
  elseif ($e || !$o->flush($e)) {
    break;
  }
  else {# take a rest
    usleep(100000);# 100ms
  }
}
# terminate
$o->close($e);
if ($e)
{
  echo "=ERROR=\n";
  var_dump($e);
  echo "\npress any key to quit..";
  Conio::getch();
}

