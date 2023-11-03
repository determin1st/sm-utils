<?php declare(strict_types=1);
# prepare {{{
require_once(
  __DIR__.DIRECTORY_SEPARATOR.
  '..'.DIRECTORY_SEPARATOR.
  '..'.DIRECTORY_SEPARATOR.
  'mustache.php'
);
$m = SM\Mustache::new([
  'helper' => [
    'BR'  => "\n",
    'TAB' => "\t",
    'PAD' => "\n\t",
  ],
]);
# }}}
# lambdas {{{
$a = $m->outdent('

    {{BR}}
    {{BR}}FALSY LAMBDA OR{{BR}}

    ^1: path not found{{TAB}}
    {{^f.not_found 1}}
      ok (first section)
    {{|}}
      fail
    {{/f.not_found}}
    {{BR}}

    ^2: boolean false{{TAB}}
    {{^test false}}ok (first section){{|}}fail{{/test}}
    {{BR}}

    ^3: boolean true{{TAB}}
    {{^test true}}fail{{|}}ok (second section){{/test}}
    {{BR}}

    ^4: empty string{{TAB}}
    {{^test empty_string}}
      ok (first section)
    {{|}}
      fail
    {{/test}}
    {{BR}}

    ^5: non-empty string{{TAB}}
    {{^test non_empty_string}}
      fail
    {{|}}
      ok (second section)
    {{/test}}
    {{BR}}

    ^6: integer zero{{TAB}}
    {{^integer 0}}ok (first section){{|}}fail{{/integer}}
    {{BR}}

    ^7: integer non-zero{{TAB}}
    {{^integer 123}}fail{{|}}ok (second section){{/integer}}
    {{BR}}

    ^8: empty array{{TAB}}{{TAB}}
    {{^test empty_array}}ok (first section){{|}}fail{{/test}}
    {{BR}}

    ^9: non-empty array{{TAB}}
    {{^test array}}fail{{|}}ok (second section){{/test}}
    {{BR}}

    ^10: object{{TAB}}{{TAB}}
    {{^test object}}fail{{|}}ok (second section){{/test}}
    {{BR}}



    {{BR}}TRUTHY LAMBDA OR{{BR}}

    #1: boolean false{{TAB}}
    {{#test false}}fail{{|}}ok (second section){{/test}}
    {{BR}}

    #2: boolean true{{TAB}}
    {{#test true}}ok (first section){{|}}fail{{/test}}
    {{BR}}

    #3: empty string{{TAB}}
    {{#test empty_string}}fail{{|}}ok (second section){{/test}}
    {{BR}}

    #4: non-empty string{{TAB}}
    {{#string ok (content substitution)}}fail{{|}}fail{{/string}}
    {{BR}}

    #5: integer zero{{TAB}}
    {{#integer 0}}
      ok (first section + helper={{.}})
    {{|}}
      fail
    {{/integer}}
    {{BR}}

    #6: integer non-zero{{TAB}}
    {{#integer 123}}
      ok (first section + helper={{.}})
    {{|}}
      fail
    {{/integer}}
    {{BR}}

    #7: empty array{{TAB}}{{TAB}}
    {{#test empty_array}}fail{{|}}ok (second section){{/test}}
    {{BR}}

    #8: iterable array{{TAB}}
    {{#is_equals 12345,ok (first section + iteration),fail (%s)}}
      {{#test array}}{{.}}{{|}}fail{{/test}}
    {{/is_equals}}
    {{BR}}

    #9: hashmap array{{TAB}}
    {{#is_equals Joe,ok (first section + helper),fail (%s)}}
      {{#test hashmap}}
        {{name}}
      {{|}}
        second section
      {{/test}}
    {{/is_equals}}
    {{BR}}

    #10: object{{TAB}}{{TAB}}
    {{#is_equals ok,ok (first section + helper),fail (%s)}}
      {{#test object}}
        {{property}}
      {{|}}
        second section
      {{/test}}
    {{/is_equals}}
    {{BR}}

    #11: empty iterable obj{{TAB}}
    {{#is_equals empty,ok (second section),fail (%s)}}
      {{#test object_empty_iterable}}
        {{.}}
      {{|}}
        empty
      {{/test}}
    {{/is_equals}}
    {{BR}}

    #12: iterable object{{TAB}}
    {{#is_equals 12345,ok (first section + iteration),fail (%s)}}
      {{#test object_iterable}}
        {{.}}
      {{|}}
        empty
      {{/test}}
    {{/is_equals}}
    {{BR}}



    {{BR}}ITERABLE LAMBDA OR{{BR}}

    @1: empty array{{TAB}}{{TAB}}
    {{@test empty_array}}fail{{|}}ok (second section){{/test}}
    {{BR}}

    @2: iterable array{{TAB}}
    {{#is_equals 1/2/3/4/5,ok (first section + iteration),fail (%s)}}
      {{@test array}}
        {{.}}{{^_last}}/{{/_last}}
      {{|}}
        fail
      {{/test}}
    {{/is_equals}}
    {{BR}}

    @3: hashmap array{{TAB}}
    {{#is_equals name=Joe/age=80,ok (first section + traversal),fail (%s)}}
      {{@test hashmap}}
        {{_key}}={{.}}
        {{^_last}}/{{/_last}}
      {{|}}
        second section
      {{/test}}
    {{/is_equals}}
    {{BR}}

    @4: empty object{{TAB}}
    {{#is_equals empty,ok (second section),fail (%s)}}
      {{@test object_empty_iterable}}
        {{.}}
      {{|}}
        empty
      {{/test}}
    {{/is_equals}}
    {{BR}}

    @5: iterable object{{TAB}}
    {{#is_equals 1/2/3/4/5,ok (first section + iteration),fail (%s)}}
      {{@test object_iterable}}
        {{.}}
        {{^_last}}/{{/_last}}
      {{|}}
        empty
      {{/test}}
    {{/is_equals}}
    {{BR}}

    @6: traversable object{{TAB}}
    {{#is_equals one=1/two=2/three=3,ok (first section + traversal),fail (%s)}}
      {{@test object_traversable}}
        {{_key}}={{.}}
        {{^_last}}/{{/_last}}
      {{|}}
        second section
      {{/test}}
    {{/is_equals}}
    {{BR}}



    {{BR}}FALSY LAMBDA SWITCH{{BR}}

    ^1: path not found{{TAB}}
    {{^f.not_found 1}}
      ok (falsy section)
    {{|123}}
      fail (switch section)
    {{|}}
      fail (truthy section)
    {{/f.not_found}}
    {{BR}}

    ^2: boolean false{{TAB}}
    {{^test false}}
      ok (falsy section)
    {{|}}
      fail (truthy section)
    {{|123}}
      fail (switch section)
    {{/test}}
    {{BR}}

    ^3: boolean true{{TAB}}
    {{^test true}}
      fail (falsy section)
    {{|123}}
      fail (switch section)
    {{|}}
      ok (truthy section)
    {{/test}}
    {{BR}}

    ^4: integer zero{{TAB}}
    {{^integer 0}}
      ok (falsy section)
    {{|}}
      fail (truthy section)
    {{|123}}
      fail (switch section)
    {{/integer}}
    {{BR}}

    ^5: empty string{{TAB}}
    {{^test empty_string}}
      ok (falsy section)
    {{|}}
      fail (truthy section)
    {{|123}}
      fail (switch section)
    {{/test}}
    {{BR}}

    ^6: empty array{{TAB}}{{TAB}}
    {{^test empty_array}}
      ok (falsy section)
    {{|}}
      fail (truthy section)
    {{|123}}
      fail (switch section)
    {{/test}}
    {{BR}}

    ^7: non-empty array{{TAB}}
    {{^test array}}
      fail (falsy section)
    {{|}}
      ok (truthy section)
    {{|123}}
      fail (switch section)
    {{/test}}
    {{BR}}

    ^8: object{{TAB}}{{TAB}}
    {{^test object}}
      fail (falsy section)
    {{|}}
      ok (truthy section)
    {{|123}}
      fail (switch section)
    {{/test}}
    {{BR}}

    ^9: string match{{TAB}}
    {{^string 0}}
      fail (falsy section)
    {{|}}
      fail (truthy section)
    {{|0}}
      ok (switch section)
    {{/string}}
    {{BR}}

    ^10: integer match{{TAB}}
    {{^integer 0}}
      fail (falsy section)
    {{|}}
      fail (truthy section)
    {{|0}}
      ok (switch section)
    {{/integer}}
    {{BR}}

    ^11: string no match{{TAB}}
    {{^string hello}}
      fail (falsy section)
    {{|}}
      ok (truthy section)
    {{|0}}
      fail (switch section)
    {{/string}}
    {{BR}}

    ^12: integer>0 no match{{TAB}}
    {{^integer 123}}
      fail (falsy section)
    {{|}}
      ok (truthy section)
    {{|0}}
      fail (switch section)
    {{/string}}
    {{BR}}

    {{BR}}{{BR}}

');
$b = [
  'is_empty' => (static function($m,$a) {
    $s = $m->render();
    if ($a === '') {
      return ($s === '') ? 1 : 0;
    }
    $b = explode(',', $a, 2);
    if ($s === '') {
      return $b[0];
    }
    return sprintf($b[1], $s);
  }),
  'is_equals' => (static function($m,$a) {
    $s = $m->render();
    $b = explode(',', $a, 3);
    if ($s === $b[0]) {
      return $b[1];
    }
    return sprintf($b[2], $s);
  }),
  'not_empty' => (static function($m,$a,$i=-1) {
    return ~$i ? $m->get($i) : '';
  }),
  'f' => [
    'not_a_func' => false,
    'sum' => (static function($m,$a) {
      $b = 0;
      foreach (explode(' ', $a) as $c) {
        $b += intval($c);
      }
      return $b;
    }),
  ],
  'test' => (static function($m,$a) {
    switch ($a) {
    case 'empty_string':
      return '';
    case 'non_empty_string':
      return '0';
    case 'false':
      return false;
    case 'true':
      return true;
    case 'empty_array':
      return [];
    case 'array':
      return [1,2,3,4,5];
    case 'object':
      return (object)['property'=>'ok'];
    case 'object_empty_iterable':
      return (new class() implements Countable,ArrayAccess
      {
        public array $a=[];
        function count(): int {return count($this->a);}
        function offsetExists(mixed $i): bool {return true;}
        function offsetGet(mixed $i): mixed {return $this->a[$i];}
        function offsetSet(mixed $i, mixed $v): void {}
        function offsetUnset(mixed $i): void {}
      });
    case 'object_iterable':
      return (new class() implements Countable,ArrayAccess
      {
        public array $a=[1,2,3,4,5];
        function count(): int {return count($this->a);}
        function offsetExists(mixed $i): bool {return true;}
        function offsetGet(mixed $i): mixed {return $this->a[$i];}
        function offsetSet(mixed $i, mixed $v): void {}
        function offsetUnset(mixed $i): void {}
      });
    case 'object_traversable':
      return (new class() implements Countable,Iterator
      {
        public $v=[1,2,3],$k=['one','two','three'],$i=0;
        function count(): int {return count($this->v);}
        function current(): mixed {return $this->v[$this->i];}
        function key(): mixed {return $this->k[$this->i];}
        function next(): void {++$this->i;}
        function rewind(): void {$this->i = 0;}
        function valid(): bool {return $this->i >= count($this->v);}
      });
    case 'hashmap':
      return [
        'name' => 'Joe',
        'age'  => 80,
      ];
    }
    return $a;
  }),
  'string' => (static function($m,$a) {
    return $a;
  }),
  'integer' => (static function($m,$a) {
    return intval($a);
  }),
];
# }}}
# render! {{{
try
{
  $c = $m->prepare($a, $b);
  #$c = $m->render($a, $b);
  echo "===============================\n";
  #var_dump($a);
  #var_dump($b);
  echo $c;
  #var_dump($m->code);
}
catch (Throwable $e) {
  var_dump($e);
}
exit;
# }}}
# lambda iterator {{{
$a = $m->outdent('

  numbers: 
  {{@numbers}}
    {{.}}
    {{^_last}},{{/}}
  {{/}}

  {{BR}}

  numbers: 
  {{#iterate numbers}}
    {{.}}
    {{^last}},{{/}}
  {{/}}

  {{BR}}

  numbers: 
  {{#arrayIterator numbers}}
    {{value}}
    {{^last}},{{/}}
  {{/}}

');
$b = [
  'numbers' => [1,2,3,4,5],
];
$b['arrayIterator'] = (function ($m,$a) use ($b) {
  # prepare
  $list = $b[$a];
  $last = count($list) - 1;
  $pair = [];
  # compose
  for ($x='',$i=0; $i <= $last; ++$i)
  {
    $pair[] = [
      'last'  => $i === $last,
      'value' => $list[$i],
    ];
  }
  return $pair;
});
$b['iterate'] = (function ($m,$a) use ($b) {
  # prepare
  $list = $b[$a];
  $last = count($list) - 1;
  $help = ['last' => ($last === 0)];
  # add helper
  $m->helper($help);
  # compose
  for ($x='',$i=0; $i <= $last; ++$i)
  {
    $help['last'] = $i === $last;
    $x .= $m->render($list[$i]);
  }
  return $x;
});
/***/
# }}}
### JUNKYARD
# ? {{{
$a = $m->outdent('



');
/***
$b = $m->_tokenize($a, $m->s0, $m->s1, $m->n0, $m->n1);
$b = $m->_parse($a, $b);
#$c = $m->_compose($b);
#var_dump($c);
exit;
/***/
$b = [
  'people' => [
    ['name'=>'Joe','age'=>80],
    ['name'=>'Bill','age'=>67],
    ['name'=>'Donald','age'=>77],
  ],
  'lambda' => (static function($m,$a) {
    return 123;
    return true;
    #return $m->prepare($m->text());
  }),
];
/***/
# }}}
# ASSISTED OBJECT ITERATION {{{
$render($m->outdent('

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
$render($m->outdent('

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
###
