<?php declare(strict_types=1);
# defs {{{
namespace SM;
use Generator,Throwable,CURLFile;
use function
  curl_init,curl_setopt_array,curl_errno,curl_error,
  curl_strerror,curl_getinfo,curl_reset,curl_close,
  curl_multi_init,curl_multi_setopt,curl_multi_errno,
  curl_multi_strerror,curl_multi_exec,curl_multi_select,
  curl_multi_add_handle,curl_multi_remove_handle,
  curl_multi_info_read,curl_multi_getcontent,
  curl_multi_close,
  ###
  is_scalar,is_array,is_object,is_string,is_int,
  is_bool,is_file,min,pow,strpos,substr,rtrim,ltrim,
  strtolower,strtoupper,ctype_upper,basename,
  json_encode,json_decode,json_last_error_msg,
  array_search,array_splice,array_is_list,array_shift,
  explode,count,http_build_query;
use const
  CURLOPT_POST,CURLOPT_URL,CURLOPT_HTTPHEADER,
  CURLOPT_HEADER,CURLOPT_POSTFIELDS,CURLOPT_TIMEOUT_MS,
  CURLOPT_RETURNTRANSFER,CURLOPT_CUSTOMREQUEST,
  CURLMSG_DONE,PHP_QUERY_RFC3986,JSON_UNESCAPED_UNICODE,
  DIRECTORY_SEPARATOR;
