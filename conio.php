<?php declare(strict_types=1);
# defs {{{
namespace SM;
use FFI,Throwable;
use function
  class_exists,function_exists,file_exists,
  class_alias,chr,ord,mb_chr,mb_ord,
  strlen,strval,substr,strpos;
use const
  PHP_OS_FAMILY,DIRECTORY_SEPARATOR;
###
require_once __DIR__.DIRECTORY_SEPARATOR.'error.php';
# }}}
abstract class ConioBase
{
  # common {{{
  static ?object $ERROR=null;
  static string  $LAST_CHAR='';
  static int     $IS_ANSI=-1;
  protected static $con,$ready=false;
  protected function __construct() {}
  abstract static function check(): bool;
  abstract static function init(): bool;
  abstract static function kbhit(): bool;
  abstract static function getch(): string;
  abstract static function getch_wait(): string;
  abstract static function putch(string $c): bool;
  abstract static function is_ansi(): bool;
  # }}}
  # hlp {{{
  static function is_keycode(string $c): bool # {{{
  {
    return (
      strlen($c) === 2 &&
      ($c[0] === "\x00" || $c[0] === "\xE0")
    );
  }
  # }}}
  static function to_keycode(string $c): array # {{{
  {
    return [ord($c[0]), ord($c[1])];
  }
  # }}}
  static function int2ch(int $i): string # {{{
  {
    if ($i <= 255) {
      return chr($i);
    }
    if (($c = mb_chr($i, 'UTF-16LE')) === false)
    {
      self::$ERROR = ErrorEx::warn('mb_chr', strval($i));
      return '';
    }
    return $c;
  }
  # }}}
  static function ch2int(string $c): int # {{{
  {
    switch (strlen($c)) {
    case 0:
      return -1;
    case 1:
      return ord($c);
    }
    if (($i = mb_ord($c, 'UTF-8')) === false)
    {
      self::$ERROR = ErrorEx::warn('mb_ord', $s);
      return -1;
    }
    return $i;
  }
  # }}}
  # }}}
}
class ConioWin extends ConioBase
{
  const FILE = __DIR__.DIRECTORY_SEPARATOR.'conio_win.h';
  static function check(): bool # {{{
  {
    if (!class_exists('FFI'))
    {
      self::$ERROR = ErrorEx::fail('extension required: FFI');
      return false;
    }
    if (!function_exists('mb_ord'))
    {
      self::$ERROR = ErrorEx::fail('extension required: mbstring');
      return false;
    }
    if (!file_exists(self::FILE))
    {
      self::$ERROR = ErrorEx::fail('file not found: '.self::FILE);
      return false;
    }
    return true;
  }
  # }}}
  static function init(): bool # {{{
  {
    if (self::$ready || !self::check()) {
      return false;
    }
    try
    {
      self::$ready = true;
      self::$con   = FFI::load(self::FILE);
    }
    catch (Throwable $e)
    {
      self::$ERROR = ErrorEx::from($e);
      self::$con   = null;
    }
    return self::$con && class_alias(
      '\SM\ConioWin',
      '\SM\Conio', false
    );
  }
  # }}}
  static function kbhit(): bool # {{{
  {
    return self::$con->_kbhit() !== 0;
  }
  # }}}
  static function getch(): string # {{{
  {
    return self::$con->_kbhit()
      ? self::getch_wait()
      : (self::$LAST_CHAR = '');
  }
  # }}}
  static function getch_wait(): string # {{{
  {
    try
    {
      $i = self::$con->_getwch();
      $c = ($i === 0 || $i === 224)
        ? chr($i).chr(self::$con->_getwch())
        : self::int2ch($i);
      ###
      return self::$LAST_CHAR = $c;
    }
    catch (Throwable $e)
    {
      self::$ERROR = ErrorEx::from($e);
      return self::$LAST_CHAR = '';
    }
  }
  # }}}
  static function putch(string $c): bool # {{{
  {
    try
    {
      return ~($i = self::ch2int($c))
        ? (self::$con->_putwch($i) === $i)
        : false;
    }
    catch (Throwable $e)
    {
      self::$ERROR = ErrorEx::from($e);
      return false;
    }
  }
  # }}}
  static function is_ansi(): bool # {{{
  {
    try
    {
      # check cache
      switch (self::$IS_ANSI) {
      case 0:
        return false;
      case 1:
        return true;
      }
      # detect ANSI console
      # https://gist.github.com/fnky/458719343aabd01cfb17a3a4f7296797
      ###
      # flush current input
      while (self::getch() !== '')
      {}
      # request cursor position
      if (self::$con->_cputs("\033[6n"))
      {
        # TODO: set ERROR
        return false;
      }
      # check no reply
      usleep(1);
      if (!self::$con->_kbhit())
      {
        self::$con->_cputs("\r\t\r");# erase garbage
        self::$IS_ANSI = 0;
        return false;
      }
      # read the reply
      $r = '';
      do {
        $r .= self::getch_wait();
      }
      while (self::$con->_kbhit());
      # match the reply as ESC[#;#R
      if (($i = strlen($r)) < 6 ||
          substr($r, 0, 2) !== "\033[" ||
          ($i = strpos($r, ';', 2)) === false ||
          strpos($r, 'R', $i) === false)
      {
        self::$con->_cputs("\r\t\r");# erase garbage
        self::$IS_ANSI = 0;
        return false;
      }
      self::$IS_ANSI = 1;
      return true;
    }
    catch (Throwable $e)
    {
      self::$ERROR = ErrorEx::from($e);
      return false;
    }
  }
  # }}}
}
return (PHP_OS_FAMILY === 'Windows')
  ? ConioWin::init()
  : false;
###
