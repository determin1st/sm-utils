<?php declare(strict_types=1);
# defs {{{
namespace SM;
use
  SyncSharedMemory,SyncSemaphore,SyncEvent,
  Throwable;
use function
  sys_get_temp_dir,file_exists,touch,preg_match,intval,
  strval,strlen,substr,str_repeat,pack,unpack,ord,hrtime,
  array_shift,array_unshift,is_array,is_int,is_string,
  is_object,is_callable,is_dir;
use function SM\{
  class_name,class_basename,
  hrtime_delta_ms,
  hrtime_expired, proc_id
};
use const
  DIRECTORY_SEPARATOR;
###
require_once __DIR__.DIRECTORY_SEPARATOR.'error.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'promise.php';
# }}}
class SyncBuffer # {{{
{
  # constructor {{{
  static function new(string $id, int $size): object
  {
    try
    {
      # construct
      $buf = new static($id, $size,
        new SyncSharedMemory($id, 4 + $size)
      );
      # clear at first encounter
      if ($buf->mem->first()) {
        $buf->memWriteSize(0);
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
  protected function &memRead(): string # {{{
  {
    # read content size
    $data = '';
    if (($i = $this->memReadSize()) === 0) {
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
  protected function memReadSize(): int # {{{
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
  protected function memWrite(string &$data, int $offs): int # {{{
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
    $this->memWriteSize($k);
    return $n;
  }
  # }}}
  protected function memWriteSize(int $size): bool # {{{
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
  protected function memAppend(string &$data): int # {{{
  {
    # check empty
    if ($data === '') {
      return 0;
    }
    # check current size of the buffer
    if (($i = $this->memReadSize()) === -1)
    {
      throw ErrorEx::fail(
        'unable to append, buffer is overflowed'
      );
    }
    # complete
    return $this->memWrite($data, $i);
  }
  # }}}
  # }}}
  function &read(?object &$error=null): ?string # {{{
  {
    $data = &$this->readShared($error);
    if ($data !== null) {
      $this->clear($error);
    }
    return $data;
  }
  # }}}
  function &readShared(?object &$error=null): ?string # {{{
  {
    static $BAD=null;
    try {
      return $this->memRead();
    }
    catch (Throwable $e) {
      return ErrorEx::set($error, $e)->var($BAD);
    }
  }
  # }}}
  function size(?object &$error=null): int # {{{
  {
    try {
      return $this->memReadSize();
    }
    catch (Throwable $e) {
      return ErrorEx::set($error, $e)->val(-2);
    }
  }
  # }}}
  function write(string &$data, ?object &$error=null): int # {{{
  {
    try
    {
      return ($data === '')
        ? $this->memWriteSize(0)
        : $this->memWrite($data, 0);
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
      return $this->memAppend($data);
    }
    catch (Throwable $e) {
      return ErrorEx::set($error, $e)->val(-1);
    }
  }
  # }}}
  function clear(?object &$error=null): bool # {{{
  {
    try {
      return $this->memWriteSize(0);
    }
    catch (Throwable $e) {
      return ErrorEx::set($error, $e)->val(false);
    }
  }
  # }}}
}
# }}}
class SyncFlag # {{{
{
  # base {{{
  static function new(string $id): object
  {
    try
    {
      return new self(
        $id, new SyncEvent($id, 1, 0)
      );
    }
    catch (Throwable $e) {
      return ErrorEx::from($e);
    }
  }
  protected function __construct(
    public string $id,
    public object $event,
    public bool   $state = false
  ) {}
  function __destruct() {
    $this->clear();
  }
  # }}}
  # helpers {{{
  function eventSet(bool $shared): bool # {{{
  {
    # check
    if ($this->state || $this->event->wait(0)) {
      return true;# already set
    }
    # set
    if (!$this->event->fire()) {
      throw ErrorEx::fail('SyncEvent::fire');
    }
    if ($shared) {# dont appropriate
      return true;
    }
    return $this->state = true;
  }
  # }}}
  function eventClear(bool $shared): bool # {{{
  {
    # check
    if (!$this->state)
    {
      if (!$this->event->wait(0)) {
        return true;# already clean
      }
      if (!$shared) {
        return false;# appropriated
      }
    }
    # clear
    if (!$this->event->reset()) {
      throw ErrorEx::fail('SyncEvent::reset');
    }
    $this->state = false;
    return true;
  }
  # }}}
  function _set(# {{{
    ?object &$error, bool $shared
  ):bool
  {
    try {
      return $this->eventSet($shared);
    }
    catch (Throwable $e)
    {
      ErrorEx::set($error, $e);
      return false;
    }
  }
  # }}}
  function _clear(# {{{
    ?object &$error, bool $shared
  ):bool
  {
    try {
      return $this->eventClear($shared);
    }
    catch (Throwable $e)
    {
      ErrorEx::set($error, $e);
      return false;
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
class SyncFlagMaster # {{{
{
  # base {{{
  static function new(
    string $id, string $dir=''
  ):object
  {
    try
    {
      # prepare directory
      if ($dir === '') {
        $dir = sys_get_temp_dir();
      }
      else
      {
        $dir = rtrim($dir, '/\\');
        if (!is_dir($dir))
        {
          throw ErrorEx::fail(
            $id, 'directory not found'.
            "\n".$dir
          );
        }
      }
      # determine file path
      $file =
        $dir.DIRECTORY_SEPARATOR.
        $id.'.flag';
      # complete
      return new self(
        $id, $file, new SyncEvent($id, 1, 0)
      );
    }
    catch (Throwable $e) {
      return ErrorEx::from($e);
    }
  }
  protected function __construct(
    public string $id,
    public string $file,
    public object $event
  ) {
    # master flag must auto-set itself
    # create lockfile
    if (Fx::file_persist($file))
    {
      throw ErrorEx::fail(
        $id, 'master flag has been already set'.
        "\nlockfile: ".$file.
        "\nremove it manually to override"
      );
    }
    else {
      Fx::file_touch($file);
    }
    # set when necessary
    if (!$event->wait(0) && !$event->fire())
    {
      throw ErrorEx::fail(
        $id, 'SyncEvent::fire'
      );
    }
  }
  function __destruct()
  {
    # clear
    Fq::file_unlink($this->file);
    $this->event->reset();
  }
  # }}}
  # api {{{
  function get(): bool
  {
    return (
      $this->event->wait(0) &&
      Fx::file_persist($this->file)
    );
  }
  # }}}
}
# }}}
class SyncNum # {{{
{
  const TIMEWAIT=5000;
  # base {{{
  static function new(
    string $id, int $count,
    bool    $guarded  = false,
    ?object $callback = null
  ):object
  {
    try
    {
      return new self(
        $id, $count,
        new SyncSharedMemory($id, 4*$count),
        ($guarded
          ? new SyncSemaphore($id.'-sem', 1, 0)
          : null
        ),
        $callback
      );
    }
    catch (Throwable $e) {
      return ErrorEx::from($e);
    }
  }
  protected function __construct(
    public string  $id,
    public int     $count,
    public object  $mem,
    public ?object $guard,
    public ?object $callback,
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
  # helpers {{{
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
  function memRead(int $k): int # {{{
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
  function memWrite(int $k, int $n): void # {{{
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
  function memReset(): void # {{{
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
  # api {{{
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
    return $this->unlock($error)
      ? $n
      : -1;
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
  # }}}
}
# }}}
class SyncLock # {{{
{
  # constructor {{{
  static function new(string $id, int $max=1): object
  {
    try
    {
      if ($max < 1 || $max > 1000)
      {
        throw ErrorEx::fail(
          'incorrect max='.$max.
          ', not in range=[1,1000]'
        );
      }
      $num = SyncNum::new($id.'-num', 1, $max > 1);
      return new static(
        $id, $max, ErrorEx::peep($num),
        new SyncSemaphore($id, $max, 0)
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
    public string $id,
    public int    $max,
    public object $num,
    public object $sem,
    public int    $state = 0
  ) {
    $num->callback = $this->onChange(...);
    if ($num->mem->first()) {
      while ($sem->unlock()) {}
    }
  }
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
        'incorrect lock number='.$n1.
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
# Readers/Writers
abstract class SyncReaderWriter # {{{
{
  # constructor {{{
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
  # }}}
  # api {{{
  function &read(?object &$error=null): ?string
  {
    static $NONE=null;
    return $NONE;
  }
  function write(string &$data, ?object &$error=null): bool {
    return true;
  }
  function flush(?object &$error=null): bool {
    return true;
  }
  function close(?object &$error=null): bool {
    return true;
  }
  # }}}
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
  static function getInstanceFlag(array &$o): object # {{{
  {
    static $k0='instance-flag';
    static $k1='instance-id';
    if (isset($o[$k0]))
    {
      if (!is_object($x = $o[$k0])) {
        throw self::badOption($k0);
      }
      return $x;
    }
    if (isset($o[$k1]))
    {
      if (!is_string($o[$k1]) ||
          ($id = $o[$k1]) === '')
      {
        throw self::badOption($k1);
      }
    }
    else {
      $id = self::getId($o).'-'.Fx::$PROCESS_ID;
    }
    return ErrorEx::peep(
      SyncFlagMaster::new($id, self::getDir($o))
    );
  }
  # }}}
  static function getCallback(array $o): ?object # {{{
  {
    static $k='callback';
    if (!isset($o[$k])) {
      return null;
    }
    if (!is_callable($f = $o[$k])) {
      throw self::badOption($k);
    }
    return $f;
  }
  # }}}
  static function badOption(string $k): object # {{{
  {
    return ErrorEx::fail(
      'incorrect option', $k
    );
  }
  # }}}
  static function parseInfo(string &$s, ?object &$e): ?array # {{{
  {
    static $ERR='incorrect info format';
    static $EXP_INFO = (
      '/^'.
      '([0-9]{1})'.         # case
      ':([a-z0-9-]{1,128})'.# id
      '(:(.+)){0,1}'.       # info
      '$/i'
    );
    if (preg_match($EXP_INFO, $s, $a)) {
      return [intval($a[1]),$a[2],$a[4]??''];
    }
    ErrorEx::set($e, ErrorEx::fail($ERR, $s));
    return null;
  }
  # }}}
  function timeout(): bool # {{{
  {
    return hrtime_expired(
      $this->timeWait, $this->time
    );
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
      $id = self::getId($o);
      $size = self::getSize($o);
      return new static(
        $id, self::getTimeWait($o),
        ErrorEx::peep(SyncLock::new($id.'-r')),
        ErrorEx::peep(SyncLock::new($id.'-w')),
        ErrorEx::peep(SyncNum::new($id.'-x', 2, true)),
        ErrorEx::peep(SyncBuffer::new($id, $size))
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
    # shared state structure:
    # [0]: total number of readers reading
    # [1]: data buffer content
    #  (0) empty, ready for (1) or (3)
    #  (1) request, ready for (2)
    #  (2) response
    #  (3) notification
    public object $state,
    public object $data,
    public bool   $reading = false,
    public int    $pending = 0
  ) {}
  # }}}
  # hlp {{{
  protected function canWrite(?object &$error): bool # {{{
  {
    # check current state
    switch ($this->pending) {
    case  2: return true;# continuation
    case  0: break;# available
    default: return false;# busy
    }
    # check reader woring or unable to lock
    if ($this->reader->getShared($error) ||
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
      # cleanup
      if ($this->pending) {
        $this->close($error);
      }
      else {
        $this->writer->clear($error);
      }
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
    case 0:# CLIENT: request
      # check writable
      if (!$this->canWrite($error)) {
        break;
      }
      # fallthrough..
    case 2:
      # write the request
      if (!$this->dataWrite($data, 1, $error)) {
        break;
      }
      # success
      return true;
    case 1:# SERVER: response
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
      if (!$this->dataWrite($data, 2, $error)) {
        break;
      }
      # success
      return true;
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
    return (
      $this->canWrite($error) &&
      $this->dataWrite($data, 3, $error)
    );
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
      # fallthrough..
    case 4:
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
        # check incorrect state or timed out
        if ($n !== 1) {
          $error = ErrorEx::fail($E0, $n);
        }
        elseif ($this->timeout()) {
          $error = ErrorEx::fail($E1);
        }
        break;
      }
      # read the response
      return $this->data->read($error);
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
      if (($n = $this->state->get(1, $error)) !== 2)
      {
        # check for continuation
        if ($n) {
          return true;
        }
        # complete
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
    # check necessary
    if (!$this->reading && !$this->pending) {
      return true;
    }
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
      $n && $n--;
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
    $this->pending = 0;
    $s->unlock($e);
    return $e
      ? ErrorEx::set($error, $e)->val(false)
      : true;
  }
  # }}}
}
# }}}
class SyncBroadcastMaster extends SyncReaderWriter # {{{
{
  # Broadcast: one writer, many readers
  # constructor {{{
  static function new(array $o): object
  {
    try
    {
      $id   = self::getId($o);
      $size = self::getSize($o);
      $flag = ErrorEx::peep(SyncFlagMaster::new(
        $id.'-master', self::getDir($o)
      ));
      $info = ErrorEx::peep(SyncExchange::new([
        'id'   => $id.'-info',
        'size' => 200,
      ]));
      return new self(
        $id, $flag, $info,
        ErrorEx::peep(SyncBuffer::new($id, $size)),
        self::getCallback($o)
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
    public ?object $writer,
    public object  $info,
    public object  $data,
    public ?object $callback,
    public array   $queue  = [],
    public array   $reader = [],
    public array   $state  = []
  ) {}
  # }}}
  # hlp {{{
  protected function isReady(?object &$error): bool # {{{
  {
    # check data is not pending
    if (!$this->time) {
      return true;
    }
    # prepare
    $f = $this->callback;
    $x = hrtime_expired(
      self::DEF_TIMEWAIT, $this->time
    );
    $n = 0;
    # count pending readers
    foreach ($this->reader as $id => &$a)
    {
      # check escaped (closed without a notice)
      if (!$a[0]->getShared())
      {
        unset($this->reader[$id]);
        $f && $f(0, $id, 'escape');
      }
      # check charged and pending
      if ($a[2] && $a[1]->getShared())
      {
        # check timeout
        if ($x)
        {
          $a[1]->clearShared($error);
          unset($this->reader[$id]);
          $f && $f(0, $id, 'timeout');
        }
        else {
          $n++;# count pending
        }
      }
    }
    # check still pending
    if ($n) {
      return false;
    }
    # reset and count readers that read the data
    foreach ($this->reader as &$a)
    {
      if ($a[2])
      {
        $a[2] = 0;
        $n++;
      }
    }
    # complete
    $this->time = 0;
    $f && $f(4, '*', strval($n));
    return true;
  }
  # }}}
  protected function dataWrite(# {{{
    string &$data, ?object &$error
  ):bool
  {
    # check data fits into the buffer
    $i = strlen($data);
    $j = $this->data->sizeMax;
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
    # write
    if ($this->data->write($data, $error) < 0) {
      return false;
    }
    # enter pending state
    $this->time = hrtime(true);
    foreach ($this->reader as &$a)
    {
      $a[1]->setShared($error);
      $a[2] = 1;
    }
    return true;
  }
  # }}}
  protected function infoRead(?object &$error): ?array # {{{
  {
    if (($a = $this->info->read($error)) &&
        ($b = self::parseInfo($a, $error)))
    {
      return $b;
    }
    return null;
  }
  # }}}
  protected function readerDetach(# {{{
    string $id, ?object &$error
  ):void
  {
    # invalidate reader object
    $this->reader[$id][1]->clearShared($error);
    unset($this->reader[$id]);
    $this->state = [];
    # close exchange
    $this->info->pending &&
    $this->info->close($error);
  }
  # }}}
  protected function flushState(?object &$error): bool # {{{
  {
    # check no reader state
    if (!($s = &$this->state)) {
      return true;
    }
    # get info
    if (!($a = $this->infoRead($error)))
    {
      # invalidate upon error or lack of activity
      if ($error || !$this->info->pending) {
        $this->readerDetach($id, $error);
      }
      return false;
    }
    # match operation and identifier
    if ($s[0] !== $a[0] || $s[1] !== $a[1])
    {
      $error = ErrorEx::fail(
        'reader='.$s[1].' operation='.$s[0].
        ' does not match info='.$a[0].':'.$a[1]
      );
      $this->readerDetach($id, $error);
      return false;
    }
    # operate
    switch ($a[0]) {
    case 1:# attachment
      break;
    case 2:# retransmission
      # buffer is populated with the data,
      # activate all readers except the one,
      # which initiated the retransmission
      $this->time = hrtime(true);
      foreach ($this->reader as $id => &$reader)
      {
        if ($s[1] === $id) {
          continue;
        }
        $reader[1]->setShared($error);
        $reader[2] = 1;
      }
      break;
    }
    # clear state
    $s = [];
    # invoke user callback
    if ($fn = $this->callback) {
      $fn($a[0], $a[1], $a[2]);
    }
    return false;
  }
  # }}}
  protected function flushInfo(?object &$error): bool # {{{
  {
    # checkout info
    if (($a = $this->infoRead($error)) === null) {
      return true;
    }
    # check reader is not attached
    if ($a[0] !== 1 &&
        !isset($this->reader[$a[1]]))
    {
      $error = ErrorEx::warn(
        'reader='.$a[1].' is not attached'
      );
      $this->info->pending &&
      $this->info->close($error);
      return false;
    }
    # handle operation
    switch ($a[0]) {
    case 0:# detachment signal {{{
      $this->readerDetach($a[1], $error);
      if ($fn = $this->callback) {
        $fn(0, $a[1], $a[2]);
      }
      break;
    # }}}
    case 1:# attachment request {{{
      # create the "running" flag
      $f0 = SyncFlag::new($a[1]);
      if (ErrorEx::is($f0))
      {
        $error = $f0;
        break;
      }
      # the flag must be set by the reader,
      # make sure it is set
      if (!$f0->getShared())
      {
        $error = ErrorEx::warn(
          'reader='.$a[1].' is not running'
        );
        break;
      }
      # create the "pending" flag
      $f1 = SyncFlag::new($a[1].'-data');
      if (ErrorEx::is($f1))
      {
        $error = $f1;
        break;
      }
      # initially, no data is pending,
      # make sure that flag is cleared
      if ($f1->getShared() &&
          !$f1->clearShared($error))
      {
        break;# failed to clear
      }
      # reader have to create the buffer,
      # send the size of the buffer
      $b = strval($this->data->sizeMax);
      if (!$this->info->write($b, $error)) {
        break;
      }
      # success
      $this->state = $a;
      $this->reader[$a[1]] = [$f0,$f1,0];
      return false;
    # }}}
    case 2:# retransmission request {{{
      # flushing is performed when ready,
      # so retransmission is always allowed
      $b = '1';
      if (!$this->info->write($b, $error)) {
        break;
      }
      # success
      $this->state = $a;
      return false;
    # }}}
    case 3:# custom signal {{{
      if ($fn = $this->callback) {
        $fn(3, $a[1], $a[2]);
      }
      break;
    # }}}
    default:# unknown {{{
      $error = ErrorEx::warn(
        'unknown operation='.$a[0].
        ' from the reader='.$a[1]
      );
      break;
    # }}}
    }
    $this->info->pending &&
    $this->info->close($error);
    return false;
  }
  # }}}
  protected function flushQueue(?object &$error): bool # {{{
  {
    if ($this->queue)
    {
      $data = array_shift($this->queue);
      $this->dataWrite($data, $error);
    }
    return true;
  }
  # }}}
  # }}}
  function write(string &$data, ?object &$error=null): bool # {{{
  {
    # check empty or closed
    $error = null;
    if ($data === '' || !$this->writer) {
      return false;
    }
    # write when ready
    if (!$this->time && $this->isReady($error)) {
      return $this->dataWrite($data, $error);
    }
    elseif ($error) {
      return false;
    }
    # postpone otherwise
    $this->queue[] = $data;
    return true;
  }
  # }}}
  function flush(?object &$error=null): bool # {{{
  {
    # check closed or not ready
    if (!$this->writer ||
        !$this->isReady($error))
    {
      return true;
    }
    # operate
    $error = null;
    $this->flushState($error) &&
    $this->flushInfo($error)   &&
    $this->flushQueue($error);
    return $error === null;
  }
  # }}}
  function close(?object &$error=null): bool # {{{
  {
    # check
    if (!$this->writer) {
      return true;
    }
    # close
    $this->writer = $error = null;
    $this->info->pending &&
    $this->info->close($error);
    $this->reader = $this->queue = [];
    return $error === null;
  }
  # }}}
}
# }}}
class SyncBroadcast extends SyncReaderWriter # {{{
{
  # constructor {{{
  static function new(array $o): object
  {
    try
    {
      # prepare
      $id     = self::getId($o);
      $reader = self::getInstanceFlag($o);
      $writer = ErrorEx::peep(
        SyncFlag::new($id.'-master')
      );
      $info = ErrorEx::peep(SyncExchange::new([
        'id'   => $id.'-info',
        'size' => 200,
      ]));
      # construct
      return new self(
        $id, self::getTimeWait($o),
        $reader, $writer, $info,
        ErrorEx::peep(SyncFlag::new(
          $reader->id.'-data'
        )),
        self::getCallback($o)
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
    public int     $timeWait,
    public object  $reader,
    public object  $writer,
    public object  $info,
    public object  $dataFlag,
    public ?object $callback,
    public ?object $dataBuf = null,
    public array   $queue   = [],
    public array   $store   = ['',''],# read,write
    public int     $state   = 0
  ) {
    $dataFlag->clearShared();
  }
  # }}}
  # hlp {{{
  protected function isReady(?object &$error): bool # {{{
  {
    static $E1='incorrect master response';
    $error = null;
    switch ($this->state) {
    case -1:# on hold {{{
      $this->timeout() &&
      $this->stateSet(0, 'retry', $error);
      break;
    # }}}
    case  0:# attachment (1) {{{
      # check master escaped
      if (!$this->writer->getShared($error)) {
        break;
      }
      # try to initiate
      $a = '1:'.$this->reader->id;
      if (!$this->info->write($a, $e))
      {
        if ($e && $e->level)
        {
          $error = $e;
          $this->stateSet(-1, 'fail', $error);
        }
        break;
      }
      # move to the next stage
      $this->stateSet(1, '', $error);
      break;
    # }}}
    case  1:# attachment (2) {{{
      # get the response
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
      # notify about success
      $a = '1:'.$this->reader->id;
      if (!$this->info->notify($a, $error))
      {
        $this->stateSet(-1, 'fail', $error);
        break;
      }
      # move to the next stage
      $this->stateSet(2, '', $error);
      break;
    # }}}
    case  2:# confirmation {{{
      # wait confirmed
      if (!$this->info->flush($error))
      {
        $error && $this->stateSet(-1, 'fail', $error);
        break;
      }
      # move to the next stage
      $this->stateSet(3, '', $error);
      break;
    # }}}
    case  3:# ready {{{
      # check master escaped
      if (!$this->writer->getShared($error))
      {
        $this->stateSet(0, 'escape', $error);
        break;
      }
      # positive
      return true;
    # }}}
    case  4:# retransmission (1) {{{
      # check master escaped
      if (!$this->writer->getShared($error))
      {
        $this->stateSet(0, 'escape', $error);
        break;
      }
      # flush pending data
      if (!$this->dataFlush($error))
      {
        $this->stateSet(-1, 'fail', $error);
        break;
      }
      # try to initiate
      $a = '2:'.$this->reader->id;
      if (!$this->info->write($a, $e))
      {
        if ($e && $e->level)
        {
          $error = $e;
          $this->stateSet(-1, 'fail', $error);
        }
        break;
      }
      # move to the next stage
      $this->stateSet(5, '', $error);
      break;
    # }}}
    case  5:# retransmission (2) {{{
      # get the response
      if (($a = $this->info->read($error)) === null)
      {
        $error && $this->stateSet(-1, 'fail', $error);
        break;
      }
      # restart when denied
      if ($a !== '1')
      {
        $this->stateSet(4, 'retry', $error);
        break;
      }
      # write stored data
      $i = $this->dataBuf->write($this->store[1], $error);
      $this->store[1] = '';
      if ($i < 0)
      {
        $this->stateSet(-1, 'fail', $error);
        break;
      }
      # notify about success
      $a = '2:'.$this->reader->id;
      if (!$this->info->notify($a, $error))
      {
        $this->stateSet(-1, 'fail', $error);
        break;
      }
      # move to confirmation
      $this->stateSet(2, '', $error);
      break;
    # }}}
    }
    return false;
  }
  # }}}
  protected function stateSet(# {{{
    int $new, string $info, ?object &$error
  ):bool
  {
    # when entering..
    $old = $this->state;
    switch ($new) {
    case -2:
      # try to quit gracefully
      if ($this->state === 3 &&
          $this->writer->getShared($error))
      {
        $a = '0:'.$this->reader->id;
        if ($this->info->signal($a, $error)) {
          $info = 'graceful';
        }
      }
      break;
    case -1:
      $this->time = hrtime(true);
      # falltrough..
    case 0:
      $this->info->pending &&
      $this->info->close($error);
      break;
    }
    # set new state
    $this->state = $new;
    if ($f = $this->callback) {
      $f($old, $new, $info);
    }
    return $error === null;
  }
  # }}}
  protected function dataFlush(?object &$error): bool # {{{
  {
    if (!$this->dataFlag->getShared()) {
      return true;
    }
    $data = &$this->dataBuf->readShared($error);
    if ($data === null) {
      return false;
    }
    $this->store[0] = $data;
    return $this->dataFlag->clearShared($error);
  }
  # }}}
  # }}}
  function isPending(): bool # {{{
  {
    return $this->dataFlag->getShared();
  }
  # }}}
  function &read(?object &$error=null): ?string # {{{
  {
    # prepare
    static $NONE=null;
    $error = null;
    # check read store
    if ($this->store[0] !== '')
    {
      $data = $this->store[0];
      $this->store[0] = '';
      return $data;
    }
    # check not ready or no data pending
    if (!$this->isReady($error) ||
        !$this->dataFlag->getShared())
    {
      return $NONE;
    }
    # read and clear
    $data = $this->dataBuf->readShared($error);
    $this->dataFlag->clearShared($error);
    return $data;
  }
  # }}}
  function write(string &$data, ?object &$error=null): bool # {{{
  {
    # check not ready
    $error = null;
    if ($data === '' || !$this->isReady($error))
    {
      $error = ErrorEx::skip();
      return false;
    }
    # enter retransmission state
    $this->store[1] = $data;
    $this->stateSet(4, '', $error);
    return true;
  }
  # }}}
  function signal(string &$data, ?object &$error=null): bool # {{{
  {
    # check not ready
    $error = null;
    if ($data === '' || !$this->isReady($error))
    {
      $error = ErrorEx::skip();
      return false;
    }
    # try to send signal
    $a = '3:'.$this->reader->id.':'.$data;
    return $this->info->signal($a, $error);
  }
  # }}}
  function flush(?object &$error=null): bool # {{{
  {
    $error = null;
    return $this->isReady($error);
  }
  # }}}
  function close(?object &$error=null): bool # {{{
  {
    return ($this->state !== -2)
      ? $this->stateSet(-2, '', $error)
      : true;
  }
  # }}}
}
# }}}
class SyncAggregateMaster extends SyncReaderWriter # {{{
{
  # Aggregate: one reader, many writers
  # TODO: chunks/separation, array mode
  # constructor {{{
  static function new(array $o): object
  {
    try
    {
      $id   = self::getId($o);
      $size = self::getSize($o);
      $flag = ErrorEx::peep(SyncFlagMaster::new(
        $id.'-master', self::getDir($o)
      ));
      return new self(
        $id, $flag,
        ErrorEx::peep(SyncLock::new($id.'-lock')),
        ErrorEx::peep(SyncBuffer::new($id, $size))
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
    public ?object $reader,
    public object  $lock,
    public object  $data,
    public string  $store = '',# own writes
    public string  $chunk = '' # overflown parts
  ) {}
  # }}}
  function &read(?object &$error=null): ?string # {{{
  {
    # prepare
    $error = null;
    $data  = null;
    # lock
    if ($this->lock->set($error) <= 0) {
      return $data;
    }
    # get buffer state
    switch ($n = $this->data->size($error)) {
    case -2:# error
      break;
    case  0:# empty
      # check and drain own writes
      if ($this->store !== '')
      {
        $data = $this->store;
        $this->store = '';
      }
      break;
    default:# pending data
      # read the data
      $data = $this->data->read($error);
      if ($data === null) {
        break;
      }
      # resolve overflow
      if ($n === -1)
      {
        # accumulate chunks
        $this->chunk .= $data;
        $data = null;
      }
      elseif ($this->chunk !== '')
      {
        # assemble chunks into result
        $data = $this->chunk.$data;
        $this->chunk = '';
      }
      # append own writes
      if ($this->chunk === '' &&
          $this->store !== '')
      {
        $data .= $this->store;
        $this->store = '';
      }
      break;
    }
    # complete
    $this->lock->clear($error);
    return $data;
  }
  # }}}
  function write(string &$data, ?object &$error=null): bool # {{{
  {
    $this->store .= $data;
    return true;
  }
  # }}}
  function close(?object &$error=null): bool # {{{
  {
    if (!$this->reader) {
      return true;
    }
    $this->reader = null;
    return true;
  }
  # }}}
}
# }}}
class SyncAggregate extends SyncReaderWriter # {{{
{
  # constructor {{{
  static function new(array $o): object
  {
    try
    {
      $id = self::getId($o);
      $size = self::getSize($o);
      return new static(
        $id, self::getTimeWait($o),
        ErrorEx::peep(SyncFlag::new($id.'-master')),
        ErrorEx::peep(SyncLock::new($id.'-lock')),
        ErrorEx::peep(SyncBuffer::new($id, $size))
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
    public int     $timeWait,
    public object  $reader,
    public object  $lock,
    public object  $data,
    public string  $store = '',
    public string  $chunk = ''
  ) {}
  # }}}
  # hlp {{{
  protected function dataWrite(# {{{
    string &$data, ?object &$error
  ):bool
  {
    # append and check failed
    $n = $this->data->append($data, $error);
    if ($n === -1) {
      return false;
    }
    # check fully written
    if ($n === strlen($data)) {
      return true;
    }
    # store leftover chunk
    $this->chunk = substr($data, $n);
    $this->time  = hrtime(true);
    return false;
  }
  # }}}
  protected function postpone(string &$data): void # {{{
  {
    if ($this->time === 0) {
      $this->time = hrtime(true);
    }
    $this->store .= $data;
  }
  # }}}
  protected function clear(?object &$error): bool # {{{
  {
    # when this instance has chunks,
    # the buffer is in overflow mode,
    # invalidate all the data
    if ($this->chunk !== '')
    {
      $this->lock->setWait(
        self::MIN_TIMEWAIT, $error
      );
      $this->data->clear($error);
      $this->lock->clear($error);
    }
    # reset properties
    $this->time  = 0;
    $this->store = $this->chunk = '';
    return true;# always positive
  }
  # }}}
  # }}}
  function isPending(): bool # {{{
  {
    return (
      $this->store !== '' ||
      $this->chunk !== ''
    );
  }
  # }}}
  function write(string &$data, ?object &$error=null): bool # {{{
  {
    # check empty
    if ($data === '') {
      return true;
    }
    # postpone when either this instance has chunks,
    # reader is offline or unable to lock
    $error = null;
    if ($this->chunk !== '' ||
        !$this->reader->getShared() ||
        $this->lock->set($error) <= 0)
    {
      $this->postpone($data);
      return $error === null;
    }
    # check buffer state
    switch ($this->data->size($error)) {
    case -2:# error
      break;
    case -1:# overflow
      $this->postpone($data);
      break;
    default:# ok
      # check and drain previous writes
      if ($this->store)
      {
        $data = $this->store.$data;
        $this->store = '';
      }
      # write
      $this->dataWrite($data, $error);
      break;
    }
    $this->lock->clear($error);
    return $error === null;
  }
  # }}}
  function flush(?object &$error=null): bool # {{{
  {
    # check nothing to do
    if (!$this->isPending()) {
      return true;
    }
    # check timeout
    if ($this->timeout())
    {
      $error = ErrorEx::info('timeout');
      return $this->clear($error);
    }
    # check reader is offline or unable to lock
    if (!$this->reader->getShared() ||
        $this->lock->set($error) <= 0)
    {
      return false;
    }
    # check buffer state
    switch ($this->data->size($error)) {
    case -2:# error
    case -1:# overflow
      $this->lock->clear($error);
      return false;
    }
    # first, flush-drain overflow,
    # next, flush-drain previous writes
    if ($this->chunk !== '')
    {
      if ($this->dataWrite($this->chunk, $error)) {
        $this->chunk = '';
      }
    }
    else
    {
      $this->dataWrite($this->store, $error);
      $this->store = '';
    }
    # unlock
    $this->lock->clear($error);
    # check still pending
    if ($this->isPending()) {
      return false;
    }
    # reset timer and complete
    if ($this->time) {
      $this->time = 0;
    }
    return true;
  }
  # }}}
  function close(?object &$error=null): bool # {{{
  {
    $this->clear($error);
    return $error === null;
  }
  # }}}
}
# }}}
###
