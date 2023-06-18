<?php declare(strict_types=1);
namespace SM;
# requirements {{{
use
  SyncSharedMemory,SyncSemaphore,SyncEvent,Throwable;
use function
  sys_get_temp_dir,file_exists,touch,preg_match,intval,
  getmypid,strval,strlen,substr,pack,unpack,hrtime,
  array_shift,array_unshift,is_array,is_int,is_string;
use function SM\{
  class_name,class_basename,dir_exists,dir_file_path,
  file_persist,file_unlink,file_touch,hrtime_delta_ms
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
        class_basename(static::class), __FUNCTION__,
        $id, strval($size)
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
        class_name($this), $this->id,
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
        class_name($this), $this->id,
        'SyncSharedMemory::read',
        'incorrect result length='.$i.' <> 4'
      );
    }
    # convert to integer value
    if (!is_array($a = unpack('l', $s)) ||
        !isset($a[1]) || ($i = $a[1]) < -1 ||
        $i > $this->sizeMax)
    {
      throw ErrorEx::fail(
        class_name($this), $this->id,
        'incorrect buffer size'
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
  protected function _writeSize(int $size): bool # {{{
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
        class_name($this), $this->id,
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
        ? 0 : $this->_writeData($data, 0);
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
# Flag/Lock {{{
class SyncFlag # {{{
{
  # constructor {{{
  static function new(string $id): object
  {
    try {
      return new static($id, new SyncEvent($id, 1, 0));
    }
    catch (Throwable $e)
    {
      return ErrorEx::set($e, ErrorEx::fail(
        class_basename(static::class), $id
      ));
    }
  }
  protected function __construct(
    public string $id,
    public object $event,
    public bool   $state = false
  ) {
    $this->restruct();
  }
  protected function restruct(): void
  {}
  function __destruct() {
    $this->clear();
  }
  # }}}
  # api {{{
  function sync(): bool {
    return $this->state = $this->event->wait(0);
  }
  function get(): bool {
    return $this->state || $this->event->wait(0);
  }
  function wait(int $ms=-1): bool {
    return $this->state || $this->event->wait($ms);
  }
  function set(?object &$error=null): bool {
    return $this->_trySet($error);
  }
  function setShared(?object &$error=null): bool {
    return $this->_trySet($error, true);
  }
  function clear(?object &$error=null): bool {
    return $this->_tryClear($error);
  }
  function clearShared(?object &$error=null): bool {
    return $this->_tryClear($error, true);
  }
  # }}}
  protected function _trySet(# {{{
    ?object &$error, bool $shared=false
  ):bool
  {
    try {
      return $this->_set($shared);
    }
    catch (Throwable $e) {
      return ErrorEx::set($error, $e)->val(false);
    }
  }
  # }}}
  protected function _tryClear(# {{{
    ?object &$error, bool $shared=false
  ):bool
  {
    try {
      return $this->_clear($shared);
    }
    catch (Throwable $e) {
      return ErrorEx::set($error, $e)->val(false);
    }
  }
  # }}}
  protected function _set(bool $shared): bool # {{{
  {
    # check already set
    if ($this->get()) {
      return true;
    }
    # set event
    if (!$this->event->fire())
    {
      throw ErrorEx::fail(
        class_name($this), $this->id,
        'SyncEvent::fire'
      );
    }
    # set exclusive state
    if (!$shared) {
      $this->state = true;
    }
    # success
    return true;
  }
  # }}}
  protected function _clear(bool $shared): bool # {{{
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
    if (!$this->event->reset())
    {
      throw ErrorEx::fail(
        class_name($this), $this->id,
        'SyncEvent::reset'
      );
    }
    # complete
    $this->state = false;
    return true;
  }
  # }}}
}
# }}}
class SyncLock # {{{
{
  # constructor {{{
  static function new(string $id): object
  {
    try
    {
      return new static($id,
        new SyncSemaphore($id, 1, 0),
        new SyncEvent($id.'-1', 1, 0)
      );
    }
    catch (Throwable $e)
    {
      return ErrorEx::set($e, ErrorEx::fail(
        class_basename(static::class), $id
      ));
    }
  }
  protected function __construct(
    public string $id,
    public object $sem,
    public object $e1,
    public bool   $state = false
  ) {
    $this->restruct();
  }
  protected function restruct(): void
  {}
  function __destruct() {
    $this->clear();
  }
  # }}}
  # hlp {{{
  protected function _trySet(# {{{
    ?object &$error, bool $shared=false, int $wait=0
  ):bool
  {
    try {
      return $this->_set($shared, $wait);
    }
    catch (Throwable $e) {
      return ErrorEx::set($error, $e)->val(false);
    }
  }
  # }}}
  protected function _tryClear(# {{{
    ?object &$error, bool $shared=false
  ):bool
  {
    try {
      return $this->_clear($shared);
    }
    catch (Throwable $e) {
      return ErrorEx::set($error, $e)->val(false);
    }
  }
  # }}}
  protected function _set(bool $shared, int $wait): bool # {{{
  {
    # check already locked by this instance
    if ($this->state) {
      return true;
    }
    # try to lock
    if (!$this->sem->lock($wait)) {
      return false;
    }
    # set event
    if (!$this->e1->fire())
    {
      $this->sem->unlock();
      throw ErrorEx::fail(
        class_name($this), $this->id,
        'SyncEvent::fire'
      );
    }
    # set state for exclusive (own) lock
    if (!$shared) {
      $this->state = true;
    }
    # complete
    return true;
  }
  # }}}
  protected function _clear(bool $shared): bool # {{{
  {
    # check not locked by this instance
    if (!$this->state)
    {
      # check not locked
      if (!$this->e1->wait(0)) {
        return true;
      }
      # locked by another instance,
      # deny exclusive access
      if (!$shared) {
        return false;
      }
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
    $i = -1;
    if (!$this->sem->unlock($i))
    {
      $this->e1->fire();
      throw ErrorEx::fail(
        class_name($this), $this->id,
        'SyncSemaphore::unlock', $i
      );
    }
    # complete
    $this->state = false;
    return true;
  }
  # }}}
  # }}}
  # api {{{
  function sync(): bool {
    return $this->state = $this->e1->wait(0);
  }
  function get(): bool {
    return $this->state || $this->e1->wait(0);
  }
  function set(?object &$error=null): bool {
    return $this->_trySet($error);
  }
  function setWait(int $ms=-1, ?object &$error=null): bool {
    return $this->_trySet($error, false, $ms);
  }
  function setShared(?object &$error=null): bool {
    return $this->_trySet($error, true);
  }
  function setSharedWait(int $ms=-1, ?object &$error=null): bool {
    return $this->_trySet($error, true, $ms);
  }
  function clear(?object &$error=null): bool {
    return $this->_tryClear($error);
  }
  function clearShared(?object &$error=null): bool {
    return $this->_tryClear($error, true);
  }
  # }}}
}
# }}}
abstract class SyncBaseFile # {{{
{
  # constructor {{{
  static function new(string $id, string $dir): object
  {
    try
    {
      $file = dir_file_path($dir, $id.'.sync');
      if (!dir_exists($file)) {
        throw ErrorEx::fail('incorrect path');
      }
      return new static(
        ErrorEx::peep(static::getBase($id)), $file
      );
    }
    catch (Throwable $e)
    {
      return ErrorEx::set($e, ErrorEx::fail(
        class_basename(static::class), $id, $file
      ));
    }
  }
  protected function __construct(
    public object $base,
    public string $file
  ) {
    $this->restruct();
  }
  protected function restruct(): void
  {}
  function __destruct() {
    $this->clear();
  }
  # }}}
  # api {{{
  function persist(): bool {
    return file_persist($this->file);
  }
  function get(): bool {
    return $this->base->get();
  }
  function set(?object &$error=null): bool
  {
    return (
      $this->base->set($error) &&
      file_touch($this->file, $error)
    );
  }
  function setShared(?object &$error=null): bool
  {
    return (
      $this->base->setShared($error) &&
      file_touch($this->file, $error)
    );
  }
  function clear(?object &$error=null): bool
  {
    return (
      $this->base->clear($error) &&
      file_unlink($this->file, $error)
    );
  }
  function clearShared(?object &$error=null): bool
  {
    return (
      $this->base->clearShared($error) &&
      file_unlink($this->file, $error)
    );
  }
  # }}}
  abstract static function getBase(string $id): object;
}
# }}}
class SyncFlagFile extends SyncBaseFile # {{{
{
  static function getBase(string $id): object {
    return SyncFlag::new($id);
  }
}
# }}}
class SyncLockFile extends SyncBaseFile # {{{
{
  static function getBase(string $id): object {
    return SyncLock::new($id);
  }
  function setWait(int $ms=-1, ?object &$error=null): bool
  {
    return (
      $this->base->setWait($ms, $error) &&
      file_touch($this->file, $error)
    );
  }
  function setSharedWait(int $ms=-1, ?object &$error=null): bool
  {
    return (
      $this->base->setSharedWait($ms, $error) &&
      file_touch($this->file, $error)
    );
  }
}
# }}}
trait SyncBaseMaster # {{{
{
  protected function restruct(): void
  {
    static $ERR='master is already running';
    if ($this->get()) {
      throw ErrorEx::fail($ERR);
    }
    if (!$this->set($error)) {
      throw $error;
    }
  }
}
# }}}
trait SyncBaseFileMaster # {{{
{
  protected function restruct(): void
  {
    static $ERR='master is already running';
    # check
    if ($this->get())
    {
      # master is already set,
      # abort when file persist or unable to clear
      $e = null;
      if ($this->persist() || !$this->clearShared($e)) {
        throw ErrorEx::set($e, ErrorEx::fail($ERR));
      }
    }
    # set
    if (!$this->set($error)) {
      throw $error;
    }
  }
}
# }}}
class SyncFlagMaster extends SyncFlag {use SyncBaseMaster;}
class SyncFlagFileMaster extends SyncFlagFile {use SyncBaseFileMaster;}
class SyncLockMaster extends SyncLock {use SyncBaseMaster;}
class SyncLockFileMaster extends SyncLockFile {use SyncBaseFileMaster;}
# }}}
# Readers-Writers
abstract class SyncReaderWriter # {{{
{
  const
    DEF_SIZE = 1000,
    TIMEOUT  = 3*1000;# N*ms
  ###
  public int $time=0;
  abstract static function new(array $o): object;
  function __destruct() {
    $this->close();
  }
  static function getId(array &$o): string # {{{
  {
    static $EXP_ID='/^[a-z0-9-]{1,64}$/i';
    if (!($id = $o['id'] ?? '') === '') {
      throw ErrorEx::fail('<id> is empty');
    }
    if (!preg_match($EXP_ID, $id)) {
      throw ErrorEx::fail('<id> is incorrect', $id);
    }
    return $id;
  }
  # }}}
  static function getDir(array &$o): string # {{{
  {
    if (!isset($o['dir'])) {
      return '';
    }
    if (!is_string($dir = $o['dir'])) {
      throw ErrorEx::fail('<dir> is incorrect');
    }
    if ($dir === '') {
      $dir = sys_get_temp_dir();
    }
    return $dir;
  }
  # }}}
  static function getSize(array &$o): int # {{{
  {
    if (!isset($o['size'])) {
      return 0;
    }
    if (!is_int($i = $o['size']) || $i < 1) {
      throw ErrorEx::fail('<size> is incorrect');
    }
    return $i;
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
  static function pid(): string # {{{
  {
    if (($i = getmypid()) === false) {
      throw ErrorEx::fail('getmypid() has failed');
    }
    return strval($i);
  }
  # }}}
  protected function timeout(int $ms=0, int $t=0): bool # {{{
  {
    $t = hrtime_delta_ms($this->time, $t);
    return $ms
      ? ($t > $ms)
      : ($t > self::TIMEOUT);
  }
  # }}}
  # api {{{
  function &read(?object &$error=null): string {
    $x = '';return $x;
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
      $id  = self::getId($o);
      $dir = self::getDir($o);
      $id0 = $id.'-master';
      $rid = $id.'-'.self::pid();
      $id1 = $id.'-lock';
      $id2 = $id.'-info';
      # create master-writer flag
      $writer = $dir
        ? SyncFlagFile::new($id0, $dir)
        : SyncFlag::new($id0);
      $reader = $dir
        ? SyncFlagFileMaster::new($rid, $dir)
        : SyncFlagMaster::new($rid);
      # construct
      return new static(
        $id, $rid, ErrorEx::peep($writer),
        ErrorEx::peep($reader),
        ErrorEx::peep(SyncLock::new($id1)),
        ErrorEx::peep(SyncFlag::new($id2.'-1')),
        ErrorEx::peep(SyncBuffer::new($id2, self::DEF_SIZE)),
        ErrorEx::peep(SyncFlag::new($rid.'-1')),
        null, $o['callback'] ?? null
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
    public string  $rid,
    public ?object $writer,
    public object  $reader,
    public object  $lock,
    public object  $infoFlag,
    public object  $infoBuf,
    public object  $dataFlag,
    public ?object $dataBuf,
    public ?object $callback,
    public array   $queue  = [],
    public int     $status = 0
  ) {
    $dataFlag->clearShared();
  }
  # }}}
  # hlp {{{
  protected function isReady(?object &$error): bool # {{{
  {
    $lock = false;
    switch ($this->status) {
    case -1:# hold {{{
      if ($this->timeout()) {
        $this->statusSet(0, 'retry', $error);
      }
      break;
    # }}}
    case  0:# registration {{{
      # check, lock, re-check
      if (!$this->writer->get()  ||
          $this->infoFlag->get() ||
          !($lock = $this->lock->set($error)) ||
          !$this->writer->get()  ||
          $this->infoFlag->get())
      {
        break;
      }
      # write registration info
      $a = '1:'.$this->rid;
      if ($this->infoBuf->write($a, $error) < 0) {
        break;
      }
      # enter next stage
      $this->statusSet(1, '', $error);
      break;
    # }}}
    case  1:# activation {{{
      # check writer escaped or info flag cleared
      if (!$this->writer->get() ||
          !$this->infoFlag->get())
      {
        $this->statusSet(-1, 'escape', $error);
        break;
      }
      # check no info arrived yet
      if (!$this->dataFlag->get())
      {
        # check timed out
        $this->timeout() &&
        $this->statusSet(-1, 'timeout', $error);
        break;
      }
      # try to lock
      if (!($lock = $this->lock->set($error))) {
        break;
      }
      # re-check
      if (!$this->writer->get()   ||
          !$this->infoFlag->get() ||
          !$this->dataFlag->get())
      {
        $this->statusSet(-1, 'escape', $error);
        break;
      }
      # ACTIVATE!
      # read and parse info
      $a = $this->infoBuf->read($error);
      if ($a === '' || ($i = intval($a)) < 1)
      {
        ErrorEx::set($error, ErrorEx::fail(
          'incorrect buffer size='.$a
        ));
        $this->statusSet(-1, 'fail', $error);
        break;
      }
      # construct data buffer
      if ($this->dataBuf === null ||
          $this->dataBuf->sizeMax !== $i)
      {
        $o = SyncBuffer::new($this->id, $i);
        if (ErrorEx::is($o))
        {
          ErrorEx::set($error, $o);
          $this->statusSet(-1, 'fail', $error);
          break;
        }
        $this->dataBuf = $o;
      }
      # report successful activation
      $a = '2:'.$this->rid;
      if ($this->infoBuf->write($a, $error) < 0)
      {
        $this->statusSet(-1, 'fail', $error);
        break;
      }
      # enter next stage
      $this->statusSet(2, '', $error);
      break;
    # }}}
    case  2:# checking {{{
      # check master-writer is running
      if ($this->writer->get()) {
        return true;
      }
      # get back to registration
      $this->statusSet(-1, 'TEST', $error);
      #$this->statusSet(0, 'escape', $error);
      break;
    # }}}
    }
    $lock && $this->lock->clear($error);
    return false;
  }
  # }}}
  protected function statusSet(# {{{
    int $new, string $info, ?object &$error
  ):void
  {
    # leaving stage..
    switch ($old = $this->status) {
    case 1:
      # should be set by the master
      $this->dataFlag->clearShared($error);
      break;
    }
    # entering stage..
    switch ($new) {
    case 1:
      # should be cleared by the master
      $this->infoFlag->setShared($error);
      break;
    }
    $this->time   = hrtime(true);
    $this->status = $new;
    if ($f = $this->callback) {
      $f($old, $info, $new);
    }
  }
  # }}}
  protected function infoWrite(# {{{
    string &$data, ?object &$error
  ):bool
  {
    $a = '4:'.$this->rid.':'.$data;
    return (
      ~$this->infoBuf->write($a, $error) &&
      $this->infoFlag->setShared($error)
    );
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
    $o = false;
    if ($this->isReady($error)  &&
        !$this->infoFlag->get() &&
        ($o = $this->lock->set($error)) &&
        $this->isReady($error)  &&
        !$this->infoFlag->get())
    {
      $this->infoWrite($data, $error);
    }
    else {
      $this->queue[] = $data;# postpone
    }
    # unlock and complete
    $o && $this->lock->clear($error);
    return $error === null;
  }
  # }}}
  function &read(?object &$error=null): string # {{{
  {
    # prepare
    $a = '';
    $o = false;
    # check, lock and re-check
    if ($this->isReady($error) &&
        $this->dataFlag->get() &&
        ($o = $this->lock->set($error)) &&
        $this->isReady($error) &&
        $this->dataFlag->get())
    {
      # read and clear flag
      $a = $this->dataBuf->readShared($error);
      $this->dataFlag->clearShared($error);
    }
    # unlock and complete
    $o && $this->lock->clear($error);
    return $a;
  }
  # }}}
  function flush(?object &$error=null): bool # {{{
  {
    if ($this->isReady($error)  &&
        $this->queue            &&
        !$this->infoFlag->get() &&
        ($o = $this->lock->set($error)) &&
        $this->isReady($error)  &&
        !$this->infoFlag->get())
    {
      $data = array_shift($this->queue);
      $this->infoWrite($data, $error);
    }
    return $error === null;
  }
  # }}}
  function close(?object &$error=null): bool # {{{
  {
    # check already closed
    if (!$this->writer) {
      return true;
    }
    # try to close gracefully
    if ($this->status === 2 &&
        $this->lock->setWait(self::TIMEOUT, $error))
    {
      $a = '0:'.$this->rid;
      $b = (
        $this->writer->get()    &&
        !$this->infoFlag->get() &&
        ~$this->infoBuf->write($a, $error) &&
        $this->infoFlag->setShared($error)
      );
      $this->lock->clear($error);
    }
    else {
      $b = false;
    }
    # cleanup
    $this->reader->clearShared($error);
    $this->statusSet(-2, ($b ? 'graceful' : ''), $error);
    $this->writer = null;
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
      $size = self::getSize($o) ?: self::DEF_SIZE;
      $id0  = $id.'-master';
      $id1  = $id.'-lock';
      $id2  = $id.'-info';
      # create master-writer flag
      $writer = $dir
        ? SyncFlagFileMaster::new($id0, $dir)
        : SyncFlagMaster::new($id0);
      # construct
      return new static(
        $id, ErrorEx::peep($writer),
        ErrorEx::peep(SyncLock::new($id1)),
        ErrorEx::peep(SyncFlag::new($id2.'-1')),
        ErrorEx::peep(SyncBuffer::new($id2, self::DEF_SIZE)),
        ErrorEx::peep(SyncBuffer::new($id, $size)),
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
    public ?object $writer,
    public object  $lock,
    public object  $infoFlag,
    public object  $infoBuf,
    public object  $dataBuf,
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
    # discard info and data
    $this->infoFlag->clearShared($error);
    $this->infoBuf->clear($error);
    $this->dataBuf->clear($error);
    # clear own running flag and unlock
    $this->writer->clear($error);
    $this->lock->clear($error);
    # cleanup
    $this->reader = $this->queue = [];
    $this->master = null;
    return $error === null;
  }
  # }}}
}
# }}}
/***
### SyncAggregate: MasterReader-SlaveWriters
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
      return $error === null;
    }
    return false;
  }
  # }}}
  function close(?object &$error=null): bool # {{{
  {
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
class SyncExchange extends SyncReaderWriter # {{{
{
  # constructor {{{
  static function new(array $o): object
  {
    try
    {
      # prepare
      $id   = self::getId($o);
      $dir  = self::getDir($o);
      $size = self::getSize($o) ?? self::DEF_SIZE;
      # create objects
      $flag = $dir
        ? SyncFlagFile::new($id.'-1', $dir)
        : SyncFlag::new($id.'-1');
      $lock = $dir
        ? SyncLockFile::new($id.'-2', $dir)
        : SyncLock::new($id.'-2');
      # construct
      return new static($id,
        ErrorEx::peep(SyncBuffer::new($id, $size)),
        ErrorEx::peep($flag), ErrorEx::peep($lock)
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
    public object $buf,
    public object $flag,
    public object $lock,
    public int    $ts = 0
  ) {}
  # }}}
  function &read(?object &$error=null): string # {{{
  {
  }
  # }}}
  function write(string &$data, ?object &$error=null): bool # {{{
  {
  }
  # }}}
  function flush(?object &$error=null): bool # {{{
  {
  }
  # }}}
  function close(?object &$error=null): bool # {{{
  {
  }
  # }}}
}
# }}}
/***/
###
