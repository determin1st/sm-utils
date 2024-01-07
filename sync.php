<?php declare(strict_types=1);
# defs {{{
namespace SM;
use
  SyncSharedMemory,SyncSemaphore,SyncEvent,
  Throwable,ArrayAccess;
use function
  sys_get_temp_dir,file_exists,touch,preg_match,intval,
  strval,strlen,substr,str_repeat,pack,unpack,ord,hrtime,
  array_shift,array_unshift,is_array,is_int,is_string,
  is_object,is_callable,is_dir;
use const
  DIRECTORY_SEPARATOR;
###
require_once __DIR__.DIRECTORY_SEPARATOR.'promise.php';
# }}}
class SyncBuffer # {{{
{
  # base {{{
  static function new(string $id, int $size): object
  {
    try
    {
      if ($size <= 0)
      {
        throw ErrorEx::fail(
          'incorrect size='.$size
        );
      }
      return new self(
        $id, $size,
        new SyncSharedMemory($id, 4 + $size)
      );
    }
    catch (Throwable $e)
    {
      return ErrorEx::set($e, ErrorEx::fail(
        __CLASS__, __FUNCTION__
      ));
    }
  }
  protected function __construct(
    public string $id,
    public int    $size,
    public object $mem
  ) {
    if ($mem->first()) {
      $this->clear();
    }
  }
  # }}}
  function sizeGet(): int # {{{
  {
    $a = $this->mem->read(0, 4);
    if (strlen($a) !== 4) {
      throw ErrorEx::fatal('SyncSharedMemory::read');
    }
    return unpack('l', $a)[1];
  }
  # }}}
  function sizeSet(int $size): int # {{{
  {
    $n = $this->mem->write(pack('l', $size), 0);
    if ($n !== 4) {
      throw ErrorEx::fatal('SyncSharedMemory::write');
    }
    return $size;
  }
  # }}}
  function get(int &$n=0): string # {{{
  {
    # read content size
    if (!($n = $this->sizeGet())) {
      return '';
    }
    $size = ($n < 0)
      ? $this->size # overflown
      : $n;
    # read content
    $data = $this->mem->read(4, $size);
    if (strlen($data) !== $size) {
      throw ErrorEx::fatal('SyncSharedMemory::read');
    }
    return $data;
  }
  # }}}
  function set(string $data, int $offs=0): int # {{{
  {
    static $ERR='SyncSharedMemory::write';
    # determine required space
    if (!($n = strlen($data))) {
      return 0;
    }
    # determine available space
    if ($offs < 0)
    {
      # append mode
      if (($offs = $this->sizeGet()) < 0 ||
          ($m = $this->size - $offs) <= 0)
      {
        return 0;
      }
    }
    elseif (($m = $this->size - $offs) <= 0)
    {
      throw ErrorEx::fail(
        __CLASS__, __FUNCTION__,
        'incorrect offset='.$offs.', '.
        'must be less than size='.$this->size
      );
    }
    # check overflow
    if ($n > $m)
    {
      # write one chunk
      $i = $this->mem->write(
        substr($data, 0, $m), 4+$offs
      );
      if ($i !== $m) {
        throw ErrorEx::fatal($ERR);
      }
      # set overflow
      $this->sizeSet(-1);
      return $m;
    }
    # write all
    $i = $this->mem->write($data, 4+$offs);
    if ($i !== $n) {
      throw ErrorEx::fatal($ERR);
    }
    # set total size
    return $this->sizeSet($offs + $n);
  }
  # }}}
  function clear(): void # {{{
  {
    $this->sizeSet(0);
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
    catch (Throwable $e)
    {
      return ErrorEx::set($e, ErrorEx::fail(
        __CLASS__, __FUNCTION__
      ));
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
    catch (Throwable $e)
    {
      return ErrorEx::set($e, ErrorEx::fail(
        __CLASS__, __FUNCTION__
      ));
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
    Fx::try_file_unlink($this->file);
    $this->event->reset();
  }
  # }}}
  function get(): bool # {{{
  {
    return (
      $this->event->wait(0) &&
      Fx::file_persist($this->file)
    );
  }
  # }}}
}
# }}}
class SyncNums implements ArrayAccess # {{{
{
  # base {{{
  static function new(string $id, int $count=1): object
  {
    try
    {
      return new self($id, $count,
        new SyncSharedMemory($id, 4*$count)
      );
    }
    catch (Throwable $e)
    {
      return ErrorEx::set($e, ErrorEx::fail(
        __CLASS__, __FUNCTION__
      ));
    }
  }
  protected function __construct(
    public string  $id,
    public int     $count,
    public object  $mem
  ) {
    if ($mem->first()) {
      $this->reset();
    }
  }
  # }}}
  # [] access {{{
  function offsetExists(mixed $k): bool {
    return ($k >= 0 || $k < $this->count);
  }
  function offsetGet(mixed $k): mixed {
    return $this->get($k);
  }
  function offsetSet(mixed $k, mixed $v): void {
    $this->set($v, $k);
  }
  function offsetUnset(mixed $k): void {
    $this->set(0, $k);
  }
  # }}}
  function get(int $k=0): int # {{{
  {
    $a = $this->mem->read(4*$k, 4);
    if (strlen($a) !== 4) {
      throw ErrorEx::fatal('SyncSharedMemory::read');
    }
    return unpack('l', $a)[1];
  }
  # }}}
  function set(int $x, int $k=0): void # {{{
  {
    $x = $this->mem->write(pack('l', $x), 4*$k);
    if ($x !== 4) {
      throw ErrorEx::fatal('SyncSharedMemory::write');
    }
  }
  # }}}
  function reset(): void # {{{
  {
    $a = 4 * $this->count;
    $b = $this->mem->write(str_repeat("\x00", $a));
    if ($a !== $b) {
      throw ErrorEx::fatal('SyncSharedMemory::write');
    }
  }
  # }}}
}
# }}}
class SyncLock # {{{
{
  # base {{{
  static function new(
    string $id, int $max=1, int $weight=1
  ):object
  {
    try
    {
      if ($max < 1 || $weight < 1 ||
          $max < $weight)
      {
        throw ErrorEx::fail(
          'incorrect argument(s): '.
          'max='.$max.', weight='.$weight
        );
      }
      return new self(
        $id, $max, $weight,
        ErrorEx::peep(SyncNums::new($id)),
        new SyncSemaphore($id.'-sem', 1, 0)
      );
    }
    catch (Throwable $e)
    {
      return ErrorEx::set($e, ErrorEx::fail(
        __CLASS__, __FUNCTION__
      ));
    }
  }
  protected function __construct(
    public string $id,
    public int    $max,
    public int    $weight,
    public object $num,
    public object $sem,
    public int    $locked = 0
  ) {}
  function __destruct()
  {
    try {$this->unlock();}
    catch (Throwable) {}
  }
  # }}}
  function get(): int # {{{
  {
    return $this->num->get();
  }
  # }}}
  function set(): bool # {{{
  {
    # check locked by this instance
    if ($this->locked) {
      return true;
    }
    # check locked by another instance
    $m = $this->max;
    $w = $this->weight;
    $n = ($num = $this->num)->get();
    if ($n + $w  > $m) {
      return false;
    }
    # set the guard
    if (!($sem = $this->sem)->lock(1000)) {
      throw ErrorEx::fatal('SyncSemaphore::lock');
    }
    # determine increment and check again
    if (($n = $num->get() + $w) > $m)
    {
      if (!$sem->unlock()) {
        throw ErrorEx::fatal('SyncSemaphore::unlock');
      }
      return false;
    }
    # complete
    $num->set($n);
    $this->locked = $w;
    if (!$sem->unlock()) {
      throw ErrorEx::fatal('SyncSemaphore::unlock');
    }
    return true;
  }
  # }}}
  function clear(): bool # {{{
  {
    # check not locked
    if (!($w = $this->locked)) {
      return true;
    }
    # set the guard
    if (!($sem = $this->sem)->lock(1000)) {
      throw ErrorEx::fatal('SyncSemaphore::lock');
    }
    # determine decrement
    $num = $this->num;
    if (($n = $num->get() - $w) < 0) {
      $n = 0;
    }
    # complete
    $num->set($n);
    $this->locked = 0;
    if (!$sem->unlock()) {
      throw ErrorEx::fatal('SyncSemaphore::unlock');
    }
    return true;
  }
  # }}}
}
# }}}
# Readers/Writers
abstract class SyncReaderWriter # {{{
{
  # option extractors {{{
  static function o_id(array $o): string # {{{
  {
    static $EXP_ID='/^[a-z0-9-]{1,64}$/i';
    static $k='id';
    if (!isset($o[$k]) || ($id = $o[$k]) === '' ||
        !preg_match($EXP_ID, $id))
    {
      throw self::o_fail($k);
    }
    return $id;
  }
  # }}}
  static function o_size(array $o): int # {{{
  {
    static $k='size';
    if (!isset($o[$k])) {
      return self::DEF_SIZE;
    }
    if (!is_int($i = $o[$k]) ||
        $i < 1 || $i > self::MAX_SIZE)
    {
      throw self::o_fail($k);
    }
    return $i;
  }
  # }}}
  static function o_dir(array $o): string # {{{
  {
    static $k='dir';
    if (!isset($o[$k])) {
      return '';
    }
    if (!is_string($dir = $o[$k])) {
      throw self::o_fail($k);
    }
    if ($dir === '') {
      $dir = sys_get_temp_dir();
    }
    return $dir;
  }
  # }}}
  static function o_int(# {{{
    array $o, string $k, int $def,
    ?array $minmax=null
  ):int
  {
    if (!isset($o[$k])) {
      return $def;
    }
    if (!is_int($i = $o[$k])) {
      throw self::o_fail($k);
    }
    if ($minmax &&
        ($i < $minmax[0] || $i > $minmax[1]))
    {
      throw self::o_fail($k);
    }
    return $i;
  }
  # }}}
  static function o_bool(# {{{
    array $o, string $k, bool $def
  ):bool
  {
    if (!isset($o[$k])) {
      return $def;
    }
    if (!is_bool($v = $o[$k])) {
      throw self::o_fail($k);
    }
    return $v;
  }
  # }}}
  static function o_instance(array $o): object # {{{
  {
    static $k0='instance-flag';
    static $k1='instance-id';
    if (isset($o[$k0]))
    {
      if (!is_object($x = $o[$k0])) {
        throw self::o_fail($k0);
      }
      return $x;
    }
    if (isset($o[$k1]))
    {
      if (!is_string($o[$k1]) ||
          ($id = $o[$k1]) === '')
      {
        throw self::o_fail($k1);
      }
    }
    else {
      $id = self::o_id($o).'-'.Fx::$PROCESS_ID;
    }
    return ErrorEx::peep(
      SyncFlagMaster::new($id, self::o_dir($o))
    );
  }
  # }}}
  static function o_callback(array $o): ?object # {{{
  {
    static $k='callback';
    if (!isset($o[$k])) {
      return null;
    }
    if (!is_callable($f = $o[$k])) {
      throw self::o_fail($k);
    }
    return $f;
  }
  # }}}
  static function o_fail(string $k): object # {{{
  {
    return ErrorEx::fail(static::class,
      'option "'.$k.'" is incorrect'
    );
  }
  # }}}
  # }}}
  const
    DEF_SIZE = 1000,# bytes
    MAX_SIZE = 1000000,# bytes
    TIMEOUT_TRANSFER = 100,# ms
    TIMEOUT_RESPONSE = 1000,# ms
    TIMEOUT_MAX      = 300*1000;# ms
  ###
  abstract static function new(array $o): object;
  abstract function read(): object;
  abstract function write(string $data): object;
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
      return new self(
        $id = self::o_id($o),
        self::o_int(
          $o, 'timeout', self::TIMEOUT_RESPONSE,
          [self::TIMEOUT_TRANSFER, self::TIMEOUT_MAX]
        ),
        self::o_bool(
          $o, 'shared', false
        ),
        ErrorEx::peep(SyncBuffer::new(
          $id, self::o_size($o)
        )),
        ErrorEx::peep(SyncLock::new($id.'-r')),
        ErrorEx::peep(SyncLock::new($id.'-w')),
        ErrorEx::peep(SyncNums::new($id.'-x')),
      );
    }
    catch (Throwable $e)
    {
      return ErrorEx::set($e, ErrorEx::fail(
        __CLASS__, __FUNCTION__
      ));
    }
  }
  protected function __construct(
    public string  $id,
    public int     $timeout,# response timeout
    public bool    $shared,# multiple readers/writers
    public object  $data,# buffer
    public object  $reader,
    public object  $writer,
    public object  $state,
    public ?object $action = null
  ) {}
  # }}}
  function read(): object # {{{
  {
    if ($this->action)
    {
      throw ErrorEx::fail(
        __CLASS__, __FUNCTION__,
        'previous action is not finished'
      );
    }
    return Promise::Context(
      $this->action =
      new SyncExchangeRead($this)
    );
  }
  # }}}
  function write(string $data): object # {{{
  {
    if ($a = $this->action)
    {
      throw ErrorEx::fail(
        __CLASS__, __FUNCTION__,
        'previous action is not finished'
      );
    }
    return Promise::Context(
      $this->action =
      new SyncExchangeWrite($this, $data)
    );
  }
  # }}}
}
# }}}
class SyncExchangeRead extends Completable # {{{
{
  const STAGE = [# {{{
    1=>'request entry',
    2=>'request expect',
    3=>'request read',
    4=>'response entry',
    5=>'response write',
    6=>'response write chunk',
    7=>'response confirmation',
    8=>'exchange completion'
  ];
  # }}}
  function __construct(# {{{
    public object $base,
    public string $value = '',
    public int    $stage = 1,
    public int    $time  = 0,
    public int    $cycle = 0
  ) {}
  # }}}
  # helpers {{{
  function readChunk(): int # {{{
  {
    $data = $this->base->data;
    $this->value .= $data->get($size);
    if ($size)
    {
      $this->time = self::$HRTIME;
      $data->clear();
    }
    return $size;
  }
  # }}}
  function writeFirst(int $next): self # {{{
  {
    # update the time
    $this->time = self::$HRTIME;
    # write and check bytes written
    $n = $this->base->data->set($this->value);
    if ($n < strlen($this->value))
    {
      # more chunks to write,
      # cut the value for the next write
      $this->value = substr($this->value, $n);
      $this->stage = $next;
    }
    else {
      $this->stage = $next + 1;
    }
    return $this;
  }
  # }}}
  function writeNext(): ?object # {{{
  {
    # check buffer is dirty
    $data = $this->base->data;
    if ($data->sizeGet()) {
      return $this->checkTimeout();
    }
    # write the next chunk
    $n = $data->set($this->value);
    if ($n < strlen($this->value)) {
      $this->value = substr($this->value, $n);
    }
    else {
      $this->stage++;
    }
    $this->time = self::$HRTIME;
    return $this;
  }
  # }}}
  function checkTimeout(int $ms=0): ?object # {{{
  {
    $expired = Fx::hrtime_expired(
      $ms ?: $this->base::TIMEOUT_TRANSFER,
      self::$HRTIME, $this->time
    );
    if (!$expired) {
      return self::$THEN->delay();
    }
    $this->result->fail(static::class,
      'timed out ('.static::STAGE[$this->stage].')'
    );
    return null;
  }
  # }}}
  function badState(int $n): void # {{{
  {
    $this->result->fail(static::class,
      'incorrect state='.$n.' of the exchange'
    );
  }
  # }}}
  # }}}
  function complete(): ?object # {{{
  {
    $base = $this->base;
    switch ($this->stage) {
    ### request
    case 1:# aquire the lock {{{
      if (!$base->reader->set()) {
        return self::$THEN->delay();
      }
      $this->time = self::$HRTIME;
      $this->stage++;
      return $this;
      # }}}
    case 2:# wait for the first request {{{
      # check current state
      switch ($n = $base->state->get()) {
      case 0:# nothing
        # check standalone
        if (!$base->shared) {
          return self::$THEN->delay();
        }
        # give other readers a chance
        $expired = Fx::hrtime_expired(
          2000, self::$HRTIME, $this->time
        );
        if (!$expired) {
          return self::$THEN->delay();
        }
        $base->reader->clear();
        $this->stage--;
        return self::$THEN->delay(100);
      case 1:# request
        # move to the next stage
        $this->time = self::$HRTIME;
        $this->stage++;
        return $this;
      }
      return $this->badState($n);
      # }}}
    case 3:# read the request {{{
      # read and check
      if (!($n = $this->readChunk())) {
        return $this->checkTimeout();
      }
      if ($n < 0) {# more to read
        return $this;
      }
      # complete at this point
      $this->stage++;
      return null;
      # }}}
    ### response
    case 4:# neutral entry {{{
      $this->time = self::$HRTIME;
      $this->stage++;
      return $this;
      # }}}
    case 5:# write the response (first) {{{
      # check current state
      switch ($n = $base->state->get()) {
      case 0:
        $this->result->fail(__CLASS__,
          "response is not expected,\n".
          "the writer has finished the exchange.\n".
          "please, revise the exchange protocol!"
        );
        return null;
      case 1:# request (previous state)
        return $this->checkTimeout();
      case 2:# response
        return $this->writeFirst(6);
      }
      return $this->badState($n);
      # }}}
    case 6:# write the response (next) {{{
      return $this->writeNext();
      # }}}
    case 7:# wait the data is read {{{
      # check buffer is dirty
      if ($base->data->sizeGet()) {
        return $this->checkTimeout();
      }
      # move to the next stage
      $this->stage++;
      return $this;
      # }}}
    case 8:# wait complete {{{
      # check current state
      switch ($n = $base->state->get()) {
      case 0:# nothing
        # complete the exchange
        return $this->done();
      case 1:# request (a new cycle)
        # continue the exchange
        $this->value = '';
        $this->stage = 3;
        $this->time  = self::$HRTIME;
        $this->cycle++;
        return $this;
      case 2:# response (the previous state)
        return $this->checkTimeout();
      }
      return $this->badState($n);
      # }}}
    }
    return self::$THEN->abort();
  }
  # }}}
  function cancel(): ?object # {{{
  {
    return $this->done();
  }
  # }}}
  function done(): void # {{{
  {
    if ($this->stage)
    {
      $base = $this->base;
      $base->action = null;
      if ($this->stage > 1) {
        $base->reader->clear();
      }
      $this->stage = 0;
    }
  }
  # }}}
  function write(string $data): object # {{{
  {
    $this->value = $data;
    $this->time  = self::$HRTIME;
    return Promise::Context($this);
  }
  # }}}
}
# }}}
class SyncExchangeWrite extends SyncExchangeRead # {{{
{
  const STAGE = [# {{{
    1=>'request entry',
    2=>'request lock',
    3=>'request write',
    4=>'request chunk write',
    5=>'request confirmation',
    6=>'response accept',
    7=>'response read'
  ];
  # }}}
  function complete(): ?object # {{{
  {
    $base = $this->base;
    switch ($this->stage) {
    ### request
    case 1:# entry {{{
      $this->time = self::$HRTIME;
      $this->stage++;
      return $this;
      # }}}
    case 2:# lock {{{
      if (!$base->writer->set()) {
        return $this->checkTimeout();
      }
      $this->stage++;
      return $this;
      # }}}
    case 3:# write first {{{
      # write and set the state
      $this->writeFirst(4);
      $base->state->set(1);
      return $this;
      # }}}
    case 4:# write next {{{
      return $this->writeNext();
      # }}}
    case 5:# confirm {{{
      # check buffer is dirty
      if ($base->data->sizeGet()) {
        return $this->checkTimeout();
      }
      # shift to the next stage and
      # complete at this point
      $this->stage++;
      return null;
      # }}}
    ### response
    case 6:# entry {{{
      $base->state->set(2);
      $this->time = self::$HRTIME;
      $this->stage++;
      return $this;
      # }}}
    case 7:# read {{{
      # read and check
      if (!($n = $this->readChunk())) {
        return $this->checkTimeout($base->timeout);
      }
      if ($n < 0) {# more to read
        return $this;
      }
      # complete at this point
      $this->stage = 3;
      $this->cycle++;
      return null;
      # }}}
    }
    return self::$THEN->abort();
  }
  # }}}
  function cancel(): ?object # {{{
  {
    return $this->done();
  }
  # }}}
  function done(): void # {{{
  {
    if ($this->stage)
    {
      $base = $this->base;
      $base->action = null;
      if ($this->stage > 2)
      {
        $base->state->set(0);
        $base->writer->clear();
      }
      $this->stage = 0;
    }
  }
  # }}}
  function read(): object # {{{
  {
    $this->value = $data;
    $this->time  = self::$HRTIME;
    return Promise::Context($this);
  }
  # }}}
}
# }}}
/***
class SyncBroadcastMaster extends SyncReaderWriter # {{{
{
  # Broadcast: one writer, many readers
  # constructor {{{
  static function new(array $o): object
  {
    try
    {
      $id   = self::o_id($o);
      $size = self::o_size($o);
      $flag = ErrorEx::peep(SyncFlagMaster::new(
        $id.'-master', self::o_dir($o)
      ));
      $info = ErrorEx::peep(SyncExchange::new([
        'id'   => $id.'-info',
        'size' => 200,
      ]));
      return new self(
        $id, $flag, $info,
        ErrorEx::peep(SyncBuffer::new($id, $size)),
        self::o_callback($o)
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
  function write(string $data, ?object &$error=null): bool # {{{
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
      $id     = self::o_id($o);
      $reader = self::o_instance($o);
      $writer = ErrorEx::peep(
        SyncFlag::new($id.'-master')
      );
      $info = ErrorEx::peep(SyncExchange::new([
        'id'   => $id.'-info',
        'size' => 200,
      ]));
      # construct
      return new self(
        $id, self::o_timeout($o),
        $reader, $writer, $info,
        ErrorEx::peep(SyncFlag::new(
          $reader->id.'-data'
        )),
        self::o_callback($o)
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
  function write(string $data, ?object &$error=null): bool # {{{
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
      $id   = self::o_id($o);
      $size = self::o_size($o);
      $flag = ErrorEx::peep(SyncFlagMaster::new(
        $id.'-master', self::o_dir($o)
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
  function write(string $data, ?object &$error=null): bool # {{{
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
      $id = self::o_id($o);
      $sz = self::o_size($o);
      return new static(
        $id, self::o_timeout($o),
        ErrorEx::peep(SyncFlag::new($id.'-master')),
        ErrorEx::peep(SyncLock::new($id.'-lock')),
        ErrorEx::peep(SyncBuffer::new($id, $sz))
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
  function write(string $data, ?object &$error=null): bool # {{{
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
/***/
###
