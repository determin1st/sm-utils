<?php declare(strict_types=1);
# prepare {{{
require_once(__DIR__.DIRECTORY_SEPARATOR.'help.php');
require_once(
  __DIR__.DIRECTORY_SEPARATOR.
  '..'.DIRECTORY_SEPARATOR.
  '..'.DIRECTORY_SEPARATOR.
  'mustache.php'
);
if (!($json = get_testfiles()))
{
  echo("unable to load testfiles\n");
  exit(1);
}
$file = isset($argv[1])
  ? explode('.', $argv[1])[0]
  : '';
$test = isset($argv[2])
  ? intval($argv[2])
  : -1;
###
if ($file && isset($json[$file]))
{
  if ($test >= 0 && $test < count($json[$file]['tests'])) {
    $json = $json[$file];
  }
  else
  {
    $json = [$file => $json[$file]];
    $test = -1;
  }
  echo('selected: '.$file."\n");
}
else
{
  $test = -1;
  echo('selected: '.implode('/', array_keys($json))."\n");
}
# }}}
$m = \SM\Mustache::new([
  'escape'=>true,
  'unescape'=>true,
  'dedent'=>1,
]);
if (~$test)
{
  # single
  $a = $json['tests'][$test];
  echo("       test: {$a['name']}\n");
  echo("description: {$a['desc']}\n");
  echo("   template: [".bg_color($a['template'], 'cyan')."]\n");
  echo('       data: '.var_export($a['data'], true)."\n");
  echo("   expected: [".bg_color($a['expected'], 'magenta')."]\n");
  echo("------------\n\n");
  /***
  $b  = $m->outdent($a['template']);
  $r0 = $m->prepare($b, $a['data']);
  $r1 = $m->render($b, $a['data']);
  /***/
  $r0 = $m->prepare($a['template'], $a['data']);
  $r1 = $m->render($a['template'], $a['data']);
  /***/
  echo("prepare(): [".bg_color($r0, 'magenta')."]\n");
  echo(" render(): [".bg_color($r1, 'magenta')."]\n");
  if ($r0 === $a['expected'] &&
      $r1 === $a['expected'])
  {
    echo(color('ok', 'green', 1)."\n");
  }
  else {
    echo(color('fail', 'red', 1)."\n");
  }
}
else
{
  # multiple
  $stop = (count($json) > 1);
  foreach ($json as $file => $j)
  {
    echo '> testing: '.color($file, 'cyan', 1)."\n";
    $i = 0;
    foreach ($j['tests'] as $a)
    {
      echo ' #'.color($i++, 'cyan', 1).': ';
      echo $a['name'].'.. ';
      $skip = (isset($a['skip']) && $a['skip']);
      if ($skip && $stop)
      {
        echo color('skip', 'blue', 0)."\n";
        continue;
      }
      /***
      $b  = $m->outdent($a['template']);
      $r0 = $m->prepare($b, $a['data']);
      $r1 = $m->render($b, $a['data']);
      /***/
      $r0 = $m->prepare($a['template'], $a['data']);
      $r1 = $m->render($a['template'], $a['data']);
      /***/
      $b0 = $r0 === $a['expected'];
      $b1 = $r1 === $a['expected'];
      if ($b0 && $b1)
      {
        echo color('ok', 'green', 1)."\n";
        continue;
      }
      echo color('fail', ($skip?'black':'red'), 1);
      if (!$b0) {echo ' prepare()';}
      if (!$b1) {echo ' render()';}
      echo "\n";
      if ($stop) {
        break 2;
      }
    }
  }
}
###
