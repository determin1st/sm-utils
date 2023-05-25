<?php declare(strict_types=1);
namespace SM;
# globals {{{
use
  Traversable,Closure,SplFixedArray;
use function
  is_callable,is_scalar,is_object,is_array,is_int,is_string,is_numeric,
  hash,method_exists,call_user_func,preg_quote,preg_match,
  str_replace,trim,ltrim,strlen,strpos,substr,
  sprintf,ord,htmlspecialchars,ctype_alnum,ctype_space,
  array_fill,array_key_exists,key,count,explode,array_shift,array_pop;
# }}}
class Mustache {
  # {{{
  const
    SPEC      = '1.1.2',
    NAME_SIZE = 32,# max {{name}} size (without delimiters/spacing)
    CACHE_MAX = 1000,# max number of cached templates
    DELIMS    = '{}[]()<>:%=~-_?*@!|',# valid delimiter chars
    TERMINUS  = "\nTEMPLATE";
  static
    $DELIMS_EXP = '/^([_]{2,4})(\s+)([_]{2,4})$/',
    $TEMPLATE   = '

function(object $x):string { #%s,depth=%s
return <<<TEMPLATE
%s
TEMPLATE;
}

    ';
  public
    $logger,$delims,$helper,$escaper,$recur,
    $cache,$hash,$text,$func,$total = 0;
  # }}}
  static function parseDelims(string $text): ?object # {{{
  {
    return preg_match(self::$DELIMS_EXP, $text, $x)
      ? SplFixedArray::fromArray([$x[1],$x[3]])
      : null;
  }
  # }}}
  static function construct(array $o = []): ?self # {{{
  {
    # initialize statics once
    if (self::$TEMPLATE[0] !== 'f')
    {
      self::$DELIMS_EXP = str_replace('_', preg_quote(self::DELIMS), self::$DELIMS_EXP);
      self::$TEMPLATE = str_replace("\r", "", trim(self::$TEMPLATE));
    }
    # create and initialize new instance
    $I = new self();
    if (isset($o[$k = 'logger']) && is_callable($o[$k])) {
      $I->logger = $o[$k];
    }
    if (!($I->delims = self::parseDelims($o['delims'] ?? '{{ }}')))
    {
      $I->log('incorrect delimiters', 1);
      return null;
    }
    if (isset($o[$k = 'helper'])) {
      $I->helper = &$o[$k];
    }
    $I->escaper = isset($o[$k = 'escaper'])
      ? (is_callable($o[$k]) ? $o[$k] : !!$o[$k])
      : false;
    $I->recur = isset($o[$k = 'recur'])
      ? $o[$k]
      : false;
    $I->cache = array_fill(0, 65536, null);# root (~4mb)
    $I->hash  = [null];
    $I->text  = [null];
    $I->func  = [null];
    return $I;
  }
  # }}}
  private function __construct() # {{{
  {}
  # }}}
  function __debugInfo() # {{{
  {
    return [
      'total' => $this->total
    ];
  }
  # }}}
  function log(string $text, int $level = 0): void # {{{
  {
    ($log = $this->logger) && $log($text, $level);
  }
  # }}}
  function render(# {{{
    string        $text,
    string|array  $p1,
    ?array        $p2 = null
  ):string
  {
    # check
    if (strlen($text) < 5) {
      return $text;
    }
    if ($p2 === null)
    {
      # context only
      $p2 = $p1;
      $p1 = $this->delims;
    }
    else
    {
      # delimiters and context
      if (!is_string($p1) || !($p1 = self::parseDelims($p1)))
      {
        $this->log('incorrect delimiters', 1);
        return $text;
      }
    }
    # check open delimiter present
    if (strpos($text, $p1[0]) === false) {
      return $text;
    }
    # check overflow
    if (($n = $this->total) >= self::CACHE_MAX)
    {
      $this->log('cache overflow', 1);
      return $text;
    }
    # create template function and
    # execute it within context
    $i = $this->renderFunc($p1, $text);
    $x = ~$i ?
      $this->func[$i](new MustacheContext($this, $p1, $p2))
      : '';
    # check failed or non-standard delimiters specified
    if ($i === -1 || $p1 !== $this->delims)
    {
      # revert cache to the previous state
      for ($i = $this->total - 1; $i >= $n; --$i)
      {
        $this->text[$i] = null;
        $this->func[$i] = null;
        $this->hash[$i] = null;
      }
      $this->total = $n;
    }
    # done
    return $x;
  }
  # }}}
  function renderFunc(# {{{
    object  $delims,
    string  &$text,
    ?array  &$tree = null,
    int     $depth = -1
  ):int
  {
    # check delimiters are default
    if ($delims === $this->delims)
    {
      # compute hash and checkout cache
      $k = hash('md4', $text, true);
      if (($i = $this->cacheGet($k)) !== null) {
        return $i;
      }
    }
    else {
      $k = null;
    }
    # create parse tree
    if ($tree === null)
    {
      $tree = &$this->tokenize($delims, $text);
      if (($tree = &$this->parse($text, $tree)) === null) {
        return -1;
      }
    }
    # create template renderer function
    $f = $this->compose($delims, $tree, ++$depth);
    $i = $this->total;# must go after composition
    $f = sprintf(self::$TEMPLATE, $i, $depth, $f);
    $this->func[$i] = eval("return ($f);");
    $this->text[$i] = $text;
    $this->hash[$i] = $k;
    $this->total    = $i + 1;
    $k && $this->log($f, 0);
    # complete
    return (!$k || $this->cacheSet($k, $i)) ? $i : -1;
  }
  # }}}
  function cacheGet(string $k): ?int # {{{
  {
    # determine root index
    $y = (ord($k[1]) << 8) + ord($k[0]);
    $x = $this->cache[$y];
    # lookup
    for ($i = 2; $i < 16; ++$i)
    {
      if ($x === null || is_int($x)) {
        break;
      }
      $x = $x[ord($k[$i])];
    }
    return $x;
  }
  # }}}
  function cacheSet(string $k, int $ki): bool # {{{
  {
    # determine root index
    $z = &$this->cache;
    $y = (ord($k[1]) << 8) + ord($k[0]);
    $x = $z[$y];
    # lookup
    for ($i = 2; $i < 16; ++$i)
    {
      if ($x === null)# free place
      {
        $z[$y] = $ki;
        return true;
      }
      if (is_int($x))# hold
      {
        # replace invalid
        if ($x >= $this->total)
        {
          $z[$y] = $ki;
          return true;
        }
        # allocate new bucket
        $b = new SplFixedArray(256);
        # put holder to a new position
        $a = $this->hash[$x];
        $b[ord($a[$i])] = $x;
        # replace holder with the bucket
        $z[$y] = $b;
      }
      # traverse to the next bucket
      $z = &$z[$y];
      $y = ord($k[$i]);
      $x = $z[$y];
    }
    $this->log('hash collision', 1);
    return false;
  }
  # }}}
  function &tokenize(# {{{
    object $delims,
    string &$text
  ):array
  {
    # prepare
    $tokens = [];
    $size0  = strlen($delims[0]);
    $size1  = strlen($delims[1]);
    $length = strlen($text);
    $i = $i0 = $i1 = $line = 0;
    # iterate
    while ($i0 < $length)
    {
      # search both newline and tag opening
      $a = strpos($text, "\n", $i0);
      $b = strpos($text, $delims[0], $i0);
      # check no more tags left
      if ($b === false)
      {
        # to be able to catch standalone tags later,
        # add next text chunk spearately
        if ($a !== false)
        {
          $tokens[$i++] = ['',substr($text, $i0, $a - $i0 + 1),$line++];
          $i0 = $a + 1;# move to the char after newline
        }
        # add last text chunk as a whole and complete
        $tokens[$i++] = ['',substr($text, $i0),$line];
        break;
      }
      # accumulate text tokens
      while ($a !== false && $a < $b)
      {
        $i1 = $a + 1;# move to the char after newline
        $tokens[$i++] = ['',substr($text, $i0, $i1 - $i0),$line++];
        $a = strpos($text, "\n", $i0 = $i1);
      }
      # check something left before the opening
      if ($i0 < $b)
      {
        # add last text token (at the same line)
        $c = substr($text, $i0, $b - $i0);
        $tokens[$i++] = ['',$c,$line];
        # determine indentation size
        $indent = (trim($c, " \t") ? -1 : strlen($c));
        $i0 = $b;
      }
      else {# opening at newline
        $indent = 0;
      }
      # the tag must not be empty, oversized or unknown, so,
      # find closing delimiter, check for false opening and
      # validate tag (first character)
      $b += $size0;# shift to the tag name
      if (($a = strpos($text, $delims[1], $b)) === false ||
          !($c = trim(substr($text, $b, $a - $b), ' ')) ||
          (!ctype_alnum($c[0]) && strpos('#^?|/.&!', $c[0]) === false) ||
          (strlen($c) > self::NAME_SIZE && $c[0] !== '!'))
      {
        # report as problematic but acceptable (not an error)
        $this->log('false tag skipped', 0);
        # check newline
        if ($i && !$tokens[$i - 1][0] &&
            substr($tokens[$i - 1][1], -1) === "\n")
        {
          ++$line;
        }
        # add false opening as a text token (at the same line)
        $tokens[$i++] = ['',$delims[0],$line];
        # continue after the false opening
        $i0 = $b;
        continue;
      }
      # determine position of the next char after the closing delimiter
      $i1 = $a + $size1;
      # add syntax token
      # [<0:type>,<1:name>,<2:line>,<3:indent>,<4:index0>,<5:index1>]
      switch ($c[0]) {
      case '#':
      case '^':
      case '?':
        # block start
        $tokens[$i++] = [$c[0],ltrim(substr($c, 1), ' '),$line,$indent,$i1];
        break;
      case '|':
        # inner block
        $tokens[$i++] = ['|',ltrim(substr($c, 1), ' '),$line,$indent,$i0,$i1];
        break;
      case '/':
        # block end
        $tokens[$i++] = ['/',ltrim(substr($c, 1), ' '),$line,$indent,$i0];
        break;
      case '!':
        # comment
        $tokens[$i++] = ['!','',$line,$indent];
        break;
      case '&':
        # tagged variable
        $c = $c[0].ltrim(substr($c, 1), ' ');
        # fallthrough..
      default:
        # variable
        $tokens[$i++] = ['_',$c,$line,$indent];
        break;
      }
      # continue
      $i0 = $i1;
    }
    # tokens collected,
    # clear standalone blocks
    # {{{
    # prepare
    $line = $size0 = $size1 = 0;
    $length = $i;
    # iterate
    for ($i = 0; $i <= $length; ++$i)
    {
      # check on the same line
      if ($i < $length && $line === $tokens[$i][2]) {
        ++$size0;# total tokens in a line
      }
      else
      {
        # line changed,
        # check line has any blocks that could be standalone
        if ($size1 && ($c = $size0 - $size1) && $c <= 2)
        {
          # get first and last token indexes
          $a = $i - $size0;
          $b = $i - 1;
          # check count difference
          if ($c === 1)
          {
            # one token isn't a block,
            # it must be the last (line terminator) or
            # the first (identation whitespace) text token
            if (!$tokens[$b][0] &&
                ctype_space($tokens[$b][1]))
            {
              $tokens[$b][1] = '';
            }
            elseif ($i === $length && !$tokens[$a][0] &&
                    ctype_space($tokens[$a][1]))
            {
              $tokens[$a][1] = '';
              break;# final block(s)
            }
          }
          else
          {
            # two tokens are not blocks,
            # check both first and last are whitespaces
            if (!$tokens[$a][0] && !$tokens[$b][0] &&
                ctype_space($tokens[$a][1]) &&
                ctype_space($tokens[$b][1]))
            {
              $tokens[$a][1] = $tokens[$b][1] = '';
            }
          }
        }
        # check the end
        if ($i === $length) {
          break;
        }
        # change line and reset counters
        $line  = $tokens[$i][2];
        $size0 = 1;
        $size1 = 0;
      }
      # count blocks
      if (($a = $tokens[$i][0]) && strpos('#^|/!', $a) !== false) {
        ++$size1;
      }
    }
    # }}}
    # complete
    $tokens[] = null;
    return $tokens;
  }
  # }}}
  function &parse(# {{{
    string  &$text,
    array   &$tokens,
    int     &$i = 0,
    ?array  &$p = null
  ):?array
  {
    static $NULL = null;
    # node:[<0:type>,<1:name>,<2:line>,<3:indent>,<4:size>,<5:[sect]>]
    # sect:[<0:condition>,<1:text>,<2:tree>,..]
    # prepare
    if ($p)
    {
      # first section's condition is the block condition
      $p[5][] = ($p[0] === '^') ? 0 : 1;
      $from = $p[4];
    }
    else {
      $from = -1;
    }
    $tree = [];
    $size = 0;
    # iterate tokens
    while ($t = &$tokens[$i++])
    {
      switch ($t[0]) {
      case '#':
      case '^':
      case '?':
        # block
        $t[5] = [];
        if (!$this->parse($text, $tokens, $i, $t)) {
          return $NULL;# something went wrong
        }
        elseif ($t[4]) {# non-empty
          $tree[$size++] = &$t;
        }
        break;
      case '|':
        # block section
        if ($p === null)
        {
          $this->log('unexpected: |'.$t[1].' at line '.$t[2], 1);
          return $NULL;
        }
        $p[5][] = substr($text, $from, $t[4] - $from);
        $p[5][] = $tree;
        $p[5][] = strlen($t[1])
          ? "'".$t[1]."'"
          : abs($p[5][0] - 1);
        $from = $t[5];
        $tree = [];
        $size = 0;
        break;
      case '/':
        # block terminator
        if ($p === null || $t[1] !== $p[1])
        {
          $this->log('unexpected: /'.$t[1].' at line '.$t[2], 1);
          return $NULL;
        }
        if ($size)
        {
          $p[5][] = substr($text, $from, $t[4] - $from);
          $p[5][] = &$tree;
          $p[4]   = count($p[5]);
        }
        else {
          $p[4] = 0;
        }
        return $p;
      default:
        # text/variable (non-empty)
        $t[1] && ($tree[$size++] = &$t);
        break;
      }
    }
    # check
    if ($p !== null)
    {
      $this->log('missing close: '.$p[1].' at line '.$p[2], 1);
      return $NULL;
    }
    return $tree;
  }
  # }}}
  function compose(# {{{
    object  $delims,
    array   &$tree,
    int     $depth
  ):string
  {
    $code = '';
    foreach ($tree as &$t)
    {
      switch ($t[0]) {
      case '':
        # text
        # apply heredoc guards
        if (strpos($t[1], '\\') !== false) {
          $t[1] = str_replace('\\', '\\\\', $t[1]);
        }
        if (strpos($t[1], '$') !== false) {
          $t[1] = str_replace('$', '\\$', $t[1]);
        }
        $code .= $t[1];
        break;
      case '_':
        # variable
        $code .= '{$x(\''.$t[1].'\')}';
        break;
      case '#':
      case '^':
      case '?':
        # block
        for ($x = '', $i = 0; $i < $t[4]; $i += 3)
        {
          $x .= ','.$t[5][$i].
                ','.$this->renderFunc($delims, $t[5][$i + 1], $t[5][$i + 2], $depth);
        }
        $code .= '{$x->f(\''.$t[0].$t[1]."'".$x.')}';
        break;
      }
    }
    # apply heredoc terminator guard and complete
    return (strpos($code, self::TERMINUS) !== false)
      ? str_replace(self::TERMINUS, '{$x}', $code)
      : $code;
  }
  # }}}
}
class MustacheContext # {{{
{
  public $stack = [null,null],$last = 1;
  function __construct(
    public object $engine,
    public object $delims,
    array|string &$context
  )
  {
    $this->engine = $engine;
    $this->delims = $delims;
    if ($engine->helper) {
      $this->stack[0] = &$engine->helper;
    }
    if ($context) {
      $this->stack[1] = &$context;
    }
  }
  function __toString() {
    return Mustache::TERMINUS;
  }
  function __invoke(string $name): string
  {
    # variable {{{
    # check tagged as reference
    if ($isRef = ($name[0] === '&')) {
      $name = substr($name, 1);
    }
    # resolve value
    $v = ($name === '.')
      ? $this->stack[$this->last]# implicit iterator
      : $this->v($name);# named
    # handle falsy
    if (!$v) {
      return $v === 0 ? '0' : '';
    }
    # handle function
    if ($isFunc = ($v instanceof Closure)) {
      $v = $v('');
    }
    # check proper type
    if (is_string($v))
    {
      # check template recursion
      if ($isFunc && $this->engine->recur &&
          strpos($v, $this->delims[0]) !== false &&
          strpos($v, $this->delims[1]) !== false)
      {
        # recurse
        $i = $this->engine->renderFunc($this->delims, $v);
        $v = ~$i ? $this->engine->func[$i]($this) : '';
      }
      elseif (!$isRef && ($f = $this->engine->escaper))
      {
        # escape characters
        $v = ($f === true)
          ? htmlspecialchars($v)
          : $f($v);
      }
      return $v;
    }
    elseif (is_numeric($v)) {
      return "$v";
    }
    return '';
    # }}}
  }
  function f(string $name, int|string ...$sect): string
  {
    # block {{{
    # prepare
    $e = $this->engine;
    $k = count($sect);
    $type = $name[0];
    $name = substr($name, 1);
    # resolve value
    $v = ($name === '.')
      ? $this->stack[$this->last]# implicit iterator
      : $this->v($name);# named
    # handle falsy ('',[],0,false,null)
    if (!$v && $v !== '0')
    {
      for ($i = 0; $i < $k; $i += 2)
      {
        if ($sect[$i] === 0) {
          return $e->func[$sect[$i + 1]]($this);
        }
      }
      return '';
    }
    # handle lambda block
    if (is_callable($v))
    {
      # invoke handler
      $v = ($k === 2)
        ? call_user_func($v, $e->text[$sect[1]])
        : call_user_func($v);
      # handle falsy result
      if (!$v && $v !== '0')
      {
        for ($i = 0; $i < $k; $i += 2)
        {
          if ($sect[$i] === 0) {
            return $e->func[$sect[$i + 1]]($this);
          }
        }
        return '';
      }
      # handle single sectioned block
      if ($k === 2)
      {
        # handle inverted block
        if (!$sect[0]) {
          return '';
        }
        # handle block content substitution
        if (is_string($v))
        {
          # check recursion enabled and feasible
          if (!$e->recur ||
              strpos($v, $this->delims[0]) === false ||
              strpos($v, $this->delims[1]) === false)
          {
            return $v;
          }
          # recurse
          return ~($i = $e->renderFunc($this->delims, $v))
            ? $e->func[$i]($this)
            : '';
        }
      }
      # continue..
    }
    # value is truthy,
    # determine section to render
    if ($k === 2)
    {
      # single section, must be truthy
      if ($sect[0] === 0) {
        return '';# falsy section, not rendered
      }
      $i = $sect[1];
    }
    elseif ($k === 4 && is_int($sect[2]))
    {
      # select truthy section
      $i = ($sect[0] === 1) ? $sect[1] : $sect[3];
    }
    elseif (is_scalar($v))
    {
      # search for switch section
      for ($x = "$v", $i = 2; $i < $k; $i += 2) {
        if ($sect[$i] === $x) {break;}
      }
      # check not found
      if ($i === $k)
      {
        # search for truthy/default section
        for ($i = 0; $i < $k; $i += 2) {
          if ($sect[$i] === 1) {break;}
        }
        # check not found
        if ($i === $k) {
          return '';
        }
      }
      $i = $sect[$i + 1];
    }
    else
    {
      # non-scalar, truthy value
      # search for truthy/default section
      for ($i = 0; $i < $k; $i += 2) {
        if ($sect[$i] === 1) {break;}
      }
      # check not found
      if ($i === $k) {
        return '';
      }
      $i = $sect[$i + 1];
    }
    $sect = $e->func[$i];
    # check block doesn't need value
    if ($type === '?') {
      return $sect($this);
    }
    # check value is iterable
    # - array must have numeric keys (assumed all or none)
    # - object must be traversable
    if ((($x = is_array($v)) && is_int(key($v))) ||
        (!$x && is_object($v) && ($v instanceof Traversable)))
    {
      # iterate, render and accumulate result
      $x = '';
      foreach ($v as &$i)
      {
        $this->stack[++$this->last] = &$i;
        $x .= $sect($this);
        $this->last--;
      }
      unset($i);
    }
    else
    {
      # expand, render and collapse context
      $this->stack[++$this->last] = &$v;
      $x = $sect($this);
      $this->last--;
    }
    # complete
    return $x;
    # }}}
  }
  function v(string $name)
  {
    # name resolution {{{
    # prepare
    if (strpos($name, '.') === false) {
      $dots = null;
    }
    else
    {
      $dots = explode('.', $name);
      $name = array_shift($dots);
    }
    # resolve the first name
    # iterate stack backwards
    for ($v = '', $i = $this->last; $i >= 0; --$i)
    {
      # checkout truthy frame
      if ($x = &$this->stack[$i])
      {
        # check array or object
        if (is_array($x))
        {
          # check property
          if (array_key_exists($name, $x))
          {
            $v = &$x[$name];
            break;
          }
        }
        elseif (is_object($x))
        {
          # check property
          if (isset($x->$name))
          {
            $v = $x->$name;
            break;
          }
          # check method
          if (method_exists($x, $name))
          {
            # wrap the last name's function and complete
            if (!$dots) {
              return Closure::fromCallable([$x, $name]);
            }
            # otherwise, use the call result for the further traversal
            $v = $x->$name();
            break;
          }
        }
      }
    }
    # check non-resolved or nothing more to resolve
    if (!$v || !$dots) {
      return $v;
    }
    # resolve dot notation
    # traverse the value (til the last name)
    $name = array_pop($dots);
    foreach ($dots as $i)
    {
      if (is_array($v))
      {
        # property must be set (may be callbable)
        if (isset($v[$i]))
        {
          if (is_callable($v[$i])) {
            $v = &$v[$i]();
          }
          else {
            $v = &$v[$i];
          }
          continue;
        }
      }
      elseif (is_object($v))
      {
        # property must be set, method must be called otherwise
        if (isset($v->$name))
        {
          $v = &$v->$name;
          continue;
        }
        elseif (method_exists($name, $v))
        {
          $v = &$v->$name();
          continue;
        }
      }
      return '';# traverse failed
    }
    # resolve the last name (array property/function or object property/method)
    if (is_array($v)) {
      return isset($v[$name]) ? $v[$name] : '';
    }
    if (is_object($v))
    {
      if (isset($v->$name)) {
        return $v->$name;
      }
      if (method_exists($name, $v)) {
        return Closure::fromCallable([$v, $name]);
      }
    }
    return '';
    # }}}
  }
}
# }}}
?>
