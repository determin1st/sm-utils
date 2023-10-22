<?php declare(strict_types=1);
# prepare {{{
require_once(__DIR__.DIRECTORY_SEPARATOR.'help.php');
$test = isset($argv[1])
  ? intval($argv[1])
  : 1;
$count = isset($argv[2])
  ? intval($argv[2])
  : 1;
###
if (!($json = get_testfiles()))
{
  echo("unable to load testfiles\n");
  exit(1);
}
# }}}
# load script {{{
$pad = "\t\t\t";
$fun = 'render()';
$t = hrtime(true);
switch ($test) {
case 1:
case 2:
  require_once(
    __DIR__.DIRECTORY_SEPARATOR.
    '..'.DIRECTORY_SEPARATOR.
    '..'.DIRECTORY_SEPARATOR.
    'mustache.php'
  );
  $m = \SM\Mustache::new(['escape'=>true]);
  $i = 'sm-mustache';
  if ($test === 2)
  {
    $fun = 'set()+get()';
    if ($count > 9) {
      $pad = "\t\t";
    }
  }
  elseif ($count > 9999) {
    $pad = "\t\t";
  }
  break;
case 3:
  require_once(
    __DIR__.DIRECTORY_SEPARATOR.
    '..'.DIRECTORY_SEPARATOR.
    '..'.DIRECTORY_SEPARATOR.
    '__junk'.DIRECTORY_SEPARATOR.
    'mustache.php'
  );
  $m = \SM\Mustache::construct([
    'escaper' => true
  ]);
  $i = 'sm-mustache-old';
  $pad = "\t\t";
  break;
default:
  require_once(
    __DIR__.
    DIRECTORY_SEPARATOR.
    'mustache.php'.DIRECTORY_SEPARATOR.
    'vendor'.DIRECTORY_SEPARATOR.
    'autoload.php'
  );
  $m = new Mustache_Engine();
  $i = 'mustache';
  break;
}
$t = (int)((hrtime(true) - $t)/1e+6);
echo(
  "\nPHP> ".color($i, 'cyan', 1).
  ': loaded in '.$t.'ms, '.$fun.'*'.$count.
  $pad
);
# }}}
switch ($test) {
case 2:# {{{
  $t = hrtime(true);
  foreach ($json as $k => &$j)
  {
    if (isset($j['speed']) && !$j['speed']) {
      continue;
    }
    $i = 0;
    foreach ($j['tests'] as &$test)
    {
      if (isset($test['skip']) &&
          $test['skip'])
      {
        continue;
      }
      $test['id'] = $m->set($test['template']);
    }
  }
  while ($count--)
  {
    foreach ($json as $k => $j)
    {
      if (isset($j['speed']) && !$j['speed']) {
        continue;
      }
      $i = 0;
      foreach ($j['tests'] as $test)
      {
        if (isset($test['skip']) &&
            $test['skip'])
        {
          continue;
        }
        if ($m->get($test['id'], $test['data']) !== $test['expected'])
        {
          echo(color('fail', 'red', 1)."\n");
          break 2;
        }
      }
    }
  }
  break;
  # }}}
default:# {{{
  $t = hrtime(true);
  while ($count--)
  {
    foreach ($json as $k => $j)
    {
      if (isset($j['speed']) && !$j['speed']) {
        continue;
      }
      $i = 0;
      foreach ($j['tests'] as $test)
      {
        if (isset($test['skip']) &&
            $test['skip'])
        {
          continue;
        }
        if ($m->render($test['template'], $test['data']) !== $test['expected'])
        {
          echo(color('fail', 'red', 1)."\n");
          break 2;
        }
      }
    }
  }
  break;
  # }}}
}
$t = (int)((hrtime(true) - $t)/1e+6);
echo(" ".$t."ms");
exit(0);
###
