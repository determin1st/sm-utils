<?php declare(strict_types=1);
# defs {{{
namespace SM;
use
  Error,Throwable;
use function
  class_alias,set_error_handler,func_num_args,
  implode,count,array_reverse,array_pop,array_is_list,
  array_slice,is_string,strval,strpos,strrpos,substr;
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
class ErrorLog implements Mustachable # {{{
{
  const TEXT_TEMPLATE=[# {{{
    'root' => # {{{
    '

      {{#assign level-color}}
        {{^level}}PASS
        {{|     }}FAIL
        {{/}}
      {{/}}
      {{#assign spacer-1}}
        {{^type}} ► {{|}} ◄ {{/}}
      {{/}}
      {{#assign spacer-2}}
        {{spacer-1}}
      {{/}}

      ♦{{level-color}}:{:SP:}
      {{^logs-cnt}}
        {{insert message-only}}
      {{|}}
        {{insert message}}{:BR:}
        {{insert logs}}
      {{/}}
      {:BR:}

    ',
    # }}}
    'message-only' => # {{{
    '

      {{^msg-type}}
      {{|1}}
        {{insert msg-path}}
        {{insert span}}
      {{|2|3}}
        {:!
          *...
          │╓
          └╢
           ║
           ╙
        :}
        {{insert msg-path}}
        {{insert span}}
        {:BR:}
        │╓{:BR:}
        └╢
        {{#msg-title}}
          {:SP:}{{.}}{:BR:}
          {:SP:}║
        {{/}}
        {{@msg-block}}
          {{^_first}}
            {:SP:}║
          {{/}}
          {:SP:}{{.}}{:BR:}
        {{/}}
        {:SP:}╙
      {{/}}

    ',
    # }}}
    'message' => # {{{
    '

      {{^msg-type}}
      {{|1}}
        {{insert msg-path}}
        {{insert span}}
      {{|2|3}}
        {:!
          *...
          │╓
          ├╢
          │║
          │╙
        :}
        {{insert msg-path}}
        {{insert span}}{:BR:}
        │╓{:BR:}
        ├╢
        {{#msg-title}}
          {:SP:}{{.}}{:BR:}
          │║
        {{/}}
        {{@msg-block}}
          {{^_first}}
            │║
          {{/}}
          {:SP:}{{.}}{:BR:}
        {{/}}
        │╙
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
    'span' => # {{{
    '

      {:SP:}{:SP:}
      ~{{time}}·{{unit}}

    ',
    # }}}
    'logs' => # {{{
    '

      {{#assign spacer-1}}
        ·
      {{/}}
      {{@logs}}
        {{^_last}}
          {{@slice item}}
            {{#_first}}├{{|}}│{{/}}
            {{.}}{:BR:}
          {{/}}
        {{|}}
          {{@slice item}}
            {{#_first}}
              └
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

      {{#assign level-color}}
        {{^level}}INFO
        {{|1    }}WARNING
        {{|2    }}ERROR
        {{/}}
      {{/}}
      {{#assign spacer-2}}
        {{^type}} ► {{|}} ◄ {{/}}
      {{/}}

      {{^msg-type}}
        ●{{level-color}}
        {{^logs-cnt}}{{|}}
          {{insert logs}}
        {{/}}
      {{|}}
        ▪{{level-color}}{:SP:}
        {{^logs-cnt}}
          {{insert message-only}}
        {{|}}
          {{insert message}}{:BR:}
          {{insert logs}}
        {{/}}
      {{/}}

    ',
    # }}}
  ];
  # }}}
  const TEXT_CHARS=[# {{{
    'BR'   => "\n",
    'NBSP' => "\xC2\xA0",
    'SP'   => ' ',
  ];
  # }}}
  const ANSI_TEMPLATE=[# {{{
    'root' => # {{{
    '

      {{#assign level-color}}
        {{^level}}{:green:}
        {{|     }}{:red:}
        {{/}}
      {{/}}
      {{#assign level-bright-color}}
        {{^level}}{:green-bright:}
        {{|     }}{:red-bright:}
        {{/}}
      {{/}}
      {{#assign spacer-1}}
        {{^type}} ► {{|}} ◄ {{/}}
      {{/}}
      {{#assign spacer-2}}
        {{spacer-1}}
      {{/}}

      {:! ♦◆ :}
      {{level-bright-color}}♦{:end-color:}
      {{^logs-cnt}}
        {{insert message-only}}
      {{|}}
        {{insert message}}{:BR:}
        {{insert logs}}
      {{/}}
      {:BR:}

    ',
    # }}}
    'message-only' => # {{{
    '

      {{^msg-type}}
      {{|1}}
        {{insert msg-path}}
        {{insert span}}
      {{|2}}
        {:!
          *╓
          └╢
           ║
           ╙
        :}
        {{level-color}}╓{:end-color:}
        {:BR:}
        {{level-bright-color}}└{:end-color:}
        {{level-color}}╢{:end-color:}
        {{#msg-title}}
          {:SP:}
          {:underline:}{{.}}{:end-underline:}
          {:BR:}{:SP:}
          {{level-color}}║{:end-color:}
        {{/}}
        {{@msg-block}}
          {{^_first}}
            {:SP:}
            {{level-color}}║{:end-color:}
          {{/}}
          {:SP:}{{.}}{:BR:}
        {{/}}
        {:SP:}
        {{level-color}}╙{:end-color:}
        {{insert span}}
      {{|3}}
        {:!
          *...
          │╓
          └╢
           ║
           ╙
        :}
        {{insert msg-path}}
        {{insert span}}
        {:BR:}
        {{level-bright-color}}│{:end-color:}
        {{level-color}}╓{:end-color:}
        {:BR:}
        {{level-bright-color}}└{:end-color:}
        {{level-color}}╢{:end-color:}
        {{#msg-title}}
          {:SP:}
          {:underline:}{{.}}{:end-underline:}
          {:BR:}{:SP:}
          {{level-color}}║{:end-color:}
        {{/}}
        {{@msg-block}}
          {{^_first}}
            {:SP:}
            {{level-color}}║{:end-color:}
          {{/}}
          {:SP:}{{.}}{:BR:}
        {{/}}
        {:SP:}
        {{level-color}}╙{:end-color:}
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
        {:!
          *╓
          ├╢
          │║
          │╙
          ╰
        :}
        {{level-color}}╓{:end-color:}
        {:BR:}
        {{level-bright-color}}├{:end-color:}
        {{level-color}}╢{:end-color:}
        {{#msg-title}}
          {:SP:}
          {:underline:}{{.}}{:end-underline:}
          {:BR:}
          {{level-bright-color}}│{:end-color:}
          {{level-color}}║{:end-color:}
        {{/}}
        {{@msg-block}}
          {{^_first}}
            {{level-bright-color}}│{:end-color:}
            {{level-color}}║{:end-color:}
          {{/}}
          {:SP:}{{.}}
          {:BR:}
        {{/}}
        {{level-bright-color}}│{:end-color:}
        {{level-color}}╙{:end-color:}
        {{insert span}}
      {{|3}}
        {:!
          *...
          │╓
          ├╢
          │║
          │╙
        :}
        {{insert msg-path}}
        {{insert span}}
        {:BR:}
        {{level-bright-color}}│{:end-color:}
        {{level-color}}╓{:end-color:}
        {:BR:}
        {{level-bright-color}}├{:end-color:}
        {{level-color}}╢{:end-color:}
        {{#msg-title}}
          {:SP:}
          {:underline:}{{.}}{:end-underline:}
          {:BR:}
          {{level-bright-color}}│{:end-color:}
          {{level-color}}║{:end-color:}
        {{/}}
        {{@msg-block}}
          {{^_first}}
            {{level-bright-color}}│{:end-color:}
            {{level-color}}║{:end-color:}
          {{/}}
          {:SP:}{{.}}{:BR:}
        {{/}}
        {{level-bright-color}}│{:end-color:}
        {{level-color}}╙{:end-color:}
      {{/}}

    ',
    # }}}
    'msg-path' => # {{{
    '

      {{@msg-path}}
        {{^_first}}
          {{level-color}}
          {{^_last}}
            {{spacer-1}}
          {{|}}
            {{spacer-2}}
          {{/}}
          {:end-color:}
        {{/}}
        {{.}}
      {{/}}

    ',
    # }}}
    'span' => # {{{
    '

      {:! ･ ㎱ ㎲ ㎳ :}
      {:SP:}{:black-bright:}
      ~{{time}}
      {{#unit}}
      {{|ns}}
        ㎱
      {{|us}}
        ㎲
      {{|ms}}
        ㎳
      {{/}}
      {:end-color:}

    ',
    # }}}
    'logs' => # {{{
    '

      {:! ･· :}
      {{#assign spacer-1}}
        ·
      {{/}}
      {{@logs}}
        {{^_last}}
          {{@slice item}}
            {{.....level-bright-color}}
            {{#_first}}├{{|}}│{{/}}
            {:end-color:}
            {{.}}
            {:BR:}
          {{/}}
        {{|}}
          {{@slice item}}
            {{#_first}}
              {{.....level-bright-color}}
              └
              {:end-color:}
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

      {{#assign level-color}}
        {{^level}}{:green:}
        {{|1    }}{:yellow:}
        {{|2    }}{:red:}
        {{/}}
      {{/}}
      {{#assign level-bright-color}}
        {{^level}}{:green-bright:}
        {{|1    }}{:yellow-bright:}
        {{|2    }}{:red-bright:}
        {{/}}
      {{/}}
      {{#assign spacer-2}}
        {{^type}} ► {{|}} ◄ {{/}}
      {{/}}

      {{^msg-type}}
        {{level-bright-color}}●{:end-color:}
        {{^logs-cnt}}{{|}}
          {{insert logs}}
        {{/}}
      {{|}}
        {:! ▪●■◯ :}
        {{level-bright-color}}▪{:end-color:}
        {{^logs-cnt}}
          {{insert message-only}}
        {{|}}
          {{insert message}}{:BR:}
          {{insert logs}}
        {{/}}
      {{/}}

    ',
    # }}}
  ];
  # }}}
  const ANSI_CHARS=[# {{{
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
  const ITEM_HELPER=[# {{{
    'level-color' => '',
    'level-bright-color' => '',
    'spacer-1'  => '',
    'spacer-2'  => '',
    'msg-type'  => 0,
    'msg-path'  => [],
    'msg-title' => '',
    'msg-block' => [],
    'logs-cnt'  => 0,
  ];
  # }}}
  # singleton constructor {{{
  static self   $I;
  public array  $template=[];
  public int    $index=0;
  public object $mustache;
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
  static function init(): void {
    self::$I = new self();
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
    'slice'  => 7+4
  ];
  function assign(object $m, string $a): string
  {
    # render primary section and
    # assign result to the value in the stack
    $m->value($a, $m->render());
    return '';
  }
  function insert(object $m, string $a): string
  {
    $t = $this->template[$this->index][$a];
    switch ($a) {
    case 'span':
      return ($n = $m->value('.span'))
        ? $m->get($t, self::h_span($n))
        : '';
    case 'logs':
      return $m->get($t, $m->value('..'));
    }
    return $m->get($t);
  }
  function slice(object $m, string $a): array
  {
    switch ($a) {
    case 'item':
      self::h_item(
        $m->value('..'),
        $m->value('.')
      );
      break;
    }
    return explode("\n",
      $m->get($this->template[$this->index][$a])
    );
  }
  # }}}
  # helpers {{{
  static function h_item(array &$h, array $item): array # {{{
  {
    # prepare
    $n = isset($item['msg'])
      ? count($item['msg']) : 0;
    ###
    while ($n)
    {
      # seek caret
      $s = $item['msg'][$n - 1];
      $i = strpos($s, "\n");
      # path only? (oneliner)
      if ($i === false)
      {
        $n = 1;
        $h['msg-path'] = $item['msg'];
        break;
      }
      # has multiple lines
      # inside?
      if ($i)
      {
        # no path?
        if ($n < 2)
        {
          $h['msg-path']  = [];
          $h['msg-title'] = '';
          $h['msg-block'] = explode("\n", $s);
          $n = 2;
          break;
        }
        # compose path
        $a = array_slice($item['msg'], 0, $n - 1);
        $a[] = substr($s, 0, $i);
        # set path + multiline
        $h['msg-path']  = $a;
        $h['msg-title'] = '';
        $h['msg-block'] = explode(
          "\n", substr($s, $i + 1)
        );
        $n = 3;
        break;
      }
      # set multiline block
      $h['msg-block'] = explode("\n", ltrim($s));
      # no title?
      if ($n < 2)
      {
        $h['msg-path']  = [];
        $h['msg-title'] = '';
        $n = 2;
        break;
      }
      # set title
      $h['msg-title'] = $item['msg'][$n - 2];
      # no path?
      if ($n < 3)
      {
        $h['msg-path'] = [];
        $n = 2;
        break;
      }
      # set path
      $h['msg-path'] = array_slice(
        $item['msg'], 0, $n - 2
      );
      $n = 3;
      break;
    }
    # complete
    $h['msg-type'] = $n;
    $h['logs-cnt'] = isset($item['logs'])
      ? count($item['logs'])
      : 0;
    return $item;
  }
  # }}}
  static function h_span(int $n): array # {{{
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
    return [
      'time' => $n,
      'unit' => $s,
    ];
  }
  # }}}
  # }}}
  static function render(# {{{
    array $a, bool $ansi=false
  ):string
  {
    return array_is_list($a)
      ? self::all($a, $ansi)
      : self::one($a, $ansi);
  }
  # }}}
  static function all(# {{{
    array $a, bool $ansi=false
  ):string
  {
    $I = self::$I;
    $I->index = $i = $ansi ? 1 : 0;
    $t = $I->template[$i]['root'];
    $m = $I->mustache;
    $h = &$m->value('.');
    for ($x='',$i=0,$j=count($a); $i < $j; ++$i) {
      $x .= $m->get($t, self::h_item($h, $a[$i]));
    }
    return $x;
  }
  # }}}
  static function one(# {{{
    array $a, bool $ansi=false
  ):string
  {
    $I = self::$I;
    $I->index = $i = $ansi ? 1 : 0;
    $m = $I->mustache;
    return $m->get(
      $I->template[$i]['root'],
      self::h_item($m->value('.'), $a)
    );
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
return ErrorLog::init() && ErrorEx::init();
###
