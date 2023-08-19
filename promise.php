<?php declare(strict_types=1);
# defs {{{
namespace SM;
use ArrayAccess,Closure,Error,Throwable;
use function
  hrtime,sleep,usleep,count,in_array,
  array_shift,array_unshift,array_splice;
use const
  DIRECTORY_SEPARATOR;
###
require_once __DIR__.DIRECTORY_SEPARATOR.'error.php';
# }}}
abstract class Completable # {{{
{
  public ?object $result=null;
  abstract function complete(): ?object;
  function cancel(): ?object {
    return null;
  }
  static object $THEN;# continuator
  static int    $TIME=0;# current nanotime
  static function from(?object $x): object
  {
    return $x
      ? (($x instanceof self)
        ? $x
        : (($x instanceof Closure)
          ? new PromiseOp($x)
          : (($x instanceof Error)
            ? new PromiseError($x)
            : new PromiseValue($x))))
      : new PromiseNone();
  }
}
# }}}
abstract class Reversible extends Completable # {{{
{
  public array $reverse=[];
  abstract function stop(): void;
  function undo(): void # {{{
  {
    if ($q = &$this->reverse)
    {
      # undo previously finished reversibles
      foreach ($q as $p) {$p->undo();}
      $q = [];
    }
  }
  # }}}
  function cancel(): ?object # {{{
  {
    if ($r = $this->result)
    {
      $this->stop();
      $this->undo();
    }
    return $r;
  }
  # }}}
}
# }}}
class Promise extends Reversible # {{{
{
  # base {{{
  public array $pending=[];# actions to complete
  public int   $idle=0;# future time in nanoseconds
  function __construct(object $action) {
    $this->pending[] = $action;
  }
  # }}}
  # construction {{{
  static function from(?object $x): self # {{{
  {
    return (!$x || !($x instanceof self))
      ? new self(Completable::from($x))
      : $x;
  }
  # }}}
  static function Value(...$v): self # {{{
  {
    return new self((count($v) === 1)
      ? new PromiseValue($v[0])
      : new PromiseValue($v)
    );
  }
  # }}}
  static function Func(object $f, ...$a): self # {{{
  {
    return new self($a
      ? new PromiseFunc($f, $a)
      : new PromiseOp($f)
    );
  }
  # }}}
  static function Call(object $f, ...$a): self # {{{
  {
    return new self(new PromiseCall($f, $a));
  }
  # }}}
  static function When(bool $ok, object $x, ...$a): self # {{{
  {
    return new self(new PromiseWhen($ok, $a
      ? new PromiseFunc($x, $a)
      : Completable::from($x)
    ));
  }
  # }}}
  static function Delay(# {{{
    int $ms, ?object $x=null
  ):self
  {
    if ($x) {$x = Completable::from($x);}
    return new self(new PromiseTimeout($ms, $x));
  }
  # }}}
  static function Timeout(# {{{
    int $ms, object $x, ...$a
  ):self
  {
    return new self(new PromiseTimeout($ms, $a
      ? new PromiseFunc($x, $a)
      : Completable::from($x)
    ));
  }
  # }}}
  static function Column(# {{{
    array $group, int $break=1
  ):self
  {
    return new self(new PromiseColumn(
      $group, $break
    ));
  }
  # }}}
  static function Row(# {{{
    array $group, int $break=0, int $first=0
  ):self
  {
    return new self(new PromiseRow(
      $group, $break, $first
    ));
  }
  # }}}
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
  function thenColumn(# {{{
    array $group, int $break=1
  ):self
  {
    $this->pending[] = new PromiseColumn(
      $group, $break
    );
    return $this;
  }
  # }}}
  function thenRow(# {{{
    array $group, int $break=0, int $first=0
  ):self
  {
    $this->pending[] = new PromiseRow(
      $group, $break, $first
    );
    return $this;
  }
  # }}}
  # positive
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
    $this->pending[] = new PromiseWhen(true,
      new PromiseCall($f, $a)
    );
    return $this;
  }
  # }}}
  function okayDelay(int $ms, ?object $x=null): self # {{{
  {
    if ($x) {$x = Completable::from($x);}
    $this->pending[] = new PromiseWhen(true,
      new PromiseTimeout($ms, $x)
    );
    return $this;
  }
  # }}}
  function okayTimeout(int $ms, object $x, ...$a): self # {{{
  {
    $this->pending[] = new PromiseWhen(true,
      new PromiseTimeout($ms, $a
        ? new PromiseFunc($x, $a)
        : Completable::from($x)
      )
    );
    return $this;
  }
  # }}}
  function okayColumn(# {{{
    array $group, int $break=1
  ):self
  {
    $this->pending[] = new PromiseWhen(true,
      new PromiseColumn($group, $break)
    );
    return $this;
  }
  # }}}
  function okayRow(# {{{
    array $group, int $break=0, int $first=0
  ):self
  {
    $this->pending[] = new PromiseWhen(true,
      new PromiseRow($group, $break, $first)
    );
    return $this;
  }
  # }}}
  function okayFuse(object $x): self # {{{
  {
    $this->pending[] = new PromiseWhen(true,
      new PromiseFuse($x)
    );
    return $this;
  }
  # }}}
  # negative
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
    $this->pending[] = new PromiseWhen(false,
      new PromiseCall($f, $a)
    );
    return $this;
  }
  # }}}
  function failDelay(int $ms, ?object $x=null): self # {{{
  {
    if ($x) {$x = Completable::from($x);}
    $this->pending[] = new PromiseWhen(false,
      new PromiseTimeout($ms, $x)
    );
    return $this;
  }
  # }}}
  function failTimeout(int $ms, object $x, ...$a): self # {{{
  {
    $this->pending[] = new PromiseWhen(false,
      new PromiseTimeout($ms, $a
        ? new PromiseFunc($x, $a)
        : Completable::from($x)
      )
    );
    return $this;
  }
  # }}}
  function failColumn(# {{{
    array $group, int $break=0
  ):self
  {
    $this->pending[] = new PromiseWhen(false,
      new PromiseColumn($group, $break)
    );
    return $this;
  }
  # }}}
  function failRow(# {{{
    array $group, int $break=0, int $first=0
  ):self
  {
    $this->pending[] = new PromiseWhen(false,
      new PromiseRow($group, $break, $first)
    );
    return $this;
  }
  # }}}
  function failFuse(object $x): self # {{{
  {
    $this->pending[] = new PromiseWhen(false,
      new PromiseFuse($x)
    );
    return $this;
  }
  # }}}
  # }}}
  function complete(): ?object # {{{
  {
    # check idle timeout (I/O resource polling)
    if ($this->idle)
    {
      if ($this->idle > self::$TIME) {
        return null;# skip for this time
      }
      $this->idle = 0;# activate
    }
    # check complete (not pending)
    if (!($q = &$this->pending)) {
      return $this->result;
    }
    # initialize once
    if (!($a = $q[0])->result)
    {
      if ($this->result === null) {
        $this->result = new PromiseResult();
      }
      $a->result = $this->result;
    }
    # execute
    if ($x = $a->complete())
    {
      # handle repetition
      if ($x === $a) {
        return null;
      }
      # handle dynamic continuator
      if ($x === self::$THEN)
      {
        switch ($x->getId()) {
        case 1:# lazy repetition
          $this->idle = $x->time;
          return null;
        case 2:# immediate recursion
          array_shift($q);
          return $this->complete();
        case 3:# immediate expansive recursion
          $x = $x->getAction();
          if ($x instanceof self) {
            array_splice($q, 0, 1, $x->pending);
          }
          else {
            $q[0] = Completable::from($x);
          }
          return $this->complete();
        case 4:# fusion
          $q = [];
          $x = $x->getAction();
          break;
        case 5:# immediate cancellation
          return $this->cancel();
        case 6:# immediate completion
          $q = [];
          return $this->result;
        default:# skip unknown
          array_shift($q);
          return null;
        }
      }
      # handle expansive continuation
      if ($x instanceof self) {
        array_splice($q, 0, 1, $x->pending);
      }
      else {
        $q[0] = Completable::from($x);
      }
      # collect reversible
      if (($a instanceof Reversible) &&
          !in_array($a, $this->reverse, true))
      {
        array_unshift($this->reverse, $a);
      }
      # incomplete
      return null;
    }
    # eject one completed
    array_shift($q);
    # collect reversible
    if (($a instanceof Reversible) &&
        !in_array($a, $this->reverse, true))
    {
      array_unshift($this->reverse, $a);
    }
    # to save event loop cycles,
    # its better to do one more check here,
    # finish incomplete or complete
    return $q ? null : $this->result;
  }
  # }}}
  function cancel(): ?object # {{{
  {
    if ($r = $this->result)
    {
      $r->reverse();
      $this->stop();
      $this->undo();
    }
    return $r;
  }
  # }}}
  function stop(): void # {{{
  {
    if ($q = &$this->pending)
    {
      # cancel current action and cleanup
      $q[0]->cancel();
      $q = [];
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
      if (isset(self::$columns[$id])) {
        self::$columns[$id][] = $p;
      }
      else
      {
        self::$columns[$id] = [$p];
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
abstract class PromiseAction extends Completable # {{{
{
  function repeat(int $ms=0): object
  {
    return $ms
      ? self::$THEN->delay($ms)
      : $this;
  }
}
# }}}
class PromiseOp extends PromiseAction # {{{
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
}
# }}}
class PromiseFunc extends PromiseAction # {{{
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
# }}}
# action helpers {{{
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
    $this->result->error($this->error);
    return self::$THEN->hop();
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
    return self::$THEN->hop();
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
    # select action when condition met
    $a = ($this->result->ok === $this->ok)
      ? $this->action
      : null;
    # hop to the next or selected action
    return self::$THEN->hop($a);
  }
}
# }}}
class PromiseFuse extends Completable # {{{
{
  function __construct(
    public object $action
  ) {}
  function complete(): ?object
  {
    $this->result->fuse();
    return self::$THEN->fuse($this->action);
  }
}
# }}}
class PromiseTimeout extends Completable # {{{
{
  const MAX_DELAY = 60*60*1000;# 1h=60*60sec in milli
  static function check(int $ms): ?object # {{{
  {
    static $E0='incorrect delay, less than zero';
    static $E1='incorrect delay, greater than maximum';
    if ($delay < 0) {
      return ErrorEx::fail($E0, $delay);
    }
    if ($delay > self::MAX_DELAY) {
      return ErrorEx::fail($E1, $delay);
    }
    return null;# good
  }
  # }}}
  function __construct(# {{{
    public int     $delay,
    public ?object $action
  ) {
    ErrorEx::peep(self::check($delay));
  }
  # }}}
  function complete(): ?object # {{{
  {
    # delay once
    if ($ms = $this->delay)
    {
      $this->delay = 0;
      return self::$THEN->delay($ms);
    }
    # continue
    return self::$THEN->hop($this->action);
  }
  # }}}
}
# }}}
# }}}
# action groups {{{
abstract class PromiseGroup extends Reversible # {{{
{
  # groups operate over promises,
  # which may or may not contain reversible actions,
  # reversible actions are not detectable in stasis
  public int $index=0,$cnt=0;
  function init(): void
  {
    # convert items into promises
    foreach ($this->pending as &$p)
    {
      $p = Promise::from($p);
      $this->cnt++;
    }
  }
  function fixNum(int &$n): void
  {
    if ($n > $this->cnt) {
      $n = 0;# all
    }
    elseif ($n < 0) {
      $n = 1;# one
    }
  }
}
# }}}
class PromiseColumn extends PromiseGroup # {{{
{
  function __construct(# {{{
    public array &$pending,
    public int   $break
  ) {
    $this->init();
    $this->fixNum($this->break);
  }
  # }}}
  function complete(): ?object # {{{
  {
    # prepare
    $q = &$this->pending;
    $i = &$this->index;
    # check already complete
    if ($i >= ($j = $this->cnt)) {
      return null;
    }
    # initialize
    if (!($p = $q[$i])->result)
    {
      $p->result = ($i === 0)
        ? new PromiseResult($this->result)
        : $q[$i - 1]->result;
    }
    # execute
    if (!($r = $p->complete()))
    {
      return $p->idle
        ? self::$THEN->idle($p->idle)
        : $this;
    }
    # one complete,
    # collect reversible
    if ($p->reverse) {
      array_unshift($this->reverse, $p);
    }
    # check all complete
    if (++$i === $j)
    {
      $this->result->group($r, $j, $i);
      return null;
    }
    # check breakable failed
    if ($this->break && !$r->ok)
    {
      return --$this->break
        ? $this : $this->stop();
    }
    # continue
    return $this;
  }
  # }}}
  function stop(): void # {{{
  {
    # check started and unfinished
    if (($q = &$this->pending) &&
        ($r = $q[0]->result) &&
        ($j = $this->cnt) &&
        ($i = $this->index) < $j)
    {
      # cancel current unfinished action
      $q[$i]->result &&
      $q[$i]->cancel();
      # settle partial result
      $this->result->group($r, $j, $i);
    }
    # cleanup
    $q = []; $this->cnt = 0;
  }
  # }}}
}
# }}}
class PromiseRow extends PromiseGroup # {{{
{
  public ?object $subresult=null;
  function __construct(# {{{
    public array &$pending,
    public int   $break,
    public int   $first
  ) {
    $this->init();
    $this->fixNum($this->break);
    $this->fixNum($this->first);
  }
  # }}}
  function complete(): ?object # {{{
  {
    # prepare
    $q = &$this->pending;
    $k = &$this->index;# number of complete
    # check already complete
    if ($k >= ($j = $this->cnt)) {
      return null;
    }
    # initialize once
    if (!($r = $this->subresult))
    {
      # create a group
      $this->subresult = $r =
        new PromiseResult($this->result);
      # create group items
      foreach ($q as $p) {
        $p->result = new PromiseResult($r);
      }
    }
    # to activate idle state of the row,
    # both number of idle items and
    # closest idle timeout must be determined
    $idleCnt  = 0;
    $idleTime = self::$TIME + Loop::MAX_TIMEOUT;
    # execute all
    for ($i=0; $i < $j; ++$i)
    {
      # check already complete
      if (!($p = $q[$i])) {
        continue;
      }
      # execute
      if (!($x = $p->complete()))
      {
        if ($p->idle)
        {
          $idleCnt++;
          if ($idleTime > $p->idle) {
            $idleTime = $p->idle;
          }
        }
        continue;
      }
      # complete one
      $q[$i] = null; $k++;
      $r->cell($x, $i);
      # collect reversible
      if ($p->reverse) {
        array_unshift($this->reverse, $p);
      }
      # check break condition
      if ($this->break && !$x->ok)
      {
        if (--$this->break) {
          continue;# more allowed
        }
        return $this->stop();
      }
      # check race condition
      if ($this->first)
      {
        if (--$this->first) {
          continue;# more allowed
        }
        return $this->stop();
      }
    }
    # check all complete
    if (!($i = $j - $k))
    {
      $this->result->group($r, $j, $k);
      return null;
    }
    # check all idle
    if ($idleCnt === $i) {
      return self::$THEN->idle($idleTime);
    }
    # continue
    return $this;
  }
  # }}}
  function stop(): void # {{{
  {
    # check started and unfinished
    if (($q = &$this->pending)  &&
        ($r = $this->subresult) &&
        ($j = $this->cnt) &&
        $this->index < $j)
    {
      # cancel current
      foreach ($q as $i => $p)
      {
        if ($p)
        {
          $p->cancel();
          $r->cell($p->result, $i);
        }
      }
      # settle partial result
      $this->result->group(
        $r, $j, $this->index
      );
    }
    # cleanup
    $q = []; $this->cnt = 0;
  }
  # }}}
}
# }}}
# }}}
# result {{{
class PromiseResult implements ArrayAccess
{
  # base {{{
  const
    IS_INFO     = 0,
    IS_WARNING  = 1,
    IS_FAILURE  = 2,
    IS_ERROR    = 3,# ErrorEx object
    IS_GROUP    = 4,# result of the column/row
    IS_CELL     = 5,# result + index
    IS_FUSION   = 6,# all => one track
    IS_REVERSAL = 7;# all => cancellation track
  ###
  public object $track;
  public bool   $ok;
  public mixed  $value = null;
  ###
  function __construct(?object $r=null)
  {
    $this->track = new PromiseResultTrack();
    $this->ok    = &$this->track->ok;
    if ($r) {$this->value = $r->value;}
  }
  # }}}
  # getters {{{
  private function current(): object # {{{
  {
    # select unconfirmed first
    if (!$this->track->title) {
      return $this->track;
    }
    # create new unconfirmed
    $t = new PromiseResultTrack($this->track);
    $this->track = $t;
    $this->ok = &$t->ok;
    return $t;
  }
  # }}}
  function isEmpty(): bool # {{{
  {
    $t = $this->track;
    return !$t->trace && !$t->title;
  }
  # }}}
  # [] access {{{
  function offsetExists(mixed $k): bool {
    return !!$this->offsetGet($k);
  }
  function offsetGet(mixed $k): mixed
  {
    for ($t=$this->track; $k && $t; --$k) {
      $t = $t->prev;
    }
    return $t;
  }
  function offsetSet(mixed $k, mixed $v): void
  {}
  function offsetUnset(mixed $k): void
  {}
  # }}}
  # }}}
  # setters {{{
  function info(...$msg): self # {{{
  {
    array_unshift(
      $this->current()->trace,
      [self::IS_INFO, $msg]
    );
    return $this;
  }
  # }}}
  function warn(...$msg): self # {{{
  {
    array_unshift(
      $this->current()->trace,
      [self::IS_WARNING, $msg]
    );
    return $this;
  }
  # }}}
  function fail(...$msg): self # {{{
  {
    array_unshift(
      $this->current()->trace,
      [self::IS_FAILURE, $msg]
    );
    $this->ok = false;
    return $this;
  }
  # }}}
  function error(object $e): self # {{{
  {
    array_unshift(
      $this->current()->trace,
      [self::IS_ERROR, ErrorEx::from($e)]
    );
    if ($e->hasError()) {
      $this->ok = false;
    }
    return $this;
  }
  # }}}
  function group(object $r, int $total, int $complete): self # {{{
  {
    array_unshift(
      $this->current()->trace,
      [self::IS_GROUP, $r, $total, $complete]
    );
    if (!$r->ok) {
      $this->ok = false;
    }
    return $this;
  }
  # }}}
  function cell(object $r, int $index): self # {{{
  {
    array_unshift(
      $this->current()->trace,
      [self::IS_CELL, $r, $index]
    );
    return $this;
  }
  # }}}
  function fuse(): self # {{{
  {
    $t1 = $this->track;
    $this->track = $t0 = new PromiseResultTrack();
    $this->ok = &$t0->ok;
    array_unshift(
      $t0->trace, [self::IS_FUSION, $t1]
    );
    return $this;
  }
  # }}}
  function reverse(): self # {{{
  {
    $t1 = $this->track;
    $t0 = new PromiseResultTrack(null, false);
    $this->track = $t0;
    $this->ok = &$t0->ok;
    array_unshift(
      $t0->trace, [self::IS_REVERSAL, $t1]
    );
    return $this;
  }
  # }}}
  function confirm(...$msg): self # {{{
  {
    $this->current()->title = $msg;
    return $this;
  }
  # }}}
  # }}}
}
class PromiseResultTrack implements ArrayAccess
{
  function __construct(# {{{
    public ?object $prev  = null,
    public bool    $ok    = true,
    public array   $trace = [],
    public ?array  $title = null
  ) {}
  # }}}
  # [] access {{{
  function offsetExists(mixed $k): bool {
    return isset($this->trace[$k]);
  }
  function offsetGet(mixed $k): mixed {
    return $this->trace[$k] ?? null;
  }
  function offsetSet(mixed $k, mixed $v): void
  {}
  function offsetUnset(mixed $k): void
  {}
  # }}}
}
# }}}
# dynamic continuator {{{
if (!isset(Completable::$THEN))
{
  Completable::$THEN = new class() extends PromiseNone
  {
    const DEF_DELAY = 50000000;# 50ms in nano
    public int     $id=0,$time=0;
    public ?object $action=null;
    # setters
    function delay(int $ms=0): object # {{{
    {
      # check specified
      if ($ms)
      {
        # check incorrect
        if ($e = PromiseTimeout::check($ms)) {
          return $e;
        }
        # convert milli => nano
        $ms = (int)($ms * 1000000);
      }
      else {
        $ms = self::DEF_DELAY;
      }
      # complete
      $this->time = self::$TIME + $ms;
      $this->id = 1;
      return $this;
    }
    # }}}
    function idle(int $ns): self # {{{
    {
      $this->time = $ns;
      $this->id = 1;
      return $this;
    }
    # }}}
    function hop(?object $action=null): self # {{{
    {
      if ($action)
      {
        $this->action = $action;
        $this->id = 3;
      }
      else {
        $this->id = 2;
      }
      return $this;
    }
    # }}}
    function fuse(object $action): self # {{{
    {
      $this->action = $action;
      $this->id = 4;
      return $this;
    }
    # }}}
    function abort(): self # {{{
    {
      $this->id = 5;
      return $this;
    }
    # }}}
    function done(): self # {{{
    {
      $this->id = 6;
      return $this;
    }
    # }}}
    # getters
    function getId(): int # {{{
    {
      $id = $this->id;
      $this->id = 0;
      return $id;
    }
    # }}}
    function getAction(): object # {{{
    {
      $a = $this->action;
      $this->action = null;
      return $a;
    }
    # }}}
  };
}
# }}}
###
