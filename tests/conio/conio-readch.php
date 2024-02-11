<?php declare(strict_types=1);
namespace SM;
require_once
  __DIR__.DIRECTORY_SEPARATOR.
  '..'.DIRECTORY_SEPARATOR.
  '..'.DIRECTORY_SEPARATOR.
  'autoload.php';
###
if ($e = Conio::$ERROR)
{
  echo "\n".ErrorLog::render($e);
  exit;
}
echo "\n";
echo "Conio::readch(): reads characters one by one\n";
echo "---\n";
echo "type any character to continue.. ";
if (!($r = await(Conio::readch()))->ok)
{
  echo "\n".ErrorLog::render($r);
  exit;
}
echo "ok\n";
echo "type anything or [q] to quit: ";
while (1)
{
  /***/
  if (!($r = await(Conio::readch()))->ok)
  {
    echo "\n".ErrorLog::render($r);
    break;
  }
  $c = $r->value;
  /*** FUZZY VERSION ***
  if (($c = Conio::getch()) === '')
  {
    usleep(100000);# 100ms cooldown
    continue;
  }
  /***/
  for ($a='',$i=0,$j=strlen($c); $i < $j; ++$i) {
    $a .= dechex(ord($c[$i]));
  }
  echo (
    '['.(ctype_space($c) ? '' : $c.':').
    '0x'.$a.']'
  );
  if ($c === 'q') {
    break;
  }
}
echo "\n";
###
