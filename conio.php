<?php declare(strict_types=1);
# defs {{{
namespace SM;
use FFI,SplDoublyLinkedList,Throwable;
use function
  class_exists,function_exists,
  dechex,unpack,chr,ord,mb_convert_encoding,
  mb_chr,mb_ord,
  str_repeat,strlen,strval,substr,strpos,usleep,
  register_shutdown_function;
use const
  PHP_OS_FAMILY,DIRECTORY_SEPARATOR;
###
require_once __DIR__.DIRECTORY_SEPARATOR.'promise.php';
# }}}
class Conio # {{{
{
  # stasis {{{
  static ?object $BASE=null,$ERROR=null;
  static function init(): bool
  {
    if (self::$BASE) {
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
      self::$BASE = (PHP_OS_FAMILY === 'Windows')
        ? new ConioWinBase()
        : new ConioBase();
    }
    catch (Throwable $e)
    {
      self::$ERROR = ErrorEx::from($e);
      self::$BASE  = new ConioBase();
    }
    return !self::$ERROR;
  }
  private function __construct()
  {}
  # }}}
  # informational {{{
  static function is_focused(): int {
    return self::$BASE->focus;
  }
  static function is_ansi(): int {
    return self::$BASE->ansi;
  }
  static function is_async(): int {
    return self::$BASE->async;
  }
  static function id(): string {
    return self::$BASE->id();
  }
  static function proc(int $what=1): array
  {
    $a = self::$BASE->getProcessList();
    for ($b=[],$i=0,$j=count($a); $i < $j; ++$i) {
      $b[] = $a[$i][$what];
    }
    return $b;
  }
  # }}}
  # operational {{{
  static function readch(): object {
    return new Promise(new ConioReadCh(self::$BASE));
  }
  # }}}
}
# }}}
class ConioReadCh extends Completable # {{{
{
  function __construct(# {{{
    public object $base
  ) {}
  # }}}
  function _wait(): object # {{{
  {
    # active waiting? (5s)
    if (($t = $this->base->time) &&
        (self::$HRTIME - $t) < 5000100000)
    {
      return self::$THEN->delay(1);
    }
    # relaxed waiting
    return self::$THEN->delay();
  }
  # }}}
  function _complete(): ?object # {{{
  {
    if (($ch = $this->base->getch()) === '') {
      return $this->_wait();
    }
    $this->result->value = $ch;
    return null;
  }
  # }}}
}
# }}}
class ConioBase # dummy {{{
{
  public int
    $time=0,# last input timestamp (ns)
    $focus=-1,# is screen focused/active?
    $cols=0,$rows=0,# screen buffer size
    $posx=0,$posy=0,# cursor position
    $async=0,# async writing?
    $ansi=0;# VT support level
  public string
    $devAttr='';# VT device attributes
  ### VT based
  function id(): string # {{{
  {
    if (($x = $this->devAttr) === '') {
      return '';
    }
    static $vt100 = [
      '?1;2c' => 'VT100 with Advanced Video Option',
      '?1;0c' => 'VT101 with No Options',
      '?4;6c' => 'VT132 with Advanced Video and Graphics',
      '?6c'   => 'VT102',
      '?7c'   => 'VT131'
    ];
    if (isset($vt100[$x])) {
      return $vt100[$x];
    }
    static $vt220 = [
      '?12' => 'VT125',
      '?62' => 'VT220',
      '?63' => 'VT320',
      '?64' => 'VT420',
      '?65' => 'VT510/VT525'
    ];
    if (isset($vt220[$s = substr($x, 0, 3)])) {
      return $vt220[$s];
    }
    return $x;
  }
  # }}}
  ### dummies
  function getProcessList(): array {return [];}
  function getch(): string {return '';}
  function gets(): string {return '';}
  function puts(string $c): void {}
}
# }}}
class ConioWinBase extends ConioBase # {{{
{
  # modes {{{
  #|0x0001 # ENABLE_PROCESSED_INPUT
  #|0x0002 # ENABLE_LINE_INPUT
  #|0x0004 # ENABLE_ECHO_INPUT
  #|0x0008 # ENABLE_WINDOW_INPUT
  #|0x0010 # ENABLE_MOUSE_INPUT
  #|0x0020 # ENABLE_INSERT_MODE
  #|0x0040 # ENABLE_QUICK_EDIT_MODE
  #|0x0080 # ENABLE_EXTENDED_FLAGS
  #|0x0100 # ENABLE_AUTO_POSITION
  #|0x0200 # ENABLE_VIRTUAL_TERMINAL_INPUT
  const MODE_INPUT2 = 0|0x0008|0x0200;
  const MODE_INPUT1 = 0|0x0008|0x0010;
  #|0x0001 # ENABLE_PROCESSED_OUTPUT
  #|0x0002 # ENABLE_WRAP_AT_EOL_OUTPUT
  #|0x0004 # ENABLE_VIRTUAL_TERMINAL_PROCESSING
  #|0x0008 # DISABLE_NEWLINE_AUTO_RETURN
  #|0x0010 # ENABLE_LVB_GRID_WORLDWIDE
  const MODE_OUTPUT2 = 0|0x0002|0x0004;
  const MODE_OUTPUT1 = 0|0x0001|0x0002;
  # }}}
  # constructor {{{
  public object $kernel32,$keys,$mouse,$wcharToUtf8;
  public int    $stdin=0,$stdout=0;
  public int    $keyCount=0,$mouseCount=0;
  public int    $charAttr=0;
  public array  $mode,$codepage,$window,$windowMax;
  function __construct()
  {
    # prepare {{{
    $this->kernel32 = $api = FFI::load(
      __DIR__.DIRECTORY_SEPARATOR.
      'conio-kernel32.h'
    );
    $this->keys  = new SplDoublyLinkedList();
    $this->mouse = new SplDoublyLinkedList();
    # }}}
    # get input/output handles {{{
    # first, try to get CONIN handle
    # as it directly represents the console,
    # while STDIN may be redirected
    $i = $api->CreateFileA(
      'CONIN$', 0x80000000|0x40000000,
      0, null, 3, 0, 0
    );
    if ($i < 1 || $i > 2147483647)
    {
      throw ErrorEx::fail(
        'kernel32::CreateFileA', 'CONIN$', $i
      );
    }
    # check console api supports such a handle
    if ($api->FlushConsoleInputBuffer($i))
    {
      # obtain the output handle
      $o = $api->CreateFileA(
        'CONOUT$', 0x80000000|0x40000000,
        0x00000002, null, 3, 0, 0
      );
      if ($o < 1 || $o > 2147483647)
      {
        throw ErrorEx::fail(
          'kernel32::CreateFileA', 'CONOUT$', $o
        );
      }
    }
    else
    {
      # obtain degraded handles
      $i = $api->GetStdHandle(4294967286);# -10
      if ($i < 1 || $i > 2147483647)
      {
        # INVALID_HANDLE_VALUE=0xffffffff
        throw ErrorEx::fail(
          'kernel32::GetStdHandle', 'STDIN', $i
        );
      }
      $o = $api->GetStdHandle(4294967285);# -11
      if ($o < 1 || $o > 2147483647)
      {
        throw ErrorEx::fail(
          'kernel32::GetStdHandle', 'STDOUT', $o
        );
      }
      # check for redirection
      if ($api->GetFileType($i) !== 2 ||
          $api->GetFileType($o) !== 2)
      {
        throw ErrorEx::fail(__CLASS__,
          "STDIO and/or STDOUT is being redirected\n".
          "Conio is intended to operate ".
          "only with the console"
        );
      }
    }
    $this->stdin  = $i;
    $this->stdout = $o;
    # }}}
    # construct utf-8 transcoder {{{
    if (function_exists('mb_convert_encoding'))
    {
      $this->wcharToUtf8 = (
      function(string $buf, int $len):string
      {
        return mb_convert_encoding(
          $buf, 'UTF-8', 'UTF-16LE'
        );
      });
    }
    else
    {
      $this->wcharToUtf8 = (
      function(string $buf, int $len): string
      {
        $n = 4*$len;# overkill?
        $s = str_repeat("\x00", $n);
        $i = $this->kernel32->WideCharToMultiByte(
          65001, # CP_UTF8
          0, $buf, $len, $s, $n,
          null, null
        );
        return $i ? substr($s, 0, $i) : '';
      });
    }
    # }}}
    # get console information {{{
    # get input/output modes
    $mi = "\x00\x00\x00\x00";
    $mo = "\x00\x00\x00\x00";
    if (!$api->GetConsoleMode($i, $mi))
    {
      throw ErrorEx::fail(
        'kernel32::GetConsoleMode',
        'STDIN', $this->lastError()
      );
    }
    if (!$api->GetConsoleMode($o, $mo))
    {
      throw ErrorEx::fail(
        'kernel32::GetConsoleMode',
        'STDOUT', $this->lastError()
      );
    }
    $this->mode = [
      ($mi = unpack('L', $mi)[1]),
      ($mo = unpack('L', $mo)[1])
    ];
    # get screen information
    $s = str_repeat("\x00", 4+4+2+8+4+4);
    if (!$api->GetConsoleScreenBufferInfo($o, $s))
    {
      throw ErrorEx::fail(
        'kernel32::GetConsoleScreenBufferInfo',
        $this->lastError()
      );
    }
    $this->cols = unpack('S', substr($s, 0, 2))[1];
    $this->rows = unpack('S', substr($s, 2, 2))[1];
    $this->posx = $posx =
      unpack('S', substr($s, 4, 2))[1];
    $this->posy = $posy =
      unpack('S', substr($s, 6, 2))[1];
    $this->charAttr = unpack('S', substr($s, 8, 2))[1];
    $this->window = [# SMALL_RECT
      unpack('S', substr($s, 10, 2))[1],
      unpack('S', substr($s, 12, 2))[1],
      unpack('S', substr($s, 14, 2))[1],
      unpack('S', substr($s, 16, 2))[1]
    ];
    $this->windowMax = [
      unpack('S', substr($s, 18, 2))[1],
      unpack('S', substr($s, 20, 2))[1]
    ];
    # get input/output codepages
    if (!($ci = $api->GetConsoleCP()))
    {
      throw ErrorEx::fail(
        'kernel32::GetConsoleCP',
        $this->lastError()
      );
    }
    if (!($co = $api->GetConsoleOutputCP()))
    {
      throw ErrorEx::fail(
        'kernel32::GetConsoleOutputCP',
        $this->lastError()
      );
    }
    $this->codepage = [$ci, $co];
    # }}}
    # configure the console {{{
    # switch to UTF-8 codepages
    if ($ci !== 65001 &&
        !$api->SetConsoleCP(65001))
    {
      throw ErrorEx::fail(
        'kernel32::SetConsoleCP',
        $this->lastError()
      );
    }
    if ($co !== 65001 &&
        !$api->SetConsoleOutputCP(65001))
    {
      throw ErrorEx::fail(
        'kernel32::SetConsoleOutputCP',
        $this->lastError()
      );
    }
    # try to switch to raw input with
    # virtual terminal sequences support
    if ($mi === ($x = self::MODE_INPUT2)) {
      $mi = -1;# no need to switch
    }
    elseif (!$api->SetConsoleMode($i, $x))
    {
      # check not the "incorrect parameter" error
      if (($e = $api->GetLastError()) !== 87)
      {
        throw ErrorEx::fail(
          'kernel32::SetConsoleMode',
          $this->lastError($e)
        );
      }
      # incorrect parameter means that
      # VT sequence mode is not supported,
      # try to degrade to raw input only
      if ($mi === ($x = self::MODE_INPUT1)) {
        $mi = -1;# no need to switch
      }
      elseif (!$api->SetConsoleMode($i, $x))
      {
        throw ErrorEx::fail(
          'kernel32::SetConsoleMode',
          $this->lastError()
        );
      }
      if ($mo === ($x = self::MODE_OUTPUT1)) {
        $mo = -1;# no need to switch
      }
      elseif (!$api->SetConsoleMode($o, $x))
      {
        throw ErrorEx::fail(
          'kernel32::SetConsoleMode',
          $this->lastError()
        );
      }
      # limited support (ConEmu?)
      $this->ansi = 1;
    }
    else
    {
      # VT sequences are supported,
      # switch output mode
      if ($mo === ($x = self::MODE_OUTPUT2)) {
        $mo = -1;# no need to switch
      }
      elseif (!$api->SetConsoleMode($o, $x))
      {
        throw ErrorEx::fail(
          'kernel32::SetConsoleMode',
          $this->lastError()
        );
      }
      # native windows support
      $this->ansi = 2;
    }
    # get device attributes
    ###
    # this must emit response into the
    # console input immediately after being
    # recognized on the output.
    # The ENABLE_VIRTUAL_TERMINAL_INPUT flag
    # does not apply to query commands
    # as it is assumed that an application
    # making the query will always want
    # to receive the reply.
    ###
    $this->flushInput();
    $this->puts("\x1B[0c");
    $s = $this->gets();
    if (strlen($s) < 5 ||
        substr($s, 0, 3) !== "\x1B[?")
    {
      # not supported, cleanup
      $this->ansi = 0;
      $this->setCursorPos($posx, $posy);
      $this->puts('    ');
      $this->setCursorPos($posx, $posy);
    }
    else
    {
      # store
      $this->devAttr = substr($s, 2);
    }
    # }}}
    # set cleanup routine {{{
    register_shutdown_function(
    function() use ($api,$i,$o,$mi,$mo,$ci,$co)
    {
      # restore modes
      if ($mi >= 0) {
        $api->SetConsoleMode($i, $mi);
      }
      if ($mo >= 0) {
        $api->SetConsoleMode($o, $mo);
      }
      # restore codepages
      if ($ci !== 65001) {
        $api->SetConsoleCP($ci);
      }
      if ($co !== 65001) {
        $api->SetConsoleOutputCP($co);
      }
    });
    # }}}
  }
  # }}}
  /* structs {{{
  # 4+N bytes
  INPUT_RECORD STRUCT
    EventType             WORD ?
    two_byte_alignment    WORD ?
    UNION
      KeyEvent                KEY_EVENT_RECORD            <>
      MouseEvent              MOUSE_EVENT_RECORD          <>
      WindowBufferSizeEvent   WINDOW_BUFFER_SIZE_RECORD   <>
      MenuEvent               MENU_EVENT_RECORD           <>
      FocusEvent              FOCUS_EVENT_RECORD          <>
    ENDS
  INPUT_RECORD ENDS
  # 4+2+2+2+2+4 = 16 bytes
  KEY_EVENT_RECORD STRUCT
    bKeyDown          DWORD ?
    wRepeatCount      WORD ?
    wVirtualKeyCode   WORD ?
    wVirtualScanCode  WORD ?
    UNION
      UnicodeChar     WORD ?
      AsciiChar       BYTE ?
    ENDS
    dwControlKeyState DWORD ?
  KEY_EVENT_RECORD ENDS
  # 4+4+4+4 = 16 bytes
  MOUSE_EVENT_RECORD STRUCT
    dwMousePosition       COORD <>
    dwButtonState         DWORD      ?
    dwControlKeyState     DWORD      ?
    dwEventFlags          DWORD      ?
  MOUSE_EVENT_RECORD ENDS
  # 4 bytes
  WINDOW_BUFFER_SIZE_RECORD STRUCT
    dwSize  COORD <>
  WINDOW_BUFFER_SIZE_RECORD ENDS
  # 2 bytes
  MENU_EVENT_RECORD STRUCT
    dwCommandId  DWORD      ?
  MENU_EVENT_RECORD ENDS
  # 4 bytes
  FOCUS_EVENT_RECORD STRUCT
    bSetFocus  DWORD      ?
  FOCUS_EVENT_RECORD ENDS

  COORD STRUCT
    x  WORD      ?
    y  WORD      ?
  COORD ENDS
  MAXSCREENX = 43
  MAXSCREENY = 8
  SMALL_RECT STRUCT
    Left      WORD      ?
    Top       WORD      ?
    Right     WORD      ?
    Bottom    WORD      ?
  SMALL_RECT ENDS
  CONSOLE_CURSOR_INFO STRUCT
    dwSize    DWORD      ?
    bVisible  DWORD      ?
  CONSOLE_CURSOR_INFO ENDS
  /* }}} */
  function read(): int # {{{
  {
    # check any event is pending
    $api = $this->kernel32;
    $num = "\x00\x00\x00\x00";
    $i   = $api->GetNumberOfConsoleInputEvents(
      $this->stdin, $num
    );
    if (!$i)
    {
      throw ErrorEx::fail(
        'kernel32::GetNumberOfConsoleInputEvents',
        $this->lastError()
      );
    }
    if (!($cnt = unpack('l', $num)[1])) {
      return $this->keyCount;
    }
    # allocate buffer and read all events
    $buf = str_repeat("\x00", 20 * $cnt);
    $i   = $api->ReadConsoleInputW(
      $this->stdin, $buf, $cnt, $num
    );
    if (!$i)
    {
      throw ErrorEx::fail(
        'kernel32::ReadConsoleInputW',
        $this->lastError()
      );
    }
    if ($cnt !== ($i = unpack('l', $num)[1]))
    {
      throw ErrorEx::fail(
        'kernel32::ReadConsoleInputW',
        $cnt.' events were pending, '.
        'but '.$i.' events were read'
      );
    }
    # update last input timestamp
    $this->time = Completable::$HRTIME;
    # decode events
    $toUtf8 = $this->wcharToUtf8;
    $keys   = $this->keys;
    $mouse  = $this->mouse;
    for ($i=0,$j=0; $j < $cnt; ++$j)
    {
      # check event type
      $k  = unpack('S', substr($buf, $i, 2))[1];
      $i += 4;
      switch ($k) {
      case 0x0001:# KEY_EVENT {{{
        # translate character
        $ch = substr($buf, 10+$i, 2);
        $ch = ($ch !== "\x00\x00")
          ? $toUtf8($ch, 1)
          : '';
        ###
        $keys->push([
          /* 0:bKeyDown
          If the key is pressed,
          this member is TRUE. Otherwise,
          this member is FALSE (the key is released)
          */
          !!unpack('L', substr($buf, $i, 4))[1],
          /* 1:wRepeatCount
          The repeat count, which indicates that
          a key is being held down. For example,
          when a key is held down, you might get five
          events with this member equal to 1,
          one event with this member equal to 5,
          or multiple events with this member greater
          than or equal to 1.
          */
          unpack('S', substr($buf, 4+$i, 2))[1],
          /* 2:wVirtualKeyCode
          A virtual-key code that identifies
          the given key in a device-independent manner.
          */
          unpack('S', substr($buf, 6+$i, 2))[1],
          /* 3:wVirtualScanCode
          The virtual scan code of the given key that
          represents the device-dependent value
          generated by the keyboard hardware.
          */
          unpack('S', substr($buf, 8+$i, 2))[1],
          /* 4:UnicodeChar
          Translated Unicode character.
          */
          $ch,
          /* 5:dwControlKeyState
          The state of the control keys.
          This member can be one or more of the following values.
          CAPSLOCK_ON 0x0080 The CAPS LOCK light is on.
          ENHANCED_KEY 0x0100 The key is enhanced. See remarks.
          LEFT_ALT_PRESSED 0x0002 The left ALT key is pressed.
          LEFT_CTRL_PRESSED 0x0008 The left CTRL key is pressed.
          NUMLOCK_ON 0x0020 The NUM LOCK light is on.
          RIGHT_ALT_PRESSED 0x0001 The right ALT key is pressed.
          RIGHT_CTRL_PRESSED 0x0004 The right CTRL key is pressed.
          SCROLLLOCK_ON 0x0040 The SCROLL LOCK light is on.
          SHIFT_PRESSED 0x0010 The SHIFT key is pressed.
          ***
          Enhanced keys for the IBM® 101- and 102-key
          keyboards are the INS, DEL, HOME, END, PAGE UP,
          PAGE DOWN, and direction keys in the clusters
          to the left of the keypad; and the divide (/)
          and ENTER keys in the keypad.
          ***
          Keyboard input events are generated when any key,
          including control keys, is pressed or released.
          However, the ALT key when pressed and released
          without combining with another character,
          has special meaning to the system and
          is not passed through to the application.
          Also, the CTRL+C key combination is not passed
          through if the input handle is in processed mode
          (ENABLE_PROCESSED_INPUT).
          */
          unpack('L', substr($buf, 12+$i, 4))[1]
        ]);
        # rotate unhandled events
        if (++$this->keyCount > 100)
        {
          $keys->shift();
          $this->keyCount--;
        }
        $i += 16;
        break;
        # }}}
      case 0x0002:# MOUSE_EVENT {{{
        ###
        $mouse->push([
          /* dwMousePosition
          A COORD structure that contains the location
          of the cursor, in terms of the console screen
          buffer's character-cell coordinates.
          */
          unpack('S', substr($buf, 0+$i, 2))[1],
          unpack('S', substr($buf, 2+$i, 2))[1],
          /* dwButtonState
          The status of the mouse buttons.
          The least significant bit corresponds to the
          leftmost mouse button. The next least
          significant bit corresponds to the rightmost
          mouse button. The next bit indicates the
          next-to-leftmost mouse button. The bits then
          correspond left to right to the mouse buttons.
          A bit is 1 if the button was pressed.
          The following constants are defined for the
          first five mouse buttons:
          FROM_LEFT_1ST_BUTTON_PRESSED 0x0001:
            The leftmost mouse button.
          FROM_LEFT_2ND_BUTTON_PRESSED 0x0004:
            The second button fom the left.
          FROM_LEFT_3RD_BUTTON_PRESSED 0x0008:
            The third button from the left.
          FROM_LEFT_4TH_BUTTON_PRESSED 0x0010:
            The fourth button from the left.
          RIGHTMOST_BUTTON_PRESSED 0x0002:
            The rightmost mouse button.
          */
          unpack('L', substr($buf, 4+$i, 4))[1],
          /* dwControlKeyState
          The state of the control keys. This member can
          be one or more of the following values:
          CAPSLOCK_ON 0x0080:
            The CAPS LOCK light is on.
          ENHANCED_KEY 0x0100:
            The key is enhanced. See remarks.
          LEFT_ALT_PRESSED 0x0002:
            The left ALT key is pressed.
          LEFT_CTRL_PRESSED 0x0008:
            The left CTRL key is pressed.
          NUMLOCK_ON 0x0020:
            The NUM LOCK light is on.
          RIGHT_ALT_PRESSED 0x0001:
            The right ALT key is pressed.
          RIGHT_CTRL_PRESSED 0x0004:
            The right CTRL key is pressed.
          SCROLLLOCK_ON 0x0040:
            The SCROLL LOCK light is on.
          SHIFT_PRESSED 0x0010:
            The SHIFT key is pressed.
          */
          unpack('L', substr($buf, 8+$i, 4))[1],
          /* dwEventFlags
          The type of mouse event. If this value is zero,
          it indicates a mouse button being pressed or
          released. Otherwise, this member is one of
          the following values:
          DOUBLE_CLICK 0x0002:
            The second click (button press) of a
            double-click occurred. The first click is
            returned as a regular button-press event.
          MOUSE_HWHEELED 0x0008:
            The horizontal mouse wheel was moved.
          ***
          If the high word of the dwButtonState member
          contains a positive value, the wheel was
          rotated to the right. Otherwise, the wheel was
          rotated to the left.
          MOUSE_MOVED 0x0001:
            A change in mouse position occurred.
          MOUSE_WHEELED 0x0004:
            The vertical mouse wheel was moved.
          ***
          If the high word of the dwButtonState member
          contains a positive value, the wheel was
          rotated forward, away from the user. Otherwise,
          the wheel was rotated backward, toward the user.
          */
          unpack('L', substr($buf, 12+$i, 4))[1]
        ]);
        # rotate unhandled events
        if (++$this->mouseCount > 100)
        {
          $mouse->shift();
          $this->mouseCount--;
        }
        $i += 16;
        break;
        # }}}
      case 0x0004:# WINDOW_BUFFER_SIZE_EVENT {{{
        # Buffer size events are placed in
        # the input buffer when the console is
        # in window-aware mode (ENABLE_WINDOW_INPUT)
        ###
        $this->cols =
          unpack('S', substr($buf, 0+$i, 2))[1];
        $this->rows =
          unpack('S', substr($buf, 2+$i, 2))[1];
        ###
        var_dump(
          "cols=".$this->cols.",".
          "rows=".$this->rows."\n"
        );
        $i += 4;
        break;
        # }}}
      case 0x0008:# MENU_EVENT {{{
        # This document describes console platform
        # functionality that is no longer a part of
        # our ecosystem roadmap. We do not recommend
        # using this content in new products,
        # but we will continue to support existing
        # usages for the indefinite future.
        # Our preferred modern solution focuses on
        # virtual terminal sequences for maximum
        # compatibility in cross-platform scenarios.
        # You can find more information about this
        # design decision in our classic console
        # vs. virtual terminal document.
        ###
        $i += 4;
        break;
        # }}}
      case 0x0010:# FOCUS_EVENT {{{
        ###
        $this->focus =
          unpack('L', substr($buf, $i, 4))[1];
        ###
        $i += 4;
        break;
        # }}}
      default:# unknown {{{
        /***
        throw ErrorEx::fail(
          'kernel32::ReadConsoleInputW',
          'unknown event['.$j.'|'.$cnt.'] type='.$k
        );
        /***/
        # skip the rest
        break 2;
        # }}}
      }
    }
    return $this->keyCount;
  }
  # }}}
  function write(string $s): void # {{{
  {
    $api = $this->kernel32;
    $overlapped = (
      "\x00\x00\x00\x00". # ULONG_PTR Internal;
      "\x00\x00\x00\x00". # ULONG_PTR InternalHigh;
      /***
      union {
        struct {
          DWORD Offset;
          DWORD OffsetHigh;
        } DUMMYSTRUCTNAME;
        PVOID Pointer;
      } DUMMYUNIONNAME;
      /***/
      "\x00\x00\x00\x00". # DWORD Offset;
      "\x00\x00\x00\x00". # DWORD OffsetHigh;
      "\x00\x00\x00\x00". # HANDLE    hEvent;
      "\x00\x00\x00\x00".
      "\x00\x00\x00\x00"
    );
    /* completion routine {{{
    void LpoverlappedCompletionRoutine(
      [in]      DWORD dwErrorCode,
      [in]      DWORD dwNumberOfBytesTransfered,
      [in, out] LPOVERLAPPED lpOverlapped
    )
    {...}
    The return value for an asynchronous operation
    is 0 (ERROR_SUCCESS) if the operation completed
    successfully or if the operation completed
    with a warning. To determine whether an I/O
    operation was completed successfully,
    check that dwErrorCode is 0, call
    GetOverlappedResult, then call GetLastError.
    For example, if the buffer was not large
    enough to receive all of the data from a call
    to ReadFileEx, dwErrorCode is set to 0,
    GetOverlappedResult fails, and GetLastError
    returns ERROR_MORE_DATA.
    ---
    Returning from this function allows another
    pending I/O completion routine to be called.
    All waiting completion routines are called
    before the alertable thread's wait is
    completed with a return code of WAIT_IO_COMPLETION.
    The system may call the waiting completion
    routines in any order. They may or may not
    be called in the order the I/O
    functions are completed.
    ---
    Each time the system calls a completion routine,
    it uses some of the application's stack.
    If the completion routine does additional
    asynchronous I/O and alertable waits,
    the stack may grow.
    }}} */
    $callback = (function(int $e, int $n): void {
      ###
      var_dump("GOT THE RESULT!", $e, $n);
      ###
    });
    /***
    $i = $api->WriteFileEx(
      $o, $s, strlen($s),
      $overlapped, $callback
    );
    /***/
    $len = "\x00\x00\x00\x00";
    $i = $api->WriteFile(
      $o, $s, strlen($s),
      $len, $overlapped
    );
    if (!$i || ($i = $api->GetLastError()))
    {
      echo '[ASYNC]';
      var_dump($i, $s = $this->lastError($i));
      throw ErrorEx::fail(
        'kernel32::WriteFileEx',
        #$this->lastError($i)
        $s
      );
      for ($i=0; $i < 100; ++$i)
      {
        usleep(100000);# 100ms
      }
    }
    else {
      echo '[SYNC]';
    }
  }
  # }}}
  function flushInput(): void # {{{
  {
    $x = $this->kernel32->FlushConsoleInputBuffer(
      $this->stdin
    );
    if (!$x)
    {
      throw ErrorEx::fail(
        'kernel32::FlushConsoleInputBuffer',
        $this->lastError()
      );
    }
  }
  # }}}
  function setCursorPos(int $x, int $y): void # {{{
  {
    $r = $this->kernel32->SetConsoleCursorPosition(
      $this->stdout, ($y << 16) + $x
    );
    if (!$r)
    {
      throw ErrorEx::fail(
        'kernel32::SetConsoleCursorPosition',
        $this->lastError()
      );
    }
    $this->posx = $x;
    $this->posy = $y;
  }
  # }}}
  function lastError(int $e=0): string # {{{
  {
    # get last error number
    $api = $this->kernel32;
    if (!$e && !($e = $api->GetLastError())) {
      return '';
    }
    $x = '0x'.dechex($e);
    $s = str_repeat("\x00", 2000);
    $i = $api->FormatMessageW(0
      |0x00001000 # FORMAT_MESSAGE_FROM_SYSTEM
      |0x00000200 # FORMAT_MESSAGE_IGNORE_INSERTS
      |0,
      null, $e, 0, $s, 1000, null
    );
    if (!$i) {
      return $x;
    }
    $j = $i * 2;
    $s = substr($s, 0, $j);
    $r = ($this->wcharToUtf8)($s, $i);
    return trim($r);
  }
  # }}}
  function getProcessList(int $max=10): array # {{{
  {
    # get the list of process identifiers
    $api = $this->kernel32;
    $lst = str_repeat("\x00\x00\x00\x00", $max);
    $n = $api->GetConsoleProcessList($lst, $max);
    if (!$n)
    {
      throw ErrorEx::fail(
        'kernel32::GetConsoleProcessList',
        $this->lastError()
      );
    }
    elseif ($n > $max) {# bigger buffer needed
      return $this->getProcessList($n);
    }
    # iterate the list and get each process details,
    # skip the first process as it refers to myself
    $path = str_repeat("\x00", 500);
    for ($a=[],$i=1; $i < $n; ++$i)
    {
      # get process handle from identifier
      $pid = unpack('L', substr($lst, 4*$i, 4))[1];
      $h = $api->OpenProcess(0x1000, false, $pid);
      if (!$h)
      {
        throw ErrorEx::fail(
          'kernel32::OpenProcess', $this->lastError()
        );
      }
      # get path to executable (dont fail here)
      $j = $api->K32GetProcessImageFileNameA(
        $h, $path, 500
      );
      $s = $j ? substr($path, 0, $j) : '';
      # cleanup
      if (!$api->CloseHandle($h))
      {
        throw ErrorEx::fail(
          'kernel32::CloseHandle', $this->lastError()
        );
      }
      # extract executable name
      $name = ($j = strrpos($s, '\\'))
        ? substr($s, $j + 1)
        : $s;
      # add process info
      $a[] = [$pid, $name, $s];
    }
    return $a;
  }
  # }}}
  function getch(): string # {{{
  {
    # read and check no keys
    if (!$this->read()) {
      return '';
    }
    # filter til the first key with a character
    $cnt  = &$this->keyCount;
    $keys = $this->keys;
    do
    {
      $cnt--;
      $key = $keys->shift();
      if ($key[0] && $key[4] !== '') {
        return $key[4];
      }
    }
    while ($cnt);
    return '';
  }
  # }}}
  function gets(): string # {{{
  {
    # read and check no keys
    if (!$this->read()) {
      return '';
    }
    # consume all
    $cnt  = &$this->keyCount;
    $keys = $this->keys;
    $str  = '';
    do
    {
      $cnt--;
      $key = $keys->shift();
      if ($key[0] && $key[4] !== '') {
        $str .= $key[4];
      }
    }
    while ($cnt);
    return $str;
  }
  # }}}
  function puts(string $s): void # {{{
  {
    static $len="\x00\x00\x00\x00";
    $i = $this->kernel32->WriteConsoleA(
      $this->stdout, $s, strlen($s), $len, null
    );
    if (!$i)
    {
      throw ErrorEx::fail(
        'kernel32::WriteConsoleA',
        $this->lastError()
      );
    }
  }
  # }}}
}
# }}}
return Conio::init();
###
