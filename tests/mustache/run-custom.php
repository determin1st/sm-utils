<?php
require_once(
  __DIR__.DIRECTORY_SEPARATOR.
  '..'.DIRECTORY_SEPARATOR.
  '..'.DIRECTORY_SEPARATOR.
  'mustache.php'
);
$m = SM\Mustache::new();
$f = function($a, $b) use ($m) # {{{
{
  try
  {
    $c = $m->render($a, $b);
    echo "======\n";
    var_dump($a);
    echo "------\n";
    #var_dump($b);
    var_dump($c);
    echo "======\n";
    #var_dump($m->code);
  }
  catch (Throwable $e)
  {
    var_dump(SM\ErrorEx::from($e));
    exit(1);
  }
};
$p = function($a, $b, $c) use ($m)
{
  try
  {
    $c = $m->prepare($a, $b, $c);
    echo "======\n";
    var_dump($a);
    echo "------\n";
    #var_dump($b);
    var_dump($c);
    echo "======\n";
    #var_dump($m->code);
  }
  catch (Throwable $e)
  {
    var_dump(SM\ErrorEx::from($e));
    exit(1);
  }
};
# }}}
# ASSISTED OBJECT ITERATION {{{

$m->prepare('{{.}}', 'Joe');
exit;

$f($m->trim('

  array: [{{#block}}{{.}},{{/block}}]

  assisted array: [{{@block}}
    {{.}}{{^_last}},{{/_last}}
  {{/block}}]

'), [
  'name'  => 'David',
  'car'   => ['color' => 'silver'],
  #'block' => [1,2,3,4,5],
  /***/
  'block' => (new class() implements Countable,ArrayAccess
  {
    public array $a=[1,2,3,4,5];
    function count(): int {
      return count($this->a);
    }
    function offsetExists(mixed $offset): bool {
      return true;
    }
    function offsetGet(mixed $offset): mixed {
      return $this->a[$offset];
    }
    function offsetSet(mixed $offset, mixed $value): void
    {}
    function offsetUnset(mixed $offset): void
    {}
  }),
  /***/
]);
# }}}
# ASSISTED BACKPEDAL {{{
$f($m->trim('

  {{@block}}
    yes {{name}}, value={{.}},
    index={{_index}}
    {{#_first}}(FIRST){{/_first}}
    {{#_last}}(LAST){{/_last}} [
    {{@block}}
      {{#__index}}{{|2}}
        {{_index}}=
      {{/__index}}
      {{.}}
      {{^_last}},{{/_last}}
    {{/block}}
    ]

  {{|}}
    no, {{name}}
  {{/block}}


'), [
  'name'  => 'David',
  'block' => [1,2,3,4,5],
]);
# }}}
# PREPARATION {{{
$p($m->alltrim("

  {:BR:}{:BR:}
  {:N:}\t{:name:},{:BR:}
  {:N:}\twelcome to the {{name}}'s party{:BR:}
  {:N:}\t{:#iter:}
    {:.:}/
  {:/iter:}{:BR:}
  {:N:}\t{:#map:}
    {:to:}
  {:/map:}{:BR:}
  {:BR:}

"), [
  'name' => 'Joe',
  'BR'   => "\n",
  'iter' => [1,2,3,4,5],
  'map'  => ['to'=>['another'=>[1,2,3]]],
], '{: :}');
# }}}
###
