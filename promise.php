<?php declare(strict_types=1);
# defs {{{
namespace SM;
use
  SplDoublyLinkedList,SplObjectStorage,
  Closure,Error,Throwable,ArrayAccess;
use function
  is_array,count,array_unshift,array_pop,array_push,
  array_reverse,in_array,implode,time,hrtime,usleep;
use const
  DIRECTORY_SEPARATOR;
###
require_once __DIR__.DIRECTORY_SEPARATOR.'error.php';
# }}}
abstract class Completable # {{{
{
  public ?object $result=null;
  abstract function complete(): ?object;
  abstract function cancel(): ?object;
  ###
  static object $THEN;# dynamic continuator
  static int    $TIME=0,$HRTIME=0;# current time
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
      : new PromiseNop();
  }
}
# }}}
abstract class Reversible extends Completable # {{{
{
  function undo(): void {
    $this->result->warn('not implemented');
  }
}
# }}}
class Promise extends Reversible # {{{
{
  # base {{{
  public ?array $reverse=null;
  public int    $pending=-1,$idle=0;
  public object $actions;
  ###
  function __construct(object $action)
  {
    $q = new SplDoublyLinkedList();
    $q->push($action);
    $this->actions = $q;
  }
  function reverseAdd(object $action): void # {{{
  {
    if (!($q = &$this->reverse)) {
      $q = [];
    }
    if (!($action instanceof PromiseGroup))
    {
      if (!in_array($action, $q, true)) {
        $q[] = $action;# one
      }
    }
    elseif ($action->reverse) {# many
      array_push($q, ...$action->reverse);
    }
  }
  # }}}
  static function expand(# {{{
    object $q, object $x
  ):int
  {
    if ($x instanceof self)
    {
      $q->shift();
      $x = $x->actions;
      $i = $n = $x->count();
      while (--$i >= 0) {
        $q->unshift($x->offsetGet($i));
      }
      return $n - 1;
    }
    $q->offsetSet(0, Completable::from($x));
    return 0;
  }
  # }}}
  static function fuse(# {{{
    object &$q, object $x
  ):int
  {
    if ($x instanceof self)
    {
      $q = $x->actions;# replace
      return $q->count();
    }
    $q = new SplDoublyLinkedList();# recreate
    $q->push(Completable::from($x));
    return 1;
  }
  # }}}
  # }}}
  # construction {{{
  static function from(object|array|null $x): self # {{{
  {
    return is_array($x)
      ? self::Column($x)
      : (($x instanceof self)
        ? $x : new self(Completable::from($x)));
  }
  # }}}
  static function Value(...$v): self # {{{
  {
    $a = match (count($v))
    {
      0 => new PromiseValue(null),
      1 => new PromiseValue($v[0]),
      default => new PromiseValue($v)
    };
    return new self($a);
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
  static function When(# {{{
    bool $ok, object $x, ...$a
  ):self
  {
    return new self(new PromiseWhen($ok, $a
      ? new PromiseFunc($x, $a)
      : Completable::from($x)
    ));
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
    return new self(
      new PromiseColumn($group, $break)
    );
  }
  # }}}
  static function Row(# {{{
    array $group, int $break=0, int $first=0
  ):self
  {
    return new self(
      new PromiseRow($group, $break, $first)
    );
  }
  # }}}
  # }}}
  # composition {{{
  function then(object $x, ...$a): self # {{{
  {
    $q = $this->actions;
    if ($a) {# assume callable
      $q->push(new PromiseFunc($x, $a));
    }
    elseif ($x instanceof self)
    {
      # append all
      foreach ($x->actions as $action) {
        $q->push($action);
      }
    }
    else {# append one
      $q->push(Completable::from($x));
    }
    return $this;
  }
  # }}}
  function thenCall(object $f, ...$a): self # {{{
  {
    $this->actions->push(new PromiseCall($f, $a));
    return $this;
  }
  # }}}
  function thenTimeout(# {{{
    int $ms, object $x, ...$a
  ):self
  {
    $this->actions->push(new PromiseTimeout($ms, $a
      ? new PromiseFunc($x, $a)
      : Completable::from($x)
    ));
    return $this;
  }
  # }}}
  function thenColumn(# {{{
    array $group, int $break=1
  ):self
  {
    $this->actions->push(new PromiseColumn(
      $group, $break
    ));
    return $this;
  }
  # }}}
  function thenRow(# {{{
    array $group, int $break=0, int $first=0
  ):self
  {
    $this->actions->push(new PromiseRow(
      $group, $break, $first
    ));
    return $this;
  }
  # }}}
  # positive
  function okay(object $x, ...$a): self # {{{
  {
    $this->actions->push(new PromiseWhen(true, $a
      ? new PromiseFunc($x, $a)
      : Completable::from($x)
    ));
    return $this;
  }
  # }}}
  function okayCall(object $f, ...$a): self # {{{
  {
    $this->actions->push(new PromiseWhen(true,
      new PromiseCall($f, $a)
    ));
    return $this;
  }
  # }}}
  function okayTimeout(# {{{
    int $ms, object $x, ...$a
  ):self
  {
    $this->actions->push(new PromiseWhen(true,
      new PromiseTimeout($ms, $a
        ? new PromiseFunc($x, $a)
        : Completable::from($x)
      )
    ));
    return $this;
  }
  # }}}
  function okayColumn(# {{{
    array $group, int $break=1
  ):self
  {
    $this->actions->push(new PromiseWhen(true,
      new PromiseColumn($group, $break)
    ));
    return $this;
  }
  # }}}
  function okayRow(# {{{
    array $group, int $break=0, int $first=0
  ):self
  {
    $this->actions->push(new PromiseWhen(true,
      new PromiseRow($group, $break, $first)
    ));
    return $this;
  }
  # }}}
  function okayFuse(object $x): self # {{{
  {
    $this->actions->push(new PromiseWhen(true,
      new PromiseFuse($x)
    ));
    return $this;
  }
  # }}}
  # negative
  function fail(object $x, ...$a): self # {{{
  {
    $this->actions->push(new PromiseWhen(false, $a
      ? new PromiseFunc($x, $a)
      : Completable::from($x)
    ));
    return $this;
  }
  # }}}
  function failCall(object $f, ...$a): self # {{{
  {
    $this->actions->push(new PromiseWhen(false,
      new PromiseCall($f, $a)
    ));
    return $this;
  }
  # }}}
  function failTimeout(# {{{
    int $ms, object $x, ...$a
  ):self
  {
    $this->actions->push(new PromiseWhen(false,
      new PromiseTimeout($ms, $a
        ? new PromiseFunc($x, $a)
        : Completable::from($x)
      )
    ));
    return $this;
  }
  # }}}
  function failColumn(# {{{
    array $group, int $break=0
  ):self
  {
    $this->actions->push(new PromiseWhen(false,
      new PromiseColumn($group, $break)
    ));
    return $this;
  }
  # }}}
  function failRow(# {{{
    array $group, int $break=0, int $first=0
  ):self
  {
    $this->actions->push(new PromiseWhen(false,
      new PromiseRow($group, $break, $first)
    ));
    return $this;
  }
  # }}}
  function failFuse(object $x): self # {{{
  {
    $this->actions->push(new PromiseWhen(false,
      new PromiseFuse($x)
    ));
    return $this;
  }
  # }}}
  # }}}
  function complete(): ?object # {{{
  {
    # check idle timeout (I/O resource polling)
    if ($this->idle)
    {
      if ($this->idle > self::$HRTIME) {
        return null;# skip this time
      }
      $this->idle = 0;# activate
    }
    # prepare
    $q = &$this->actions;
    if (($i = &$this->pending) <= 0)
    {
      # check finished
      if (!$i) {
        return $this->result;
      }
      # initialize self
      if (!$this->result) {# preset is possible
        $this->result = new PromiseResult();
      }
      $i = $q->count();
    }
    # initialize current action
    if (!($a = $q->offsetGet(0))->result) {
      $a->result = $this->result;
    }
    # execute
    if ($x = $a->complete())
    {
      # handle repetition
      if ($x === $a) {
        return null;
      }
      # handle dynamic continuation
      if ($x === self::$THEN)
      {
        switch ($x->getId()) {
        case 1:# lazy repetition
          $this->idle = $x->time;
          return null;
        case 2:# immediate recursion
          $q->shift();
          return --$i
            ? $this->complete()
            : $this->result->_end();
        case 3:# immediate expansive recursion
          $i += self::expand($q, $x->getAction());
          return $this->complete();
        case 4:# fusion
          $i = self::fuse($q, $x->getAction());
          return null;
        case 5:# immediate cancellation
          return $this->cancel();
        case 6:# immediate completion
          $i = 0;
          return $this->result->_end();
        default:# skip unknown
          $q->shift();
          return --$i
            ? null : $this->result->_end();
        }
      }
      # collect reversible
      if ($a instanceof Reversible) {
        $this->reverseAdd($a);
      }
      # handle expansive continuation
      $i += self::expand($q, $x);
      return null;
    }
    # collect reversible
    if ($a instanceof Reversible) {
      $this->reverseAdd($a);
    }
    # one complete, eject and finish
    $q->shift();
    return --$i
      ? null : $this->result->_end();
  }
  # }}}
  function cancel(): ?object # {{{
  {
    # check not started
    if ($this->pending < 0) {
      return null;
    }
    # cancel one unfinished
    if ($this->pending > 0)
    {
      $this->pending = 0;
      $this->actions[0]->cancel();
    }
    # undo all finished and complete
    $this->undo();
    return $this->result->_end();
  }
  # }}}
  function undo(): void # {{{
  {
    if ($q = &$this->reverse)
    {
      $r = $this->result->_undo();
      $i = count($q);
      while (--$i >= 0)
      {
        $q[$i]->result = $r;
        $q[$i]->undo();
      }
      $q = null;
    }
  }
  # }}}
}
# }}}
class Loop # {{{
{
  # TODO: performance measuring mode
  # TODO: cancellation/stop
  # constructor {{{
  const MAX_TIMEOUT = 24*60*60*1000000000;# nanosec
  static ?object $ERROR=null;
  private static ?self $I=null;
  private function __construct(
    public int    $timeout = 0,
    public object $row = new SplObjectStorage(),
    public int    $rowCnt  = 0,
    public array  $columns = [],
    public int    $colCnt  = 0,
    public int    $added   = 0,
    public int    $pending = 0
  ) {}
  # }}}
  # core {{{
  function add(object $p, string $id=''): self # {{{
  {
    if ($id === '')
    {
      $this->row->attach($p);
      $this->rowCnt++;
      $this->added++;
    }
    elseif (isset($this->columns[$id])) {
      $this->columns[$id]->push($p);
    }
    else
    {
      $q = new SplDoublyLinkedList();
      $q->push($p);
      $this->columns[$id] = $q;
      $this->colCnt++;
      $this->added++;
    }
    return $this;
  }
  # }}}
  function spin(): int # {{{
  {
    # check idle
    if ($z = &$this->timeout)
    {
      self::cooldown($z);
      return $z;
    }
    # when new promises added,
    # update low resolution timestamp
    # which is used upon result construction
    if ($this->added)
    {
      $this->added = 0;
      Completable::$TIME = time();
    }
    # update high resolution timestamp and
    # determine maximal idle timestamp
    Completable::$HRTIME = $t = hrtime(true);
    $idleTime = $t + self::MAX_TIMEOUT;
    $idleCnt  = 0;
    # spin columns
    $q0 = &$this->columns;
    if ($n0 = &$this->colCnt)
    {
      foreach ($q0 as $id => $q)
      {
        if (($p = $q->offsetGet(0))->complete())
        {
          $q->shift();
          if ($q->isEmpty())
          {
            unset($q0[$id]);
            $n0--;
          }
        }
        elseif ($i = $p->idle)
        {
          $idleCnt++;
          if ($idleTime > $i) {
            $idleTime = $i;
          }
        }
      }
    }
    # spin the row
    $q1 = $this->row;
    if ($n1 = &$this->rowCnt)
    {
      $q1->rewind(); $j = $n1;
      while ($j--)
      {
        if (($p = $q1->current())->complete())
        {
          $q1->detach($p);
          $n1--;
        }
        else
        {
          $q1->next();
          if ($i = $p->idle)
          {
            $idleCnt++;
            if ($idleTime > $i) {
              $idleTime = $i;
            }
          }
        }
      }
    }
    # update and check total
    switch ($this->pending = $n0 + $n1) {
    case 0:# all exhausted
      break;
    case $idleCnt:# all idle
      # convert nano=>micro and set timeout
      $t = (int)(($idleTime - $t) / 1000);
      $z = $t ?: 1;
      # discharge
      self::cooldown($z);
    }
    return $z;
  }
  # }}}
  # }}}
  static function cooldown(int &$t): void # {{{
  {
    if ($t > 1000000)
    {
      usleep(500000);$t -= 500000;
      usleep(500000);$t -= 500000;
    }
    else {
      usleep($t);$t = 0;
    }
  }
  # }}}
  static function init(): bool # {{{
  {
    if (self::$ERROR) {return false;}
    if (self::$I)     {return true;}
    try
    {
      self::$I = new self();
      return true;
    }
    catch (Throwable $e)
    {
      self::$ERROR = ErrorEx::from($e);
      return false;
    }
  }
  # }}}
  static function await(object $p): object # {{{
  {
    $I = self::$I->add($p);
    while ($p->pending) {
      $I->spin();
    }
    return $p->result;
  }
  # }}}
}
# }}}
function await(object|array $p): object # {{{
{
  return Loop::await(is_array($p)
    ? Promise::Row($p)
    : Promise::from($p)
  );
}
# }}}
# actions {{{
abstract class PromiseAction extends Completable # {{{
{
  function cancel(): ?object {
    return null;
  }
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
class PromiseNop extends Completable # {{{
{
  function complete(): ?object {
    return null;
  }
  function cancel(): ?object {
    return null;
  }
}
# }}}
class PromiseError extends PromiseNop # {{{
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
class PromiseValue extends PromiseNop # {{{
{
  function __construct(
    public mixed &$value
  ) {}
  function complete(): ?object
  {
    $this->result->extend()->setRef($this->value);
    return self::$THEN->hop();
  }
}
# }}}
class PromiseWhen extends PromiseNop # {{{
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
class PromiseFuse extends PromiseNop # {{{
{
  function __construct(
    public object $action
  ) {}
  function complete(): ?object
  {
    $this->result->_fuse();
    return self::$THEN->fuse($this->action);
  }
}
# }}}
class PromiseTimeout extends PromiseNop # {{{
{
  const MAX_DELAY = 24*60*60*1000;# millisec
  static function check(int $ms): ?object # {{{
  {
    static $E0='incorrect delay, less than zero';
    static $E1='incorrect delay, greater than maximum';
    if ($ms < 0) {
      return ErrorEx::fail($E0, $ms);
    }
    if ($ms > self::MAX_DELAY) {
      return ErrorEx::fail($E1, $ms);
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
  public ?array $reverse=null;
  public int    $idx=-1,$cnt=0;
  function __construct(
    public array &$group,
    public int   $break
  ) {
    # groups operate on promises,
    # convert all items
    foreach ($group as &$p) {
      $p = Promise::from($p);
    }
    # set number of promises
    $this->cnt = $n = count($group);
    # set correct break number
    if ($break > $n) {
      $this->break = 0;# none
    }
    elseif ($break < 0) {
      $this->break = 1;# one
    }
  }
  function reverseAdd(object $promise): void
  {
    if (!($q = &$this->reverse)) {
      $q = [];
    }
    array_push($q, ...$promise->reverse);
  }
}
# }}}
class PromiseColumn extends PromiseGroup # {{{
{
  function complete(): ?object # {{{
  {
    # prepare
    $q = &$this->group;
    $i = &$this->idx;
    # check finished
    if ($i >= ($j = $this->cnt)) {
      return null;
    }
    # initialize
    if ($i < 0)
    {
      $p = $q[$i = 0];
      $p->result = $this->result->_column($j);
    }
    elseif (!($p = $q[$i])->result) {
      $p->result = $q[$i - 1]->result;
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
    $p->reverse && $this->reverseAdd($p);
    # check all complete
    if (++$i >= $j)
    {
      $this->result->_columnEnd($i);
      return null;
    }
    # check breakable failed
    if ($this->break && !$r->ok)
    {
      if (--$this->break) {
        return $this;# more to break
      }
      $this->cancel();
      return null;
    }
    # continue
    return $this;
  }
  # }}}
  function cancel(): ?object # {{{
  {
    # check not started
    if (($i = &$this->idx) < 0) {
      return null;
    }
    # check not finished
    if ($i < ($j = $this->cnt))
    {
      # cancel one and finish
      $q = &$this->group;
      $q[$i]->result && $q[$i]->cancel();
      $this->result->_columnEnd($i);
      $i = $j;
    }
    return $this->result;
  }
  # }}}
}
# }}}
class PromiseRow extends PromiseGroup # {{{
{
  function __construct(# {{{
    public array &$group,
    public int   $break,
    public int   $first
  ) {
    parent::__construct($group, $break);
    if ($first > $this->cnt) {
      $this->first = 0;# all
    }
    elseif ($first < 0) {
      $this->first = 1;# one
    }
  }
  # }}}
  function complete(): ?object # {{{
  {
    # prepare
    $q = &$this->group;
    $i = &$this->idx;
    # check finished
    if ($i >= ($j = $this->cnt)) {
      return null;
    }
    # initialize
    if ($i < 0) {
      $i = $this->result->_row($q, $j);
    }
    # to enter idle state, the number of idle items and
    # the closest idle timeout must be determined
    $idleTime = self::$HRTIME + Loop::MAX_TIMEOUT;
    $idleCnt  = 0;
    # execute all
    for ($k=0; $k < $j; ++$k)
    {
      # checkout
      if (!($p = $q[$k]))
      {
        $idleCnt++;
        continue;
      }
      # execute
      if (!($r = $p->complete()))
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
      # one complete
      $q[$k] = $this->result->_rowDone($r, $k, ++$i);
      $idleCnt++;
      # collect reversibles
      $p->reverse && $this->reverseAdd($p);
      # check break condition
      if ($this->break && !$r->ok)
      {
        if (--$this->break) {
          continue;# more to break
        }
        $this->cancel();
        return null;
      }
      # check race condition
      if ($this->first)
      {
        if (--$this->first) {
          continue;# more to come
        }
        $this->cancel();
        return null;
      }
    }
    # complete idle, active or finished
    return ($i < $j)
      ? (($idleCnt === $j)
        ? self::$THEN->idle($idleTime)
        : $this)
      : null;
  }
  # }}}
  function cancel(): ?object # {{{
  {
    # check not started
    if (($i = &$this->idx) < 0) {
      return null;
    }
    # check not finished
    if ($i < $this->cnt)
    {
      $i = $this->result->_rowCancel(
        $this->group, $this->cnt
      );
    }
    return $this->result;
  }
  # }}}
}
# }}}
# }}}
# result {{{
class PromiseResult
  implements ArrayAccess,Loggable
{
  # constructor {{{
  const
    IS_INFO    = 0,
    IS_WARNING = 1,
    IS_FAILURE = 2,
    IS_ERROR   = 3,# ErrorEx object
    IS_COLUMN  = 4,# column group
    IS_ROW     = 5,# row group
    IS_FUSION  = 6,# all => one track
    IS_UNDO    = 8;# all => cancellation track
  ###
  public int    $time;
  public object $track;
  public bool   $ok;
  public array  $store;
  public mixed  $value;
  ###
  function __construct(?array &$x=null)
  {
    $this->time  = Completable::$TIME;
    $this->track = new PromiseResultTrack();
    $this->ok    = &$this->track->ok;
    $this->store = [null];
    $this->setRef($x);
  }
  # }}}
  # util {{{
  function __debugInfo(): array # {{{
  {
    return [
      'store' => $this->store,
      'track' => $this->track,
    ];
  }
  # }}}
  static function trace_info(array $t): array # {{{
  {
    foreach ($t as &$a)
    {
      $a = match ($a[0]) {
      self::IS_INFO
        => 'INFO: '.implode('·', $a[1]),
      self::IS_WARNING
        => 'WARNING: '.implode('·', $a[1]),
      self::IS_FAILURE
        => 'FAILURE: '.implode('·', $a[1]),
      self::IS_ERROR
        => 'ERROR: '.$a[1]->message(),
      self::IS_COLUMN
        => ['COLUMN: '.$a[2].'/'.$a[3], $a[1]],
      self::IS_ROW
        => ['ROW: '.$a[2].'/'.$a[3], $a[1]],
      self::IS_FUSION
        => ['FUSE', $a[1]],
      self::IS_UNDO
        => ['UNDO', $a[1]],
      default
        => '?',
      };
    }
    return $t;
  }
  # }}}
  function _track(): object # {{{
  {
    if (!$this->track->title) {
      return $this->track;
    }
    $t = new PromiseResultTrack($this->track);
    $this->track = $t;
    $this->ok = &$t->ok;
    return $t;
  }
  # }}}
  # }}}
  # [] access {{{
  function offsetExists(mixed $k): bool {
    return $k >= 0 && $k < count($this->store);
  }
  function offsetGet(mixed $k): mixed
  {
    $n = count($this->store);
    if ($k < 0) {
      $k += $n;
    }
    return ($k >= 0 && $k < $n)
      ? $this->store[$k]
      : null;
  }
  function offsetSet(mixed $k, mixed $v): void
  {}
  function offsetUnset(mixed $k): void
  {}
  # }}}
  # loggable {{{
  function log(): array # {{{
  {
    $t = $this->track;
    $a = self::trace_logs($t->trace);
    $t->prev && array_push(
      $a, ...self::track_logs($t->prev)
    );
    return [
      'level' => $t->ok ? 0 : 2,
      'msg'   => $t->title,
      'span'  => self::track_span($t),
      'time'  => $this->time,
      'logs'  => $a
    ];
  }
  # }}}
  static function track_level(object $t): int # {{{
  {
    if (!$t->ok) {
      return 2;
    }
    foreach ($t->trace as $e)
    {
      switch ($e[0]) {
      case self::IS_WARNING:
        return 1;
      case self::IS_ERROR:
        if ($e[1]->hasIssue()) {
          return 1;
        }
        break;
      case self::IS_COLUMN:
        if (self::track_level($e[1])) {
          return 1;
        }
        break;
      }
    }
    return 0;
  }
  # }}}
  static function track_span(object $t): int # {{{
  {
    $x = 0;
    do {
      $x += $t->span + self::trace_span($t->trace);
    }
    while ($t = $t->prev);
    return $x;
  }
  # }}}
  static function track_logs(object $t): array # {{{
  {
    $a = [];
    do
    {
      if ($t->trace)
      {
        $a[] = [
          'level' => self::track_level($t),
          'msg'   => $t->title,
          'span'  => $t->span,
          'logs'  => self::trace_logs($t->trace)
        ];
      }
      else
      {
        $a[] = [
          'level' => $t->ok ? 0 : 2,
          'msg'   => $t->title,
          'span'  => $t->span,
        ];
      }
    }
    while ($t = $t->prev);
    return $a;
  }
  # }}}
  static function trace_span(array &$t): int # {{{
  {
    $x = 0;
    $i = count($t);
    while (--$i >= 0)
    {
      switch ($t[$i][0]) {
      case self::IS_COLUMN:
      case self::IS_ROW:
        $x += $t[$i][4];
        break;
      case self::IS_FUSION:
      case self::IS_UNDO:
        $x += self::track_span($t[$i][1]);
        break;
      }
    }
    return $x;
  }
  # }}}
  static function trace_logs(array &$trace): array # {{{
  {
    # trace should be iterated in reverse order
    $a = [];
    $i = count($trace);
    while (--$i >= 0)
    {
      $t = &$trace[$i];
      switch ($t[0]) {
      case self::IS_INFO:
      case self::IS_WARNING:
      case self::IS_FAILURE:
        $a[] = [
          'level' => $t[0],
          'msg'   => $t[1],
        ];
        break;
      case self::IS_ERROR:
        $a[] = $t[1]->log();
        break;
      case self::IS_COLUMN:
        $trk = $t[1];
        $a[] = [
          'level' => self::track_level($trk),
          'msg'   => ['COLUMN',$t[2].'/'.$t[3]],
          'span'  => self::track_span($trk),
          'logs'  => self::trace_logs($trk->trace)
        ];
        break;
      case self::IS_ROW:
        $j = 0;
        $b = [];
        foreach ($t[1] as $c)
        {
          $trk = $c[0];
          $k = $trk->ok ? 0 : 2;
          $m = '#'.$c[1];
          $d = self::trace_logs($trk->trace);
          $trk->prev && array_push(
            $d, ...self::track_logs($trk->prev)
          );
          $b[] = [
            'level' => $k,
            'msg'   => [$m, ...$trk->title],
            'span'  => self::track_span($trk),
            'logs'  => $d
          ];
          if ($k && !$j) {
            $j = 2;
          }
        }
        $a[] = [
          'level' => $j,
          'msg'   => ['ROW',$t[2].'/'.$t[3]],
          'span'  => $t[4],
          'logs'  => $b
        ];
        break;
      case self::IS_FUSION:
        $trk = $t[1];
        if ($trk->title) {
          $b = self::track_logs($trk);
        }
        else
        {
          $b = self::trace_logs($trk->trace);
          $trk->prev && array_push(
            $b, ...self::track_logs($trk->prev)
          );
        }
        $a[] = [
          'level' => self::track_level($trk),
          'msg'   => [$trk->ok?'FUSED':'DEFUSED'],
          'span'  => self::track_span($trk),
          'logs'  => $b
        ];
        break;
      case self::IS_UNDO:
        $trk = $t[1];
        $a[] = [
          'level' => $t[1]->ok ? 0 : 2,
          'msg'   => ['UNDO'],
          'span'  => self::track_span($trk),
          'logs'  => self::track_logs($trk)
        ];
        break;
      }
    }
    return $a;
  }
  # }}}
  # }}}
  # value setters {{{
  function extend(): self
  {
    array_unshift($this->store, null);
    $this->value = &$this->store[0];
    return $this;
  }
  function setRef(mixed &$value): void
  {
    $this->store[0] = &$value;
    $this->value    = &$this->store[0];
  }
  function set(mixed $value): void {
    $this->extend()->setRef($value);
  }
  # }}}
  # track setters {{{
  # internal use
  function _column(int $total): object # {{{
  {
    $r = new PromiseResult($this->store);
    $this->_track()->trace[] = [
      self::IS_COLUMN, $r, 0, $total,
      Completable::$HRTIME
    ];
    return $r;
  }
  # }}}
  function _columnEnd(int $done): void # {{{
  {
    $e = &$this->track->trace;
    $e = &$e[count($e) - 1];
    $r = $e[1];
    $e[1] = $r->track;
    $e[2] = $done;
    $e[4] = Completable::$HRTIME - $e[4];
    ###
    if (!$r->ok && $this->ok) {
      $this->ok = false;
    }
    array_pop($r->store);
    $this->extend()->setRef($r->store);
  }
  # }}}
  function _row(array &$q, int $total): int # {{{
  {
    for ($v=[],$i=0; $i < $total; ++$i)
    {
      $q[$i]->result = $r = new self($this->store);
      $v[$i] = &$r->store;
    }
    $this->_track()->trace[] = [
      self::IS_ROW, [], 0, $total,
      Completable::$HRTIME
    ];
    $this->extend()->setRef($v);
    return 0;
  }
  # }}}
  function _rowDone(# {{{
    object $r, int $i, int $n
  ):void
  {
    # get row element
    $e = &$this->track->trace;
    $e = &$e[count($e) - 1];
    # store completed result's track
    $e[1][] = [$r->track, $i];
    $e[2]++;
    # determine total duration at last completion
    if ($n >= $e[3]) {
      $e[4] = Completable::$HRTIME - $e[4];
    }
    # change current state upon failure
    if (!$r->ok && $this->ok) {
      $this->ok = false;
    }
    # remove initial value from individual store
    array_pop($this->value[$i]);
  }
  # }}}
  function _rowCancel(array &$q, int $total): int # {{{
  {
    $e = &$this->track->trace;
    $e = &$e[count($e) - 1];
    for ($i=0; $i < $total; ++$i)
    {
      if ($p = $q[$i])
      {
        $e[1][] = [$p->cancel()->track, $i];
        array_pop($this->value[$i]);
      }
    }
    $e[4] = Completable::$HRTIME - $e[4];
    return $total;
  }
  # }}}
  function _fuse(): self # {{{
  {
    if (!($t0 = $this->track)->title) {
      $t0->span = Completable::$HRTIME - $t0->span;
    }
    $t1 = new PromiseResultTrack();
    $this->track = $t1;
    $this->ok    = &$t1->ok;
    $t1->trace[] = [self::IS_FUSION, $t0];
    return $this;
  }
  # }}}
  function _undo(): self # {{{
  {
    $t0 = $this->track;
    $t1 = new PromiseResultTrack(
      null, $t0->ok
    );
    $this->track = $t1;
    $this->ok    = &$t1->ok;
    $t0->trace[] = [self::IS_UNDO, $t0];
    return $this;
  }
  # }}}
  function _end(): self # {{{
  {
    $this->track->title || $this->confirm('{}');
    return $this;
  }
  # }}}
  # external
  function info(...$msg): void # {{{
  {
    $this->_track()->trace[] = [
      self::IS_INFO, ErrorEx::stringify($msg)
    ];
  }
  # }}}
  function warn(...$msg): void # {{{
  {
    $this->_track()->trace[] = [
      self::IS_WARNING, ErrorEx::stringify($msg)
    ];
  }
  # }}}
  function fail(...$msg): void # {{{
  {
    $this->_track()->trace[] = [
      self::IS_FAILURE, ErrorEx::stringify($msg)
    ];
    $this->ok = false;
  }
  # }}}
  function error(object $e): void # {{{
  {
    $this->_track()->trace[] = [
      self::IS_ERROR, ErrorEx::set($e)
    ];
    if ($e->hasError() && $this->ok) {
      $this->ok = false;
    }
  }
  # }}}
  function confirm(...$msg): void # {{{
  {
    $t = $this->_track();
    $t->title = ErrorEx::stringify($msg);
    $t->span  = Completable::$HRTIME - $t->span;
  }
  # }}}
  # }}}
}
class PromiseResultTrack
{
  function __construct(# {{{
    public ?object $prev  = null,
    public bool    $ok    = true,
    public ?array  $title = null,
    public array   $trace = [],
    public int     $span  = 0
  ) {
    $this->span = Completable::$HRTIME;
  }
  # }}}
  function __debugInfo(): array # {{{
  {
    $a['ok'] = $this->ok;
    if ($this->title)
    {
      $a['title'] = implode('·', $this->title);
      $a['span(ms)'] = (int)($this->span / 1000000);
    }
    $a['trace'] = PromiseResult::trace_info(
      array_reverse($this->trace)
    );
    if ($this->prev) {
      $a['prev'] = $this->prev;
    }
    return $a;
  }
  # }}}
}
# }}}
# continuator {{{
if (!isset(Completable::$THEN))
{
  Completable::$THEN = new class() extends PromiseNop
  {
    const DEF_DELAY = 50000000;# 50ms in nano
    public int     $id=0,$time=0;
    public ?object $action=null;
    # setters
    function delay(int $ms=0): object # {{{
    {
      if ($ms)
      {
        if ($e = PromiseTimeout::check($ms)) {
          return $e;
        }
        $ms = (int)($ms * 1000000);# milli => nano
      }
      else {
        $ms = self::DEF_DELAY;
      }
      $this->id = 1;
      $this->time = self::$HRTIME + $ms;
      return $this;
    }
    # }}}
    function idle(int $ns): self # {{{
    {
      $this->id = 1;
      $this->time = $ns;
      return $this;
    }
    # }}}
    function hop(?object $action=null): self # {{{
    {
      if ($action)
      {
        $this->id = 3;
        $this->action = $action;
      }
      else {
        $this->id = 2;
      }
      return $this;
    }
    # }}}
    function fuse(object $action): self # {{{
    {
      $this->id = 4;
      $this->action = $action;
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
return Loop::init();
###
