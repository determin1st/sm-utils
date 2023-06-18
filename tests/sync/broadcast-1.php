<?php declare(strict_types=1);
namespace SM;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
require_once DIR_SM_UTILS.'conio.php';
require_once DIR_SM_UTILS.'sync.php';
###
$o = SyncBroadcastMaster::new([
  'id'       => 'test-broadcast',
  'callback' => (function(int $case, string $id, string $info): void
  {
    echo '> ';
    switch ($case) {
    case 0:
      $info = $info ?: 'graceful';
      echo 'reader['.$id.'] is detached ('.$info.')';
      break;
    case 1:
      echo 'reader['.$id.'] is attached';
      break;
    case 2:
      echo 'reader['.$id.'] is reading..';
      break;
    case 3:
      echo 'complete: '.$info.' readers have read the message';
      break;
    case 4:
      echo 'reader['.$id.']: '.$info;
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
echo "broadcast master started\n";
echo "press [w] to write, [q] to quit\n";
$m = 'a message from the master';
while (1)
{
  # operate
  switch (Conio::getch_nowait()) {
  case 'w':
    echo '> write: '.$m."\n";
    if (!$o->write($m, $e))
    {
      var_dump($e);
      break 2;
    }
    break;
  case 'q':
    echo "> quit\n";
    break 2;
  case '':
    # take a rest
    usleep(100000);# 100ms
    break;
  }
  # execute periodics
  if (!$o->flush($e)) {
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
  Conio::getch();
}

