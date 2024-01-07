<?php declare(strict_types=1);
# defs {{{
namespace SM;
use FFI,Throwable;
use function
  class_exists,function_exists,file_exists,
  class_alias,chr,ord,mb_chr,mb_ord,
  strlen,strval,substr,strpos,usleep;
use const
  PHP_OS_FAMILY,DIRECTORY_SEPARATOR;
###
require_once __DIR__.DIRECTORY_SEPARATOR.'promise.php';
# }}}
class Conio # {{{
{
  # base {{{
  static ?object $I=null,$ERROR=null;
  static int     $ANSI=0,$HRTIME=0;
  static string  $LAST_CHAR='';
  static function init(): bool
  {
    if (self::$I) {
      return !self::$ERROR;
    }
    try
    {
      if (!class_exists('FFI'))
      {
        throw ErrorEx::fail(
          'extension required: FFI'
        );
      }
      if (!function_exists('mb_ord'))
      {
        throw ErrorEx::fail(
          'extension required: mbstring'
        );
      }
      self::$I = (PHP_OS_FAMILY === 'Windows')
        ? new ConioWin()
        : new ConioNop();
    }
    catch (Throwable $e)
    {
      self::$ERROR = ErrorEx::from($e);
      self::$I = new ConioNop();
    }
    return !self::$ERROR;
  }
  private function __construct()
  {}
  # }}}
  static function is_ansi(): bool # {{{
  {
    if (!self::$ANSI)
    {
      if (self::$I->is_ansi()) {self::$ANSI++;}
      else {self::$ANSI--;}
    }
    return self::$ANSI > 0;
  }
  # }}}
  static function is_keycode(string $c): bool # {{{
  {
    return (
      (strlen($c) === 2) &&
      ($c[0] === "\x00" || $c[0] === "\xE0")
    );
  }
  # }}}
  static function kbhit(): bool # {{{
  {
    return self::$I->kbhit();
  }
  # }}}
  static function getch(): object # {{{
  {
    $I = self::$I;
    self::$LAST_CHAR = '';
    return Promise::Func(static function($A) use ($I) {
      # probe
      if (($ch = $I->getch()) === '')
      {
        # repeat mode
        ###
        # common maximal performance for keyboard:
        # rate  = 32cps = 1000ms/32 = 31ms
        # delay = 250ms
        ###
        # fast?
        if ($t = $I->hrtime)
        {
          if ($A::$HRTIME - $t < 1001000000)
          {
            $I->hrtime = $A::$HRTIME;# prolong
            return $A->repeat(30);
          }
          $I->hrtime = 0;# slowdown
        }
        # slow
        return $A->repeat(200);
      }
      # complete
      $I->hrtime = $A::$HRTIME;
      $A->result->value = self::$LAST_CHAR = $ch;
      return null;
    });
  }
  # }}}
  static function putch(string $c): bool # {{{
  {
    return self::$I->putch($c);
  }
  # }}}
}
# }}}
class ConioNop # dummy {{{
{
  public int $hrtime=0;
  # helpers {{{
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
  # api {{{
  function kbhit(): bool {
    return false;
  }
  function getch(): string {
    return '';
  }
  function getch_wait(): string {
    return '';
  }
  function putch(string $c): bool {
    return false;
  }
  function is_ansi(): bool {
    return false;
  }
  # }}}
}
# }}}
class ConioWin extends ConioNop # {{{
{
  # base {{{
  const FILE = 'conio-win.h';
  public object $con;
  function __construct()
  {
    $file = __DIR__.DIRECTORY_SEPARATOR.self::FILE;
    if (!file_exists($file))
    {
      throw ErrorEx::fail(
        'file not found: '.$file
      );
    }
    $this->con = FFI::load($file);
  }
  # }}}
  # api {{{
  function kbhit(): bool {
    return $this->con->_kbhit() !== 0;
  }
  function getch(): string
  {
    if (!$this->con->_kbhit()) {
      return '';
    }
    $i = $this->con->_getwch();
    return ($i === 0 || $i === 224)
      ? chr($i).chr($this->con->_getwch())
      : self::int2ch($i);
  }
  function getch_wait(): string
  {
    $i = $this->con->_getwch();
    return ($i === 0 || $i === 224)
      ? chr($i).chr($this->con->_getwch())
      : self::int2ch($i);
  }
  function putch(string $c): bool
  {
    return ~($i = self::ch2int($c))
      ? ($this->con->_putwch($i) === $i)
      : false;
  }
  function is_ansi(): bool
  {
    # detect ANSI console
    # flush input
    while ($this->getch() !== '')
    {}
    # request cursor position
    if ($this->con->_cputs("\033[6n")) {
      throw ErrorEx::fail('_cputs');
    }
    # check no reply
    usleep(1);
    if (!$this->con->_kbhit())
    {
      $this->con->_cputs("\r\t\r");# cleanup
      return false;
    }
    # read the reply
    $r = '';
    do {
      $r .= $this->getch_wait();
    }
    while ($this->con->_kbhit());
    # match the reply as ESC[#;#R
    if (($i = strlen($r)) < 6 ||
        substr($r, 0, 2) !== "\033[" ||
        ($i = strpos($r, ';', 2)) === false ||
        strpos($r, 'R', $i) === false)
    {
      $this->con->_cputs("\r\t\r");# erase garbage
      return false;
    }
    return true;
  }
  # }}}
}
# }}}
return Conio::init();
###
