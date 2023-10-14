<?php
require_once(
  __DIR__.DIRECTORY_SEPARATOR.
  '..'.DIRECTORY_SEPARATOR.
  '..'.DIRECTORY_SEPARATOR.
  'mustache.php'
);
# {{{
if (0) # tokenizer/parser/composer/renderer tests
{
  $m = \SM\Mustache::construct([
    'logger' => Closure::fromCallable('logit'),
  ]);
  $a = '

    {{#block}}
      yes, {{name}}
    {{|}}
      no, {{name}}
    {{/block}}

  ';
  $a = '{{#if}}yep{{|1}}wtf{{|}}nope{{/if}}';
  /***
  echo "========\nTOKENS:\n";
  $b = $m->tokenize($m->delims, $a);
  var_dump($b);
  echo "========\nTREE:\n";
  $c = $m->parse($a, $b);
  var_dump($c);
  echo "========\n";
  $d = $m->compose($m->delims, $c, 0);
  var_dump($d);
  echo "========\n";
  /***/
  /***/
  $b = [
    'if' => 1,
    'name' => 'David',
  ];
  echo "========\n";
  $c = $m->render($a, $b);
  echo "========\n";
  var_export($a);
  var_export($b);
  var_export($c);
  exit;
  /***/
  exit;
}
# }}}
# prep {{{
# check arguments
$args = array_slice($argv, 1);
if (!($i = count($args)) || !$args[0])
{
  # all
  if (!($file = glob(__DIR__.DIRECTORY_SEPARATOR.'*.json')))
  {
    logit("glob() failed\n");
    exit;
  }
  $test = -1;
}
else
{
  # single
  $file = [__DIR__.DIRECTORY_SEPARATOR.$args[0]];
  $test = ($i === 1) ? -1 : intval($args[1]);
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
    exit;
  }
  $i = explode('.', basename($i))[0];
  if ($i === 'lambdas')
  {
    # create functions
    foreach ($j['tests'] as &$k)
    {
      $e = $k['data']['lambda'];
      $f = 'return (function($m,$text=""){'.$e.'});';
      $k['data']['lambda_e'] = $e;
      $k['data']['lambda'] = Closure::fromCallable(eval($f));
    }
    unset($k);
  }
  $json[$i] = $j;
}
logit("selected: ".implode('/', array_keys($json))."\n");
# }}}
# run {{{
$m = \SM\Mustache::construct([
  'escape'  => true,
  'recurse' => true,
]);
if (~$test)
{
  # single
  $json = array_pop($json);
  $test = $json['tests'][$test];
  logit("running test: {$test['name']}\n");
  logit("description: {$test['desc']}\n");
  logit("template: [".str_bg_color($test['template'], 'cyan')."]\n");
  logit('data: '.var_export($test['data'], true)."\n");
  logit("expected: [".str_bg_color($test['expected'], 'magenta')."]\n");
  logit("\n");
  $res = $m->render($test['template'], $test['data']);
  logit("\n");
  logit("result: [".str_bg_color($res, 'magenta')."]\n");
  if ($res === $test['expected']) {
    logit(str_fg_color('ok', 'green', 1)."\n");
  }
  else {
    logit(str_fg_color('fail', 'red', 1)."\n");
  }
}
else
{
  # multiple
  $noSkip = (count($json) > 1);
  foreach ($json as $k => $j)
  {
    logit("> testing: ".str_fg_color($k, 'cyan', 1)."\n");
    $i = 0;
    foreach ($j['tests'] as $test)
    {
      logit(" #".str_fg_color($i++, 'cyan', 1).": {$test['name']}.. ");
      if (!$noSkip && isset($test['skip']) && $test['skip']) {
        logit(str_fg_color('skip', 'blue', 0)."\n");
        continue;
      }
      if ($m->render($test['template'], $test['data']) === $test['expected']) {
        logit(str_fg_color('ok', 'green', 1)."\n");
      }
      elseif (isset($test['skip']) && $test['skip']) {
        logit(str_fg_color('skip', 'blue', 0)."\n");
      }
      else
      {
        logit(str_fg_color('fail', 'red', 1)."\n");
        if ($noSkip) {
          break 2;
        }
      }
    }
  }
}
# }}}
# logger {{{
function logit($m, $level=-1)
{
  if (~$level) {
    $m = "sm: ".str_bg_color($m, ($level ? 'red' : 'cyan'))."\n";
  }
  fwrite(STDOUT, $m);
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
