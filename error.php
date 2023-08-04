<?php declare(strict_types=1);
# defs {{{
namespace SM;
use Error,Throwable;
use function
  class_alias,func_num_args,is_object,implode,count,
  array_unshift,array_reverse,is_string,strval;
###
# }}}
class ErrorEx extends Error
{
  const # {{{
    ERROR_NUM =
    [
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
    ],
    ERROR_LEVEL =
    [
      0 => 'Info',
      1 => 'Warning',
      2 => 'Error',
      3 => 'Fatal'
    ];
  ###
  # }}}
  # constructors {{{
  function __construct(
    public int     $level = 0,
    public array   $msg   = [],
    public mixed   $value = null,
    public ?object $next  = null
  ) {
    # make sure all messages are strings
    foreach ($msg as &$m)
    {
      if (!is_string($m)) {
        $m = strval($m);
      }
    }
    parent::__construct('', -1);
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
  static function failFn(...$msg): self
  {
    # prefix messages with the point of failure
    $e = new self(2, $msg);
    $a = (count($a = $e->getTrace()) > 1)
      ? $a[1]['function'].'@'.$a[0]['line']
      : $a[0]['function'];
    array_unshift($e->msg, $a);
    return $e;
  }
  static function num(int $n, string $msg): self
  {
    return new self(3, [
      (self::ERROR_NUM[$n] ?? "($n) UNKNOWN"), $msg
    ]);
  }
  # }}}
  # outer api {{{
  static function from(?object $e, bool $null=false): ?self # {{{
  {
    if ($e === null)
    {
      return $null
        ? null
        : new self(0);
    }
    if ($e instanceof self)
    {
      return $null
        ? ($e->errorlevel() ? $e : null)
        : $e;
    }
    return ($e instanceof Throwable)
      ? new self(3, [], $e)
      : ($null
        ? null
        : new self(0, [], $e));
  }
  # }}}
  static function value(object $e): self # {{{
  {
    return ($e instanceof Throwable)
      ? new self(3, [], $e)
      : new self(0, [], $e);
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
  static function peep(?object $e): object # {{{
  {
    if ($e === null)  {throw self::skip();}
    if (self::is($e)) {throw $e;}
    return $e;
  }
  # }}}
  static function is($e): bool # {{{
  {
    return $e
      && is_object($e)
      && ($e instanceof self);
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
  function count(): int # {{{
  {
    for ($x=1, $e=$this->next; $e; $e=$e->next) {
      $x++;
    }
    return $x;
  }
  # }}}
  ###
  function  val(mixed  $v): mixed {return $v;}
  function &var(mixed &$v): mixed {return $v;}
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
  function isFatal(): bool {
    return $this->level >= 3;
  }
  function hasError(): bool {
    return $this->errorlevel(2) > 1;
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
}
return class_alias(
  '\SM\ErrorEx',
  '\SM\EE', false
);
###
