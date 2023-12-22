<?php declare(strict_types=1);
namespace SM;
use function define,defined,spl_autoload_register;
use const DIRECTORY_SEPARATOR;
###
defined('SM\\AUTOLOAD') ||
define('SM\\AUTOLOAD', new class()
{
  const MAP=[
    'SM\\Conio'    => 'conio.php',
    'SM\\Error'    => 'error.php',
    'SM\\ErrorLog' => 'error.php',
    'SM\\Fetch'    => 'fetch.php',
    'SM\\Mustache' => 'mustache.php',
    'SM\\Promise'  => 'promise.php',
  ];
  public object $callback;
  public bool   $ready=false;
  function __construct() {
    $this->callback = $this->autoload(...);
  }
  function autoload(string $class): void
  {
    if (isset(self::MAP[$class]))
    {
      include
        __DIR__.
        DIRECTORY_SEPARATOR.
        self::MAP[$class];
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
return (\SM\AUTOLOAD)->register();
###
