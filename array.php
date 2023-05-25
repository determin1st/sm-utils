<?php declare(strict_types=1);
namespace SM;
use # {{{
  JsonSerializable,ArrayAccess,Iterator,Stringable,
  SyncEvent,SyncReaderWriter,SyncSharedMemory,
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
  ### CURL
  curl_init,curl_setopt_array,curl_exec,
  curl_errno,curl_error,curl_strerror,curl_close,
  curl_multi_init,curl_multi_add_handle,curl_multi_exec,
  curl_multi_select,curl_multi_strerror,
  curl_multi_info_read,curl_multi_remove_handle,
  curl_multi_close,
  ### misc
  proc_open,is_resource,proc_get_status,proc_terminate,
  getmypid,ob_start,ob_get_length,ob_flush,ob_end_clean,
  pack,unpack,time,hrtime,sleep,usleep,
  min,max,pow;
# }}}
interface Transactor # {{{
{
  function transaction(): bool;
  function rollback(): void;
  function commit(): void;
}
# }}}
class ArrayNode # {{{
  implements ArrayAccess, JsonSerializable, Transactor
{
  # constructor {{{
  public $brr,$count = 0,$changed = false;
  function __construct(
    public array    $arr,
    public int      $limit  = 0,
    public ?object  $parent = null,
    public int      $depth  = 0
  ) {
    $this->restruct($limit);
  }
  # }}}
  function __debugInfo(): array # {{{
  {
    return [
      'limit' => $this->limit,
      'array' => $this->arr,
    ];
  }
  # }}}
  function restruct(int $limit): self # {{{
  {
    # check growth possibility
    if ($this->limit > $limit) {
      return $this;
    }
    # grow
    $this->limit = $limit;
    if (($this->count = count($this->arr)) &&
        ($limit = $limit - 1) >= 0)
    {
      $depth = $this->depth + 1;
      foreach ($this->arr as $k => &$v)
      {
        if (is_array($v)) {
          $v = new self($v, $limit, $this, $depth);
        }
      }
    }
    return $this;
  }
  # }}}
  function change(?self $node = null): self # {{{
  {
    if ($this->brr === null && $this->parent) {
      $this->parent->change($node ?? $this);
    }
    else {
      $this->changed = true;
    }
    return $this;
  }
  # }}}
  # [] access {{{
  function offsetExists(mixed $k): bool {
    return isset($this->arr[$k]);
  }
  function offsetGet(mixed $k): mixed {
    return $this->arr[$k] ?? null;
  }
  function offsetSet(mixed $k, mixed $v): void
  {
    $set = isset($this->arr[$k]);
    if ($v === null)
    {
      if ($set)
      {
        unset($this->arr[$k]);
        $this->count--;
        $this->change();
      }
    }
    elseif (!$set || $v !== $this->arr[$k])
    {
      if (is_array($v) && ($limit = $this->limit))
      {
        $this->arr[$k] = new self(
          $v, $limit - 1, $this, $this->depth + 1
        );
      }
      else {
        $this->arr[$k] = $v;
      }
      $set || $this->count++;
      $this->change();
    }
  }
  function offsetUnset(mixed $k): void {
    $this->offsetSet($k, null);
  }
  # }}}
  # transactor {{{
  function transaction(): void
  {
    if ($this->brr === null)
    {
      $this->brr = $this->arr;
      return true;
    }
    return false;
  }
  function rollback(): void
  {
    if ($this->brr !== null)
    {
      if ($this->changed)
      {
        $this->arr = $this->brr;
        $this->brr = null;
        $this->restruct($this->limit);
        $this->changed = false;
        $this->change();
      }
      else {
        $this->brr = null;
      }
    }
  }
  function commit(): void
  {
    if ($this->brr !== null)
    {
      $this->brr = null;
      if ($this->changed)
      {
        $this->changed = false;
        $this->change();
      }
    }
  }
  # }}}
  function &jsonSerialize(): array # {{{
  {
    # cleanup and reset flags
    $this->filterEmpty();
    $this->changed = false;
    return $this->arr;
  }
  # }}}
  function isEmpty(): bool # {{{
  {
    foreach ($this->arr as $k => &$v)
    {
      if (!is_object($v) ||
          !($v instanceof self) ||
          !$v->isEmpty())
      {
        return false;
      }
    }
    return true;
  }
  # }}}
  function indexOfKey(string|int $k): int # {{{
  {
    # prepare
    if (($c = $this->count) === 0) {
      return -1;
    }
    $a = &$this->arr;
    $i = 0;
    # search key index
    reset($a);
    while ($k !== strval(key($a))) {
      $i++; next($a);
    }
    # restore pointer
    $j = $i;
    while ($j--) {
      prev($a);
    }
    # complete
    return $i;
  }
  # }}}
  function indexOfValue(mixed &$value): int # {{{
  {
    $i = 0;
    foreach ($this->arr as &$v)
    {
      if ($v === $value) {
        return $i;
      }
      $i++;
    }
    return -1;
  }
  # }}}
  function indexOfArray(string $k, int|string $v): int # {{{
  {
    $i = 0;
    foreach ($this->arr as &$a)
    {
      if ($a[$k] === $v) {
        return $i;
      }
      $i++;
    }
    return -1;
  }
  # }}}
  function prepend(mixed $value): self # {{{
  {
    array_unshift($this->arr, $value);
    $this->count++;
    $this->change();
    return $this;
  }
  # }}}
  function delete(mixed $value): self # {{{
  {
    if (($i = $this->indexOfValue($value)) >= 0)
    {
      array_splice($this->arr, $i, 1);
      $this->count--;
      $this->change();
    }
    return $this;
  }
  # }}}
  function replace(mixed $v0, mixed $v1): self # {{{
  {
    if (($i = $this->indexOfValue($v0)) >= 0)
    {
      $this->arr[$i] = $v1;
      $this->change();
    }
    return $this;
  }
  # }}}
  function obtain(string $k): object # {{{
  {
    $a = &$this->arr;
    if (isset($a[$k]))
    {
      if (is_object($a[$k])) {
        return $a[$k];
      }
    }
    else
    {
      $a[$k] = [];
      $this->count++;
    }
    return $a[$k] = new self(
      $a[$k], 0, $this, $this->depth + 1
    );
  }
  # }}}
  function import(array &$a): void # {{{
  {
    foreach ($this->arr as $k => &$v)
    {
      if (isset($a[$k]))
      {
        if (is_object($v)) {
          $v->import($a[$k]);
        }
        else {
          $v = $a[$k];
        }
      }
    }
  }
  # }}}
  function filter(callable $f): int # {{{
  {
    # iterate, invoke checker and remove items
    $i = 0;
    $j = $this->count;
    foreach ($this->arr as $k => &$v)
    {
      if ($f($v, $i, $k))
      {
        array_splice($this->arr, $i, 1);
        $j--;
      }
      else {
        $i++;
      }
    }
    # determine filtered items count
    if (($i = $this->count - $j) > 0)
    {
      # update count and trigger change
      $this->count = $j;
      $this->change();
    }
    return $i;
  }
  # }}}
  function filterEmpty(): int # {{{
  {
    static $EMPTY = function(mixed &$v): bool
    {
      return (
        is_object($v) && ($v instanceof ArrayNode) &&
        $v->isEmpty()
      );
    };
    return $this->filter($EMPTY);
  }
  # }}}
  function each(callable $f): int # {{{
  {
    $i = 0;
    foreach ($this->arr as $k => &$v)
    {
      if (!$f($v, $i, $k)) {
        return $i;
      }
      $i++;
    }
    return -1;
  }
  # }}}
  function set(array $a): self # {{{
  {
    return $this->setRef($a);
  }
  # }}}
  function setRef(array &$a): self # {{{
  {
    $this->arr = &$a;
    return $this
      ->restruct($this->limit)
      ->change();
  }
  # }}}
  function keys(): array # {{{
  {
    return array_string_keys($this->arr);
  }
  # }}}
  function &slice(# {{{
    int $idx = 0, ?int $len = null
  ):array
  {
    $a = array_slice($this->arr, $idx, $len);
    return $a;
  }
  # }}}
}
# }}}
class ArrayStackValue implements ArrayAccess # {{{
{
  # constructor {{{
  private $stack = [],$value;
  private function __construct()
  {}
  static function new(mixed $value = ''): self
  {
    $o = new self();
    $o->value = $value;
    return $o;
  }
  # }}}
  function __isset(string $k): bool # {{{
  {
    return $this->seek($k) >= 0;
  }
  # }}}
  function __get(string $k): mixed # {{{
  {
    return (($i = $this->seek($k)) >= 0)
      ? $this->stack[$i][$k]
      : null;
  }
  # }}}
  # [] access {{{
  function offsetExists(mixed $k): bool {
    return $this->seek($k) >= 0;
  }
  function offsetGet(mixed $k): mixed
  {
    return (($i = $this->seek($k)) >= 0)
      ? $this->stack[$i][$k]
      : $this->value;
  }
  function offsetSet(mixed $k, mixed $v): void
  {}
  function offsetUnset(mixed $k): void
  {}
  # }}}
  function seek(string $k): int # {{{
  {
    for ($i = 0, $j = count($this->stack); $i < $j; ++$i)
    {
      if (isset($this->stack[$i][$k])) {
        return $i;
      }
    }
    return -1;
  }
  # }}}
  function push(array $a): self # {{{
  {
    return $this->pushRef($a);
  }
  # }}}
  function pushRef(array &$a): self # {{{
  {
    array_unshift($this->stack, null);
    $this->stack[0] = &$a;
    return $this;
  }
  # }}}
  function pop(): self # {{{
  {
    array_shift($this->stack);
    return $this;
  }
  # }}}
}
# }}}
class ArrayBaseSpec implements ArrayAccess # {{{
{
  # constructor {{{
  private function __construct(
    private object $base,
    private object $spec
  ) {}
  static function new(object $base, object $spec): self
  {
    $o = new self();
    $o->value = $value;
    return $o;
  }
  # }}}
  function __construct(# {{{
    private object $base, # ~ArrayNode/Union
    private object $spec  # ~ArrayNode
  ) {}
  # }}}
  # [] access {{{
  function offsetExists(mixed $k): bool {
    return isset($this->base[$k]);
  }
  function offsetGet(mixed $k): mixed
  {
    return $this->spec[$k]
      ?? $this->base[$k]
      ?? null;
  }
  function offsetSet(mixed $k, mixed $v): void {
    $this->spec[$k] = $v;
  }
  function offsetUnset(mixed $k): void {
    $this->spec[$k] = null;
  }
  # }}}
  function obtain(string $k): object # {{{
  {
    return $this->spec->obtain($k);
  }
  # }}}
}
# }}}
###
