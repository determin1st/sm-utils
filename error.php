<?php declare(strict_types=1);
# defs {{{
namespace SM;
use Error,Throwable;
use function
  class_alias,set_error_handler,func_num_args,
  is_object,is_string,array_is_list,
  implode,explode,count,array_reverse,array_pop,
  array_unshift,array_slice,date,intval,strval,
  ltrim,str_repeat,strpos,strrpos,substr;
use const
  DIRECTORY_SEPARATOR;
###
require_once
  __DIR__.DIRECTORY_SEPARATOR.'mustache.php';
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
    if ($this->isFatal())
    {
      $a['trace'] = $this->value
        ? $this->value->getTrace()
        : $this->getTrace();
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
    # compose one
    $a = $this->logSelf();
    if (!($e = $this->next)) {
      return $a;
    }
    # compose group
    $a = [$a];
    do {
      $a[] = $e->logSelf();
    }
    while ($e = $e->next);
    return [
      'level' => $this->errorlevel(2),
      'logs'  => $a,
    ];
  }
  # }}}
  function logSelf(): array # {{{
  {
    # compose standard
    if ($this->level < 3)
    {
      return [
        'level' => $this->level,
        'msg'   => $this->msg,
        'trace' => self::get_trace($this)
      ];
    }
    # compose exceptional
    if ($e = $this->value)
    {
      # exception is set as value,
      # extract the message and disposition
      $m = [$e->getMessage()];
      $t = self::get_trace($this);
      $a = $e->getFile();
      if ($b = strrpos($a, DIRECTORY_SEPARATOR))
      {
        $c = substr($a, 0, $b + 1);
        $a = substr($a, $b + 1);
      }
      else {
        $c = '';
      }
      array_unshift($t, [
        'case' => 0,# file only
        'file' => $a,
        'dir'  => $c,
        'line' => $e->getLine()
      ]);
    }
    else
    {
      $m = $this->msg;
      $t = self::get_trace($this);
    }
    return [
      'level' => 2,
      'msg'   => $m,
      'trace' => $t
    ];
  }
  # }}}
  static function get_trace(object $e): array # {{{
  {
    $a = [];
    foreach ($e->getTrace() as $b)
    {
      if (empty($b['file'])) {
        $b['case'] = 1;# function only
      }
      else
      {
        $c = $b['file'];
        if ($i = strrpos($c, DIRECTORY_SEPARATOR))
        {
          $b['dir']  = substr($c, 0, $i + 1);
          $b['file'] = substr($c, $i + 1);
        }
        else {
          $b['dir'] = '';
        }
        $b['case'] = empty($b['class'])
          ? 2 # file + function
          : 3;# file + class + function
      }
      $a[] = $b;
    }
    return $a;
  }
  # }}}
  # }}}
}
# }}}
class ErrorLog implements Mustachable # {{{
{
  const ANSI_TEMPLATE=[# {{{
    'root' => # {{{
    '

      {{^level}}
        {{#assign level-color-0}}{:green:}{{/}}
        {{#assign level-color-1}}{:green-bright:}{{/}}
      {{|}}
        {{#assign level-color-0}}{:red:}{{/}}
        {{#assign level-color-1}}{:red-bright:}{{/}}
      {{/}}
      {{#assign joiner-0}}{{level-color-1}}│{:end-color:}{{/}}
      {{#assign joiner-1}}{{level-color-1}}└{:end-color:}{{/}}
      {{#assign joiner-2}}{{level-color-1}}├{:end-color:}{{/}}
      {{#assign spacer-1}}
        {{level-color-0}}
        {{^type}} ► {{|}} ◄ {{/}}
        {:end-color:}
      {{/}}
      {{#assign spacer-2}}
        {{spacer-1}}
      {{/}}

      {{insert time}}

      {{level-color-1}}♦{:end-color:}
      {{@slice message}}
        {{^_first}}{{pad-0}}{{/}}
        {{.}}{:BR:}
      {{/}}
      {{#has-logs}}
        {{#slice logs}}
          {{pad-0}}{{.}}{:BR:}
        {{/}}
      {{/}}

    ',
    # }}}
    'time' => # {{{
    '

      {{#assign pad-0}}
        {{^level}}{:bg-green:}
        {{|     }}{:bg-red:}
        {{/}}
        {:REPEAT  ,10:}
        {:end-bg-color:}
      {{/}}

      {{^time}}
        {{pad-0}}
      {{|}}
        {{#date}}
          {:COLOR black,cyan:}
          {:erase-line:}{:BR:}
          {:SP:}
          {{Y}}/{{M}}/{{D}}
          {:SP:}{:erase-line:}
          {:BR:}
          {:end-colors:}
          {:BR:}
        {{/}}
        {{^level}}{:COLOR cyan-bright,green:}
        {{|     }}{:COLOR cyan-bright,red:}
        {{/}}
        {:SP:}
        {{h}}:{{m}}:{{s}}
        {:SP:}
        {:end-colors:}
      {{/}}

    ',
    # }}}
    'message' => # {{{
    '

      {{^msg-type}}
      {{|1}}
        {{insert msg-path}}
        {{insert span}}
      {{|2}}
        {{insert msg-block}}
        {{insert span}}
      {{|3}}
        {{insert msg-path}}
        {{insert span}}
        {:BR:}
        {{joiner-0}}
        {{insert msg-block}}
      {{/}}

    ',
    # }}}
    'msg-path' => # {{{
    '

      {{@msg-path}}
        {{^_first}}
          {{^_last}}
            {{spacer-1}}
          {{|}}
            {{spacer-2}}
          {{/}}
        {{/}}
        {{.}}
      {{/}}

    ',
    # }}}
    'msg-block' => # {{{
    '

      {:!
        0??╓
        1├└╢ ...
        2│ ║ ───
        2│ ║ ...
        2│ ╙
      :}
      {{#has-logs}}
        {{#assign pad-1}}{{joiner-2}}{{/}}
        {{#assign pad-2}}{{joiner-0}}{{/}}
      {{|}}
        {{#assign pad-1}}{{joiner-1}}{{/}}
        {{#assign pad-2}}{:SP:}{{/}}
      {{/}}

      {{level-color-0}}╓{:end-color:}
      {:BR:}
      {{pad-1}}{{level-color-0}}╢{:end-color:}
      {{#has-msg-block}}
        {{#msg-title}}
          {:SP:}{:underline:}{{.}}{:end-underline:}
          {:BR:}
          {{pad-2}}{{level-color-0}}║{:end-color:}
        {{/}}
        {{@msg-block}}
          {{^_first}}
            {{pad-2}}{{level-color-0}}║{:end-color:}
          {{/}}
          {:SP:}{{.}}
          {:BR:}
        {{/}}
        {{#has-trace}}
          {{pad-2}}{{level-color-0}}║ ───{:end-color:}
          {:BR:}
          {{#slice trace}}
            {{pad-2}}{{level-color-0}}║{:end-color:}
            {:SP:}{{.}}
            {:BR:}
          {{/}}
        {{/}}
      {{|}}
        {{@slice trace}}
          {{^_first}}
            {{pad-2}}{{level-color-0}}║{:end-color:}
          {{/}}
          {:SP:}{{.}}
          {:BR:}
        {{/}}
      {{/}}
      {{pad-2}}{{level-color-0}}╙{:end-color:}

    ',
    # }}}
    'trace' => # {{{
    '

      {{@trace}}
        {{^case}}
          {{#trace-dir}}
            {:black-bright:}{{dir}}{:end-color:}
          {{/}}
          {{file}}
          {:cyan:}:{{line}}{:end-color:}
        {{|1}}
          INTERNAL
          {:SP:}→{:SP:}
          {:cyan:}{{function}}{:end-color:}
        {{|2}}
          {{#trace-dir}}
            {:black-bright:}{{dir}}{:end-color:}
          {{/}}
          {{file}}
          {:cyan:}:{{line}}{:end-color:}
          {:SP:}→{:SP:}
          {:cyan:}{{function}}{:end-color:}
        {{|3}}
          {{#trace-dir}}
            {:black-bright:}{{dir}}{:end-color:}
          {{/}}
          {{file}}
          {:cyan:}:{{line}}{:end-color:}
          {:SP:}→{:SP:}
          {{class}}{{type}}
          {:cyan:}{{function}}{:end-color:}
        {{/}}
        {{^_last}}{:BR:}{{/}}
      {{/}}

    ',
    # }}}
    'span' => # {{{
    '

      {:! ･ ㎱ ㎲ ㎳ :}
      {:SP:}{:black-bright:}
      ~{{time}}
      {{^unit}}
      {{|ns}}㎱
      {{|us}}㎲
      {{|ms}}㎳
      {{/}}
      {:end-color:}

    ',
    # }}}
    'logs' => # {{{
    '

      {{@logs}}
        {{^_last}}
          {{@slice item}}
            {{#_first}}
              {{.....joiner-2}}
            {{|}}
              {{.....joiner-0}}
            {{/}}
            {{.}}
            {:BR:}
          {{/}}
        {{|}}
          {{@slice item}}
            {{#_first}}
              {{.....joiner-1}}
            {{|}}
              {:SP:}
            {{/}}
            {{.}}
            {{^_last}}{:BR:}{{/}}
          {{/}}
        {{/}}
      {{/}}

    ',
    # }}}
    'item' => # {{{
    '

      {{^level}}
        {{#assign level-color-0}}{:green:}{{/}}
        {{#assign level-color-1}}{:green-bright:}{{/}}
      {{|1}}
        {{#assign level-color-0}}{:yellow:}{{/}}
        {{#assign level-color-1}}{:yellow-bright:}{{/}}
      {{|2}}
        {{#assign level-color-0}}{:red:}{{/}}
        {{#assign level-color-1}}{:red-bright:}{{/}}
      {{/}}
      {{#assign joiner-0}}{{level-color-1}}│{:end-color:}{{/}}
      {{#assign joiner-1}}{{level-color-1}}└{:end-color:}{{/}}
      {{#assign joiner-2}}{{level-color-1}}├{:end-color:}{{/}}
      {{#assign spacer-1}}
        {{level-color-0}}·{:end-color:}
      {{/}}
      {{#assign spacer-2}}
        {{level-color-0}}
        {{^type}} ► {{|}} ◄ {{/}}
        {:end-color:}
      {{/}}

      {{^msg-type}}
        {{level-color-1}}●{:end-color:}
        {:BR:}
        {{insert logs}}
      {{|}}
        {{level-color-1}}▪{:end-color:}
        {{insert message}}
        {{#has-logs}}
          {:BR:}
          {{insert logs}}
        {{/}}
      {{/}}

    ',
    # }}}
  ];
  # }}}
  const TEXT_TEMPLATE=[# {{{
    'root' => # {{{
    '

      {{#assign level-tag}}
        {{^level}}PASS
        {{|     }}FAIL
        {{/}}
      {{/}}
      {{#assign joiner-0}}│{{/}}
      {{#assign joiner-1}}└{{/}}
      {{#assign joiner-2}}├{{/}}
      {{#assign spacer-1}}
        {{^type}} > {{|}} < {{/}}
      {{/}}
      {{#assign spacer-2}}
        {{spacer-1}}
      {{/}}


      ■{{insert time}}
      {{level-tag}}

      {{@slice message}}
        {{^_first}}{{pad-0}}{{/}}
        {{.}}{:BR:}
      {{/}}
      {{#has-logs}}
        {{#slice logs}}
          {{pad-0}}{{.}}{:BR:}
        {{/}}
      {{/}}

    ',
    # }}}
    'time' => # {{{
    '

      {{#assign pad-0}}
        {:!REPEAT ░,20:}
        {:!REPEAT  ,20:}
      {{/}}

      {{^time}}
        {{level-pad}}
      {{|}}
        {{Y}}/{{M}}/{{D}}
        {:SP:}
        {{h}}:{{m}}:{{s}}
        {:SP:}
      {{/}}

    ',
    # }}}
    'message' => # {{{
    '

      {{^msg-type}}
      {{|1}}
        : {{insert msg-path}}
        {{insert span}}
      {{|2}}
        {:BR:}
        {{joiner-0}}
        {{insert msg-block}}
        {{insert span}}
      {{|3}}
        : {{insert msg-path}}
        {{insert span}}
        {:BR:}
        {{joiner-0}}
        {{insert msg-block}}
      {{/}}

    ',
    # }}}
    'msg-path' => # {{{
    '

      {{@msg-path}}
        {{^_first}}
          {{^_last}}
            {{spacer-1}}
          {{|}}
            {{spacer-2}}
          {{/}}
        {{/}}
        {{.}}
      {{/}}

    ',
    # }}}
    'msg-block' => # {{{
    '

      {{#has-logs}}
        {{#assign pad-1}}{{joiner-2}}{{/}}
        {{#assign pad-2}}{{joiner-0}}{{/}}
      {{|}}
        {{#assign pad-1}}{{joiner-1}}{{/}}
        {{#assign pad-2}}{:SP:}{{/}}
      {{/}}

      ╓
      {:BR:}
      {{pad-1}}╢
      {{#has-msg-block}}
        {{#msg-title}}
          {:SP:}{{.}}
          {:BR:}
          {{pad-2}}║
        {{/}}
        {{@msg-block}}
          {{^_first}}
            {{pad-2}}║
          {{/}}
          {:SP:}{{.}}
          {:BR:}
        {{/}}
        {{#has-trace}}
          {{pad-2}}║ ───
          {:BR:}
          {{#slice trace}}
            {{pad-2}}║
            {:SP:}{{.}}
            {:BR:}
          {{/}}
        {{/}}
      {{|}}
        {{@slice trace}}
          {{^_first}}
            {{pad-2}}║
          {{/}}
          {:SP:}{{.}}
          {:BR:}
        {{/}}
      {{/}}
      {{pad-2}}╙

    ',
    # }}}
    'trace' => # {{{
    '

      {{@trace}}
        {{^case}}
          {{#text-trace-dir}}{{dir}}{{/}}
          {{file}}:{{line}}
        {{|1}}
          INTERNAL
          {:SP:}>{:SP:}
          {{function}}
        {{|2}}
          {{#text-trace-dir}}{{dir}}{{/}}
          {{file}}:{{line}}
          {:SP:}>{:SP:}
          {{function}}
        {{|3}}
          {{#text-trace-dir}}{{dir}}{{/}}
          {{file}}:{{line}}
          {:SP:}>{:SP:}
          {{class}}{{type}}{{function}}
        {{/}}
        {{^_last}}{:BR:}{{/}}
      {{/}}

    ',
    # }}}
    'span' => # {{{
    '

      {:SP:}~{{time}}·{{unit}}

    ',
    # }}}
    'logs' => # {{{
    '

      {{@logs}}
        {{^_last}}
          {{@slice item}}
            {{#_first}}
              {{.....joiner-2}}
            {{|}}
              {{.....joiner-0}}
            {{/}}
            {{.}}
            {:BR:}
          {{/}}
        {{|}}
          {{@slice item}}
            {{#_first}}
              {{.....joiner-1}}
            {{|}}
              {:SP:}
            {{/}}
            {{.}}
            {{^_last}}{:BR:}{{/}}
          {{/}}
        {{/}}
      {{/}}

    ',
    # }}}
    'item' => # {{{
    '

      {{#assign level-tag}}
        {{^level}}INFO
        {{|1    }}WARNING
        {{|2    }}ERROR
        {{/}}
      {{/}}
      {{#assign spacer-1}}
        ·
      {{/}}
      {{#assign spacer-2}}
        {{^type}} > {{|}} < {{/}}
      {{/}}

      {{^msg-type}}
        ■{{level-tag}}
        {:BR:}
        {{insert logs}}
      {{|}}
        >{{level-tag}}
        {{insert message}}
        {{#has-logs}}
          {:BR:}
          {{insert logs}}
        {{/}}
      {{/}}

    ',
    # }}}
  ];
  # }}}
  const ANSI_CHARS=[# {{{
    # erase
    'erase-screen' => "\033[J",
    'erase-line'   => "\033[K",
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
  const TEXT_CHARS=[# {{{
    'BR'   => "\n",
    'TAB'  => "\t",
    'NBSP' => "\xC2\xA0",
    'SP'   => ' ',
  ];
  # }}}
  const ITEM_HELPER=[# {{{
    # options
    'trace-dir'=>false,
    'text-trace-dir'=>true,
    # assignable
    'level-tag'=>'',
    'level-color-0'=>'','level-color-1'=>'',
    'joiner-0'=>'','joiner-1'=>'','joiner-2'=>'',
    'spacer-1'=>'','spacer-2'=>'',
    'pad-0'=>'','pad-1'=>'','pad-2'=>'',
    # helpers
    'has-logs'=>false,'has-trace'=>false,
    'has-msg-block'=>false,
    'msg-type'=>0,'msg-path'=>[],
    'msg-title'=>'','msg-block'=>[],
  ];
  # }}}
  # singleton constructor {{{
  static self   $I;
  public object $mustache;
  public array  $template=[],$date=['',''];
  public int    $index=0;
  private function __construct()
  {
    $this->mustache = $m = Mustache::new([
      'helpers' => [
        $this, self::TEXT_CHARS,
        self::ITEM_HELPER, self::ANSI_CHARS
      ]
    ]);
    $a = [];
    foreach (self::TEXT_TEMPLATE as $k => $t) {
      $a[$k] = $m->prep($t, '{: :}');
    }
    $this->template[] = $a;
    $a = [];
    foreach (self::ANSI_TEMPLATE as $k => $t) {
      $a[$k] = $m->prep($t, '{: :}');
    }
    $this->template[] = $a;
    $m->pull();# eject ANSI chars
  }
  static function init(array $o=[]): void
  {
    if (isset(self::$I))
    {
      $I = self::$I;
      if (isset($o[$k = 'ansi'])) {
        $I->index = $o[$k] ? 1 : 0;
      }
      if (isset($o[$k = 'trace-dir'])) {
        $I->mustache->value($k, !!$o[$k]);
      }
      if (isset($o[$k = 'text-trace-dir'])) {
        $I->mustache->value($k, !!$o[$k]);
      }
      $I->date = ['',''];
    }
    else {
      self::$I = new self();
    }
  }
  function __debugInfo(): array
  {
    return [
      'object is '.
      (isset(self::$I)?'':'not ').'initialized.'
    ];
  }
  # }}}
  # lambdas {{{
  const STASH = [
    'assign' => 7+1,
    'insert' => 7+1,
    'slice'  => 7+4,
    'COLOR'  => 7+1,
    'REPEAT' => 7+1
  ];
  function assign(object $m, string $a): string # {{{
  {
    # render primary section and
    # assign result to the value in the stack
    $m->value($a, $m->render());
    return '';
  }
  # }}}
  function insert(object $m, string $a): string # {{{
  {
    $i = $this->index;
    $t = $this->template[$i][$a];
    switch ($a) {
    case 'time':# {{{
      # create datetime helper
      # get timestamp
      if ($n = $m->value('.time'))
      {
        # parse and set variables
        $a = explode('/', date('Y/m/d/H/i/s', $n));
        $b = $a[0].$a[1].$a[2];
        $c = [
          'Y' => $a[0],
          'M' => $a[1],
          'D' => $a[2],
          'h' => $a[3],
          'm' => $a[4],
          's' => $a[5],
          'time' => true,
          'date' => false,
        ];
        # set/display date when changed
        if ($this->date[$i] !== $b)
        {
          $this->date[$i] = $b;
          $c['date'] = true;
        }
      }
      else {
        $c = ['time' => false];
      }
      return $m->get($t, $c);
      # }}}
    case 'span':# {{{
      # get duration
      if (!($n = $m->value('.span'))) {
        return '';
      }
      # prepare time value and unit
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
      # render
      return $m->get($t, [
        'time' => $n,
        'unit' => $s,
      ]);
      # }}}
    case 'logs':
      return $m->get($t, $m->value('..'));
    }
    return $m->get($t);
  }
  # }}}
  function slice(object $m, string $a): array # {{{
  {
    $t = $this->template[$this->index][$a];
    switch ($a) {
    case 'item':
      self::h_item(
        $m->value('..'),
        $m->value('.')
      );
      break;
    case 'logs':
      return explode(
        "\n", $m->get($t, $m->value('..'))
      );
    }
    return explode("\n", $m->get($t));
  }
  # }}}
  function COLOR(object $m, string $a): string # {{{
  {
    static $COLOR=[# {{{
      'black'   => 30,
      'red'     => 31,
      'green'   => 32,
      'yellow'  => 33,
      'blue'    => 34,
      'magenta' => 35,
      'cyan'    => 36,
      'white'   => 37,
      'black-bright'   => 90,
      'red-bright'     => 91,
      'green-bright'   => 92,
      'yellow-bright'  => 93,
      'blue-bright'    => 94,
      'magenta-bright' => 95,
      'cyan-bright'    => 96,
      'white-bright'   => 97,
    ];
    # }}}
    $b = explode(',', $a);
    $i = $COLOR[$b[0]];
    $j = $COLOR[$b[1]] + 10;
    return "\033[".$i.';'.$j.'m';
  }
  # }}}
  function REPEAT(object $m, string $a): string # {{{
  {
    if (strpos($a, ','))
    {
      $b = explode(',', $a);
      $i = intval($b[1]);
      $a = $b[0];
    }
    else {
      $i = 1;
    }
    return str_repeat($a, $i);
  }
  # }}}
  # }}}
  static function h_item(array &$h, array $item): array # {{{
  {
    # determine message count
    $n = isset($item['msg'])
      ? count($item['msg'])
      : 0;
    # set easy flags
    $h['has-logs']  = (
      isset($item['logs']) &&
      count($item['logs']) > 0
    );
    $h['has-trace'] = $hasTrace =
      isset($item['trace']);
    # check no message
    if (!$n)
    {
      # trace as message?
      $h['has-msg-block'] = false;
      $h['msg-type'] = $hasTrace ? 2 : 0;
      return $item;
    }
    # analyze the message
    $m = $item['msg'];
    $s = $m[$n - 1];
    if (($i = strpos($s, "\n")) === false)
    {
      # path only or with the trace
      $h['has-msg-block'] = false;
      $h['msg-type'] = $hasTrace ? 3 : 1;
      $h['msg-path'] = $m;
      return $item;
    }
    # multiline
    $h['has-msg-block'] = true;
    if ($i)
    {
      if ($n < 2)
      {
        # multiline only
        $h['msg-type']  = 2;
        $h['msg-path']  = [];
      }
      else
      {
        # path + multiline
        $m[$n - 1] = substr($s, 0, $i);
        $s = substr($s, $i + 1);
        $h['msg-type'] = 3;
        $h['msg-path'] = $m;
      }
      $h['msg-title'] = '';
      $h['msg-block'] = explode("\n", $s);
      return $item;
    }
    if ($n <= 2)
    {
      # multiline only
      $h['msg-type']  = 2;
      $h['msg-path']  = [];
      $h['msg-title'] = ($n < 2)
        ? '' : $m[$n - 2];
    }
    else
    {
      # path + multiline
      $h['msg-type']  = 3;
      $h['msg-path']  = array_slice($m, 0, $n - 2);
      $h['msg-title'] = $m[$n - 2];
    }
    $h['msg-block'] = explode("\n", ltrim($s));
    return $item;
  }
  # }}}
  static function render(# {{{
    object|array $a, bool $ansi=false
  ):string
  {
    if (is_object($a)) {
      $a = $a->log();
    }
    if (func_num_args() > 1)
    {
      $I = self::$I;
      $i = $I->index;
      $I->index = $ansi ? 1 : 0;
      $s = array_is_list($a)
        ? self::all($a)
        : self::one($a);
      ###
      $I->index = $i;
      return $s;
    }
    return array_is_list($a)
      ? self::all($a)
      : self::one($a);
  }
  # }}}
  static function one(array $a): string # {{{
  {
    $I = self::$I;
    $m = $I->mustache;
    return $m->get(
      $I->template[$I->index]['root'],
      self::h_item($m->value('.'), $a)
    );
  }
  # }}}
  static function all(array $a): string # {{{
  {
    $I = self::$I;
    $t = $I->template[$I->index]['root'];
    $m = $I->mustache;
    $h = &$m->value('.');
    for ($x='',$i=0,$j=count($a); $i < $j; ++$i) {
      $x .= $m->get($t, self::h_item($h, $a[$i]));
    }
    return $x;
  }
  # }}}
}
# }}}
return ErrorEx::init() && ErrorLog::init();
###
