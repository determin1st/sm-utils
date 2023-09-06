<?php declare(strict_types=1);
namespace SM;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
require_once DIR_SM_UTILS.'functions.php';
require_once DIR_SM_UTILS.'promise.php';
###
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
echo "foreach vs for"; # {{{
echo "\n\n";
$n = 1000;
$a = array_fill(0, 100000, 1);
###
echo "(big packed array) foreach: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  foreach ($a as $b => $c) {
    $j += $c;
  }
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "(big packed array) for: ";
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
echo "(small packed array) foreach: ";
$t = hrtime(true);
for ($i=0,$j=0; $i < $n; ++$i)
{
  foreach ($a as $b => $c) {
    $j += $c;
  }
}
echo hrtime_delta_ms($t)."ms\n";
###
echo "(small packed array) for: ";
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
