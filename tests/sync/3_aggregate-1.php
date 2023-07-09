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
echo "aggregate master started\n";
echo "press [q] to quit\n";
while (1)
{
  # operate
  switch (Conio::getch()) {
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

