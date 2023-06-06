<?php declare(strict_types=1);
namespace SM;
# defs {{{
use
  JsonSerializable,ArrayAccess,Iterator,Stringable,
  Generator,Closure,CURLFile,
  Throwable,Error,Exception;
use function
  set_time_limit,ini_set,register_shutdown_function,
  set_error_handler,class_exists,function_exists,
  method_exists,func_num_args,
  ### variable handling
  gettype,intval,strval,is_object,is_array,is_bool,is_null,
  is_string,is_scalar,
  ### arrays
  explode,implode,count,reset,next,key,array_keys,
  array_push,array_pop,array_shift,array_unshift,
  array_splice,array_slice,in_array,array_search,
  array_reverse,
  ### strings
  strpos,strrpos,strlen,trim,rtrim,uniqid,ucfirst,
  str_repeat,str_replace,strtolower,
  lcfirst,strncmp,substr_count,preg_match,preg_match_all,
  hash,http_build_query,
  json_encode,json_decode,json_last_error,
  json_last_error_msg,
  ### filesystem
  file_put_contents,file_get_contents,clearstatcache,
  file_exists,unlink,filesize,filemtime,tempnam,
  sys_get_temp_dir,mkdir,scandir,fwrite,fread,fclose,glob,
  ### misc
  proc_open,is_resource,proc_get_status,proc_terminate,
  getmypid,ob_start,ob_get_length,ob_flush,ob_end_clean,
  pack,unpack,time,hrtime,sleep,usleep,
  min,max,pow;
