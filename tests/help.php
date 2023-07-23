<?php
define(
  'DIR_SM_UTILS',
  realpath(__DIR__.DIRECTORY_SEPARATOR.'..').DIRECTORY_SEPARATOR
);
require_once DIR_SM_UTILS.'conio.php';
function error_dump(?object $e): void
{
  if ($e)
  {
    echo "\n> ERRORLEVEL=".$e->errorlevel()."\n";
    var_dump($e);
    echo "\n> press any key to quit..";
    Conio::getch_wait();
  }
}
###
