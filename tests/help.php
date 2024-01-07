<?php declare(strict_types=1);
namespace SM;
require_once
  __DIR__.DIRECTORY_SEPARATOR.'..'.
  DIRECTORY_SEPARATOR.'autoload.php';
###
function test_info(# {{{
  string $name='', string $text=''
):void
{
  static $INFO='';
  if ($text && $INFO === '')
  {
    cli_set_process_title(
      $name = $name.'•'.Fx::$PROCESS_ID
    );
    $INFO  = trim($text)."\n";
    $INFO .= "[i] print this information\n";
    $INFO .= "[z] opcache_reset()\n";
    $INFO .= "[q] quit\n\n";
    echo "=================\n";
    echo $name." started\n";
    echo "=================\n";
  }
  echo $INFO;
}
# }}}
function test_key(): void # {{{
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
}
# }}}
function test_cooldown(): void # {{{
{
  test_key();
  usleep(100000);# 100ms
}
# }}}
function error_dump(?object $e): void # {{{
{
  if ($e)
  {
    echo "\n> ERRORLEVEL=".$e->errorlevel()."\n";
    var_dump($e);
    echo "\n> press any key to quit..";
    await(Conio::getch());
  }
}
# }}}
###
