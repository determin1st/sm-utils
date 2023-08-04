<?php
# prep {{{
/***/
#echo "=============\n";
#var_dump(opcache_get_status());
#echo "=============\n";
#exit;
/***/
# check arguments
$args = array_slice($argv, 1);
if (!count($args))
{
  logit("specify arguments: <variant=0/1> [<iterations=1>]\n");
  exit(1);
}
$test  = intval($args[0]);
$count = isset($args[1]) ? intval($args[1]) : 1;
# load testfiles
if (!($file = glob(__DIR__.DIRECTORY_SEPARATOR.'*.json')))
{
  logit("glob() failed\n");
  exit(1);
}
$json = [];
foreach ($file as $i)
{
  if (!file_exists($i) ||
      !($j = json_decode(file_get_contents($i), true)) ||
      !isset($j['overview']) ||
      !isset($j['tests']))
  {
    logit("incorrect testfile: $i");
    exit(1);
  }
  $i = explode('.', basename($i))[0];
  if ($i === 'lambdas')
  {
    # create functions
    foreach ($j['tests'] as &$k)
    {
      $e = $k['data']['lambda'];
      $f = 'return (function($text){'.$e.'});';
      $k['data']['lambda_e'] = $e;
      $k['data']['lambda'] = Closure::fromCallable(eval($f));
    }
    unset($k);
  }
  $json[$i] = $j;
}
# }}}
# run {{{
###
# measure load
$t = -hrtime(true);
###
# select instance variant
if ($test === 1)
{
  require_once(
    __DIR__.DIRECTORY_SEPARATOR.
    '..'.DIRECTORY_SEPARATOR.
    '..'.DIRECTORY_SEPARATOR.
    'mustache.php'
  );
  $m = \SM\Mustache::construct([
    'escaper' => true,
    'recur'   => true,
  ]);
  $i = 'sm-mustache';
}
elseif ($test === 2)
{
  require_once(
    __DIR__.DIRECTORY_SEPARATOR.
    '..'.DIRECTORY_SEPARATOR.
    '..'.DIRECTORY_SEPARATOR.
    'junk'.DIRECTORY_SEPARATOR.
    'mustache.php'
  );
  $m = \SM\Mustache::construct([
    'escaper' => true,
    'recur'   => true,
  ]);
  $i = 'sm-mustache-old';
}
else
{
  require __DIR__.DIRECTORY_SEPARATOR.'mustache_php'.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';
  $m = new Mustache_Engine();
  $i = 'mustache';
}
$t = intval(($t + hrtime(true))/1e+6);# nano to milli
logit("\nPHP> ".str_fg_color($i, 'cyan', 1).": loaded in {$t}ms, loop($count) ");
###
# measure iterations
$S = intval($count / 10);
$s = 0;
$t = -hrtime(true);
sleep(1);# 1000ms
###
# iterate over all covered tests
while ($count--)
{
  #if (++$s >= $S) {logit('.');$s = 0;}
  ###
  foreach ($json as $k => $j)
  {
    # skip certain test sets
    if (isset($j['speed']) && !$j['speed']) {
      continue;
    }
    #logit("> testing: ".str_fg_color($k, 'cyan', 1)."\n");
    $i = 0;
    foreach ($j['tests'] as $test)
    {
      #logit(" #".str_fg_color($i++, 'cyan', 1).": {$test['name']}.. ");
      if (isset($test['skip']) && $test['skip']) {
        #logit(str_fg_color('skip', 'blue', 0)."\n");
        continue;
      }
      if ($m->render($test['template'], $test['data']) === $test['expected']) {
        #logit(str_fg_color('ok', 'green', 1)."\n");
      }
      else
      {
        logit(str_fg_color('fail', 'red', 1)."\n");
        break 2;
      }
    }
  }
}
$t = intval(($t + hrtime(true))/1e+6);# nano to milli
logit(" ".($t - 1000)."ms");
exit(0);
# }}}
# util {{{
function logit($m, $level=-1)
{
  static $e = null;
  !$e && ($e = fopen('php://stderr', 'w'));
  if (~$level) {
    $m = "sm: ".str_bg_color($m, ($level ? 'red' : 'cyan'))."\n";
  }
  fwrite($e, $m);
}
function str_bg_color($m, $name, $strong=0)
{
  static $color = [
    'black'   => [40,100],
    'red'     => [41,101],
    'green'   => [42,102],
    'yellow'  => [43,103],
    'blue'    => [44,104],
    'magenta' => [45,105],
    'cyan'    => [46,106],
    'white'   => [47,107],
  ];
  $c = $color[$name][$strong];
  return (strpos($m, "\n") === false)
    ? "[{$c}m{$m}[0m"
    : "[{$c}m".str_replace("\n", "[0m\n[{$c}m", $m).'[0m';
}
function str_fg_color($m, $name, $strong=0)
{
  static $color = [
    'black'   => [30,90],
    'red'     => [31,91],
    'green'   => [32,92],
    'yellow'  => [33,93],
    'blue'    => [34,94],
    'magenta' => [35,95],
    'cyan'    => [36,96],
    'white'   => [37,97],
  ];
  $c = $color[$name][$strong];
  return "[{$c}m{$m}[0m";
}
# }}}
?>
