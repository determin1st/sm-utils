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
echo "OS: ".php_uname("s")." ".php_uname("v")."\n";
echo "Conio capabilities:\n";
echo "---\n";
echo "ANSI escape sequences\t: ";
if ($i = Conio::is_ansi())
{
  $s = ($i > 1) ? 'supported' : 'limited';
  echo $s."\n";
  echo "Terminal ID\t\t: ".Conio::id();
  echo "\n";
}
else {
  echo "not supported\n";
}
echo "Asynchronous writing\t: ";
echo Conio::is_async() ? 'supported' : 'not supported';
echo "\n";
if ($a = Conio::proc())
{
  echo "Console parent\t\t: ";
  echo implode(' <= ', $a);
  echo "\n";
}
echo "---\n";
echo "type any char to quit..";
await(Conio::readch());
echo "\n\n";
###
