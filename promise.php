<?php declare(strict_types=1);
# defs {{{
namespace SM;
use
  ArrayAccess,Closure,Error,Throwable;
use function
  array_shift,array_unshift,array_splice;
use const
  DIRECTORY_SEPARATOR;
###
require_once __DIR__.DIRECTORY_SEPARATOR.'error.php';
# }}}
abstract class Completable # {{{
{
  const MAX_TIMEOUT = 60*60*1000;# 1h=60*60sec in milli
  public ?object $result = null;
  abstract function complete(): ?object;
  function cancel(): void
  {}
  static object $DONE,$NEXT,$IDLE;
  static int    $TIME = 0;# current timestamp (hrtime)
  static function from(?object $x): object
  {
    return $x
      ? (($x instanceof self)
        ? $x
        : (($x instanceof Closure)
          ? new PromiseHand($x)
          : (($x instanceof Error)
            ? new PromiseError($x)
            : new PromiseValue($x))))
      : new PromiseNone();
  }
}
# initialize static objects
if (!isset(Completable::$DONE))
{
  Completable::$DONE = new PromiseNone();
  Completable::$NEXT = new PromiseNone();
  Completable::$IDLE = new class() extends Completable
  {
    const DEF_DELAY = 50000000;# 50ms in nano
    function __construct(
      public int $delay = self::DEF_DELAY
    ) {}
    function set(int $ms): object
    {
      # check incorrect
      static $ERR='incorrect idle timeout';
      if ($ms < 0 || $ms > self::MAX_TIMEOUT) {
        return ErrorEx::fail($ERR, $ms);
      }
      # convert milli => nano
      $this->delay = (int)($ms * 1000000);
      return $this;
    }
    function import(int $ns): self
    {
      $this->delay = $ns - self::$TIME;
      return $this;
    }
    function get(): int
    {
      # determine time in the future and
      # reset delay to the default value
      $x = self::$TIME + $this->delay;
      $this->delay = self::DEF_DELAY;
      return $x;
    }
    function complete(): ?object {
      return null;
    }
  };
}
# }}}
class Promise extends Completable # {{{
{
  # base {{{
  public int   $idle    = 0;
  public array $pending = [];
  function __construct(object $action) {
    $this->pending[] = $action;
  }
  static function from(?object $x): self
  {
    return (!$x || !($x instanceof self))
      ? new self(Completable::from($x))
      : $x;
  }
  # }}}
  # constructors {{{
  static function Value(...$v): self
  {
    return new self((count($v) === 1)
      ? new PromiseValue($v[0])
      : new PromiseValue($v)
    );
  }
  static function Func(object $f, ...$a): self
  {
    return new self($a
      ? new PromiseFunc($f, $a)
      : new PromiseHand($f)
    );
  }
  static function Call(object $f, ...$a): self {
    return new self(new PromiseCall($f, $a));
  }
  static function Delay(int $ms, ?object $x=null): self
  {
    if ($x) {$x = Completable::from($x);}
    return new self(new PromiseTimeout($ms, $x));
  }
  static function Timeout(int $ms, object $x, ...$a): self
  {
    return new self(new PromiseTimeout($ms, $a
      ? new PromiseFunc($x, $a)
      : Completable::from($x)
    ));
  }
  static function Column(array $a, int $break=0): self {
    return new self(new PromiseColumn($a, $break));
  }
  static function Row(array $a, int $break=0): self {
    return new self(new PromiseRow($a, $break));
  }
  # }}}
  # composition {{{
  function then(object $x, ...$a): self # {{{
  {
    if ($a) {# assume callable
      $this->pending[] = new PromiseFunc($x, $a);
    }
    elseif ($x instanceof self)
    {
      # append all
      foreach ($x->pending as $action) {
        $this->pending[] = $action;
      }
    }
    else {# append one
      $this->pending[] = Completable::from($x);
    }
    return $this;
  }
  # }}}
  function thenCall(object $f, ...$a): self # {{{
  {
    $this->pending[] = new PromiseCall($f, $a);
    return $this;
  }
  # }}}
  function thenDelay(int $ms, ?object $x=null): self # {{{
  {
    if ($x) {$x = Completable::from($x);}
    $this->pending[] = new PromiseTimeout($ms, $x);
    return $this;
  }
  # }}}
  function thenTimeout(int $ms, object $x, ...$a): self # {{{
  {
    $this->pending[] = new PromiseTimeout($ms, $a
      ? new PromiseFunc($x, $a)
      : Completable::from($x)
    );
    return $this;
  }
  # }}}
  function thenColumn(array $a, int $break=0): self # {{{
  {
    $this->pending[] = new PromiseColumn($a, $break);
    return $this;
  }
  # }}}
  function thenRow(array $a, int $break=0): self # {{{
  {
    $this->pending[] = new PromiseRow($a, $break);
    return $this;
  }
  # }}}
  ###
  function okay(object $x, ...$a): self # {{{
  {
    $this->pending[] = new PromiseWhen(true, $a
      ? new PromiseFunc($x, $a)
      : Completable::from($x)
    );
    return $this;
  }
  # }}}
  function okayCall(object $f, ...$a): self # {{{
  {
    $this->pending[] = new PromiseWhen(
      true, new PromiseCall($f, $a)
    );
    return $this;
  }
  # }}}
  function okayDelay(int $ms, ?object $x=null): self # {{{
  {
    if ($x) {$x = Completable::from($x);}
    $this->pending[] = new PromiseWhen(
      true, new PromiseTimeout($ms, $x)
    );
    return $this;
  }
  # }}}
  function okayTimeout(int $ms, object $x, ...$a): self # {{{
  {
    $this->pending[] = new PromiseWhen(
      true, new PromiseTimeout($ms, $a
        ? new PromiseFunc($x, $a)
        : Completable::from($x)
      )
    );
    return $this;
  }
  # }}}
  ###
  function fail(object $x, ...$a): self # {{{
  {
    $this->pending[] = new PromiseWhen(false, $a
      ? new PromiseFunc($x, $a)
      : Completable::from($x)
    );
    return $this;
  }
  # }}}
  function failCall(object $f, ...$a): self # {{{
  {
    $this->pending[] = new PromiseWhen(
      false, new PromiseCall($f, $a)
    );
    return $this;
  }
  # }}}
  function failDelay(int $ms, ?object $x=null): self # {{{
  {
    if ($x) {$x = Completable::from($x);}
    $this->pending[] = new PromiseWhen(
      false, new PromiseTimeout($ms, $x)
    );
    return $this;
  }
  # }}}
  function failTimeout(int $ms, object $x, ...$a): self # {{{
  {
    $this->pending[] = new PromiseWhen(
      false, new PromiseTimeout($ms, $a
        ? new PromiseFunc($x, $a)
        : Completable::from($x)
      )
    );
    return $this;
  }
  # }}}
  function failFix(object $x): self # {{{
  {
    $this->pending[] = new PromiseFix($x);
    return $this;
  }
  # }}}
  function done(): self # {{{
  {
    $this->pending[] = self::$DONE;
    return $this;
  }
  # }}}
  # }}}
  # execution
  function complete(): ?object # {{{
  {
    # handle idle timeout (I/O resource polling)
    if ($this->idle)
    {
      if ($this->idle > self::$TIME) {
        return null;# skip for this time
      }
      $this->idle = 0;# activate
    }
    # check already complete (no actions pending)
    if (!($q = &$this->pending)) {
      return $this->result;
    }
    # check current action is not initialized
    if (!($a = $q[0])->result)
    {
      # initialize self
      if ($this->result === null) {
        $this->result = new PromiseResult();
      }
      # check for static completion
      if ($a === self::$DONE)
      {
        $q = [];
        return $this->result;
      }
      # initialize action
      $a->result = $this->result;
    }
    # execute
    if ($x = $a->complete())
    {
      # check special
      switch ($x) {
      case $a:
        # active repetition
        return null;
      case self::$IDLE:
        # passive repetition
        $this->idle = $x->get();
        return null;
      case self::$NEXT:
        # immediate recursion
        array_shift($q);
        return $this->complete();
      case self::$DONE:
        # immediate completion
        $q = [];
        return $this->result;
      }
      # dynamic expansion/substitution
      if ($x instanceof self) {
        array_splice($q, 0, 1, $x->pending);
      }
      else {
        $q[0] = Completable::from($x);
      }
      return null;
    }
    # complete one
    array_shift($q);
    return $q ? null : $this->result;
  }
  # }}}
  function cancel(): void # {{{
  {
    # check started but not finished
    if (($q = &$this->pending) &&
        ($r = $this->result))
    {
      # cancel current action and cleanup,
      # action must do its own checks
      $q[0]->cancel();
      $q = [];
      # resolve as cancelled
      $r->cancel();
    }
  }
  # }}}
}
# }}}
class Loop # {{{
{
  # base {{{
  const MAX_TIMEOUT = 60*60*1000000000;# 1h=60*60sec in nano
  public  static int   $pending = 0;
  public  static int   $idle    = 0;
  private static int   $timeout = 0;
  private static array $columns = [];
  private static array $row     = [];
  private function __construct()
  {}
  # }}}
  static function add(object $p, string $id=''): bool # {{{
  {
    $p = Promise::from($p);
    if ($id === '')
    {
      if (isset(self::$col[$id])) {
        self::$col[$id][] = $p;
      }
      else
      {
        self::$col[$id] = [$p];
        self::$pending++;
      }
    }
    else
    {
      self::$row[] = $p;
      self::$pending++;
    }
    return true;
  }
  # }}}
  static function spin(): bool # {{{
  {
    # check nothing to do
    $n0 = count(self::$columns);
    $n1 = count(self::$row);
    if (!$n0 && !$n1)
    {
      self::$pending = 0;
      self::$idle    = 0;
      self::$timeout = 0;
      return false;
    }
    # prepare
    $idle = 0;
    Completable::$TIME = $t = hrtime(true);
    $min = $t + self::MAX_TIMEOUT;
    # execute
    if ($n0)
    {
      foreach (self::$columns as $id => &$q)
      {
        if ($q[0]->complete())
        {
          array_shift($q);
          if (!$q)
          {
            unset(self::$columns[$id]);
            $n0--;
          }
        }
        elseif ($j = $q[0]->idle)
        {
          $idle++;
          if ($min > $j) {$min = $j;}
        }
      }
    }
    if ($n1)
    {
      $q = &self::$row;
      for ($i=0; $i < $n1; ++$i)
      {
        if ($q[$i]->complete())
        {
          array_splice($q, $i--, 1);
          $n1--;
        }
        elseif ($j = $q[$i]->idle)
        {
          $idle++;
          if ($min > $j) {$min = $j;}
        }
      }
    }
    # update counters
    self::$pending = $i = $n0 + $n1;
    self::$idle    = $idle;
    self::$timeout = 0;
    # check exhausted
    if (!$i) {
      return false;
    }
    # when all pending items are idle,
    # set timeout value (in microseconds)
    if ($i === $idle && $min > $t)
    {
      $t = (int)(($min - $t) / 1000);# nano=>micro
      self::$timeout = $t ?: 1;
    }
    # positive, more work to do
    return true;
  }
  # }}}
  static function cooldown(): void # {{{
  {
    $t = &self::$timeout;
    while ($t > 1000000)
    {
      sleep(1);
      $t -= 1000000;
    }
    usleep($t);
    $t = 0;
  }
  # }}}
  static function run(): void # {{{
  {
    # TODO: performance measuring mode
    while (self::spin()) {
      self::cooldown();
    }
  }
  # }}}
  static function stop(): bool # {{{
  {
    # TODO: cancellation
    return true;
  }
  # }}}
}
# }}}
# actions {{{
# TODO: implement breakable mass
class PromiseNone extends Completable # {{{
{
  function complete(): ?object {
    return null;
  }
}
# }}}
class PromiseError extends Completable # {{{
{
  function __construct(
    public object $error
  ) {}
  function complete(): ?object
  {
    $this->result->failure($this->error);
    return self::$NEXT;
  }
}
# }}}
class PromiseValue extends Completable # {{{
{
  function __construct(
    public mixed &$value
  ) {}
  function complete(): ?object
  {
    $this->result->value = $this->value;
    return self::$NEXT;
  }
}
# }}}
class PromiseHand extends Completable # {{{
{
  function __construct(
    public object $func
  ) {}
  function complete(): ?object
  {
    try {
      return ($this->func)($this);
    }
    catch (Throwable $e) {
      return ErrorEx::from($e, true);
    }
  }
  function repeat(int $ms=0): self
  {
    return $ms
      ? self::$IDLE->set($ms)
      : $this;
  }
}
# }}}
class PromiseFunc extends Completable # {{{
{
  function __construct(
    public object $func,
    public array  &$arg,
  ) {}
  function complete(): ?object
  {
    try {
      return ($this->func)($this, ...$this->arg);
    }
    catch (Throwable $e) {
      return ErrorEx::from($e, true);
    }
  }
  function repeat(int $ms=0): self
  {
    return $ms
      ? self::$IDLE->set($ms)
      : $this;
  }
}
# }}}
class PromiseCall extends PromiseFunc # {{{
{
  function complete(): ?object
  {
    try {
      return ($this->func)(...$this->arg);
    }
    catch (Throwable $e) {
      return ErrorEx::from($e, true);
    }
  }
}
# }}}
class PromiseWhen extends Completable # {{{
{
  function __construct(
    public bool   $ok,
    public object $action
  ) {}
  function complete(): ?object
  {
    return ($this->result->ok === $this->ok)
      ? $this->action
      : self::$NEXT;
  }
}
# }}}
class PromiseFix extends Completable # {{{
{
  function __construct(public object $action)
  {}
  function complete(): ?object
  {
    if ($this->result->ok) {
      return self::$NEXT;
    }
    $this->result->group();
    return $this->action;
  }
}
# }}}
class PromiseTimeout extends Completable # {{{
{
  function __construct(
    public int     $delay,
    public ?object $action
  ) {
    static $E0='incorrect delay, less than zero';
    static $E1='incorrect delay, greater than maximum';
    if ($delay < 0)
    {
      $this->action = ErrorEx::fail($E0, $delay);
      $this->delay  = 0;
    }
    if ($delay > self::MAX_TIMEOUT)
    {
      $this->action = ErrorEx::fail($E1, $delay);
      $this->delay  = 0;
    }
  }
  function complete(): ?object
  {
    # delay once
    if ($ms = $this->delay)
    {
      $this->delay = 0;
      return self::$IDLE->set($ms);
    }
    # continue
    return $this->action ?? self::$NEXT;
  }
}
# }}}
abstract class PromiseMass extends Completable # {{{
{
  function __construct(
    public array &$pending,
    public int   $break
  ) {
    # convert items into promises
    foreach ($pending as &$p) {
      $p = Promise::from($p);
    }
  }
}
# }}}
class PromiseColumn extends PromiseMass # {{{
{
  function complete(): ?object
  {
    # check already complete
    if (!($q = &$this->pending)) {
      return self::$NEXT;
    }
    # execute
    if (!($r = $q[0]->complete()))
    {
      return ($i = $q[0]->idle)
        ? self::$IDLE->import($i)
        : $this;
    }
    # complete one
    array_shift($q);
    $this->result->add($r);
    # check all complete
    if (!$q) {
      return null;
    }
    # check breakable and failed
    if ($this->break && !$r->ok)
    {
      $q = [];
      return null;
    }
    # repeat
    return $this;
  }
  function cancel(): void
  {
    # check started but not finished
    if (($q = &$this->pending) &&
        ($r = $this->result))
    {
      # check current promise is started
      if ($q[0]->result)
      {
        $q[0]->cancel();
        $r->add($q[0]->result);
      }
      # cleanup
      $q = [];
      # result is set by controlling promise,
      # so it will be cancelled by it..
    }
  }
}
# }}}
class PromiseRow extends PromiseMass # {{{
{
  function complete(): ?object
  {
    # prepare and check already complete
    $q = &$this->pending;
    if (!($n = count($q))) {
      return self::$NEXT;
    }
    # prepare idle counter and idle minimum
    $i = 0;
    $j = self::$TIME + Loop::MAX_TIMEOUT;
    # execute all
    foreach ($q as &$p)
    {
      # check one already complete
      if (!$p)
      {
        $n--;
        continue;
      }
      # execute
      if (!($r = $p->complete()))
      {
        if ($p->idle)
        {
          $i++;
          if ($j > $p->idle) {
            $j = $p->idle;
          }
        }
        continue;
      }
      # complete one
      $p = null; $n--;
      $this->result->add($r);
      # check breakable and failed
      if ($this->break && !$r->ok)
      {
        $this->cancel();
        return null;
      }
    }
    # check all complete
    if (!$n)
    {
      $q = [];
      return null;
    }
    # check all idle and repeat
    return ($i === $n)
      ? self::$IDLE->import($j)
      : $this;
  }
  function cancel(): void
  {
    # check started but not finished
    if (($q = &$this->pending) &&
        ($r = $this->result))
    {
      foreach ($q as &$p)
      {
        if ($p && $p->result)
        {
          $p->cancel();
          $r->add($p->result);
        }
      }
      $q = [];
    }
  }
}
# }}}
# }}}
# result {{{
class PromiseResult implements ArrayAccess
{
  public $track,$ok,$value;
  function __construct(?object $t = null) # {{{
  {
    if ($t === null) {
      $t = new PromiseResultTrack();
    }
    $this->track = [$t];
    $this->ok    = &$t->ok;
    $this->value = &$t->value;
  }
  # }}}
  function __debugInfo(): array # {{{
  {
    return $this->track;
  }
  # }}}
  # [] access {{{
  function offsetExists(mixed $k): bool {
    return isset($this->track[$k]);
  }
  function offsetGet(mixed $k): mixed {
    return $this->track[$k] ?? null;
  }
  function offsetSet(mixed $k, mixed $v): void
  {}
  function offsetUnset(mixed $k): void
  {}
  # }}}
  function current(): object # {{{
  {
    # when current track is confirmed (has a title),
    # advance, otherwise stay current
    return ($t = $this->track[0])->title
      ? $this->next()
      : $t;
  }
  # }}}
  function next(): object # {{{
  {
    array_unshift(
      $this->track,
      $t = new PromiseResultTrack()
    );
    $this->ok    = &$t->ok;
    $this->value = &$t->value;
    return $t;
  }
  # }}}
  function success(mixed $value = null): self # {{{
  {
    $this->current();
    $this->value = $value;
    $this->ok = true;
    return $this;
  }
  # }}}
  function failure(?object $e = null): self # {{{
  {
    $this->current()->errorAdd($e);
    $this->ok = false;
    return $this;
  }
  # }}}
  function message(string ...$s): self # {{{
  {
    $this->track[0]->errorAdd($this->ok
      ? new ErrorEx(0, $s)
      : new ErrorEx(2, $s)
    );
    return $this;
  }
  # }}}
  ### NEW
  function add(self $result): self # {{{
  {
    $this->current()->groupAdd($result);
    return $this;
  }
  # }}}
  function confirm(string ...$title): self # {{{
  {
    $this->current()->titleSet($title);
    return $this;
  }
  # }}}
  function group(string ...$title): self # {{{
  {
    # first, set current title
    $this->current()->titleSet($title);
    # extract current track and create result out of it
    $r = new self(array_shift($this->track));
    # create new track with extracted result
    $this->next()->groupAdd($r);
    return $this;
  }
  # }}}
  function cancel(): self # {{{
  {
    return $this;
  }
  # }}}
  ### ???
  function info(...$msg): self # {{{
  {
    $this->track[0]->errorAdd(
      new ErrorEx(0, $msg)
    );
    return $this;
  }
  # }}}
  function warn(...$msg): self # {{{
  {
    $this->track[0]->errorAdd(
      new ErrorEx(1, $msg)
    );
    return $this;
  }
  # }}}
  function fail(...$msg): self # {{{
  {
    $this->track[0]->errorAdd(
      new ErrorEx(2, $msg)
    );
    return $this;
  }
  # }}}
}
class PromiseResultTrack
{
  public $ok = true,$value,$title,$error,$group;
  function __debugInfo(): array # {{{
  {
    return [
      'ok'    => $this->ok,
      'title' => implode('·', $this->title ?? []),
      'error' => $this->error,
      'group' => $this->group
    ];
  }
  # }}}
  function titleSet(array &$s): self # {{{
  {
    if (count($s) === 0) {
      $s[] = '?';# must contain something
    }
    $this->title = &$s;
    return $this;
  }
  # }}}
  function errorAdd(?object $e): void # {{{
  {
    if ($e) {
      $this->error = $e->last($this->error);
    }
  }
  # }}}
  function groupAdd(object $r): void # {{{
  {
    if ($this->group) {
      array_unshift($this->group, $r);
    }
    else {
      $this->group = [$r];
    }
    if ($this->ok && !$r->ok) {
      $this->ok = false;
    }
  }
  # }}}
}
# }}}
###
