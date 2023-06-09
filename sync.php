<?php declare(strict_types=1);
namespace SM;
# requirements {{{
use
  SyncSharedMemory,SyncSemaphore,SyncEvent,
  ArrayAccess,Throwable;
use function
  sys_get_temp_dir,file_exists,touch,preg_match,intval,
  strval,strlen,substr,str_repeat,pack,unpack,ord,hrtime,
  array_shift,array_unshift,is_array,is_int,is_string;
use function SM\{
  class_name,class_basename,dir_exists,dir_file_path,
  file_persist,file_unlink,file_touch,hrtime_delta_ms,
  hrtime_expired, proc_id
};
use const
  DIRECTORY_SEPARATOR;
###
require_once __DIR__.DIRECTORY_SEPARATOR.'error.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'functions.php';
# }}}
class SyncBuffer # {{{
{
  # constructor {{{
  static function new(string $id, int $size): object
  {
    try
    {
      # construct
      $buf = new self($id, $size,
        new SyncSharedMemory($id, 4 + $size)
      );
      # clear at first encounter
      if ($buf->mem->first()) {
        $buf->_writeSize(0);
      }
      return $buf;
    }
    catch (Throwable $e)
    {
      return ErrorEx::set($e, ErrorEx::fail(
        class_basename(static::class), __FUNCTION__
      ));
    }
  }
  protected function __construct(
    public string $id,
    public int    $sizeMax,
    public object $mem
  ) {}
  # }}}
  # hlp {{{
  protected function &_readData(): string # {{{
  {
    # read content size
    $data = '';
    if (($i = $this->_readSize()) === 0) {
      return $data;
    }
    if ($i === -1) {
      $i = $this->sizeMax;
    }
    # read content
    $data = $this->mem->read(4, $i);
    if ($i !== ($j = strlen($data)))
    {
      throw ErrorEx::fail(
        'SyncSharedMemory::read',
        'incorrect result length='.$j.' <> '.$i
      );
    }
    return $data;
  }
  # }}}
  protected function _readSize(): int # {{{
  {
    # read size bytes
    $s = $this->mem->read(0, 4);
    if (($i = strlen($s)) !== 4)
    {
      throw ErrorEx::fail(
        'SyncSharedMemory::read',
        'incorrect result length='.$i.' <> 4'
      );
    }
    # convert into integer
    if (!is_array($a = unpack('l', $s)) ||
        !isset($a[1]))
    {
      throw ErrorEx::fail(
        'unable to upack the value=['.$s.']'
      );
    }
    # check in range
    if (($i = $a[1]) < -1 || $i > $this->sizeMax)
    {
      throw ErrorEx::fail(
        'incorrect value='.$i.
        ', not in range=[-1,'.$this->sizeMax.']'
      );
    }
    return $i;
  }
  # }}}
  protected function _writeData(string &$data, int $offs): int # {{{
  {
    # prepare
    $i = strlen($data);
    $j = $this->sizeMax - $offs;# free space
    $k = $offs + 4;# real offset
    # check overflow
    if ($i > $j)
    {
      # write chunk and set overflow
      $n = $this->mem->write(substr($data, 0, $j), $k);
      $i = $j;# bytes written
      $k = -1;# buffer size (overflow)
    }
    else
    {
      # write all
      $n = $this->mem->write($data, $k);
      $k = $offs + $i;
    }
    # check bytes written
    if ($n !== $i)
    {
      throw ErrorEx::fail(
        'SyncSharedMemory::write',
        'incorrect result length='.$n.' <> '.$i
      );
    }
    # write size and complete
    $this->_writeSize($k);
    return $n;
  }
  # }}}
  protected function _writeSize(int $size): bool # {{{
  {
    $n = $this->mem->write(pack('l', $size), 0);
    if ($n !== 4)
    {
      throw ErrorEx::fail(
        'SyncSharedMemory::write',
        'incorrect result length='.$n.' <> 4'
      );
    }
    return true;
  }
  # }}}
  protected function _append(string &$data): int # {{{
  {
    # check empty
    if ($data === '') {
      return 0;
    }
    # check current size of the buffer
    if (($i = $this->_readSize()) === -1)
    {
      throw ErrorEx::fail(
        'unable to append, buffer is overflowed'
      );
    }
    # complete
    return $this->_writeData($data, $i);
  }
  # }}}
  # }}}
  function &read(?object &$error=null): string # {{{
  {
    if (($data = &$this->readShared($error)) !== '') {
      $this->clear($error);
    }
    return $data;
  }
  # }}}
  function &readShared(?object &$error=null): string # {{{
  {
    try {
      $data = &$this->_readData();
    }
    catch (Throwable $e)
    {
      $data = '';
      ErrorEx::set($error, $e);
    }
    return $data;
  }
  # }}}
  function size(?object &$error=null): int # {{{
  {
    try
    {
      return ~($i = $this->_readSize())
        ? $i : $this->sizeMax;
    }
    catch (Throwable $e) {
      return ErrorEx::set($error, $e)->val(-1);
    }
  }
  # }}}
  function write(string &$data, ?object &$error=null): int # {{{
  {
    try
    {
      return ($data === '')
        ? $this->_writeSize(0)
        : $this->_writeData($data, 0);
    }
    catch (Throwable $e)
    {
      return ErrorEx
        ::set($error, $e)
        ->val(-1);
    }
  }
  # }}}
  function append(string &$data, ?object &$error=null): int # {{{
  {
    try {
      return $this->_append($data);
    }
    catch (Throwable $e) {
      return ErrorEx::set($error, $e)->val(-1);
    }
  }
  # }}}
  function clear(?object &$error=null): bool # {{{
  {
    try {
      return $this->_writeSize(0);
    }
    catch (Throwable $e) {
      return ErrorEx::set($error, $e)->val(false);
    }
  }
  # }}}
}
# }}}
abstract class ASyncFlag # {{{
{
  # constructor {{{
  protected function __construct(
    public string $id,
    public object $event,
    public bool   $state = false
  ) {
    $this->restruct();
  }
  protected function restruct()
  {}
  function __destruct() {
    $this->clear();
  }
  # }}}
  # hlp {{{
  protected function eventSet(bool $shared): bool # {{{
  {
    # check already set
    if ($this->get()) {
      return true;
    }
    # set event
    if (!$this->event->fire()) {
      throw ErrorEx::fail('SyncEvent::fire');
    }
    # set exclusive state
    if (!$shared) {
      $this->state = true;
    }
    # success
    return true;
  }
  # }}}
  protected function eventClear(bool $shared): bool # {{{
  {
    # check not set by this instance
    if (!$this->state)
    {
      # check not set
      if (!$this->event->wait(0)) {
        return true;# no need to clear
      }
      # set by another instance,
      # deny exclusive access
      if (!$shared) {
        return false;
      }
    }
    # clear
    if (!$this->event->reset()) {
      throw ErrorEx::fail('SyncEvent::reset');
    }
    # complete
    $this->state = false;
    return true;
  }
  # }}}
  protected function _set(# {{{
    ?object &$error, bool $shared
  ):bool
  {
    try {
      return $this->eventSet($shared);
    }
    catch (Throwable $e) {
      return ErrorEx::set($error, $e)->val(false);
    }
  }
  # }}}
  protected function _clear(# {{{
    ?object &$error, bool $shared
  ):bool
  {
    try {
      return $this->eventClear($shared);
    }
    catch (Throwable $e) {
      return ErrorEx::set($error, $e)->val(false);
    }
  }
  # }}}
  # }}}
  # api {{{
  function sync(): bool {
    return $this->state = $this->event->wait(0);
  }
  function get(): bool {
    return $this->state || $this->event->wait(0);
  }
  function getShared(): bool {
    return $this->event->wait(0);
  }
  function set(?object &$error=null): bool {
    return $this->_set($error, false);
  }
  function setShared(?object &$error=null): bool {
    return $this->_set($error, true);
  }
  function clear(?object &$error=null): bool {
    return $this->_clear($error, false);
  }
  function clearShared(?object &$error=null): bool {
    return $this->_clear($error, true);
  }
  # }}}
}
# }}}
class SyncFlag extends ASyncFlag # {{{
{
  static function new(string $id): object
  {
    try
    {
      return new static(
        $id, new SyncEvent($id, 1, 0)
      );
    }
    catch (Throwable $e)
    {
      return ErrorEx::set($e, ErrorEx::fail(
        class_basename(static::class), __FUNCTION__
      ));
    }
  }
}
# }}}
class SyncFlagMaster extends SyncFlag # {{{
{
  protected function restruct(): void
  {
    static $E='master has already been set';
    if ($this->get()) {
      throw ErrorEx::fail($E);
    }
    if (!$this->set($error)) {
      throw $error;
    }
  }
}
# }}}
class SyncFlagFile extends ASyncFlag # {{{
{
  public string $file;
  static function new(string $id, string $dir): object # {{{
  {
    try
    {
      $file = dir_file_path($dir, $id.'.flag');
      if (!dir_exists($file)) {
        throw ErrorEx::fail('incorrect file path', $file);
      }
      $flag = new static(
        $id, new SyncEvent($id, 1, 0)
      );
      $flag->file = $file;
      return $flag;
    }
    catch (Throwable $e)
    {
      return ErrorEx::set($e, ErrorEx::fail(
        class_basename(static::class), __FUNCTION__
      ));
    }
  }
  # }}}
  protected function _set(# {{{
    ?object &$error, bool $shared
  ):bool
  {
    try
    {
      return (
        $this->eventSet($shared) &&
        file_touch($this->file, $error)
      );
    }
    catch (Throwable $e) {
      return ErrorEx::set($error, $e)->val(false);
    }
  }
  # }}}
  protected function _clear(# {{{
    ?object &$error, bool $shared
  ):bool
  {
    try
    {
      return (
        $this->eventClear($shared) &&
        file_unlink($this->file, $error)
      );
    }
    catch (Throwable $e) {
      return ErrorEx::set($error, $e)->val(false);
    }
  }
  # }}}
  # api
  function persist(): bool {
    return file_persist($this->file);
  }
}
# }}}
class SyncFlagFileMaster extends SyncFlagFile # {{{
{
  protected function restruct(): void
  {
    static $E='master has already been set';
    # check
    if ($this->get() && $this->persist()) {
      throw ErrorEx::fail($E, $this->file);
    }
    # set
    if (!$this->set($error)) {
      throw $error;
    }
  }
}
# }}}
class SyncNum implements ArrayAccess # {{{
{
  # constructor {{{
  const TIMEWAIT=5000;
  static function new(
    string $id, bool $guarded=false,
    int $count=1, ?object $callback=null
  ):object
  {
    try
    {
      $guard = $guarded
        ? new SyncSemaphore($id.'-sem', 1, 0)
        : null;
      return new self(
        $id, $count, new SyncSharedMemory($id, 4*$count),
        $callback, $guard
      );
    }
    catch (Throwable $e)
    {
      return ErrorEx::set($e, ErrorEx::fail(
        class_basename(static::class), __FUNCTION__
      ));
    }
  }
  protected function __construct(
    public string  $id,
    public int     $count,
    public object  $mem,
    public ?object $callback,
    public ?object $guard,
    public bool    $guarded = false
  ) {
    if ($mem->first())
    {
      $guard && $guard->unlock();
      $this->memReset();
    }
  }
  function __destruct() {
    $this->unlock();
  }
  # }}}
  # hlp {{{
  function guardSet(): bool # {{{
  {
    static $E='SyncSemaphore::lock';
    if ($this->guard && !$this->guarded)
    {
      if (!$this->guard->lock(self::TIMEWAIT)) {
        throw ErrorEx::fail($E, 'timeout');
      }
      $this->guarded = true;
    }
    return true;
  }
  # }}}
  function guardClear(): bool # {{{
  {
    static $E='SyncSemaphore::unlock';
    if ($this->guarded)
    {
      if (!$this->guard->unlock()) {
        throw ErrorEx::fail($E);
      }
      $this->guarded = false;
    }
    return true;
  }
  # }}}
  protected function memRead(int $k): int # {{{
  {
    static $E0='SyncSharedMemory::read(4)';
    static $E1='unable to unpack';
    # read the buffer
    $a = $this->mem->read(4*$k, 4);
    if (($n = strlen($a)) !== 4) {
      throw ErrorEx::fail($E0, $n);
    }
    # convert into integer
    if (!($b = unpack('l', $a)) || !isset($b[1]))
    {
      $a = (
        ord($a[0]).','.ord($a[1]).','.
        ord($a[2]).','.ord($a[3])
      );
      throw ErrorEx::fail($E1, '['.$a.']');
    }
    return $b[1];
  }
  # }}}
  protected function memWrite(int $k, int $n): void # {{{
  {
    static $E0='unable to pack';
    static $E1='SyncSharedMemory::write(4)';
    # convert into string
    if (strlen($a = pack('l', $n)) !== 4) {
      throw ErrorEx::fail($E0, $n);
    }
    # write
    if (($n = $this->mem->write($a, 4*$k)) !== 4) {
      throw ErrorEx::fail($E1, $n);
    }
  }
  # }}}
  protected function memReset(): void # {{{
  {
    static $ERR='SyncSharedMemory::write';
    $n = 4 * $this->count;
    $a = str_repeat("\x00", $n);
    if ($this->mem->write($a, 0) !== $n) {
      throw ErrorEx::fail($ERR);
    }
  }
  # }}}
  # }}}
  # [] access {{{
  function offsetExists(mixed $k): bool {
    return $k >= 0 && $k < $this->count;
  }
  function offsetGet(mixed $k): mixed {
    return $this->memRead($k);
  }
  function offsetSet(mixed $k, mixed $v): void {
    $this->memWrite($k, $v);
  }
  function offsetUnset(mixed $k): void {
    $this->memWrite($k, 0);
  }
  # }}}
  # api
  function lock(?object &$error=null): bool # {{{
  {
    try {
      return $this->guardSet();
    }
    catch (Throwable $e) {
      return ErrorEx::set($error, $e)->val(false);
    }
  }
  # }}}
  function unlock(?object &$error=null): bool # {{{
  {
    try {
      return $this->guardClear();
    }
    catch (Throwable $e) {
      return ErrorEx::set($error, $e)->val(false);
    }
  }
  # }}}
  function memGet(int $k, ?object &$error): int # {{{
  {
    try {
      return $this->memRead($k);
    }
    catch (Throwable $e) {
      return ErrorEx::set($error, $e)->val(-1);
    }
  }
  # }}}
  function memSet(int $n, int $k, ?object &$error): bool # {{{
  {
    $ok = true;
    try
    {
      if ($f = $this->callback) {
        $f($this->memRead($k), $n, $k);
      }
      $this->memWrite($k, $n);
    }
    catch (Throwable $e)
    {
      # ignore a skip (from the callback)
      if (!ErrorEx::is($e) || $e->level)
      {
        ErrorEx::set($error, $e);
        $ok = false;
      }
    }
    return $ok;
  }
  # }}}
  function get(# {{{
    int $k=0, ?object &$error=null
  ):int
  {
    try
    {
      $this->guardSet();
      $n = $this->memRead($k);
    }
    catch (Throwable $e)
    {
      ErrorEx::set($error, $e);
      $n = -1;
    }
    return $this->unlock($error) ? $n : -1;
  }
  # }}}
  function set(# {{{
    int $n, int $k=0, ?object &$error=null
  ):bool
  {
    if (!$this->lock($error)) {
      return false;
    }
    $a = $this->memSet($n, $k, $error);
    $b = $this->unlock($error);
    return $a && $b;
  }
  # }}}
  function add(# {{{
    int $x, int $k=0, ?object &$error=null
  ):bool
  {
    $ok = true;
    try
    {
      $this->guardSet();
      $n = $this->memRead($k);
      $x = $n + $x;
      if ($f = $this->callback) {
        $f($n, $x, $k);
      }
      $this->memWrite($k, $x);
      $this->guardClear();
    }
    catch (Throwable $e)
    {
      # ignore a skip (from the callback)
      if (!ErrorEx::is($e) || $e->level)
      {
        ErrorEx::set($error, $e);
        $ok = false;
      }
      if (!$this->unlock($error)) {
        $ok = false;
      }
    }
    return $ok;
  }
  # }}}
}
# }}}
abstract class ASyncLock # {{{
{
  # constructor {{{
  protected function __construct(
    public string $id,
    public int    $max,
    public object $num,
    public object $sem,
    public int    $state = 0
  ) {
    # initialize
    $num->callback = $this->onChange(...);
    if ($num->mem->first())
    {
      while ($sem->unlock()) {}
      $this->onChange(1, 0);
    }
    $this->restruct();
  }
  protected function restruct(): void
  {}
  function __destruct() {
    $this->clear();
  }
  # }}}
  # hlp {{{
  protected function onChange(int $n0, int $n1): void # {{{
  {
    if ($n1 < 0 || $n1 > $this->max)
    {
      throw ErrorEx::fail(
        'incorrect lock count='.$n1.
        ', not in range=[0,'.$this->max.']'
      );
    }
  }
  # }}}
  protected function semSet(# {{{
    ?object &$error, bool $shared, int $wait
  ):int
  {
    static $E1='cannot mix exclusive and shared behaviour';
    # check proper invocation
    if ($shared)
    {
      if ($this->state)
      {
        ErrorEx::set($error, ErrorEx::fail($E1));
        return -1;
      }
    }
    elseif ($this->state) {# already locked
      return $this->state;
    }
    # try to lock
    if (!$this->sem->lock($wait)) {
      return 0;
    }
    # increment
    if (!$this->num->add(1, 0, $error))
    {
      $this->sem->unlock();
      return -1;
    }
    # set exclusive
    if (!$shared) {
      $this->state = 1;
    }
    return 1;
  }
  # }}}
  protected function semClear(# {{{
    ?object &$error, bool $shared
  ):bool
  {
    static $E1='exclusive lock cannot be cleared as shared';
    static $E2='SyncSemaphore::unlock';
    # check proper invocation
    if ($shared)
    {
      if ($this->state)
      {
        ErrorEx::set($error, ErrorEx::fail($E1));
        return false;
      }
    }
    elseif (!$this->state) {
      return true;# not locked
    }
    # decrement
    if (!$this->num->add(-1, 0, $error)) {
      return false;
    }
    # unlock
    if (!$this->sem->unlock())
    {
      ErrorEx::set($error, ErrorEx::fail($E2));
      $this->num->add(1, 0, $error);
      return false;
    }
    # clear exclusive
    if (!$shared) {
      $this->state = 0;
    }
    # complete
    return true;
  }
  # }}}
  protected function _set(# {{{
    ?object &$error, bool $shared, int $wait
  ):int
  {
    try {
      return $this->semSet($error, $shared, $wait);
    }
    catch (Throwable $e) {
      return ErrorEx::set($error, $e)->val(-1);
    }
  }
  # }}}
  protected function _clear(# {{{
    ?object &$error, bool $shared
  ):bool
  {
    try {
      return $this->semClear($error, $shared);
    }
    catch (Throwable $e) {
      return ErrorEx::set($error, $e)->val(false);
    }
  }
  # }}}
  # }}}
  # api {{{
  function sync(?object &$error=null): int
  {
    if (($n = $this->num->get(0, $error)) >= 0) {
      $this->state = $n;
    }
    return $n;
  }
  function get(?object &$error=null): int {
    return $this->state ?: $this->num->get(0, $error);
  }
  function getShared(?object &$error=null): int {
    return $this->num->get(0, $error);
  }
  function set(?object &$error=null): int {
    return $this->_set($error, false, 0);
  }
  function setWait(int $ms=-1, ?object &$error=null): int {
    return $this->_set($error, false, $ms);
  }
  function setShared(?object &$error=null): int {
    return $this->_set($error, true, 0);
  }
  function setSharedWait(int $ms=-1, ?object &$error=null): int {
    return $this->_set($error, true, $ms);
  }
  function clear(?object &$error=null): bool {
    return $this->_clear($error, false);
  }
  function clearShared(?object &$error=null): bool {
    return $this->_clear($error, true);
  }
  # }}}
}
# }}}
class SyncLock extends ASyncLock # {{{
{
  static function new(string $id, int $max=1): object
  {
    try
    {
      $id0 = $id.'-num';
      return new static($id, $max,
        ErrorEx::peep(SyncNum::new($id0, ($max > 1))),
        new SyncSemaphore($id, $max, 0)
      );
    }
    catch (Throwable $e)
    {
      return ErrorEx::set($e, ErrorEx::fail(
        class_basename(static::class), __FUNCTION__
      ));
    }
  }
}
# }}}
class SyncLockMaster extends SyncLock # {{{
{
  protected function restruct(): void
  {
    if ($this->sync($e) && !$this->clear($e)) {
      throw $e;
    }
  }
}
# }}}
class SyncLockFile extends ASyncLock # {{{
{
  public string $file;
  static function new(# {{{
    string $id, string $dir, int $max=1
  ):object
  {
    try
    {
      $file = dir_file_path($dir, $id.'.lock');
      if (!dir_exists($file)) {
        throw ErrorEx::fail('incorrect file path', $file);
      }
      $id0  = $id.'-num';
      $lock = new static($id, $max,
        ErrorEx::peep(SyncNum::new($id0, ($max > 1))),
        new SyncSemaphore($id, $max, 0)
      );
      $lock->file = $file;
      return $lock;
    }
    catch (Throwable $e)
    {
      return ErrorEx::set($e, ErrorEx::fail(
        class_basename(static::class), __FUNCTION__
      ));
    }
  }
  # }}}
  protected function restruct(): void # {{{
  {
    # check the source of truth
    if (!$this->persist() &&
        $this->getShared($e) &&
        !$this->clearShared($e))
    {
      throw $e;
    }
  }
  # }}}
  protected function onChange(int $n0, int $n1): void # {{{
  {
    if ($n1 < 0 || $n1 > $this->max)
    {
      throw ErrorEx::fail(
        'incorrect lock count='.$n1.
        ', not in range=[0,'.$this->max.']'
      );
    }
    if ((!$n0 && !file_touch($this->file, $e)) ||
        (!$n1 && !file_unlink($this->file, $e)))
    {
      throw $e;
    }
  }
  # }}}
  function persist(): bool {
    return file_persist($this->file);
  }
}
# }}}
class SyncLockFileMaster extends SyncLockFile # {{{
{
  protected function restruct(): void
  {
    # prepare
    if (($n = $this->sync($e)) < 0) {
      throw $e;
    }
    # sync with the source of truth
    if ($this->persist())
    {
      if ($n === 0 && !$this->set($e)) {
        throw $e;
      }
    }
    else
    {
      if ($n !== 0 && !$this->clear($e)) {
        throw $e;
      }
    }
  }
}
# }}}
# Readers-Writers
abstract class SyncReaderWriter # {{{
{
  const
    DEF_SIZE = 1000,# bytes
    MAX_SIZE = 1000000,# bytes
    MIN_TIMEWAIT = 1000,# ms
    DEF_TIMEWAIT = 3*1000;# ms
  ###
  public int $time=0;
  abstract static function new(array $o): object;
  function __destruct() {
    $this->close();
  }
  static function getId(array &$o): string # {{{
  {
    static $EXP_ID='/^[a-z0-9-]{1,64}$/i';
    static $k='id';
    if (!isset($o[$k]) || ($id = $o[$k]) === '' ||
        !preg_match($EXP_ID, $id))
    {
      throw self::badOption($k);
    }
    return $id;
  }
  # }}}
  static function getDir(array &$o): string # {{{
  {
    static $k='dir';
    if (!isset($o[$k])) {
      return '';
    }
    if (!is_string($dir = $o[$k])) {
      throw self::badOption($k);
    }
    if ($dir === '') {
      $dir = sys_get_temp_dir();
    }
    return $dir;
  }
  # }}}
  static function getSize(array &$o): int # {{{
  {
    static $k='size';
    if (!isset($o[$k])) {
      return self::DEF_SIZE;
    }
    if (!is_int($i = $o[$k]) ||
        $i < 1 || $i > self::MAX_SIZE)
    {
      throw self::badOption($k);
    }
    return $i;
  }
  # }}}
  static function getTimeWait(array &$o): int # {{{
  {
    static $k='time-wait';
    if (!isset($o[$k])) {
      return self::DEF_TIMEWAIT;
    }
    if (!is_int($i = $o[$k]) ||
        $i < self::MIN_TIMEWAIT)
    {
      throw self::badOption($k);
    }
    return $i;
  }
  # }}}
  static function getInstanceFlag(array &$o): ?object # {{{
  {
    static $k='instance-flag';
    if (!isset($o[$k])) {
      return null;
    }
    if (!is_object($x = $o[$k]) ||
        !($x instanceof SyncFlag))
    {
      throw self::badOption($k0);
    }
    return $x;
  }
  # }}}
  static function getInstanceId(array &$o, string $id): string # {{{
  {
    static $k='instance-id';
    if (!isset($o[$k])) {
      return $id.'-'.proc_id();
    }
    if (!is_string($o[$k]) ||
        ($id = $o[$k]) === '')
    {
      throw self::badOption($k);
    }
    return $id;
  }
  # }}}
  static function getInstance(# {{{
    array &$o, string $id, string $dir, bool $master=false
  ):object
  {
    if ($x = self::getInstanceFlag($o)) {
      return $x;
    }
    return self::getFlag(
      self::getInstanceId($o, $id), $dir, $master
    );
  }
  # }}}
  static function getFlag(# {{{
    string $id, string $dir='', bool $master=false
  ):object
  {
    $flag = $dir
      ? ($master
        ? SyncFlagFileMaster::new($id, $dir)
        : SyncFlagFile::new($id, $dir))
      : ($master
        ? SyncFlagMaster::new($id)
        : SyncFlag::new($id));
    ###
    return ErrorEx::peep($flag);
  }
  # }}}
  static function getMasterFlag(# {{{
    string $id, string $dir
  ):object
  {
    return self::getFlag($id, $dir, true);
  }
  # }}}
  static function getLock(# {{{
    string $id, string $dir='', bool $master=false
  ):object
  {
    $lock = $dir
      ? ($master
        ? SyncLockFileMaster::new($id, $dir)
        : SyncLockFile::new($id, $dir))
      : ($master
        ? SyncLockMaster::new($id)
        : SyncLock::new($id));
    ###
    return ErrorEx::peep($lock);
  }
  # }}}
  static function getBoolean(# {{{
    array &$o, string $k, bool $default
  ):bool
  {
    return isset($o[$k])
      ? !!$o[$k] : $default;
  }
  # }}}
  static function badOption(string $k): object # {{{
  {
    return ErrorEx::fail('incorrect option: '.$k);
  }
  # }}}
  static function parseInfo(string &$s, ?object &$e): ?array # {{{
  {
    static $EXP_INFO = (
      '/^'.
      '([0-9]{1})'.         # case
      ':([a-z0-9-]{1,128})'.# id
      '(:(.+)){0,1}'.       # info
      '$/i'
    );
    if (!preg_match($EXP_INFO, $s, $a))
    {
      ErrorEx::set($e, ErrorEx::fail(
        'incorrect info ['.$s.']'
      ));
      return null;
    }
    return [intval($a[1]),$a[2],$a[4]??''];
  }
  # }}}
  protected function timeout(): bool # {{{
  {
    return hrtime_expired(
      $this->timeWait, $this->time
    );
  }
  # }}}
  # api
  function &read(?object &$error=null): ?string # {{{
  {
    $error = null;
    $data  = '';
    return $data;
  }
  # }}}
  function write(string &$data, ?object &$error=null): bool # {{{
  {
    $error = null;
    return true;
  }
  # }}}
  function flush(?object &$error=null): bool # {{{
  {
    $error = null;
    return true;
  }
  # }}}
  function close(?object &$error=null): bool # {{{
  {
    $error = null;
    return true;
  }
  # }}}
}
# }}}
class SyncExchange extends SyncReaderWriter # {{{
{
  # Exchange: one reader, one writer
  # constructor {{{
  static function new(array $o): object
  {
    try
    {
      # prepare
      $id   = self::getId($o);
      $size = self::getSize($o);
      $dir  = self::getDir($o);
      $id1  = $id.'-r';
      $id2  = $id.'-w';
      $id3  = $id.'-x';
      # construct
      return new static(
        $id, self::getTimeWait($o),
        self::getLock($id1, $dir),
        self::getLock($id2, $dir),
        ErrorEx::peep(SyncBuffer::new($id, $size)),
        ErrorEx::peep(SyncNum::new($id3, true, 2))
      );
    }
    catch (Throwable $e)
    {
      return ErrorEx::set($e, ErrorEx::fail(
        class_basename(static::class), __FUNCTION__
      ));
    }
  }
  protected function __construct(
    public string $id,
    public int    $timeWait,
    public object $reader,
    public object $writer,
    public object $data,
    # shared state structure:
    # [0]: total number of readers reading
    # [1]: data buffer content
    #  (0) empty, ready for (1) or (3)
    #  (1) request, ready for (2)
    #  (2) response
    #  (3) notification
    public object $state,
    public bool   $reading = false,
    public int    $pending = 0
  ) {}
  # }}}
  # hlp {{{
  protected function canWrite(?object &$error): bool # {{{
  {
    # check busy and try to lock
    if ($this->pending ||
        $this->reader->getShared($error) ||
        $this->writer->set($error) <= 0)
    {
      return false;# busy/failed
    }
    # operate
    while (($state = $this->state)->lock($error))
    {
      # get number of readers
      if (($n = $state->memGet(0, $error)) < 0) {
        break;
      }
      # exclude self
      if ($this->reading) {
        $n--;
      }
      # check no reader would read
      if ($n === 0)
      {
        $error = ErrorEx::skip();
        break;
      }
      # check data buffer is dirty
      if ($state->memGet(1, $error) !== 0) {
        break;
      }
      # stop reading
      if ($this->reading)
      {
        $state->memSet($n, 0, $error);
        $this->reading = false;
      }
      # positive, writer stays locked
      $state->unlock($error);
      return true;
    }
    # negative
    $state->unlock($error);
    $this->writer->clear($error);
    return false;
  }
  # }}}
  protected function dataWrite(# {{{
    string &$data, int $n, ?object &$error
  ):bool
  {
    # set data
    if ($this->data->write($data, $error) < 0 ||
        !$this->state->set($n, 1, $error))
    {
      return false;
    }
    # set employment
    $this->pending = match ($n) {
      1 => 2, # request  => waiting server response
      2 => 4, # response => waiting client confirmation
      3 => 5, # notice   => waiting server confirmation
    };
    # complete
    $this->time = hrtime(true);
    return true;
  }
  # }}}
  # }}}
  function write(string &$data, ?object &$error=null): bool # {{{
  {
    # prepare
    static $E1='incorrect invocation';
    static $E2='response already sent';
    static $E3='request already sent';
    $error = null;
    # operate
    switch ($this->pending) {
    case 0:# CLIENT => request(1)
      # check writable
      if (!$this->canWrite($error)) {
        break;
      }
      # write the request
      if (!$this->dataWrite($data, 1, $error))
      {
        $this->writer->clear($error);
        break;
      }
      # success
      return true;
    case 1:# SERVER => response(2)
      # get data buffer state
      if (($n = $this->state->get(1, $error)) < 0) {
        break;# failed
      }
      # check incorrect
      if ($n !== 1)
      {
        $error = ErrorEx::warn($E1, $E2);
        break;
      }
      # write the response
      return $this->dataWrite($data, 2, $error);
    case 2:
      $error = ErrorEx::warn($E1, $E3);
      break;
    default:
      $error = ErrorEx::warn($E1, $this->pending);
      break;
    }
    return false;
  }
  # }}}
  function notify(string &$data, ?object &$error=null): bool # {{{
  {
    $error = null;
    if ($data === '' || !$this->canWrite($error)) {
      return false;
    }
    if (!$this->dataWrite($data, 3, $error))
    {
      $this->writer->clear($error);
      return false;
    }
    return true;
  }
  # }}}
  function signal(string &$data, ?object &$error=null): bool # {{{
  {
    if ($this->notify($data, $error))
    {
      $this->close($error);
      return true;
    }
    return false;
  }
  # }}}
  function &read(?object &$error=null): ?string # {{{
  {
    static $NONE=null;
    static $E0='exchange cancelled';
    static $E1='no response, reader timed out';
    $error = null;
    switch ($this->pending) {
    case 0:# SERVER: consuming request/notice {{{
      # set reading flag
      if (!$this->reading)
      {
        # increment number of active readers
        if (!$this->state->add(1, 0, $error)) {
          break;
        }
        $this->reading = true;
      }
      # get data buffer state
      if (($n = $this->state->get(1, $error)) < 0) {
        break;# failed
      }
      # check no pending request/notice
      if ($n !== 1 && $n !== 3) {
        break;
      }
      # lock for reading
      if ($this->reader->set($error) <= 0) {
        break;# raced/skipped or failed
      }
      # set employment
      if (($this->pending = $n) === 1)
      {
        # read the request
        return $this->data->readShared($error);
      }
      # read the notice
      $data = &$this->data->read($error);
      $this->close($error);# complete exchange
      return $data;
      # }}}
    case 2:# CLIENT: consuming response {{{
      # get buffer state
      if (($n = $this->state->get(1, $error)) < 0) {
        break;# failed
      }
      # check no response arrived yet
      if ($n !== 2)
      {
        # check not in correct state or timed out
        if ($n !== 1) {
          $error = ErrorEx::fail($E0, $n);
        }
        elseif ($this->timeout()) {
          $error = ErrorEx::fail($E1);
        }
        break;
      }
      # read the response
      $data = &$this->data->read($error);
      $this->close($error);# complete exchange
      return $data;
      # }}}
    }
    return $NONE;
  }
  # }}}
  function flush(?object &$error=null): bool # {{{
  {
    static $E1='no confirmation, writer timed out';
    static $E2='no confirmation, reader timed out';
    # awaiting confirmation
    switch ($this->pending) {
    case 4:# SERVER
      # check state changed (response consumed)
      if ($this->state->get(1, $error) !== 2) {
        break;
      }
      # check client timeout
      if ($this->timeout())
      {
        $error = ErrorEx::fail($E1);
        break;
      }
      return false;
    case 5:# CLIENT
      # check state changed (notification consumed)
      if ($this->state->get(1, $error) !== 3) {
        break;
      }
      # check server timeout
      if ($this->timeout())
      {
        $error = ErrorEx::fail($E2);
        break;
      }
      return false;
    default:
      return true;
    }
    # exchange complete
    $this->close($error);
    return true;
  }
  # }}}
  function close(?object &$error=null): bool # {{{
  {
    # prepare
    $e = null;
    $s = $this->state;
    # lock
    if (!$s->lock($e)) {
      return false;
    }
    # clear reading flag
    while ($this->reading)
    {
      if (($n = $s->memGet(0, $e)) < 0) {
        break;
      }
      $n = $n ? $n - 1 : 0;
      if (!$s->memSet($n, 0, $e)) {
        break;
      }
      $this->reading = false;
    }
    # clear buffer state and
    # release reader/writer lock
    switch ($this->pending) {
    case 2:# client put the request => cancellation
      $s->memSet(0, 1, $e);
      $this->writer->clear($e);
      break;
    case 5:# client got confirmation / timeout
      $error && $s->memSet(0, 1, $e);
      $this->writer->clear($e);
      break;
    case 1:# server got the request => cancellation
    case 3:# server got the notice => confirmation
      $s->memSet(0, 1, $e);
      $this->reader->clear($e);
      break;
    case 4:# server got confirmation / timeout
      $error && $s->memSet(0, 1, $e);
      $this->reader->clear($e);
      break;
    }
    # complete
    $s->unlock($e);
    $this->pending = 0;
    return $e
      ? ErrorEx::set($error, $e)->val(false)
      : true;
  }
  # }}}
}
# }}}
###
class SyncBroadcast extends SyncReaderWriter # {{{
{
  # Broadcast: one writer, many readers
  # constructor {{{
  static function new(array $o): object
  {
    try
    {
      # prepare
      $id     = self::getId($o);
      $dir    = self::getDir($o);
      $reader = self::getInstance($o, $id, $dir, true);
      $id0    = $id.'-master';
      $id1    = $reader->id.'-broadcast';
      $info   = ErrorEx::peep(SyncExchange::new([
        'id'   => $id.'-info',
        'size' => 200,
      ]));
      # construct
      return new self(
        $id, self::getTimeWait($o),
        $reader, self::getFlag($id0),
        $info, self::getFlag($id1),
        null, $o['callback'] ?? null
      );
    }
    catch (Throwable $e)
    {
      return ErrorEx::set($e, ErrorEx::fail(
        class_basename(self::class), __FUNCTION__
      ));
    }
  }
  protected function __construct(
    public string  $id,
    public string  $timeWait,
    public object  $reader,
    public object  $writer,
    public object  $info,
    public object  $dataFlag,
    public ?object $dataBuf,
    public ?object $callback,
    public array   $queue = [],
    public int     $state = 0
  ) {
    $dataFlag->clearShared();
  }
  # }}}
  # hlp {{{
  protected function isReady(?object &$error): bool # {{{
  {
    static $E1='master set an incorrect buffer size';
    switch ($this->state) {
    case -1:# on hold {{{
      $this->timeout() &&
      $this->stateSet(0, 'retry', $error);
      break;
    # }}}
    case  0:# initiation {{{
      # check master offline
      if (!$this->writer->getShared()) {
        break;
      }
      # move to registration
      $a = '1:'.$this->reader->id;
      if ($this->info->write($a, $error)) {
        $this->stateSet(1, '', $error);
      }
      break;
    # }}}
    case  1:# registration {{{
      # read the response
      if (($a = $this->info->read($error)) === null)
      {
        $error && $this->stateSet(-1, 'fail', $error);
        break;
      }
      # parse info
      if (($i = intval($a)) < 1 ||
          $i > self::MAX_SIZE)
      {
        $error = ErrorEx::fail($E1, $a);
        $this->stateSet(-1, 'fail', $error);
        break;
      }
      # construct data buffer
      if ($this->dataBuf === null ||
          $this->dataBuf->sizeMax !== $i)
      {
        $o = SyncBuffer::new($this->id, $i);
        if (ErrorEx::is($o))
        {
          $error = $o;
          $this->stateSet(-1, 'fail', $error);
          break;
        }
        $this->dataBuf = $o;
      }
      # move to the next stage
      $this->stateSet(2, '', $error);
      break;
    # }}}
    case  2:# activation {{{
      # check escaped
      if (!$this->writer->getShared())
      {
        $this->stateSet(-1, 'escape', $error);
        break;
      }
      # send activation signal
      $a = '2:'.$this->reader->id;
      if (!$this->info->signal($a, $error))
      {
        $error && $this->stateSet(-1, 'fail', $error);
        break;
      }
      # move to the next stage
      $this->info->close($error);# dont wait
      $this->stateSet(3, '', $error);
      break;
    # }}}
    case  3:# checking {{{
      # check escaped
      if (!$this->writer->getShared())
      {
        $this->stateSet(0, 'escape', $error);
        break;
      }
      # ready
      return true;
    # }}}
    }
    return false;
  }
  # }}}
  protected function stateSet(# {{{
    int $new, string $info, ?object &$error
  ):void
  {
    # when leaving stage..
    switch ($old = $this->state) {
    case 1:
    case 2:
      $this->info->close($error);
      break;
    }
    # set new state
    $this->state = $new;
    $this->time  = hrtime(true);
    if ($f = $this->callback) {
      $f($old, $new, $info);
    }
  }
  # }}}
  # }}}
  function &read(?object &$error=null): string # {{{
  {
    # check data arrived
    if ($this->isReady($error) &&
        $this->dataFlag->getShared())
    {
      # read and clear flag
      $data = $this->dataBuf->readShared($error);
      $this->dataFlag->clearShared($error);
    }
    else {
      $data = '';
    }
    return $data;
  }
  # }}}
  function write(string &$data, ?object &$error=null): bool # {{{
  {
    return (
      $this->isReady($error) &&
      $this->info->signal($data, $error) &&
      $this->info->close($error)
    );
  }
  # }}}
  function flush(?object &$error=null): bool # {{{
  {
    return $this->isReady($error);
  }
  # }}}
  function close(?object &$error=null): bool # {{{
  {
    # check already closed
    if ($this->state === -2) {
      return true;
    }
    # check master online and
    # try to quit gracefully
    if ($this->state === 3 &&
        $this->writer->getShared())
    {
      $a = '0:'.$this->reader->id;
      $b = (
        $this->info->signal($a, $error) &&
        $this->info->close($error)
      );
    }
    else {
      $b = false;
    }
    # cleanup
    $this->reader->clearShared($error);
    $this->stateSet(-2, $b?'graceful':'', $error);
    return $error === null;
  }
  # }}}
}
# }}}
class SyncBroadcastMaster extends SyncReaderWriter # {{{
{
  # constructor {{{
  static function new(array $o): object
  {
    try
    {
      # prepare
      $id   = self::getId($o);
      $dir  = self::getDir($o);
      $size = self::getSize($o);
      $id0  = $id.'-master';
      $info = ErrorEx::peep(SyncExchange::new([
        'id'   => $id.'-info',
        'size' => 200,
      ]));
      var_dump(self::class);
      # construct
      return new self(
        $id, self::getMasterFlag($id0, $dir),
        $info, ErrorEx::peep(SyncBuffer::new($id, $size)),
        $o['callback'] ?? null
      );
    }
    catch (Throwable $e)
    {
      return ErrorEx::set($e, ErrorEx::fail(
        class_basename(self::class), __FUNCTION__
      ));
    }
  }
  protected function __construct(
    public string  $id,
    public object  $writer,
    public object  $info,
    public object  $data,
    public ?object $callback,
    public array   $queue  = [],
    public array   $reader = [],
    public string  $rid    = '',
    public bool    $ready  = true
  ) {}
  # }}}
  # hlp {{{
  protected function isReady(?object &$error): bool # {{{
  {
    # check shortcut flag
    if ($this->ready) {
      return true;# no need to check readers
    }
    # check all readers
    $t = hrtime(true);
    $f = $this->callback;
    $x = 0;
    foreach ($this->reader as $id => &$a)
    {
      # check escape (closed without notifying)
      if (!$a[0]->get())
      {
        unset($this->reader[$id]);
        $f && $f(0, $id, 'escape');
      }
      # check not pending
      if (!$a[1]->get()) {
        continue;
      }
      # check timed out
      if (hrtime_delta_ms($a[2], $t) > self::TIMEOUT)
      {
        $a[0]->clearShared($error);
        $a[1]->clearShared($error);
        unset($this->reader[$id]);
        $f && $f(0, $id, 'timeout');
      }
      else {
        $x++;# count pending
      }
    }
    # check at least one is pending
    if ($x) {
      return false;
    }
    # invoke user callback
    if ($f)
    {
      # count and reset readers that have read
      foreach ($this->reader as &$a)
      {
        if ($a[2])
        {
          $x++;
          $a[2] = 0;
        }
      }
      $f(3, '*', strval($x));
    }
    # set ready and complete
    return $this->ready = true;
  }
  # }}}
  protected function dataWrite(# {{{
    string &$data, ?array $ids, ?object &$error
  ):bool
  {
    # check data fits into buffer
    $i = strlen($data);
    $j = $this->dataBuf->sizeMax;
    if ($i > $j)
    {
      $e = ErrorEx::warn(
        'unable to fit the data('.$i.') '.
        'into the buffer('.$j.')'
      );
      return ErrorEx
        ::set($error, $e)
        ->val(false);
    }
    # write and check failed
    if ($this->dataBuf->write($data, $error) === -1) {
      return false;
    }
    # enter pending state
    $this->ready = false;
    $t = hrtime(true);
    if ($ids)
    {
      # selected
      foreach ($this->reader as $id => &$a)
      {
        if (in_array($id, $ids, true))
        {
          $a[1]->setShared($error);
          $a[2] = $t;
        }
      }
    }
    else
    {
      # all
      foreach ($this->reader as &$a)
      {
        $a[1]->setShared($error);
        $a[2] = $t;
      }
    }
    return true;
  }
  # }}}
  protected function infoRead(?object &$error): ?array # {{{
  {
    # read and parse info
    if (($a = $this->infoBuf->read($error)) &&
        ($b = self::parseInfo($a, $error)))
    {
      return $b;
    }
    return null;
  }
  # }}}
  protected function readerWait(?object &$error): int # {{{
  {
    # prepare
    $id     = $this->rid;
    $reader = &$this->reader[$id];
    $fn     = $this->callback;
    # check aborted
    if (!$reader[0]->get() ||
        !$this->infoFlag->get())
    {
      $fn && $fn(0, $id, 'escape');
      return -1;
    }
    # check pending
    if ($reader[1]->get())
    {
      # check not timed out
      if (!$this->timeout()) {
        return 0;
      }
      # cancel
      $fn && $fn(0, $id, 'timeout');
      return -1;
    }
    # check successful
    if (!($a = $this->infoRead($error)) ||
        $a[0] !== 2 || $a[1] !== $id)
    {
      $fn && $fn(0, $id, 'fail');
      return -1;
    }
    # complete
    $fn && $fn(2, $id, '');
    return 1;
  }
  # }}}
  protected function readerEvent(?object &$error): bool # {{{
  {
    # prepare
    if (!($info = $this->infoRead($error))) {
      return true;
    }
    $case = $info[0];
    $id   = $info[1];
    # check reader exists
    if ($case !== 1 && !isset($this->reader[$id]))
    {
      ErrorEx::set($error, ErrorEx::warn(
        'reader='.$id.' is not registered'
      ));
      return true;
    }
    # operate
    switch ($case) {
    case 0:
      # reader deactivation,
      # remove it from the storage
      unset($this->reader[$id]);
      break;
    case 1:
      # reader registration,
      # create reader flags [running,pending,ts]
      $reader = [null,null,0];
      $reader[0] = $e = SyncFlag::new($id);
      if (ErrorEx::is($e)) {
        return ErrorEx::set($error, $e)->val(true);
      }
      if (!$e->get())
      {
        ErrorEx::set($error, ErrorEx::warn(
          'reader='.$id.' is not running'
        ));
        return true;
      }
      $reader[1] = $e = SyncFlag::new($id.'-1');
      if (ErrorEx::is($e)) {
        return ErrorEx::set($error, $e)->val(true);
      }
      if (!$e->setShared($error)) {
        return true;
      }
      # to complete registration,
      # reader have to read the size of the buffer,
      # as it doesnt have the buffer,
      # the size is placed into the info buffer
      $a = strval($this->dataBuf->sizeMax);
      if (!$this->infoBuf->write($a, $error)) {
        return true;
      }
      # initiate the request
      $this->reader[$id] = $reader;
      $this->rid  = $id;
      $this->time = hrtime(true);
      break;
    case 4:
      # custom info
      break;
    default:
      ErrorEx::set($error, ErrorEx::warn(
        'unknown case='.$case.
        ' from the reader='.$id
      ));
      return true;
    }
    # invoke user callback
    if ($f = $this->callback) {
      $f($case, $id, $info[2]);
    }
    # complete
    return $case !== 1;
  }
  # }}}
  protected function flushReader(?object &$error): bool # {{{
  {
    if ($this->time === 0) {
      return true;
    }
    if ($this->lock->set($error))
    {
      switch ($this->readerWait($error)) {
      case -1:# failed
        unset($this->reader[$this->rid]);
      case  1:# succeed
        $this->time = 0;
        $this->infoFlag->clearShared($error);
        break;
      }
      $this->lock->clear($error);
    }
    return false;
  }
  # }}}
  protected function flushInfo(?object &$error): bool # {{{
  {
    if (!$this->infoFlag->get()) {
      return true;
    }
    if ($this->lock->set($error))
    {
      $this->infoFlag->get()     &&
      $this->readerEvent($error) &&
      $this->infoFlag->clearShared($error);
      $this->lock->clear($error);
    }
    return false;
  }
  # }}}
  protected function flushQueue(?object &$error): bool # {{{
  {
    # check ready and drain queue
    if ($this->isReady($error) && $this->queue)
    {
      $a = array_shift($this->queue);
      $this->dataWrite($a[0], $a[1], $error);
    }
    return true;
  }
  # }}}
  # }}}
  function write(string &$data, ?object &$error=null): bool # {{{
  {
    # check empty or closed
    if ($data === '' || !$this->writer) {
      return true;
    }
    # check not ready or unable to lock
    $error = null;
    if ($this->time ||
        !$this->isReady($error) ||
        !$this->lock->set($error))
    {
      $this->queue[] = [$data,null];# postpone
      return $error === null;
    }
    # write, unlock and complete
    $this->dataWrite($data, null, $error);
    $this->lock->clear($error);
    return $error === null;
  }
  # }}}
  function flush(?object &$error=null): bool # {{{
  {
    # check closed
    if (!$this->writer) {
      return true;
    }
    # operate
    $error = null;
    $this->flushReader($error) &&
    $this->flushInfo($error)   &&
    $this->flushQueue($error);
    # complete
    return $error === null;
  }
  # }}}
  function close(?object &$error=null): bool # {{{
  {
    # check already closed
    if (!$this->writer) {
      return true;
    }
    # lock insistently
    $error = null;
    while (!$this->lock->setWait(self::TIMEOUT, $error)) {
      $this->lock->clearShared($error);
    }
    # reset all readers
    if (!$this->ready)
    {
      foreach ($this->reader as &$a) {
        $a[1]->clearShared($error);
      }
    }
    # cleanup
    $this->writer->clear($error);
    $this->reader = $this->queue = [];
    $this->info->clear($error);
    $this->data->clear($error);
    return $error === null;
  }
  # }}}
}
# }}}
class SyncAggregate extends SyncReaderWriter # {{{
{
  # Aggregate: one reader, many writers
  # constructor {{{
  static function new(array $o): object
  {
    try
    {
      # prepare
      $id  = self::getId($o);
      $dir = self::getDir($o);
      $id0 = $id.'-master';
      $wid = $id.'-'.proc_id();
      $id1 = $id.'-lock';
      # create instance flags
      $reader = $dir
        ? SyncFlagFile::new($id0, $dir)
        : SyncFlag::new($id0);
      $writer = $dir
        ? SyncFlagFileMaster::new($wid, $dir)
        : SyncFlagMaster::new($wid);
      # construct
      return new static(
        $id, $wid, ErrorEx::peep($reader),
        ErrorEx::peep($writer),
        ErrorEx::peep(static::newReader($id0, $dir)),
        ErrorEx::peep(SyncLock::new($id1)),
        $o['callback'] ?? null
      );
    }
    catch (Throwable $e)
    {
      return ErrorEx::set($e, ErrorEx::fail(
        class_basename(static::class), __FUNCTION__
      ));
    }
  }
  protected function __construct(
    public string  $id,
    public string  $wid,
    public ?object $reader,
    public object  $writer,
    public object  $lock,
    public ?object $callback,
    public ?object $dataBuf = null,
    public string  $data    = '',
    public string  $chunk   = ''
  ) {}
  # }}}
  # hlp {{{
  function _write(string &$data, ?object &$error): bool # {{{
  {
    # append and check failed
    $n = $this->buf->append($data, $error);
    if ($n === -1) {
      return false;
    }
    # check all written
    if ($n === strlen($data)) {
      return true;
    }
    # until overflow is flush drained,
    # lock any further writes
    if (!$this->reader->lock->set($error))
    {
      return ErrorEx::set($error, ErrorEx::fail(
        class_name($this), $this->id,
        'unable to lock writers'
      ))->val(false);
    }
    # store overflow and complete
    $this->chunk = substr($data, $n);
    return true;
  }
  # }}}
  function _drain(?object &$error): bool # {{{
  {
    # write and check failed
    $n = $this->buf->write($this->chunk, $error);
    if ($n === -1) {
      return false;
    }
    # check all written
    if ($n === strlen($data))
    {
      $this->chunk = '';
      return true;
    }
    # reduce and complete
    $this->chunk = substr($this->chunk, $n);
    return false;
  }
  # }}}
  # }}}
  function write(string &$data, ?object &$error=null): bool # {{{
  {
    $error = null;
    return true;
    # check empty
    if ($data === '') {
      return true;
    }
    # check reader
    if (!$this->reader->status->get()) {
      return false;
    }
    # when in overflow mode or unable to lock,
    # accumulate data and bail out
    if ($this->reader->lock->get() ||
        !$this->lock->set($error))
    {
      $this->data .= $data;
      return $error === null;
    }
    # write, unlock and complete
    $a = $this->_write($data, $error);
    $b = $this->lock->clear($error);
    return $a && $b;
  }
  # }}}
  function flush(?object &$error=null): bool # {{{
  {
    $error = null;
    return true;
    # prepare
    $a = $this->reader->lock->state;
    $b = (
      !$a && strlen($this->data) &&
      !$this->reader->lock->get()
    );
    # check nothing to flush
    if ($a === $b) {
      return true;
    }
    # check reader
    if (!$this->reader->status->get())
    {
      # reader escaped,
      # cleanup
      $a && $this->reader->lock->clear($error);
      $this->chunk = $this->data = '';
      return true;
    }
    # operate
    if ($a)
    {
      # drain overflow,
      # as this instance is the only writer,
      # lock more insistently
      if (!$this->lock->setWait(100, $error)) {
        return $error === null;
      }
      # check anything left to drain or
      # anything is still in the buffer
      if ($this->chunk !== '') {
        $this->_drain($error);
      }
      elseif ($this->buf->size($error) === 0) {
        $this->reader->lock->clear($error);
      }
    }
    else
    {
      # lock and drain accumulated data
      if (!$this->lock->set($error)) {
        return $error === null;
      }
      if ($this->_write($this->data, $error)) {
        $this->data = '';
      }
    }
    # unlock and complete
    $this->lock->clear($error);
    return $error === null;
  }
  # }}}
  function close(?object &$error=null): bool # {{{
  {
    # check already closed
    if (!$this->reader) {
      return true;
    }
    # ...
    $error = null;
    # ...
    $this->reader = null;
    return true;
  }
  # }}}
}
# }}}
class SyncAggregateMaster extends SyncReaderWriter # {{{
{
  # constructor {{{
  static function new(array $o): object
  {
    try
    {
      $id0 = $id.'-reader';
      $id1 = $id.'-lock';
      return new static(
        ErrorEx::peep(static::newReader($id0, $dir)),
        ErrorEx::peep(SyncLock::new($id1)),
        ErrorEx::peep(SyncBuffer::new($id, $size))
      );
    }
    catch (Throwable $e)
    {
      return ErrorEx::set($e, ErrorEx::fail(
        class_basename(static::class),
        $id, strval($size)
      ));
    }
  }
  protected function __construct(
    public string  $id,
    public string  $wid,
    public ?object $reader,
    public object  $writer,
    public object  $lock,
    public object  $dataFlag,
    public ?object $dataBuf,
    public ?object $callback,
    public string  $data  = '',
    public string  $chunk = ''
  ) {}
  # }}}
  # hlp {{{
  # }}}
  function &read(?object &$error=null): string # {{{
  {
    $error = null;
    # lock
    $data = '';
    if (!($reader = $this->reader) ||
        !$this->lock->set($error))
    {
      return $data;
    }
    # read
    if (($data = $this->buf->read($error)) !== '')
    {
      # manage overflow
      if ($reader->lock->get())
      {
        # draining,
        # accumulate chunks
        $this->chunk .= $data;
        $data = '';
      }
      elseif ($this->chunk !== '')
      {
        # drained,
        # assemble chunks into result
        $data = $this->chunk.$data;
        $this->chunk = '';
      }
    }
    elseif ($this->data !== '' &&
            $this->chunk === '')
    {
      # take own writes as a result
      $data = $this->data;
      $this->data = '';
    }
    # unlock and complete
    $this->lock->clear($error);
    return $data;
  }
  # }}}
  function write(string &$data, ?object &$error=null): bool # {{{
  {
    $error = null;
    return true;
    if ($this->reader)
    {
      $this->data .= $data;
      return true;
    }
    return false;
  }
  # }}}
  function flush(?object &$error=null): bool # {{{
  {
    $error = null;
    return true;
    if ($this->reader)
    {
      $this->read($error);
      return $error === null;
    }
    return false;
  }
  # }}}
  function close(?object &$error=null): bool # {{{
  {
    $error = null;
    return true;
    if ($this->reader)
    {
      $this->lock->setWait(3000, $error);
      $this->reader->finit($error);
      $this->buf->clear($error);
      $this->lock->clear($error);
      $this->reader = null;
      return $error === null;
    }
    return true;
  }
  # }}}
}
# }}}
###
/***
class Syncbuf # {{{
{
  public $membuf,$rEvent,$wEvent,$timeout;
  function __construct(string $name, int $size, int $timeout) # {{{
  {
    $this->membuf  = new Buffer($name, $size);
    $this->rEvent  = new SyncEvent('R'.$name, 1);
    $this->wEvent  = new SyncEvent('W'.$name, 1);
    $this->timeout = $timeout;
  }
  # }}}
  function write(string $data, int $timeout = 0): void # {{{
  {
    if ($this->rEvent->wait(0) && !$this->rEvent->reset()) {
      throw ErrorEx::failFn('SyncEvent::reset');
    }
    if (!$this->membuf->write($data)) {
      throw ErrorEx::failFn('Buffer::write');
    }
    if ($this->membuf->overflow) {
      throw ErrorEx::failFn('buffer overflow');
    }
    if (!$this->wEvent->fire()) {
      throw ErrorEx::failFn('SyncEvent::fire');
    }
    if (!$this->rEvent->wait($timeout ?: $this->timeout)) {
      throw ErrorEx::failFn('response timed out');
    }
  }
  # }}}
  function read(int $wait = 0): string # {{{
  {
    if (!$this->wEvent->wait($wait)) {
      return '';
    }
    $data = $this->membuf->read();
    if (!$this->wEvent->reset()) {
      throw ErrorEx::failFn('SyncEvent::reset');
    }
    if ($this->membuf->overflow) {
      throw ErrorEx::failFn('buffer overflow');
    }
    if (!$this->rEvent->fire()) {
      throw ErrorEx::failFn('SyncEvent::fire');
    }
    return $data;
  }
  # }}}
  function writeRead(string $data, int $wait = 300, int $timeout = 0): string # {{{
  {
    $this->write($data, $timeout);
    return $this->read($wait);
  }
  # }}}
  function reset(): void # {{{
  {
    $this->wEvent->reset();
    $this->rEvent->reset();
    $this->membuf->reset();
  }
  # }}}
}
# }}}
/***/
###