###
require_once __DIR__.DIRECTORY_SEPARATOR.'promise.php';
# }}}
class Fetch # {{{
{
  static function new(array $o): object # {{{
  {
    if ($e = FetchGear::$ERROR) {
      return $e;
    }
    try
    {
      $h = FetchGear::o_headers($o);
      return new self($h,
        FetchGear::o_options($o, $h),
        FetchGear::o_retry($o),
        FetchGear::o_string($o, 'baseUrl'),
        FetchGear::o_bool($o, 'mounted')
      );
    }
    catch (Throwable $e) {
      return ErrorEx::from($e);
    }
  }
  # }}}
  private function __construct(# {{{
    public array  &$headers,
    public array  &$options,
    public array  $retry,
    public string $baseUrl,
    public bool   $mounted
  ) {
    # simplified?
    if ($mounted)
    {
      $headers['content-type'] = 'application/json';
      $options[CURLOPT_POST] = true;
      $options[CURLOPT_URL]  = $baseUrl;
      $options[CURLOPT_HTTPHEADER] =
        FetchGear::headers_pack($headers);
    }
  }
  # }}}
  function __invoke(array $o=[]): object # {{{
  {
    try
    {
      return $this->mounted
        ? FetchGear::promise_easy($this, $o)
        : FetchGear::promise($this, $o);
    }
    catch (Throwable $e) {
      return Promise::from($e);
    }
  }
  # }}}
  function __call(string $name, array $args): object # {{{
  {
    try
    {
      # create options with the chosen method
      $o = ($name !== 'post')
        ? ['method' => strtoupper($name)]
        : [];# POST is the default
      # when instance has its url fixed,
      # treat first argument as content,
      # otherwise set both url and content
      if ($this->mounted)
      {
        if ($args) {
          $o['content'] = $args[0];
        }
      }
      else
      {
        switch (count($args)) {
        case 2:
          $o['content'] = $args[1];
        case 1:
          $o['url'] = $args[0];
        }
      }
      # construct
      return FetchGear::promise($this, $o);
    }
    catch (Throwable $e) {
      return Promise::from($e);
    }
  }
  # }}}
}
# }}}
class FetchGear # {{{
{
  # defaults {{{
  const MURLOPT = [
    #CURLMOPT_PIPELINING
    3 => 2,# multiplex http2
    #CURLMOPT_MAX_CONCURRENT_STREAMS
    #16 => 1000,# default(100)
  ];
  const CURLOPT = [
    CURLOPT_VERBOSE          => false,# debug output
    CURLOPT_RETURNTRANSFER   => true,
    CURLOPT_CONNECTTIMEOUT   => 5,# default(0): 300
    CURLOPT_TIMEOUT          => 0,# default(0): never
    CURLOPT_TIMEOUT_MS       => 0,# same as previous
    CURLOPT_FORBID_REUSE     => false,# do reuse
    CURLOPT_FRESH_CONNECT    => false,# do reuse
    CURLOPT_FOLLOWLOCATION   => false,# no redirects
    CURLOPT_HEADEROPT        => CURLHEADER_SEPARATE,
    CURLOPT_HEADER           => true,# response headers
    CURLOPT_PROTOCOLS        => (
      CURLPROTO_HTTP|CURLPROTO_HTTPS
    ),
    CURLOPT_HTTP_VERSION     => CURL_HTTP_VERSION_NONE,
    CURLOPT_PIPEWAIT         => false,# be lazy/multiplexy?
    CURLOPT_TCP_NODELAY      => true,
    CURLOPT_TCP_KEEPALIVE    => 1,
    CURLOPT_TCP_KEEPIDLE     => 300,
    CURLOPT_TCP_KEEPINTVL    => 300,
    CURLOPT_SSL_ENABLE_ALPN  => true,# negotiate to h2
    CURLOPT_SSL_VERIFYSTATUS => false,# require OCSP during the TLS handshake?
    CURLOPT_SSL_VERIFYHOST   => 0,# are you afraid of MITM?
    CURLOPT_SSL_VERIFYPEER   => false,# false allows self-signed certs
  ];
  const HEADERS = [
    'accept' => 'application/json',
  ];
  const RETRY = [
    'callback' => null,
    'fast'     => 0,# fast retry count (0:none,-1:unlimited)
    'slow'     => 0,# slow retry count (0:none,-1:unlimited)
    'pause'    => 1800,# first slow retry pause (ms)
    'backoff'  => 6,# pause progression steps
  ];
  # max easy-handles in the pool
  const MAX_HANDLES=50;
  # }}}
  # constructor {{{
  static ?object $ERROR=null;
  private static ?self $I=null;
  private function __construct(
    public object  $murl,
    public ?object $gen = null,
    public array   $handles = [],
    public array   $actions = []
  ) {}
  function __destruct()
  {
    $this->stop();
    curl_multi_close($this->murl);
  }
  # }}}
  # hlp {{{
  static function o_array(# {{{
    array $o, string $k
  ):array
  {
    if (!isset($o[$k])) {
      return [];
    }
    if (!is_array($o[$k])) {
      throw self::o_error($k, 'not an array');
    }
    return $o[$k];
  }
  # }}}
  static function o_string(# {{{
    array &$o, string $k, string $def=''
  ):string
  {
    if (isset($o[$k]))
    {
      if (!is_string($o[$k])) {
        throw self::o_error($k, 'not a string');
      }
      return $o[$k];
    }
    return $def;
  }
  # }}}
  static function o_int(# {{{
    array &$o, string $k, int $def=0
  ):int
  {
    if (isset($o[$k]))
    {
      if (!is_int($o[$k])) {
        throw self::o_error($k, 'not an integer');
      }
      return $o[$k];
    }
    return $def;
  }
  # }}}
  static function o_bool(# {{{
    array &$o, string $k, bool $def=false
  ):bool
  {
    if (isset($o[$k]))
    {
      if (!is_bool($o[$k])) {
        throw self::o_error($k, 'not a boolean');
      }
      return $o[$k];
    }
    return $def;
  }
  # }}}
  static function o_error(# {{{
    string $k, string $desc=''
  ):object
  {
    return ErrorEx::fail('option', $k, $desc);
  }
  # }}}
  static function o_method(array &$o): string # {{{
  {
    static $k='method';
    # check empty
    if (!($s = self::o_string($o, $k))) {
      return '';
    }
    # check correct
    if (!ctype_upper($s)) {
      throw self::o_error($k, 'must be uppercase');
    }
    return $s;
  }
  # }}}
  static function o_url(array &$o, string &$base): string # {{{
  {
    # check no url provided
    if (!($url = self::o_string($o, 'url'))) {
      return $base;# use base
    }
    # check protocol specified
    if (strpos($url, ':')) {
      return $url;# ignore base
    }
    # compose together
    return $base.$url;
  }
  # }}}
  static function o_headers(# {{{
    array $o, array $def=self::HEADERS
  ):array
  {
    # fetch option
    if (!($a = self::o_array($o, 'headers'))) {
      return $def;
    }
    # convert into hashmap representation
    if (array_is_list($a)) {
      $a = self::headers_unpack($a);
    }
    # complement with defaults
    foreach ($def as $k => $v)
    {
      if (!isset($a[$k])) {
        $a[$k] = $v;
      }
    }
    return $a;
  }
  # }}}
  static function &o_options(array $o, array &$h): array # {{{
  {
    # compose CURL options
    $a = self::CURLOPT;# start with defaults
    if (isset($o['options']))
    {
      foreach ($o['options'] as $k => $v) {
        $a[$k] = $v;
      }
    }
    # in case headers were issued in CURL format,
    # convert and move them into separate hashmap
    if (isset($a[CURLOPT_HTTPHEADER]))
    {
      # convert
      $b = self::headers_unpack(
        $a[CURLOPT_HTTPHEADER]
      );
      # move (replace)
      foreach ($b as $k => $v) {
        $h[$k] = $v;
      }
      # cleanup
      unset($a[CURLOPT_HTTPHEADER], $b);
    }
    # always establish timeout
    if ($i = self::o_int($o, 'timeout')) {
      $a[CURLOPT_TIMEOUT_MS] = $i;
    }
    return $a;
  }
  # }}}
  static function o_retry(# {{{
    array $o, array $defs=self::RETRY
  ):array
  {
    if ($a = self::o_array($o, 'retry'))
    {
      # update default set with specifics
      foreach ($defs as $k => &$v)
      {
        if (isset($a[$k])) {
          $v = $a[$k];
        }
      }
    }
    return $defs;
  }
  # }}}
  static function a_request(array $o, object $x): array # {{{
  {
    static $k0='content',$k1='content-type';
    # compose headers
    $a = self::o_headers($o, $x->headers);
    # compose body/content
    if (!isset($o[$k0]))
    {
      # no content provided,
      # body is empty - remove any type hint
      $b = '';
      if (isset($a[$k1])) {
        unset($a[$k1]);
      }
    }
    elseif (is_string($b = $o[$k0]))
    {
      # could be a custom encoding..
      if (!isset($a[$k1])) {
        $a[$k1] = 'text/plain';
      }
    }
    elseif (!is_array($b))
    {
      throw ErrorEx::fail(
        'incorrect type of content: '.gettype($b)
      );
    }
    elseif (self::has_file($b)) {
      $a[$k1] = 'multipart/form-data';
    }
    elseif (isset($o['formenc']))
    {
      $a[$k1] = 'application/x-www-form-urlencoded';
      $b = http_build_query($b, '', null,
        PHP_QUERY_RFC3986
      );
    }
    else
    {
      $a[$k1] = 'application/json';
      $b = json_encode($b,
        JSON_INVALID_UTF8_IGNORE
        |JSON_UNESCAPED_UNICODE
      );
      if ($b === false)
      {
        throw ErrorEx::fail('json_encode',
          json_last_error_msg()
        );
      }
    }
    # compose CURL request
    $c = $x->options;# copy
    $c[CURLOPT_URL] = self::o_url($o, $x->baseUrl);
    $c[CURLOPT_HTTPHEADER] = self::headers_pack($a);
    if ($method = self::o_method($o))
    {
      $c[CURLOPT_CUSTOMREQUEST] = $method;
      if ($b !== '') {
        $c[CURLOPT_POSTFIELDS] = $b;
      }
      elseif (isset($c[CURLOPT_POSTFIELDS])) {
        unset($c[CURLOPT_POSTFIELDS]);
      }
    }
    elseif ($b !== '')
    {
      $c[CURLOPT_POST] = true;
      $c[CURLOPT_POSTFIELDS] = $b;
    }
    else {# post with empty body
      $c[CURLOPT_CUSTOMREQUEST] = 'POST';
    }
    return $c;
  }
  # }}}
  static function has_file(array &$data): bool # {{{
  {
    foreach ($data as &$v)
    {
      if ($v instanceof CURLFile) {
        return true;
      }
    }
    return false;
  }
  # }}}
  static function headers_pack(array &$a): array # {{{
  {
    $list = [];
    foreach ($a as $k => &$v) {
      $list[] = $k.': '.$v;
    }
    return $list;
  }
  # }}}
  static function headers_unpack(array $a): array # {{{
  {
    $map = [];
    for ($i=0,$j=count($a); $i < $j; ++$i)
    {
      $b = $a[$i];
      if ($k = strpos($b, ':', 1))
      {
        $map[strtolower(rtrim(substr($b, 0, $k)))] =
          ltrim(substr($b, $k + 1));
      }
    }
    return $map;
  }
  # }}}
  static function murl_new(): object # {{{
  {
    if (!($murl = curl_multi_init())) {
      throw ErrorEx::fail('curl_multi_init');
    }
    return $murl;
  }
  # }}}
  static function murl_set(# {{{
    object $murl, array &$opts
  ):object
  {
    foreach ($opts as $k => $v)
    {
      if (!curl_multi_setopt($murl, $k, $v))
      {
        throw ErrorEx::fail('curl_multi_setopt',
          $k, self::murl_error($murl)
        );
      }
    }
    return $murl;
  }
  # }}}
  static function murl_error(object $murl): string # {{{
  {
    return ($e = curl_multi_errno($murl))
      ? '#'.$e.' '.curl_multi_strerror($e)
      : '';
  }
  # }}}
  static function murl_select(object $murl): int # {{{
  {
    if (($i = curl_multi_select($murl, 0)) >= 0) {
      return $i;
    }
    throw ErrorEx::fail('curl_multi_select',
      self::murl_error($murl)
    );
  }
  # }}}
  static function murl_exec(# {{{
    object $murl, int &$running
  ):int
  {
    if ($e = curl_multi_exec($murl, $running))
    {
      throw ErrorEx::fail('curl_multi_exec',
        curl_multi_strerror($e)
      );
    }
    return $running;
  }
  # }}}
  static function murl_info(object $murl): ?array # {{{
  {
    static $NONE=null;
    if (!($a = curl_multi_info_read($murl))) {
      return $NONE;
    }
    if ($a['msg'] !== CURLMSG_DONE)
    {
      throw ErrorEx::fail('curl_multi_info_read',
        self::murl_error($murl)
      );
    }
    return $a;
  }
  # }}}
  static function curl_new(): object # {{{
  {
    if (!($curl = curl_init())) {
      throw ErrorEx::fail('curl_init');
    }
    return $curl;
  }
  # }}}
  static function curl_set(# {{{
    object $curl, array &$opts
  ):object
  {
    if (!curl_setopt_array($curl, $opts))
    {
      throw ErrorEx::fail('curl_setopt_array',
        self::curl_error($curl)
      );
    }
    return $curl;
  }
  # }}}
  static function curl_error(object $curl): string # {{{
  {
    return ($e = curl_errno($curl))
      ? '#'.$e.' '.curl_error($curl)
      : '';
  }
  # }}}
  static function curl_attach(# {{{
    object $curl, object $murl
  ):object
  {
    if ($e = curl_multi_add_handle($murl, $curl))
    {
      throw ErrorEx::fail('curl_multi_add_handle',
        curl_multi_strerror($e)
      );
    }
    return $curl;
  }
  # }}}
  static function curl_detach(# {{{
    object $curl, object $murl
  ):object
  {
    if ($e = curl_multi_remove_handle($murl, $curl))
    {
      throw ErrorEx::fail('curl_multi_remove_handle',
        curl_multi_strerror($e)
      );
    }
    return $curl;
  }
  # }}}
  static function curl_info(object $curl): array # {{{
  {
    if ($x = curl_getinfo($curl)) {
      return $x;
    }
    throw ErrorEx::fail('curl_getinfo',
      self::curl_error($curl)
    );
  }
  # }}}
  # }}}
  # core {{{
  function attach(object $action): ?object # {{{
  {
    if (self::$ERROR) {
      return ErrorEx::skip();
    }
    try
    {
      # create new or get an easy-handle
      $curl = null;
      $curl = $this->handles
        ? array_shift($this->handles)
        : self::curl_new();
      # configure and add it to the multi-handle
      self::curl_set($curl, $action->request);
      self::curl_attach($curl, $this->murl);
      # set and store the action
      $action->curl = $curl;
      $this->actions[] = $action;
      return null;
    }
    catch (Throwable $e)
    {
      $curl && curl_close($curl);
      return ErrorEx::from($e);
    }
  }
  # }}}
  function detach(object $action): object # {{{
  {
    # detach from the multi-handle
    $curl = self::curl_detach(
      $action->curl, $this->murl
    );
    # check the pool is not full and
    # reset or close the easy-handle
    $pool = &$this->handles;
    if (count($pool) < self::MAX_HANDLES) {
      curl_reset($pool[] = $curl);
    }
    else {
      curl_close($curl);
    }
    # remove the action from the store
    $acts = &$this->actions;
    $i = array_search($action, $acts, true);
    if ($i === false) {
      throw ErrorEx::fail('action not found');
    }
    array_splice($acts, $i, 1);
    return $action;
  }
  # }}}
  function get(object $curl): object # {{{
  {
    $a = &$this->actions;
    for ($i=0,$j=count($a); $i < $j; ++$i)
    {
      if ($a[$i]->curl === $curl) {
        return $a[$i];
      }
    }
    throw ErrorEx::fail('action not found');
  }
  # }}}
  function set(object $curl, int $x): bool # {{{
  {
    static $k='headers';
    # get corresponding action
    $action = $this->get($curl);
    # check transfer failed
    if ($x)
    {
      $e = ErrorEx::warn(curl_strerror($x));
      return $this
        ->detach($action)
        ->reject($e);
    }
    # get the result
    $v = self::curl_info($curl);
    $s = curl_multi_getcontent($curl);
    # parse headers
    if ($action->request[CURLOPT_HEADER] &&
        ($i = $v['header_size']))
    {
      $a = explode("\r\n", rtrim(substr($s, 0, $i)));
      $s = substr($s, $i);
      $v['headers'] = self::headers_unpack($a);
    }
    else {
      $v['headers'] = [];
    }
    # set content
    switch ($v['content_type']) {
    case 'application/json':
      # try decoding
      $a = json_decode($s, true, 128,
        JSON_INVALID_UTF8_IGNORE
      );
      # check failed
      if ($a === null)
      {
        $a = ErrorEx::fail('json_decode',
          json_last_error_msg(), "\n".$s
        );
        return $this
          ->detach($action)
          ->reject($a, $v);
      }
      $v['content'] = $a;
      break;
    default:
      $v['content'] = $s;
      break;
    }
    # complete
    return $this
      ->detach($action)
      ->resolve($v);
  }
  # }}}
  function start(): Generator # {{{
  {
    try
    {
      # prepare
      $murl = $this->murl;
      $acts = &$this->actions;
      $cnt  = $n = count($acts);
      # operate
      while (self::murl_exec($murl, $n) === $cnt)
      {
        # wait for activity
        while (!self::murl_select($murl))
        {
          # postpone until continuation
          if (!yield) {
            throw ErrorEx::skip();
          }
          # when new handles added,
          # update total count and exit wait loop
          if (($i = count($acts)) !== $cnt)
          {
            $cnt = $i;
            break;
          }
        }
      }
      # drain complete transfers
      while ($a = self::murl_info($murl))
      {
        $this->set($a['handle'], $a['result']);
        $cnt--;
      }
      # check insufficient drain
      if ($cnt > $n) {
        throw ErrorEx::fail('insufficient drain');
      }
      # successful
      return null;
    }
    catch (Throwable $e) {
      return ErrorEx::from($e);
    }
  }
  # }}}
  function stop(): void # {{{
  {
    # cancel actions
    if ($a = &$this->actions)
    {
      foreach ($a as $o) {$o->cancel();}
      $a = [];
    }
    # close handles
    if ($a = &$this->handles)
    {
      foreach ($a as $o) {curl_close($o);}
      $a = [];
    }
    # stop generator
    if ($gen = &$this->gen)
    {
      $gen->valid() && $gen->send(false);
      $gen = null;
    }
  }
  # }}}
  function spin(): bool # {{{
  {
    # check dirty
    if ($err = &self::$ERROR) {
      return false;
    }
    # check generator
    if ($gen = &$this->gen) {
      $gen->send(true);# continue
    }
    else {
      $gen = $this->start();
    }
    # check unfinished
    if ($gen->valid()) {
      return true;
    }
    # get the result and cleanup
    $err = $gen->getReturn();
    $gen = null;
    # complete
    return !$err;
  }
  # }}}
  # }}}
  static function init(# {{{
    array $opts=self::MURLOPT
  ):bool
  {
    if (self::$I || self::$ERROR) {
      return false;
    }
    try
    {
      $murl = null;
      $murl = self::murl_new();
      self::murl_set($murl, $opts);
      self::$I = new self($murl);
      return true;
    }
    catch (Throwable $e)
    {
      $murl && curl_multi_close($murl);
      self::$ERROR = ErrorEx::from($e);
      return false;
    }
  }
  # }}}
  static function promise(# {{{
    object $x, array $o
  ):object
  {
    return new Promise(new FetchAction(
      self::$I, $x,
      self::a_request($o, $x),
      self::o_retry($o, $x->retry)
    ));
  }
  # }}}
  static function promise_easy(# {{{
    object $x, array $o
  ):object
  {
    $s = json_encode($o,
      JSON_INVALID_UTF8_IGNORE
      |JSON_UNESCAPED_UNICODE
    );
    if ($s === false)
    {
      throw ErrorEx::fail('json_encode',
        json_last_error_msg()
      );
    }
    $x->options[CURLOPT_POSTFIELDS] = $s;
    return new Promise(new FetchAction(
      self::$I, $x, $x->options, $x->retry
    ));
  }
  # }}}
}
# }}}
class FetchAction extends Completable # {{{
{
  function __construct(# {{{
    public object  $gear,
    public object  $master,
    public array   $request,
    public array   $retry,
    public int     $step  = 0,
    public ?object $curl  = null,
    public int     $fails = 0,
    public int     $pause = 0
  ) {}
  # }}}
  function complete(): ?object # {{{
  {
    # operate
    switch ($this->step) {
    case 0:# initialize
      $this->result->extend();
      $this->step++;
    case 1:# startup
      if ($e = $this->gear->attach($this))
      {
        return $e->hasError()
          ? $e : self::$THEN->delay();
      }
      $this->pause = 0;
      $this->step++;
    case 2:# spin
      if (!$this->gear->spin())
      {
        return self::$THEN
          ->delay($this->retry['pause']);
      }
      if ($this->curl)
      {
        return self::$THEN
          ->delay($this->pause);
      }
      $this->step++;
    }
    # complete
    return null;
  }
  # }}}
  function cancel(): ?object # {{{
  {
    switch ($this->step) {
    case 2:
      $this->gear->detach($this);
      $this->curl = null;
    case 1:
    case 0:
      $this->step = 3;
      break;
    }
    return null;
  }
  # }}}
  function resolve(array &$v): bool # {{{
  {
    $this->result->setRef($v);
    $this->curl = null;
    return true;
  }
  # }}}
  function reject(object $e, ?array &$v=null): bool # {{{
  {
    # retry warnings
    if ($e->isWarning() && $this->redo($e)) {
      return false;
    }
    # fail
    $this->result->setRef($v);
    $this->result->error($e);
    $this->curl = null;
    return true;
  }
  # }}}
  function redo(object $e): bool # {{{
  {
    # prepare
    $fails = ++$this->fails;# increment
    $pause = 200;# minimal pause
    $retry = &$this->retry;
    $fast  = $retry['fast'];
    $fast  = ($fast > 0 ? $fast : 0);
    $slow  = $retry['slow'];
    $all   = $fast + ($slow > 0 ? $slow : 0);
    # invoke callback
    if (($f = $retry['callback']) &&
        !$f($e, $fails, $pause))
    {
      return $e->raise()->val(false);
    }
    # retry fast
    if (($fast > 0 && $fails <= $fast) ||
        ($fast < 0))
    {
      $this->pause = $pause;
      $this->step  = 1;
      return true;
    }
    # retry slow
    if (($slow > 0 && $fails > $all) ||
        ($slow < 0))
    {
      $i = min($fails - $fast, $retry['backoff']);
      $j = $retry['pause'] / 1000;
      $this->pause = (int)(pow($j, $i) * 1000);
      $this->step  = 1;
      return true;
    }
    # no retry
    return $e->raise()->val(false);
  }
  # }}}
}
# }}}
class FetchFile extends CURLFile # {{{
{
  public bool $isTmp;
  static function new(
    string $path, bool $isTmp=false
  ):self
  {
    $file = new static($path);
    $file->isTmp    = $isTmp;
    $file->postname = basename($path);
    return $file;
  }
  static function of(&$file): ?self {
    return ($file instanceof self) ? $file : null;
  }
  function __destruct() {
    $this->destruct();
  }
  function destruct(): void
  {
    # remove temporary file
    if ($this->isTmp && $this->name)
    {
      Fx::try_file_unlink($this->name);
      $this->name = '';
    }
  }
}
# }}}
# TODO: object that could be sent as content
return FetchGear::init();
###
