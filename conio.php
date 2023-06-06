<?php declare(strict_types=1);
namespace SM;
# defs {{{
use FFI,Throwable;
use function
  class_exists,function_exists,class_alias,
  chr,ord,mb_chr,mb_ord,strlen,strval;
use const
  PHP_OS_FAMILY,DIRECTORY_SEPARATOR;
###
require_once __DIR__.DIRECTORY_SEPARATOR.'error.php';
###
return (PHP_OS_FAMILY === 'Windows')
  ? (ConioWin::init() && class_alias('\SM\ConioWin', '\SM\Conio', false))
  : false;
# }}}
abstract class ConioBase
{
  # common {{{
  static $error;
  protected static $con,$init=false;
  protected function __construct() {}
  abstract static function check(): bool;
  abstract static function init(): bool;
  abstract static function kbhit(): bool;
  abstract static function getch_nowait(): string;
  abstract static function getch(): string;
  abstract static function putch(string $c): bool;
  # }}}
  # hlp {{{
  static function is_keycode(string $c): bool
  {
    return (
      strlen($c) === 2 &&
      ($c[0] === "\x00" || $c[0] === "\xE0")
    );
  }
  static function to_keycode(string $c): array {
    return [ord($c[0]), ord($c[1])];
  }
  static function int2ch(int $i): string
  {
    if ($i <= 255) {
      return chr($i);
    }
    if (($c = mb_chr($i, 'UTF-16LE')) === false)
    {
      self::$error = ErrorEx::warn('mb_chr', strval($i));
      return '';
    }
    return $c;
  }
  static function ch2int(string $c): int
  {
    switch (strlen($c)) {
    case 0:
      return -1;
    case 1:
      return ord($c);
    }
    if (($i = mb_ord($c, 'UTF-8')) === false)
    {
      self::$error = ErrorEx::warn('mb_ord', $s);
      return -1;
    }
    return $i;
  }
  # }}}
}
class ConioWin extends ConioBase
{
  const FILE = __DIR__.DIRECTORY_SEPARATOR.'conio.h';
  static function check(): bool # {{{
  {
    if (!class_exists('FFI'))
    {
      self::$error = ErrorEx::fail('extension required: FFI');
      return false;
    }
    if (!function_exists('mb_ord'))
    {
      self::$error = ErrorEx::fail('extension required: mbstring');
      return false;
    }
    if (!file_exists(self::FILE))
    {
      self::$error = ErrorEx::fail('file not found: '.self::FILE);
      return false;
    }
    return true;
  }
  # }}}
  static function init(): bool # {{{
  {
    if (self::$init || !self::check()) {return false;}
    try
    {
      self::$init  = true;
      self::$con   = FFI::load(self::FILE);
    }
    catch (Throwable $e)
    {
      self::$error = ErrorEx::from($e);
      self::$con   = null;
    }
    return !!self::$con;
  }
  # }}}
  # conio {{{
  static function kbhit(): bool {
    return self::$con->_kbhit() !== 0;
  }
  static function getch_nowait(): string {
    return self::$con->_kbhit() ? self::getch() : '';
  }
  static function getch(): string
  {
    try
    {
      $i = self::$con->_getwch_nolock();
      return ($i === 0 || $i === 224)
        ? chr($i).chr(self::$con->_getwch_nolock())
        : self::int2ch($i);
    }
    catch (Throwable $e)
    {
      self::$error = ErrorEx::from($e);
      return '';
    }
  }
  static function putch(string $c): bool
  {
    try
    {
      return ~($i = self::ch2int($c))
        ? (self::$con->_putwch_nolock($i) === $i)
        : false;
    }
    catch (Throwable $e)
    {
      self::$error = ErrorEx::from($e);
      return false;
    }
  }
  # }}}
}

