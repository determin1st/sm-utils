<?php declare(strict_types=1);
require_once(__DIR__.DIRECTORY_SEPARATOR.'help.php');
require_once(
  __DIR__.DIRECTORY_SEPARATOR.
  '..'.DIRECTORY_SEPARATOR.
  '..'.DIRECTORY_SEPARATOR.
  'mustache.php'
);
$m = \SM\Mustache::new(['escape'=>true]);
$x = isset($argv[1]) ? intval($argv[1]) : 1;
if (!($json = get_testfiles())) {
  exit(1);
}
###
echo "\n> ".$x.'*render(): ';
while ($x--)
{
  foreach ($json as $file => $j)
  {
    if (isset($j['speed']) && !$j['speed']) {
      continue;
    }
    foreach ($j['tests'] as $a)
    {
      if (isset($a['skip']) && $a['skip']) {
        continue;
      }
      if ($m->render($a['template'], $a['data']) !== $a['expected'])
      {
        echo "FAIL\n";
        exit(1);
      }
    }
  }
}
echo "OK\n";
###
