<?php declare(strict_types=1);
namespace SM;
require_once
  realpath(__DIR__.DIRECTORY_SEPARATOR.'..').
  DIRECTORY_SEPARATOR.'autoload.php';
###
Fx::AUTOLOAD;
###
function test_info(string $name='', string $text=''): void
{
  static $TITLE='',$INFO='';
  if ($text && $INFO === '')
  {
    $TITLE = $name.'•'.proc_id();
    cli_set_process_title($TITLE);
    $INFO  = trim($text)."\n";
    $INFO .= "[i] print this information\n";
    $INFO .= "[z] opcache_reset()\n";
    $INFO .= "[q] quit\n\n";
    echo "=================\n";
    echo $TITLE." started\n";
    echo "=================\n";
  }
  echo $INFO;
}
function test_cooldown(): void
{
  switch (Conio::$LAST_CHAR) {
  case 'i':
    echo "> info:\n";
    test_info();
    break;
  case 'z':
    echo "> opcache_get_status(): ";
    var_dump(opcache_get_status());
    echo "\n";
    echo "> opcache_reset(): ";
    if (opcache_reset()) {
      echo "OK\n";
    }
    else {
      echo "PENDING..\n";
    }
    break;
  case 'q':
    echo "> quit\n";
    exit(0);
  }
  usleep(100000);# 100ms
}
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
