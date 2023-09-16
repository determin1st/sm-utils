<?php declare(strict_types=1);
# defs {{{
namespace SM;
extension_loaded('php_ds') || dl('php_ds.dll');
use
  Throwable,Ds\Vector,Ds\Deque,
  SplDoublyLinkedList,SplObjectStorage;
###
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
require_once DIR_SM_UTILS.'functions.php';
require_once DIR_SM_UTILS.'promise.php';
# }}}
echo "expansive replacement: array_splice vs Ds\Deque vs SplDoublyLinkedList"; # {{{
echo "\n\n";
$n = 10000000;
$m = 15;
$a = [1];
$b = [1,2,3];
$o1 = new Deque($a);
$o2 = new Deque($b);
$d1 = new SplDoublyLinkedList();
$d1->push(1);
$d2 = new SplDoublyLinkedList();
foreach ($b as $i) {
  $d2->push($i);
}
$d2->setIteratorMode(SplDoublyLinkedList::IT_MODE_LIFO);
###
echo "> array_splice: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  array_splice($a, 0, 1, $b);
  if (++$j > $m) {$j = 0;$a = [1];}
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "> Deque: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  $o1->shift();
  $o1->unshift(...$o2);
  if (++$j > $m)
  {
    $j = 0;
    $o1 = new Deque([1]);
  }
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "> SplDoublyLinkedList(for): ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  $d1->shift();
  for ($k=$d2->count() - 1; $k >= 0; --$k) {
    $d1->unshift($d2->offsetGet($k));
  }
  if (++$j > $m)
  {
    $j = 0;
    $d1 = new SplDoublyLinkedList();
    $d1->push(1);
  }
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "> SplDoublyLinkedList(while): ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  $d1->shift();
  $k = $d2->count();
  while (--$k >= 0) {
    $d1->unshift($d2->offsetGet($k));
  }
  if (++$j > $m)
  {
    $j = 0;
    $d1 = new SplDoublyLinkedList();
    $d1->push(1);
  }
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "> SplDoublyLinkedList(foreach): ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  foreach ($d2 as $value) {
    $d1->unshift($value);
  }
  if (++$j > $m)
  {
    $j = 0;
    $d1 = new SplDoublyLinkedList();
    $d1->push(1);
  }
}
echo hrtime_delta_ms($t)."ms\n";
###
###
exit(0);
# }}}
echo "push: array vs Ds\Vector vs Ds\Deque vs SplDoublyLinkedList"; # {{{
echo "\n\n";
$n = 10000000;
###
echo "> array[]: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  $a = [];
  for ($j; $j < 1000; ++$j) {
    $a[] = '1234567890';
  }
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "> Vector::push: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  $o = new Vector();
  for ($j; $j < 1000; ++$j) {
    $o->push('1234567890');
  }
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "> Vector[]: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  $o = new Vector();
  for ($j; $j < 1000; ++$j) {
    $o[] = '1234567890';
  }
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "> array_push: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  $a = [];
  for ($j; $j < 1000; ++$j) {
    array_push($a, '1234567890');
  }
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "> Deque::push: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  $o = new Deque();
  for ($j; $j < 1000; ++$j) {
    $o->push('1234567890');
  }
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "> SplDoublyLinkedList::push: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  $o = new SplDoublyLinkedList();
  for ($j; $j < 1000; ++$j) {
    $o->push('1234567890');
  }
}
echo hrtime_delta_ms($t)."ms\n";
###
###
exit(0);
# }}}
echo "shift/unshift: array vs Ds\Deque vs SplDoublyLinkedList"; # {{{
echo "\n\n";
$n = 10000000;
$j = 10;
for ($a=[],$i=0; $i < $j; ++$i) {
  $a[] = '1234567890';
}
for ($o=new Deque(),$i=0; $i < $j; ++$i) {
  $o[] = '1234567890';
}
for ($d=new SplDoublyLinkedList(),$i=0; $i < $j; ++$i) {
  $d[] = '1234567890';
}
###
echo "> array: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  if ($j > 10) {array_shift($a);$j--;}
  else {array_unshift($a, '123');$j++;}
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "> Deque: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  if ($j > 10) {$o->shift();$j--;}
  else {$o->unshift('123');$j++;}
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "> SplDoublyLinkedList: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  if ($j > 10) {$d->shift();$j--;}
  else {$d->unshift('123');$j++;}
}
echo hrtime_delta_ms($t)."ms\n";
###
###
exit(0);
# }}}
echo "truthy/falsy: array vs int vs bool vs string vs object"; # {{{
echo "\n\n";
$n = 100000000;
###
$a = [0,1,2,3,4,5,6,7,8,9];
$b = [];
$x = &$a;
echo "> array: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  if ($x) {$x = &$b;}
  else    {$x = &$a;}
  #if ($a) {$j++;}
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "> int: ";
$int1 = 123;
$int2 = 0;
$x = &$int1;
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  if ($x) {$x = &$int2;}
  else    {$x = &$int1;}
  #if ($int1) {$j++;}
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "> bool: ";
$bool1 = true;
$bool2 = false;
$x = &$bool1;
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  if ($x) {$x = &$bool2;}
  else    {$x = &$bool1;}
  #if ($bool1) {$j++;}
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "> string: ";
$str1 = 'something';
$str2 = '';
$x = &$str1;
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  if ($x) {$x = &$str2;}
  else    {$x = &$str1;}
  #if ($str1) {$j++;}
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "> object/null: ";
$o1 = (object)['key'=>123];
$o2 = null;
$x = &$o1;
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  if ($x) {$x = &$o2;}
  else    {$x = &$o1;}
  #if ($o1) {$j++;}
}
echo hrtime_delta_ms($t)."ms\n";
###
###
exit(0);
# }}}
echo "non-empty array vs Deque"; # {{{
echo "\n\n";
$n = 10000000;
$a = [1,2,3];
$b = new Deque([4,5,6]);
###
echo "> array: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  if (count($a)) {$j++;}
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "> Deque: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  if ($b->count()) {$j++;}
}
echo hrtime_delta_ms($t)."ms\n";
###
###
exit(0);
# }}}
echo "hashmap foreach vs do..while"; # {{{
echo "\n\n";
$n = 10000;
for ($a=[],$i=0; $i < 1000; ++$i) {
  $a['c'.rand(1000,9999).'-'.rand(1000,9999)] = rand();
}
###
echo "> foreach: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  foreach($a as $k => $v) {
    $j++;
  }
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "> foreach (&): ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  foreach($a as $k => &$v) {
    $j++;
  }
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "> do..while: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  $v = reset($a);
  do
  {
    $k = key($a);
    $j++;
  }
  while (($v = next($a)) !== false);
}
echo hrtime_delta_ms($t)."ms\n";
###
###
exit(0);
# }}}
echo "unset vs array_pop"; # {{{
echo "\n\n";
$n = 4000000;
###
echo "> unset";
$a = array_fill(0, $n, [1,2,3]);
echo "(): ";
$t = hrtime(true);
for ($i=0,$j=$n; $i < $n; ++$i)
{
  unset($a[--$j]);
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "> array_pop";
$a = array_fill(0, $n, [1,2,3]);
echo "(): ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  \array_pop($a);
}
echo hrtime_delta_ms($t)."ms\n";
###
###
exit(0);
# }}}
echo "signal vs long sleep blocking";# {{{
echo "\n\n";
$n = 0;
if (function_exists($f = 'sapi_windows_set_ctrl_handler'))
{
  # WinOS
  $f(function (int $e) use (&$n) {
    if ($e === PHP_WINDOWS_EVENT_CTRL_C) {
      echo "[CTRL+C]";
    }
    else {
      echo "[CTRL+BREAK]";
    }
    exit($n = 1);
  });
}
else
{
  # NixOS
  # ...
}
echo "> sleep: ";
sleep(10);
echo "[".$n."]\n";
exit(0);
# }}}
echo "is_array vs is_object"; # {{{
echo "\n\n";
$n = 100000000;
$a = [1,2,3];
$o = (object)$a;
###
echo "(true) is_array: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  if (is_array($a)) {$j++;}
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "(true) is_object: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  if (is_object($o)) {$j++;}
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "(false) is_array: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  if (is_array($o)) {$j++;}
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "(false) is_object: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  if (is_object($a)) {$j++;}
}
echo hrtime_delta_ms($t)."ms\n";
###
###
exit(0);
# }}}
echo "packed foreach vs for"; # {{{
echo "\n\n";
$n = 1000;
$a = array_fill(0, 100000, 1);
###
echo "(big) foreach: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  foreach ($a as $b => $c) {
    $j += $c;
  }
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "(big) for: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  for ($b=0,$c=count($a); $b < $c; ++$b) {
    $j += $a[$b];
  }
}
echo hrtime_delta_ms($t)."ms\n";
###
$n = 1000000;
$a = array_fill(0, 100, 1);
###
echo "(small) foreach: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  foreach ($a as $b => $c) {
    $j += $c;
  }
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "(small) for: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  for ($b=0,$c=count($a); $b < $c; ++$b) {
    $j += $a[$b];
  }
}
echo hrtime_delta_ms($t)."ms\n";
###
exit(0);
# }}}
echo "const:: vs instanceof"; # {{{
echo "\n\n";
abstract class Revers {
  const REVERSIBLE=false;
}
class StasisNotReversible {
  public int $i=0;
}
class StasisFalse extends Revers {
  public int $i=0;
}
class StasisTrue extends Revers
{
  const REVERSIBLE=true;
  public int $i=0;
}
$o1 = new StasisFalse();
$o2 = new StasisTrue();
$o3 = new StasisNotReversible();
$n = 100000000;
###
echo "const:: of false: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  if ($o1::REVERSIBLE) {$j++;}
}
echo hrtime_delta_ms($t).'ms, '.(($i===$j)?'1':'0')."\n";
###
echo "instanceof false: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  if ($o3 instanceof Revers) {$j++;}
}
echo hrtime_delta_ms($t).'ms, '.(($i===$j)?'1':'0')."\n";
###
echo "const:: of true: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  if ($o2::REVERSIBLE) {$j++;}
}
echo hrtime_delta_ms($t).'ms, '.(($i===$j)?'1':'0')."\n";
###
echo "instanceof true: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  if ($o1 instanceof Revers) {$j++;}
}
echo hrtime_delta_ms($t).'ms, '.(($i===$j)?'1':'0')."\n";
###
exit(0);
# }}}
echo "__invoke() performance"; # {{{
echo "\n\n";
class Test
{
  function method(int $i): int {return $i + 1;}
  function __invoke(int $i): int {return $i + 1;}
}
$o = new Test();
$x = null;
$n = 10000000;
###
echo "calling \$o(): ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i) {
  $j = $o($i);
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "calling \$o->method(): ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i) {
  $j = $o->method($i);
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "calling \$o->__invoke(): ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i) {
  $j = $o->__invoke($i);
}
echo hrtime_delta_ms($t)."ms\n";
###
exit(0);
# }}}
###
