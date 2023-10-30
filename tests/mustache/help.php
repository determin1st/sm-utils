<?php declare(strict_types=1);
function get_testfiles(): ?array # {{{
{
  $json = [];
  $file = glob(
    __DIR__.DIRECTORY_SEPARATOR.'_*.json'
  );
  if (!$file) {
    return null;
  }
  foreach ($file as $p)
  {
    if (!is_file($p) ||
        !($x = file_get_contents($p, false)) ||
        !($a = json_decode($x, true)) ||
        !is_array($a) ||
        !isset($a['overview']) ||
        !isset($a['tests']))
    {
      continue;
    }
    $b = explode('.', basename($p))[0];
    if ($b === '_lambdas')
    {
      foreach ($a['tests'] as &$t)
      {
        $e = $t['data']['lambda'];
        $f = 'return (function($m,$a){'.$e.'});';
        $t['data']['lambda_code'] = $e;
        $t['data']['lambda'] = eval($f);
      }
    }
    $json[$b] = $a;
  }
  return $json;
}
# }}}
function bg_color($m, $name, $strong=0) # {{{
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
# }}}
function color($m, $name, $strong=0) # {{{
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
###
