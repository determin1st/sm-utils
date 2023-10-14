<?php declare(strict_types=1);
# defs {{{
namespace SM;
use
  Error,Throwable,Stringable,JsonSerializable;
use function
  class_alias,set_error_handler,func_num_args,
  implode,count,array_unshift,array_reverse,
  array_pop,array_push,array_is_list,array_slice,
  is_string,strval,strpos,strrpos,str_replace,
  substr,sprintf;
use const
  DIRECTORY_SEPARATOR;
###
require_once __DIR__.DIRECTORY_SEPARATOR.'mustache.php';
# }}}
interface Loggable # {{{
{
  ###
  # level => 0=info,1=warning,2=error
  # msg   => [..]
  # span  => integer (nanosec)
  # time  => integer (sec)
  # logs  => [..]
  ###
  function log(): array;
}
# }}}
class ErrorLog # {{{
  implements Stringable,JsonSerializable
{
  const OPTIONS = [# {{{
    'level' => [
      'green', # 0:information/success/pass
      'yellow',# 1:warning/issue
      'red',   # 2:error/failure
    ],
    'span'  => [# format,ansi,variant
      ' ~%s･%s','black',1
    ],
    # ●◆◎∙▪■✶ ▶▼ ■▄ ◥◢◤◣  ►◄ ∙･·• →
    'prefix' => [
      '◆',# 0:depth=0, header
      '▪',# 1:depth>0, item
      '●',# 2:untitled group
    ],
    'words' => '･',
    'header-type' => [' ► ',' ◄ '],# output,input
    'item-type'   => [' ► ',' ◄ '],# output,input
    'group' => [
      '│ ',
      '├─',
      '└─',
    ],
    'textbox' => [
      '╓',# spacer
      '╢ ',# first line / title
      '║ ',# multiline content
      '╙',# spacer
    ],
    'textbox-title' => [# format,ansi,variant
      '%s','underline',0
    ],
    'subgroup' => [
      # * title
      # │╓
      # └╢ multiline message
      #  ║ may be placed here
      #  ╙
      '│╓',
      '└╢ ',
      ' ║ ',
      ' ╙',
      # * title
      # │╓
      # ├╢ multiline message
      # │║ may be placed here
      # │╙
      '│╓',
      '├╢ ',
      '│║ ',
      '│╙',
    ],
    'block' => [
      '╓ ',
      '║ ',
      '╙ ',
    ],
  ];
  # }}}
  # base {{{
  static array $BASE_OPTS;
  public array $opts,$list=[];
  function __construct(array $o=[])
  {
    $this->opts = $o
      ? self::new_options($o)
      : $BASE_OPTS;
  }
  function __toString(): string {
    return $this->get();
  }
  function jsonSerialize(): mixed {
    return $this->list;
  }
  # }}}
  # hlp {{{
  static function init(): void # {{{
  {
    self::$BASE_OPTS = self::new_options([]);
  }
  # }}}
  static function new_options(array $o): array # {{{
  {
    $opts = self::OPTIONS;
    foreach ($o as $k => $v)
    {
      if (isset($opts[$k])) {
        $opts[$k] = $v;
      }
    }
    $prefix   = [];
    $headType = [];
    $itemType = [];
    $block    = ['' => $opts['block']];
    $group    = ['' => $opts['group']];
    $words    = ['' => $opts['words']];
    $textbox  = ['' => $opts['textbox']];
    foreach ($opts['level'] as $k)
    {
      $prefix[$k] = $opts['prefix'];
      foreach ($prefix[$k] as &$a) {
        $a = self::color_fg($a, $k, 1);
      }
      $headType[$k] = $opts['header-type'];
      foreach ($headType[$k] as &$a) {
        $a = self::color_fg($a, $k, 0);
      }
      $itemType[$k] = $opts['item-type'];
      foreach ($itemType[$k] as &$a) {
        $a = self::color_fg($a, $k, 1);
      }
      $group[$k] = $opts['group'];
      foreach ($group[$k] as &$a) {
        $a = self::color_fg($a, $k, 0);
      }
      $block[$k] = $opts['block'];
      foreach ($block[$k] as &$a) {
        $a = self::color_fg($a, $k, 0);
      }
      $words[$k] = self::color_fg(
        $opts['words'], $k, 1
      );
      $textbox[$k] = $opts['textbox'];
      foreach ($textbox[$k] as &$a) {
        $a = self::color_fg($a, $k, 1);
      }
    }
    $o = $opts['span'];
    $opts['span'] = $o[1]
      ? self::color_fg($o[0], $o[1], $o[2])
      : $o[0];
    $opts['prefix']      = $prefix;
    $opts['header-type'] = $headType;
    $opts['item-type']   = $itemType;
    $opts['block']       = $block;
    $opts['group']       = $group;
    $opts['words']       = $words;
    $opts['textbox']     = $textbox;
    return $opts;
  }
  # }}}
  # }}}
  # ansi renderer {{{
  const TEMPLATES = [# {{{
    'header' => '
      {{#level}}{:red-bright:}{{|}}{:green-bright:}{{/level}}
      ◆{:end-color:}
      {{msg}}{{span}}{{logs}}
    ',
    'span' => '
      {:black-bright:}~{{time}}{{unit}}{:end-color:}
    ',
    'logs' => '
    ',
  ];
  # }}}
  const ANSI_CODE = [# {{{
    # styles
    'reset'         => "\033[0m",
    'bold'          => "\033[1m",
    'faint'         => "\033[2m",
    'italic'        => "\033[3m",
    'underline'     => "\033[4m",
    'blink'         => "\033[5m",
    'inverse'       => "\033[7m",
    'hide'          => "\033[8m",
    'strike'        => "\033[9m",# strikethrough
    'end-bold'      => "\033[21m",
    'end-faint'     => "\033[22m",
    'end-italic'    => "\033[23m",
    'end-underline' => "\033[24m",
    'end-blink'     => "\033[25m",
    'end-inverse'   => "\033[27m",
    'end-hide'      => "\033[28m",
    'end-strike'    => "\033[29m",
    # foreground colors
    'black'          => "\033[30m",
    'red'            => "\033[31m",
    'green'          => "\033[32m",
    'yellow'         => "\033[33m",
    'blue'           => "\033[34m",
    'magenta'        => "\033[35m",
    'cyan'           => "\033[36m",
    'white'          => "\033[37m",
    'black-bright'   => "\033[90m",
    'red-bright'     => "\033[91m",
    'green-bright'   => "\033[92m",
    'yellow-bright'  => "\033[93m",
    'blue-bright'    => "\033[94m",
    'magenta-bright' => "\033[95m",
    'cyan-bright'    => "\033[96m",
    'white-bright'   => "\033[97m",
    # background colors
    'bg-black'          => "\033[40m",
    'bg-red'            => "\033[41m",
    'bg-green'          => "\033[42m",
    'bg-yellow'         => "\033[43m",
    'bg-blue'           => "\033[44m",
    'bg-magenta'        => "\033[45m",
    'bg-cyan'           => "\033[46m",
    'bg-white'          => "\033[47m",
    'bg-black-bright'   => "\033[100m",
    'bg-red-bright'     => "\033[101m",
    'bg-green-bright'   => "\033[102m",
    'bg-yellow-bright'  => "\033[103m",
    'bg-blue-bright'    => "\033[104m",
    'bg-magenta-bright' => "\033[105m",
    'bg-cyan-bright'    => "\033[106m",
    'bg-white-bright'   => "\033[107m",
    # color resets
    'end-color'    => "\033[39m",
    'end-bg-color' => "\033[49m",
    'end-colors'   => "\033[39;49m",
  ];
  # }}}
  static function ansi(# {{{
    string $k, int $i=0
  ):string
  {
    return "\033[".self::ANSI[$k][$i].'m';
  }
  # }}}
  static function ansi_wrap(# {{{
    string $s, string $k, int $i=0
  ):string
  {
    static $b = "\033[0m";
    $a = "\033[".self::ANSI[$k][$i].'m';
    return $a.$s.$b;
  }
  # }}}
  ###
  const ANSI = [# {{{
    # styles: standard,negation,extra
    'bold'      => [1,21],
    'faint'     => [2,22],
    'italic'    => [3,23],
    'underline' => [4,24],
    'blink'     => [5,25,6],
    'inverse'   => [7,27],
    'hide'      => [8,28],
    'strike'    => [9,29],# strikethrough
    'font'      => [10,11,12,13,14,15,16,17,18,19,20],
    'reset'     => [0,39,49],# all,foreground,background
    # foreground colors: normal,bright
    'black'   => [30,90],
    'red'     => [31,91],
    'green'   => [32,92],
    'yellow'  => [33,93],
    'blue'    => [34,94],
    'magenta' => [35,95],
    'cyan'    => [36,96],
    'white'   => [37,97],
  ];
  # }}}
  static function color_fg(# {{{
    string $s, string $k, int $i=0
  ):string
  {
    static $b = "\033[0m";
    $a = "\033[".self::ANSI[$k][$i].'m';
    return strpos($s, $b)
      ? $a.str_replace($b, $b.$a, $s).$b
      : $a.$s.$b;
  }
  # }}}
  static function color_bg(# {{{
    string $s, string $color, int $strong=0
  ):string
  {
    static $z = "\033[0m";
    if (!isset(self::ANSI[$color])) {
      return $s;
    }
    $i = $strong ? 1 : 0;
    $i = self::ANSI[$color][$i] + 10;# +10=background
    $c = "\033[".$i.'m';
    if (strpos($s, $z)) {
      $s = str_replace($z, $z.$c, $s);
    }
    return (strpos($s, "\n") === false)
      ? $c.$s.$z
      : $c.str_replace("\n", $z."\n".$c, $s).$z;
  }
  # }}}
  static function header(array $a, array $o): string # {{{
  {
    # header is the top-level element
    $b = isset($a['msg']);
    $i = $b ? 0 : 2;
    $c = $o['level'][$a['level']];
    $s = $o['prefix'][$c][$i];
    $x = '';
    if ($b)
    {
      # compose title
      $i = (isset($a['type']) && $a['type']) ? 1 : 0;
      $s = $s.implode(
        $o['header-type'][$c][$i], $a['msg']
      );
      # compose a block when multiline
      if ($i = strpos($s, "\n"))
      {
        $x = "\n".self::block(
          substr($s, $i + 1), $o['block'][$c]
        );
        $s = substr($s, 0, $i);
      }
    }
    if (isset($a['span'])) {
      $s .= self::span($a['span'], $o['span']);
    }
    if (isset($a['logs'])) {
      $x = "\n".self::group($a['logs'], $o, $c, 0);
    }
    return $s.$x;
  }
  # }}}
  static function span(int $n, string $fmt): string # {{{
  {
    if ($n > 1000000)# nano => milli
    {
      $n = (int)($n / 1000000);
      $s = 'ms';
    }
    elseif ($n > 1000)# nano => micro
    {
      $n = (int)($n / 1000);
      $s = 'us';
    }
    else {
      $s = 'ns';
    }
    return sprintf($fmt, strval($n), $s);
  }
  # }}}
  static function block(string $s, array $o): string # {{{
  {
    #╓
    #║ example line one
    #║ line two
    #╙
    [$b0,$b1,$b2] = $o;
    return
      $b0."\n".$b1.
      str_replace("\n", "\n".$b1, $s).
      "\n".$b2;
  }
  # }}}
  static function group(# {{{
    array $a, array $o, string $c, int $depth
  ):string
  {
    # prepare group links
    [$g0,$g1,$g2] = $o['group'][$c];
    if ($depth)
    {
      $s  = str_repeat(' ', 2*$depth);
      $g0 = $s.$g0;
      $g1 = $s.$g1;
      $g2 = $s.$g2;
    }
    # compose all items except the last one
    $s = '';
    $n = count($a) - 1;
    for ($i=0; $i < $n; ++$i)
    {
      $s .= self::item($a[$i], $o, $depth, $g0, $g1);
      $s .= "\n";
    }
    # compose the last item
    return $s.self::item($a[$n], $o, $depth, $g0, $g2);
  }
  # }}}
  static function item(# {{{
    array $a, array $o, int $depth,
    string $g0, string $g1
  ):string
  {
    # prepare
    $c = $o['level'][$a['level']];
    $n = isset($a['msg'])
      ? count($a['msg'])
      : 0;
    # compose
    switch ($n) {
    case 0:# untitled
      $s = $g1.$o['prefix'][$c][2];
      break;
    case 1:
      $s = $a['msg'][0];
      if (strpos($s, "\n") === false)
      {
        $s =
          $g1.
          $o['prefix'][$c][1].
          $s;
      }
      else
      {
        $s = self::textbox(
          trim($s), $o['textbox'][$c], $g0, $g1
        );
      }
      break;
    case 2:
      $s = $a['msg'][1];
      if (($i = strpos($s, "\n")) === false)
      {
        $s =
          $g1.
          $o['prefix'][$c][1].
          $a['msg'][0].
          $o['item-type'][$c][0].
          $s;
      }
      elseif ($i === 0)
      {
        $s = self::textbox(
          trim($s), $o['textbox'][$c], $g0, $g1
        );
      }
      else
      {
      }
      ###
      $s = $o['prefix'][$c][1];
      $s = $s.implode(
        $o['item-type'][$c][0], $a['msg']
      );
      /***
      $x = "\n".self::block(
        substr($s, $i + 1), $o['block'][$c]
      );
      $s = substr($s, 0, $i);
      /***/
      break;
    default:# ...
      # ...
      $s = $o['prefix'][$c][1];
      # join all as words except the last one
      $s = $s.implode(
        $o['words'][$c],
        array_slice($a['msg'], 0, -1)
      );
      # add the last one using the type separator
      $i = (isset($a['type']) && $a['type']) ? 1 : 0;
      $b = $o['item-type'][$c][$i];
      $s = $s.$b.$a['msg'][$n - 1];
      $s = $g1.$s;
      break;
    }
    # add duration
    if (isset($a['span'])) {
      $s .= self::span($a['span'], $o['span']);
    }
    # add group
    if (isset($a['logs']))
    {
      $s .= "\n".self::group(
        $a['logs'], $o, $c, 1 + $depth
      );
    }
    return $s;
  }
  # }}}
  static function textbox(# {{{
    string $s, array $o, string $g0, string $g1
  ):string
  {
    [$a0,$a1,$a2,$a3] = $o;
    return
      $g0.$a0."\n".$g1.$a1.
      str_replace("\n", "\n".$g0.$a2, $s).
      "\n".$g0.$a3;
  }
  # }}}
  static function subgroup(# {{{
    array $a, array $o, string $c, int $depth
  ):string
  {
    # prepare group links
    [$g0,$g1,$g2] = $o['group'][$c];
    if ($depth)
    {
      $s  = str_repeat(' ', 2*$depth);
      $g0 = $s.$g0;
      $g1 = $s.$g1;
      $g2 = $s.$g2;
    }
    # compose all items except the last one
    $s = '';
    $n = count($a) - 1;
    for ($i=0; $i < $n; ++$i)
    {
      $s .= self::item($a[$i], $o, $depth, $g0, $g1);
      $s .= "\n";
    }
    # compose the last item
    return $s.self::item($a[$n], $o, $depth, $g0, $g2);
  }
  # }}}
  # }}}
  static function from(object $x): string # {{{
  {
    return self::header(
      $x->log(), self::$BASE_OPTS
    );
  }
  # }}}
  function get(): string # {{{
  {
    $s = '';
    $o = $this->opts;
    foreach ($this->list as $a) {
      $s .= self::header($a, $o)."\n";
    }
    $this->list = [];# purge
    return $s;
  }
  # }}}
  function set(object $o): void # {{{
  {
    $this->list[] = $o->log();
  }
  # }}}
}
# }}}
class ErrorEx extends Error # {{{
  implements Loggable
{
  const ERROR_NUM = [# {{{
    E_ERROR             => 'ERROR',
    E_WARNING           => 'WARNING',
    E_PARSE             => 'PARSE',
    E_NOTICE            => 'NOTICE',
    E_CORE_ERROR        => 'CORE_ERROR',
    E_CORE_WARNING      => 'CORE_WARNING',
    E_COMPILE_ERROR     => 'COMPILE_ERROR',
    E_COMPILE_WARNING   => 'COMPILE_WARNING',
    E_USER_ERROR        => 'USER_ERROR',
    E_USER_WARNING      => 'USER_WARNING',
    E_USER_NOTICE       => 'USER_NOTICE',
    E_STRICT            => 'STRICT',
    E_RECOVERABLE_ERROR => 'RECOVERABLE_ERROR',
    E_DEPRECATED        => 'DEPRECATED',
    E_USER_DEPRECATED   => 'USER_DEPRECATED',
  ];
  # }}}
  const ERROR_LEVEL = [# {{{
    0 => 'Info',
    1 => 'Warning',
    2 => 'Error',
    3 => 'Fatal'
  ];
  # }}}
  # constructors {{{
  function __construct(
    public int     $level = 0,
    public array   &$msg  = [],
    public mixed   $value = null,
    public ?object $next  = null
  ) {
    parent::__construct('', -1);
    self::stringify($msg);
  }
  static function skip(): self {
    return new self(0);
  }
  static function info(...$msg): self {
    return new self(0, $msg);
  }
  static function warn(...$msg): self {
    return new self(1, $msg);
  }
  static function fail(...$msg): self {
    return new self(2, $msg);
  }
  static function fatal(...$msg): self {
    return new self(3, $msg);
  }
  private static function num(
    int $n, string $msg, string $file, int $line
  ):never
  {
    $s = isset(self::ERROR_NUM[$n])
      ? self::ERROR_NUM[$n]
      : '#'.$n.' UNKNOWN';
    $m = [$s, $msg];
    throw new self(3, $m);
  }
  # }}}
  # outer api {{{
  static function init(): bool # {{{
  {
    # initialize logger object
    ErrorLog::init();
    # set custom error handler that will throw
    # any mild/syntax errors (warnings/notices/deprecations)
    set_error_handler(self::num(...));
    # set shorthand alias
    return class_alias(
      '\\'.__NAMESPACE__.'\\ErrorEx',
      '\\'.__NAMESPACE__.'\\EE', false
    );
  }
  # }}}
  static function from(# {{{
    ?object $e, bool $null=false
  ):?self
  {
    if (!$e) {
      return $null ? null : new self(0);
    }
    if ($e instanceof self)
    {
      return $null
        ? ($e->errorlevel() ? $e : null)
        : $e;
    }
    if ($e instanceof Throwable)
    {
      $msg = [];
      return new self(3, $msg, $e);
    }
    if ($null) {
      return null;
    }
    $msg = [];
    return new self(0, $msg, $e);
  }
  # }}}
  static function value(object $e): self # {{{
  {
    $msg = [];
    return ($e instanceof Throwable)
      ? new self(3, $msg, $e)
      : new self(0, $msg, $e);
  }
  # }}}
  static function chain(?object ...$ee): self # {{{
  {
    for ($e=null, $i=0, $j=count($ee); $i < $j; ++$i)
    {
      if ($e = $ee[$i]) {
        break;
      }
    }
    for ($e=self::from($e), ++$i; $i < $j; ++$i)
    {
      $ee[$i] &&
      $e->last(self::from($ee[$i]));
    }
    return $e;
  }
  # }}}
  static function set(?object &$x, object ...$ee): self # {{{
  {
    switch (count($ee)) {
    case 0:
      return $x = self::from($x);
    case 1:
      $e = self::from($ee[0]);
      break;
    default:
      $e = self::chain(...array_reverse($ee));
      break;
    }
    return $x = $e->last($x);
  }
  # }}}
  static function peep(?object $e): ?object # {{{
  {
    if ($e && self::is($e)) {throw $e;}
    return $e;
  }
  # }}}
  static function is(&$o): bool # {{{
  {
    return $o instanceof self;
  }
  # }}}
  static function &stringify(array &$a): array # {{{
  {
    # convert items
    for ($i=0,$j=count($a); $i < $j; ++$i)
    {
      if (!is_string($a[$i])) {
        $a[$i] = strval($a[$i]);
      }
    }
    # remove empty tail
    while ($i && $a[--$i] === '') {
      array_pop($a);
    }
    return $a;
  }
  # }}}
  # }}}
  # inner api {{{
  function __debugInfo(): array # {{{
  {
    $a = [
      self::ERROR_LEVEL[$this->level] =>
      $this->message()
    ];
    if ($this->hasBacktrace()) {
      $a['trace'] = $this->value->getTrace();
    }
    if ($this->next) {
      $a['next'] = $this->next;
    }
    return $a;
  }
  # }}}
  function errorlevel(int $max=3): int # {{{
  {
    $level = $this->level;
    $next  = $this->next;
    while ($next && $level < $max)
    {
      if ($next->level > $level) {
        $level = $next->level;
      }
      $next = $next->next;
    }
    return $level;
  }
  # }}}
  function message(string $default=''): string # {{{
  {
    return $this->msg
      ? implode('·', $this->msg)
      : ($this->hasBacktrace()
        ? $this->value->getMessage()
        : $default);
  }
  # }}}
  function last(?object $e=null): self # {{{
  {
    # check
    if (($i = func_num_args()) && !$e) {
      return $this;
    }
    # seek the last error
    $last = $this;
    while ($next = $last->next) {
      $last = $next;
    }
    # complete as setter
    if ($i)
    {
      $last->next = self::from($e);
      return $this;
    }
    # complete as getter
    return $last;
  }
  # }}}
  function raise(): self # {{{
  {
    if ($this->level < 3) {
      $this->level++;
    }
    return $this;
  }
  # }}}
  function count(): int # {{{
  {
    for ($x=1, $e=$this->next; $e; $e=$e->next) {
      $x++;
    }
    return $x;
  }
  # }}}
  ###
  function &var(mixed &$v): mixed {return $v;}
  function  val(mixed  $v): mixed {return $v;}
  # }}}
  # is/has {{{
  function isInfo(): bool {
    return $this->level === 0;
  }
  function isWarning(): bool {
    return $this->level === 1;
  }
  function isError(): bool {
    return $this->level >= 2;
  }
  function isNotError(): bool {
    return $this->level < 2;
  }
  function isFatal(): bool {
    return $this->level >= 3;
  }
  function hasError(): bool {
    return $this->errorlevel(2) > 1;
  }
  function hasNoError(): bool {
    return $this->errorlevel(2) < 2;
  }
  function hasIssue(): bool {
    return $this->errorlevel(1) > 0;
  }
  function hasBacktrace(): bool
  {
    return (
      ($this->level === 3) &&
      ($this->value instanceof Throwable)
    );
  }
  # }}}
  # loggable {{{
  function log(): array # {{{
  {
    $a = $this->logSelf();
    if (!($e = $this->next)) {
      return $a;
    }
    $a = [$a];
    do {
      $a[] = $e->logSelf();
    }
    while ($e = $e->next);
    ###
    return [
      'level' => $this->errorlevel(2),
      'logs'  => $a,
    ];
  }
  # }}}
  function logSelf(): array # {{{
  {
    $msg = $this->msg;
    if (($lvl = $this->level) > 2)
    {
      # compose a message with debug backtrace
      $lvl = 2;
      if ($e = $this->value)
      {
        $msg[0] = $e::class;
        $msg[1] = $e->getMessage()."\n".
          $e->getFile().'('.$e->getLine().")\n".
          self::trace($e);
      }
      elseif ($i = count($msg)) {
        $msg[$i - 1] .= "\n".self::trace($this);
      }
      else {
        $msg[0] = self::trace($this);
      }
    }
    return [
      'level' => $lvl,
      'msg'   => $msg,
    ];
  }
  # }}}
  static function trace(object $e, int $i=0): string # {{{
  {
    $a = '';
    $b = $e->getTrace();
    for ($j=count($b)-1; $i < $j; ++$i) {
      $a .= self::trace_item($b[$i])."\n";
    }
    return $a.self::trace_item($b[$j]);
  }
  # }}}
  static function trace_item(array $a): string # {{{
  {
    if (isset($a['file']))
    {
      $s = $a['file'];
      if ($i = strrpos($s, \DIRECTORY_SEPARATOR)) {
        $s = substr($s, $i + 1);
      }
      #$s = $a['line'].':'.$s;
      #$s = $s.'('.$a['line'].')';
      $s = $s.':'.$a['line'];
    }
    else {
      $s = 'INTERNAL';
    }
    $f = isset($a['class'])
      ? $a['class'].$a['type'].$a['function']
      : $a['function'];
    ###
    return $s.' → '.$f;
  }
  # }}}
  # }}}
}
# }}}
return ErrorEx::init();
###