###
require_once __DIR__.DIRECTORY_SEPARATOR.'error.php';
# }}}
class Promise
{
  # constructors {{{
  public $next,$result;
  function __construct(
    public ?object $action = null
  ) {}
  static function from(?object $x): ?self
  {
    return $x
      ? (($x instanceof self)
        ? $x
        : (($x instanceof PromiseAction)
          ? new self($x)
          : (($x instanceof Error)
            ? self::Fail($x)
            : self::Func($x))))
      : null;
  }
  static function From(?object $x): self {
    return self::from($x) ?? new self();
  }
  static function Value(mixed ...$v): self
  {
    return new self((count($v) === 1)
      ? new PromiseActionV($v[0])
      : new PromiseActionV($v)
    );
  }
  static function Func(object $f, mixed ...$a): self
  {
    return new self(count($a)
      ? new PromiseActionFn($f, $a)
      : new PromiseActionF($f)
    );
  }
  static function Call(object $f, mixed ...$a): self {
    return new self(new PromiseActionCall($f, $a));
  }
  static function Check(object $f): self {
    return new self(new PromiseActionCheck($f));
  }
  static function Fail(object $e): self {
    return new self(new PromiseActionFail($e));
  }
  static function FailStop(?object $e = null): self {
    return new self(new PromiseActionFailStop($e));
  }
  static function One(array $a): self {
    return new self(new PromiseActionOne($a));
  }
  static function OneBreak(array $a): self {
    return new self(new PromiseActionOneBreak($a));
  }
  static function All(array $a): self {
    return new self(new PromiseActionAll($a));
  }
  static function AllBreak(array $a): self {
    return new self(new PromiseActionAllBreak($a));
  }
  # }}}
  # continuators {{{
  function then(?object $x): self
  {
    return ($x = self::from($x))
      ? $this->add($x)
      : $this;
  }
  function thenFunc(object $f, ...$a): self
  {
    return $this->add(count($a)
      ? new PromiseActionFn($f, $a)
      : new PromiseActionF($f)
    );
  }
  function thenCall(object $f, ...$a): self {
    return $this->add(new PromiseActionCall($f, $a));
  }
  function thenCheck(object $f): self {
    return $this->add(new PromiseActionCheck($f));
  }
  function thenOne(array $a): self {
    return $this->add(new PromiseActionOne($a));
  }
  function thenOneBreak(array $a): self {
    return $this->add(new PromiseActionOneBreak($a));
  }
  function thenAll(array $a): self {
    return $this->add(new PromiseActionAll($a));
  }
  function thenAllBreak(array $a): self {
    return $this->add(new PromiseActionAllBreak($a));
  }
  function thenCatch(object $x): self {
    return $this->add(new PromiseActionCatch($x));
  }
  function okayThen(object $x): self {
    return $this->add(new PromiseActionOkThen($x));
  }
  function okayFunc(object $f, mixed ...$a): self
  {
    return $this->add(count($a)
      ? new PromiseActionOkFn($f, $a)
      : new PromiseActionOkF($f)
    );
  }
  function okayCall(object $f, mixed ...$a): self {
    return $this->add(new PromiseActionOkCall($f, $a));
  }
  # }}}
  # utils {{{
  function __invoke(): self {
    return $this;
  }
  function last(): self
  {
    $p = $this;
    while ($next = $p->next) {
      $p = $next;
    }
    return $p;
  }
  function add(object $p): self
  {
    if ($this->action) {
      $this->last()->next = $p;
    }
    else
    {
      $this->action = $p->action;
      $this->next   = $p->next;
      $this->result = null;
    }
    return $this;
  }
  # }}}
  function complete(): ?object # {{{
  {
    # get action and check complete
    if (($a = $this->action) === null) {
      return $this->result;
    }
    # invoke first initializer
    if ($a->result === null &&
        $a->init(new PromiseResult()) === false)
    {
      $this->action = null;
      return $this->result = $a->result;
    }
    # invoke spinner
    if ($a->spin()) {
      return null;
    }
    # invoke finalizer
    if (($p = self::from($a->stop())) && $this->next) {
      $p->last()->next = $this->next;
    }
    # handle continuation
    if ($p || ($p = $this->next))
    {
      $this->action = $p->action;
      $this->next   = $p->next;
      # invoke next initializer
      if ($this->action->init($a->result)) {
        return null;
      }
    }
    # complete
    $this->action = null;
    return $this->result = $a->result;
  }
  # }}}
  function cancel(): bool # {{{
  {
    if ($a = $this->action)
    {
      if ($x = $a->result) {# started
        $a->stop();
      }
      else {# pending
        $x = new PromiseResult();
      }
      $this->action = null;
      $this->result = $x->failure()->message('cancel');
    }
    return true;
  }
  # }}}
}
# result
class PromiseResult implements ArrayAccess # {{{
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
  function group(object $result): self # {{{
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
  function confirmGroup(string ...$title): self # {{{
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
}
# }}}
class PromiseResultTrack # {{{
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
# queue runners
class PromiseOne # {{{
{
  public $queue = [];
  function __construct(?object $o = null) {
    $this->then($o);
  }
  function then(?object $o): self
  {
    if ($o = Promise::from($o)) {
      $this->queue[] = $o;
    }
    return $this;
  }
  function complete(): bool
  {
    for ($q = &$this->queue; $q; array_shift($q))
    {
      if (!$q[0]->complete()) {
        return false;
      }
    }
    return true;
  }
}
# }}}
class PromiseAll extends PromiseOne # {{{
{
  function complete(): bool
  {
    if (($k = count($q = &$this->queue)) === 0) {
      return false;
    }
    for ($i = $k - 1; $i >= 0; --$i)
    {
      if ($q[$i]->complete())
      {
        array_splice($q, $i, 1);
        $k--;
      }
    }
    return ($k > 0);
  }
}
# }}}
# actions
abstract class PromiseAction # {{{
{
  public $result;
  final function init(object $r): bool
  {
    $this->result = $r;
    return $this->start();
  }
  function start(): bool    {return true;}
  function  spin(): bool    {return false;}
  function  stop(): ?object {return null;}
}
# }}}
class PromiseActionV extends PromiseAction # {{{
{
  function __construct(
    public mixed &$value
  ) {}
  function start(): bool
  {
    $this->result->value = $this->value;
    return true;
  }
}
# }}}
class PromiseActionF extends PromiseAction # {{{
{
  function __construct(
    public object $func
  ) {}
  function stop(): ?object {
    return ($this->func)($this->result);
  }
}
# }}}
class PromiseActionFn extends PromiseAction # {{{
{
  function __construct(
    public object $func,
    public array  &$arg
  ) {}
  function stop(): ?object {
    return ($this->func)($this->result, ...$this->arg);
  }
}
# }}}
class PromiseActionCall extends PromiseAction # {{{
{
  function __construct(
    public object $func,
    public array  &$arg
  ) {}
  function stop(): ?object {
    return ($this->func)(...$this->arg);
  }
}
# }}}
class PromiseActionCheck extends PromiseAction # {{{
{
  function __construct(
    public object $func
  ) {}
  function start(): bool {
    return ($this->func)($this->result);
  }
}
# }}}
# error actions
class PromiseActionFail extends PromiseAction # {{{
{
  public $error;
  function __construct(?object $error)
  {
    if ($error) {
      $this->error = ErrorEx::from($error);
    }
  }
  function start(): bool
  {
    $this->result->failure($this->error);
    return true;
  }
}
# }}}
class PromiseActionFailStop extends PromiseActionFail # {{{
{
  function start(): bool
  {
    $this->result->failure($this->error)->message('stop');
    return false;
  }
}
# }}}
# spinner actions
class PromiseActionOne extends PromiseAction # {{{
{
  public $flags;# -1=failed 0=pending 1=successful 2=skipped
  function __construct(public array &$queue)
  {
    # transform items into promises
    foreach ($queue as &$p) {
      $p = Promise::From($p);
    }
    # initialize flags
    $this->flags = array_fill(0, count($queue), 0);
  }
  function spin(): bool
  {
    # seek first incomplete item
    $s = &$this->flags;
    if (($i = array_search(0, $s, true)) === false) {
      return false;
    }
    # spin one by one until exhausted
    for ($k = count($q = &$this->queue); $i < $k; ++$i)
    {
      # try to complete
      if (($x = $q[$i]->complete()) === null) {
        return true;# continue later
      }
      # complete current
      $this->result->group($x);
      $s[$i] = $x->ok
        ?  1 # successful
        : -1;# failed
    }
    return false;
  }
  function spinBreak(): bool
  {
    # set skip flags for all incomplete items
    foreach ($this->flags as &$i) {
      if ($i === 0) {$i = 2;}
    }
    return false;
  }
}
# }}}
class PromiseActionOneBreak extends PromiseActionOne # {{{
{
  function spin(): bool
  {
    # seek first incomplete item
    $s = &$this->flags;
    if (($i = array_search(0, $s, true)) === false) {
      return false;
    }
    # spin one by one until exhausted
    for ($k = count($q = &$this->queue); $i < $k; ++$i)
    {
      # try to complete
      if (($x = $q[$i]->complete()) === null) {
        return true;# continue later
      }
      # complete current
      $this->result->group($x);
      if (($s[$i] = $x->ok ? 1 : -1) === -1) {
        return $this->spinBreak();# upon failure
      }
    }
    return false;
  }
}
# }}}
class PromiseActionAll extends PromiseActionOne # {{{
{
  function spin(): bool
  {
    # seek first incomplete item
    $s = &$this->flags;
    if (($i = array_search(0, $s, true)) === false) {
      return false;
    }
    # spin all incomplete once
    for ($k = count($q = &$this->queue); $i < $k; ++$i)
    {
      if ($s[$i] === 0 && ($x = $q[$i]->complete()))
      {
        $this->result->group($x);
        $s[$i] = $x->ok ? 1 : -1;
      }
    }
    return in_array(0, $s, true);
  }
}
# }}}
class PromiseActionAllBreak extends PromiseActionAll # {{{
{
  function spin(): bool
  {
    # seek first incomplete item
    $s = &$this->flags;
    if (($i = array_search(0, $s, true)) === false) {
      return false;
    }
    # spin all incomplete once
    for ($k = count($q = &$this->queue); $i < $k; ++$i)
    {
      if ($s[$i] === 0 && ($x = $q[$i]->complete()))
      {
        $this->result->group($x);
        if (($s[$i] = $x->ok ? 1 : -1) === -1) {
          return $this->spinBreak();# upon failure
        }
      }
    }
    return in_array(0, $s, true);
  }
}
# }}}
# conditional actions
class PromiseActionCatch extends PromiseAction # {{{
{
  function __construct(
    public object $next
  ) {}
  function stop(): ?object
  {
    if ($this->result->ok) {
      return null;
    }
    return ($this->next)(
      $this->result->confirmGroup('catch')
    );
  }
}
# }}}
class PromiseActionOk extends PromiseAction # {{{
{
  function __construct(
    public object $next
  ) {}
  function stop(): ?object
  {
    return $this->result->ok
      ? $this->next
      : null;
  }
}
# }}}
class PromiseActionOkF extends PromiseActionF # {{{
{
  function stop(): ?object
  {
    return $this->result->ok
      ? ($this->func)($this->result)
      : null;
  }
}
# }}}
class PromiseActionOkFn extends PromiseActionFn # {{{
{
  function stop(): ?object
  {
    return $this->result->ok
      ? ($this->func)($this->result, ...$this->arg)
      : null;
  }
}
# }}}
class PromiseActionOkCall extends PromiseActionCall # {{{
{
  function stop(): ?object
  {
    return $this->result->ok
      ? ($this->func)(...$this->arg)
      : null;
  }
}
# }}}
###
