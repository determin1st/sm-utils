<?php declare(strict_types=1);
namespace SM;
# requirements {{{
use
  SyncSharedMemory,SyncMutex,SyncEvent,Throwable;
use function
  sys_get_temp_dir,file_exists,touch,
  strval,strlen,substr,pack,unpack,is_array;
use function SM\{
  class_name,class_basename,dir_exists,dir_file_path,
  file_persist,file_unlink,file_touch
};
use const
  DIRECTORY_SEPARATOR;
###
require_once __DIR__.DIRECTORY_SEPARATOR.'error.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'functions.php';
# }}}
class SyncBuffer # {{{
{
  static function new(string $id, int $size): object # {{{
  {
    try
    {
      # construct
      $buf = new self(
        $id, $size,
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
        class_basename(__CLASS__), $id, strval($size)
      ));
    }
  }
  # }}}
  protected function __construct(# {{{
    public string $id,
    public int    $max,
    public object $mem
  ) {}
  # }}}
  function &read(?object &$error=null): string # {{{
  {
    try
    {
      if (($data = &$this->_readData()) !== '') {
        $this->_writeSize(0);
      }
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
    try {
      return ~($i = $this->_readSize()) ? $i : $this->max;
    }
    catch (Throwable $e) {
      return ErrorEx::set($error, $e)->val(-1);
    }
  }
  # }}}
  function &_readData(): string # {{{
  {
    # read content size
    $data = '';
    if (($i = $this->_readSize()) === 0) {
      return $data;
    }
    if ($i === -1) {
      $i = $this->max;
    }
    # read content
    $data = $this->mem->read(4, $i);
    if ($i !== ($j = strlen($data)))
    {
      throw ErrorEx::fail(
        class_name($this), $this->id,
        'SyncSharedMemory::read',
        'incorrect result length='.$j.' <> '.$i
      );
    }
    return $data;
  }
  # }}}
  function _readSize(): int # {{{
  {
    # read size bytes
    $s = $this->mem->read(0, 4);
    if (($i = strlen($s)) !== 4)
    {
      throw ErrorEx::fail(
        class_name($this), $this->id,
        'SyncSharedMemory::read',
        'incorrect result length='.$i.' <> 4'
      );
    }
    # convert to integer value
    if (!is_array($a = unpack('l', $s)) ||
        !isset($a[1]) || $i < -1 || $i > $this->max)
    {
      throw ErrorEx::fail(
        class_name($this), $this->id,
        'incorrect buffer size'
      );
    }
    return $i;
  }
  # }}}
  function write(string &$data, ?object &$error=null): int # {{{
  {
    try {
      return $this->_write($data);
    }
    catch (Throwable $e) {
      return ErrorEx::set($error, $e)->val(-1);
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
  function _write(string &$data): int # {{{
  {
    # check empty
    if ($data === '') {
      return 0;
    }
    # check current size of the buffer
    if ($this->_readSize())
    {
      throw ErrorEx::fail(
        class_name($this), $this->id,
        'unable to write, buffer is dirty'
      );
    }
    # complete
    return $this->_writeData($data, 0);
  }
  # }}}
  function _append(string &$data): int # {{{
  {
    # check empty
    if ($data === '') {
      return 0;
    }
    # check current size of the buffer
    if ($this->_readSize() === -1)
    {
      throw ErrorEx::fail(
        class_name($this), $this->id,
        'unable to append, buffer is overflowed'
      );
    }
    # complete
    return $this->_writeData($data, $j);
  }
  # }}}
  function _writeData(string &$data, int $offs): int # {{{
  {
    # prepare
    $i = strlen($data);
    $j = $this->max - $offs;# free space
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
        class_name($this), $this->id,
        'SyncSharedMemory::write',
        'incorrect result='.$n.' <> '.$i
      );
    }
    # write size and complete
    $this->_writeSize($k);
    return $n;
  }
  # }}}
  function _writeSize(int $size): bool # {{{
  {
    $n = $this->mem->write(pack('l', $size), 0);
    if ($n !== 4)
    {
      throw ErrorEx::fail(
        class_name($this), $this->id,
        'SyncSharedMemory::write',
        'incorrect result='.$n.' <> 4'
      );
    }
    return true;
  }
  # }}}
}
# }}}
class SyncLock # {{{
{
  static function new(string $id, bool $own=false): object # {{{
  {
    try
    {
      # create new instance
      $lock = new self(
        $id, new SyncMutex($id),
        new SyncEvent($id.'-1', 1, 0)
      );
      # take ownership over locked state
      if ($own && $lock->e1->wait(0)) {
        $lock->state = true;
      }
      return $lock;
    }
    catch (Throwable $e)
    {
      return ErrorEx::set($e, ErrorEx::fail(
        class_basename(__CLASS__), $id
      ));
    }
  }
  # }}}
  protected function __construct(# {{{
    public string $id,
    public object $mx,
    public object $e1,
    public bool   $state = false
  ) {}
  # }}}
  function get(): bool # {{{
  {
    return $this->state || $this->e1->wait(0);
  }
  # }}}
  function set(?object &$error=null): bool # {{{
  {
    return $this->_state(true, $error);
  }
  # }}}
  function setWait(int $ms, ?object &$error=null): bool # {{{
  {
    try {
      return $this->_set($ms);
    }
    catch (Throwable $e) {
      return ErrorEx::set($error, $e)->val(false);
    }
  }
  # }}}
  function clear(?object &$error=null): bool # {{{
  {
    return $this->_state(false, $error);
  }
  # }}}
  function clearForce(?object &$error=null): bool # {{{
  {
    try
    {
      $this->state = true;# pretend locked
      return $this->_clear();
    }
    catch (Throwable $e) {
      return ErrorEx::set($error, $e)->val(false);
    }
  }
  # }}}
  function _state(bool $state, ?object &$error): bool # {{{
  {
    try
    {
      return $state
        ? $this->_set()
        : $this->_clear();
    }
    catch (Throwable $e) {
      return ErrorEx::set($error, $e)->val(false);
    }
  }
  # }}}
  function _set(int $wait=0): bool # {{{
  {
    # check already locked
    if ($this->state) {
      return true;
    }
    # try to lock
    if (!$this->mx->lock($wait)) {
      return false;
    }
    # set event
    if (!$this->e1->fire())
    {
      $this->mx->unlock();
      throw ErrorEx::fail(
        class_name($this), $this->id,
        'SyncEvent::fire'
      );
    }
    # complete
    $this->state = true;
    return true;
  }
  # }}}
  function _clear(): bool # {{{
  {
    # check not locked by this instance
    if (!$this->state)
    {
      return $this->e1->wait(0)
        ? false # locked by another instance
        : true; # no need to clear
    }
    # clear event
    if (!$this->e1->reset())
    {
      throw ErrorEx::fail(
        class_name($this), $this->id,
        'SyncEvent::reset'
      );
    }
    # unlock
    if (!$this->mx->unlock())
    {
      throw ErrorEx::fail(
        class_name($this), $this->id,
        'SyncMutex::unlock'
      );
    }
    # complete
    $this->state = false;
    return true;
  }
  # }}}
}
# }}}
class SyncLockFile # {{{
{
  static function new(# {{{
    string $id, string $file, bool $own=false
  ):object
  {
    try
    {
      if (!dir_exists($file)) {
        throw ErrorEx::fail('incorrect file path');
      }
      return new self(
        ErrorEx::get(SyncLock::new($id, $own)), $file
      );
    }
    catch (Throwable $e)
    {
      return ErrorEx::set($e, ErrorEx::fail(
        class_basename(__CLASS__), $id, $file
      ));
    }
  }
  # }}}
  protected function __construct(# {{{
    public object $lock,
    public string $file
  ) {}
  # }}}
  function persist(): bool # {{{
  {
    return file_persist($this->file);
  }
  # }}}
  function get(): bool # {{{
  {
    return $this->lock->get();
  }
  # }}}
  function set(?object &$error=null): bool # {{{
  {
    return (
      $this->lock->set($error) &&
      file_touch($this->file, $error)
    );
  }
  # }}}
  function setWait(int $ms, ?object &$error=null): bool # {{{
  {
    return (
      $this->lock->setWait($ms, $error) &&
      file_touch($this->file, $error)
    );
  }
  # }}}
  function clear(?object &$error=null): bool # {{{
  {
    return (
      $this->lock->clear($error) &&
      file_unlink($this->file, $error)
    );
  }
  # }}}
  function clearForce(?object &$error=null): bool # {{{
  {
    $a = $this->lock->clearForce($error);
    $b = file_unlink($this->file, $error);
    return $a && $b;
  }
  # }}}
}
# }}}
abstract class SyncInstance # {{{
{
  static function new(string $id, string $dir=''): object
  {
    try
    {
      if ($dir === '') {
        $dir = sys_get_temp_dir();
      }
      $file = dir_file_path($dir, $id);
      $id0  = $id.'-status';
      $id1  = $id.'-lock';
      $inst = new static($id,
        ErrorEx::get(SyncLockFile::new($id0, $file)),
        ErrorEx::get(SyncLock::new($id1))
      );
      if (!$inst->init($error)) {
        throw $error;
      }
      return $inst;
    }
    catch (Throwable $e)
    {
      return ErrorEx::set($e, ErrorEx::fail(
        class_basename(__CLASS__), $id, $dir
      ));
    }
  }
  protected function __construct(
    public string  $id,
    public ?object $status,
    public ?object $lock
  ) {}
  function __destruct() {
    $this->finit();
  }
  ###
  abstract function  init(?object &$error=null): bool;
  abstract function finit(?object &$error=null): bool;
}
# }}}
class SyncInstanceSlave extends SyncInstance # {{{
{
  function init(?object &$error=null): bool {
    return true;# nothing special here
  }
  function finit(?object &$error=null): bool
  {
    if ($this->status)
    {
      # slave is supposed to use a lock,
      # there is a chance it will forget to unlock
      $a = $this->lock->clear($error);
      $this->status = $this->lock = null;
      return $a;
    }
    return true;
  }
}
# }}}
class SyncInstanceMaster extends SyncInstance # {{{
{
  function init(?object &$error=null): bool
  {
    # check persistence
    while (($s = $this->status)->persist())
    {
      # when not locked, try to correct
      if (!$s->get() && $s->clearForce($error)) {
        break;
      }
      return ErrorEx::set($error, ErrorEx::fail(
        'instance is already running'
      ))->val(false);
    }
    # activate
    return $s->set($error);
  }
  function finit(?object &$error=null): bool
  {
    if ($this->status)
    {
      # deactivate
      $a = $this->lock->clear($error);
      $b = $this->status->clear($error);
      $this->status = $this->lock = null;
      return $a && $b;
    }
    return true;
  }
}
# }}}
abstract class SyncR1WN # {{{
{
  static function new(
    string $id, int $size, string $dir=''
  ):object
  {
    try
    {
      $id0 = $id.'-reader';
      $id1 = $id.'-lock';
      return new static(
        ErrorEx::get(static::newReader($id0, $dir)),
        ErrorEx::get(SyncLock::new($id1)),
        ErrorEx::get(SyncBuffer::new($id, $size))
      );
    }
    catch (Throwable $e)
    {
      return ErrorEx::set($e, ErrorEx::fail(
        class_basename(__CLASS__), $id, strlen($size)
      ));
    }
  }
  protected function __construct(
    public ?object $reader,
    public object  $lock,
    public object  $buf,
    public string  $data  = '',
    public string  $chunk = ''
  ) {}
  function __destruct() {
    $this->close();
  }
  ###
  abstract static function newReader(
    string $id, string $dir
  ):object;
  abstract function &read(?object &$error=null): string;
  abstract function write(string &$data, ?object &$error=null): bool;
  abstract function flush(?object &$error=null): bool;
  abstract function close(?object &$error = null): bool;
}
# }}}
class SyncR1WN_Reader extends SyncR1WN # {{{
{
  static function newReader(string $id, string $dir): object # {{{
  {
    return SyncInstanceMaster::new($id, $dir);
  }
  # }}}
  function &read(?object &$error=null): string # {{{
  {
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
    if ($this->reader)
    {
      $this->read($error);
      return ($error === null);
    }
    return false;
  }
  # }}}
  function close(?object &$error=null): bool # {{{
  {
    if ($this->reader)
    {
      echo '[DEACTIVATION]';
      $a = $this->lock->setWait(3000, $error);
      var_dump($a);
      $this->reader->finit($error);
      $this->buf->clear($error);
      $this->lock->clear($error);
      $this->reader = null;
      return ($error === null);
    }
    return true;
  }
  # }}}
}
# }}}
class SyncR1WN_Writer extends SyncR1WN # {{{
{
  static function newReader(string $id, string $dir): object # {{{
  {
    return SyncInstanceSlave::new($id, $dir);
  }
  # }}}
  function &read(?object &$error=null): string # {{{
  {
    $data = '';
    return $data;
  }
  # }}}
  function write(string &$data, ?object &$error=null): bool # {{{
  {
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
      return ($error === null);
    }
    # write, unlock and complete
    $a = $this->_write($data, $error);
    $b = $this->lock->clear($error);
    return $a && $b;
  }
  # }}}
  function flush(?object &$error=null): bool # {{{
  {
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
        return ($error === null);
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
        return ($error === null);
      }
      if ($this->_write($this->data, $error)) {
        $this->data = '';
      }
    }
    # unlock and complete
    $this->lock->clear($error);
    return ($error === null);
  }
  # }}}
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
  function close(?object &$error=null): bool # {{{
  {
    return true;
  }
  # }}}
}
# }}}
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
