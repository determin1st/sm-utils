<?php declare(strict_types=1);
namespace SM;
use # {{{
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
  # constuctors {{{
  static function skip(): self {
    return new self(0);
  }
  static function info(string ...$msg): self {
    return new self(0, $msg);
  }
  static function warn(string ...$msg): self {
    return new self(1, $msg);
  }
  static function fail(string ...$msg): self {
    return new self(2, $msg);
  }
  static function failFn(string ...$msg): self
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
  static function from(object $e): self
  {
    return ($e instanceof self)
      ? $e : new self(3, [], $e);
  }
  function __construct(
    public int     $level = 0,
    public array   $msg   = [],
    public mixed   $value = null,
    public ?object $next  = null
  ) {
    parent::__construct('', -1);
  }
  # }}}
  # util {{{
  # }}}
  function __debugInfo(): array # {{{
  {
    return [
      'level' => self::E_LEVEL[$this->level],
      'msg'   => implode('·', $this->msg),
      'next'  => $this->next
    ];
  }
  # }}}
  function levelMax(int $limit = 3): int # {{{
  {
    $a = $this->level;
    $b = $this->next;
    while ($b && $a < $limit)
    {
      if ($b->level > $a) {
        $a = $b->level;
      }
      $b = $b->next;
    }
    return $a;
  }
  # }}}
  function getMsg(string $default = ''): string # {{{
  {
    return $this->msg
      ? implode(' ', $this->msg)
      : $default;
  }
  # }}}
  # is {{{
  static function is(mixed $e): bool {
    return is_object($e) && ($e instanceof self);
  }
  function isFatal(): bool {
    return $this->level > 2;
  }
  function isError(): bool {
    return $this->level > 1;
  }
  function isWarning(): bool {
    return $this->level === 1;
  }
  function isInfo(): bool {
    return $this->level < 1;
  }
  # }}}
  # has {{{
  function hasError(): bool {
    return $this->levelMax() > 1;
  }
  function hasIssue(): bool {
    return $this->levelMax() > 0;
  }
  # }}}
  function count(): int # {{{
  {
    for ($x = 1, $e = $this->next; $e; $e = $e->next) {
      $x++;
    }
    return $x;
  }
  # }}}
  function last(): self # {{{
  {
    $a = $this;
    while ($b = $a->next) {
      $a = $b;
    }
    return $a;
  }
  # }}}
  # ? {{{
  function lastSet(?self $e = null): self
  {
    $e && ($this->last()->next = $e);
    return $this;
  }
  function nextSet(self $e): self
  {
    $this->next = $e->lastSet($this->next);
    return $this;
  }
  function nextFrom(object $e): self {
    return $this->nextSet(self::from($e));
  }
  function firstSet(self $e): self {
    return $e->lastSet($this);
  }
  function firstMsg(string ...$msg): self
  {
    $e = new self($this->levelMax(2), $msg);
    return $this->firstSet($e);
  }
  # }}}
}
###
