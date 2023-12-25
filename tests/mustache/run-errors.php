<?php declare(strict_types=1);
require_once(
  __DIR__.DIRECTORY_SEPARATOR.
  '..'.DIRECTORY_SEPARATOR.
  '..'.DIRECTORY_SEPARATOR.
  'autoload.php'
);
$m = \SM\Mustache::new();
$a = [
  ### PARSE
  'unterminated BLOCK' => # {{{
  '

    {{#boolean-true}}
      yes
    {{|}}
      no
    {{name}}

  ',
  # }}}
  'ITERATOR cannot have a CASE section' => # {{{
  '

    {{@array}}
      {{.}}
    {{|123}}
      one two three
    {{/}}

  ',
  # }}}
  'unexpected OR section' => # {{{
  '

    {{name}}
    {{|}}

  ',
  # }}}
  'unexpected CASE section' => # {{{
  '

    {{name}}
    {{|123}}

  ',
  # }}}
  'unexpected TERMINUS' => # {{{
  '
    {{& hello}}
    {{/ hello}}

  ',
  # }}}
  'the VARIABLE modifier cannot apply to the BLOCK' => # {{{
  '
    {{#&boolean-true}}
      yes
    {{/ hello}}

  ',
  # }}}
  'dot notation may not apply to the auxiliary value' => # {{{
  '
    {{@array}}
      {{_key.0}}
    {{/}}

  ',
  # }}}
  'lambda argument may not apply to the auxiliary value' => # {{{
  '
    {{@array}}
      {{_key argument}}
    {{/}}

  ',
  # }}}
  'auxiliary value cannot be iterated' => # {{{
  '
    {{@array}}
      {{@_key}}
        {{.}}
      {{/}}
    {{/}}

  ',
  # }}}
  'auxiliary value of type 3=boolean cannot be used in the SWITCH block' => # {{{
  '
    {{@array}}
      {{#_first}}
        {{.}}
      {{|1}}
        {{.}}
      {{/}}
    {{/}}

  ',
  # }}}
  ### RENDER
  'SWITCH: incorrect value type' => # {{{
  '
    {{#boolean-true}}
      something
    {{|1}}
      one
    {{/}}

  ',
  # }}}
  'ITERATOR: incorrect value type' => # {{{
  '
    {{@number-123}}
      {{.}}
    {{/}}

  ',
  # }}}
  'VARIABLE: incorrect value type' => # {{{
  '
    {{name}}
    {{array}}

  ',
  # }}}
];
$d = [
  'name'  => 'Joe',
  'array' => [1,2,3,4,5],
  'number-123' => 123,
  'boolean-true' => true,
];
echo "testing mustache exceptions";
echo "(".count($a)."):\n\n";
foreach ($a as $b => $c)
{
  echo '> '.$b.': ';
  $ok = false;
  try {
    $c = $m->prepare($c, $d);
  }
  catch (Throwable $e)
  {
    $e  = substr($e->getMessage(), 0, strlen($b));
    $ok = $e === $b;
  }
  if ($ok) {
    echo "ok\n";
  }
  else
  {
    echo "fail\n";
    echo "last render: ".$c."\n";
    echo "last error: ".$e."\n";
    break;
  }
}
###
