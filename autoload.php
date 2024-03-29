<?php declare(strict_types=1);
namespace SM;
use function define,defined,spl_autoload_register;
use const DIRECTORY_SEPARATOR;
require_once __DIR__.DIRECTORY_SEPARATOR.'functions.php';
###
defined('SM\\BASE') ||
define('SM\\BASE', new class()
{
  const DIR=__DIR__.DIRECTORY_SEPARATOR;
  const MAP=[
    'SM\\Conio'        => 'conio.php',
    'SM\\ErrorEx'      => 'error.php',
    'SM\\ErrorLog'     => 'error.php',
    'SM\\Fetch'        => 'fetch.php',
    'SM\\Promise'      => 'promise.php',
    'SM\\Loop'         => 'promise.php',
    'SM\\SyncExchange' => 'sync.php',
  ];
  public object $callback;
  public bool   $ready=false;
  function __construct() {
    $this->callback = $this->autoload(...);
  }
  function autoload(string $class): void
  {
    if (isset(self::MAP[$class])) {
      include self::DIR.self::MAP[$class];
    }
  }
  function register(): bool
  {
    if ($this->ready) {
      return true;
    }
    return $this->ready = spl_autoload_register(
      $this->callback
    );
  }
});
return (\SM\BASE)->register();
###
