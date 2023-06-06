<?php
namespace SM;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
require_once DIR_SM_UTILS.'sync.php';
###
#$o = new SyncEvent('test1');exit();
$r = SyncR1WN_Reader::new('test1', 100, __DIR__);
if (ErrorEx::is($r))
{
  var_dump($r);
  if ($r = $r->value)
  {
    echo 'file: '.$r->getFile()."\n";
    echo 'line: '.$r->getLine()."\n";
    var_dump($r->getTraceAsString());
  }
  exit;
}
echo "started\n";
for ($i=0; $i < 100; ++$i)
{
  echo '.';
  usleep(100000);# 100ms
}
echo "\nfinished\n";
if (!$r->close($error)) {
  var_dump($error);
}
#echo "\nend\n";
###
?>
