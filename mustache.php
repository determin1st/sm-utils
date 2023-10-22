<?php declare(strict_types=1);
# defs {{{
namespace SM;
use Countable,Closure;
use function
  is_callable,is_scalar,is_object,is_array,
  is_bool,is_string,method_exists,abs,hash,
  htmlspecialchars,ctype_alnum,ctype_space,
  preg_replace,str_replace,trim,ltrim,strval,
  strlen,strpos,substr,addcslashes,str_repeat,
  count,explode,array_is_list;
use const
  DIRECTORY_SEPARATOR;
###
require_once __DIR__.DIRECTORY_SEPARATOR.'error.php';
# }}}
interface Mustachable # {{{
{
  # type:
  #  0=unknown,
  #  1=string,2=numeric,3=bool,
  #  4=array,5=object,6=Mustachable,
  #  7=callable, 7+N=callable+result
  ###
  const STASH=[];# name => type
}
# }}}
class Mustache # {{{
{
  # constructor {{{
  static string  $EMPTY='';# hash of an empty string
  public string  $s0='{{',$s1='}}';# default delimiters
  public int     $n0=2,$n1=2;# delimiter sizes
  public bool    $pushed=false;# data pushed to stack
  public ?object $escape=null;# callable
  public int     $index=0;# hash/text/code/func
  public array   $hash=[],$text=[''];
  public array   $code=[''],$func=[];
  public array   $cache=[];# hash=>index
  public object  $ctx;
  private function __construct()
  {
    $this->cache[self::$EMPTY] = 0;
    $this->hash[] = self::$EMPTY;
    $this->func[] = (
      static function():string {return '';}
    );
    $this->ctx = new MustacheCtx($this);
  }
  static function new(array $o=[]): object
  {
    $I = new self();
    if (isset($o[$k = 'delims']))
    {
      $sep   = explode(' ', $o[$k], 2);
      $I->n0 = strlen($I->s0 = $sep[0]);
      $I->n1 = strlen($I->s1 = $sep[1]);
    }
    if (isset($o[$k = 'escape']))
    {
      $I->escape = is_callable($o[$k])
        ? $o[$k] : htmlspecialchars(...);
    }
    if (isset($o[$k = 'helper'])) {
      $I->helper($o[$k]);
    }
    return $I;
  }
  # }}}
  # hlp {{{
  static function init(): bool # {{{
  {
    if (self::$EMPTY === '') {
      self::$EMPTY=hash('xxh3', '', true);
    }
    return true;
  }
  # }}}
  function __debugInfo(): array # {{{
  {
    return [
      'cache size' => 1 + $this->index,
    ];
  }
  # }}}
  function _tokenize(# {{{
    string $tpl, string $s0, string $s1,
    int $n0, int $n1
  ):array
  {
    # prepare
    $tokens = [];
    $length = strlen($tpl);
    $i = $i0 = $i1 = $line = 0;
    # iterate
    while ($i0 < $length)
    {
      # search both newline and tag opening
      $a = strpos($tpl, "\n", $i0);
      $b = strpos($tpl, $s0, $i0);
      # check no more tags left
      if ($b === false)
      {
        # to be able to catch standalone tags later,
        # add next text chunk spearately
        if ($a !== false)
        {
          $tokens[$i++] = ['',substr($tpl, $i0, $a - $i0 + 1),$line++];
          $i0 = $a + 1;# move to the char after newline
        }
        # add last text chunk as a whole and complete
        $tokens[$i++] = ['',substr($tpl, $i0),$line];
        break;
      }
      # accumulate text tokens
      while ($a !== false && $a < $b)
      {
        $i1 = $a + 1;# move to the char after newline
        $tokens[$i++] = [
          '',substr($tpl, $i0, $i1 - $i0),
          $line++
        ];
        $a = strpos($tpl, "\n", $i0 = $i1);
      }
      # check something left before the opening
      if ($i0 < $b)
      {
        # add last text token (at the same line)
        $c = substr($tpl, $i0, $b - $i0);
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
      $b += $n0;# shift to the tag name
      if (!($a = strpos($tpl, $s1, $b)) ||
          !($c = trim(substr($tpl, $b, $a - $b))))
      {
        # SKIP INCORRECT TAG..
        # check newline
        if ($i && !$tokens[$i - 1][0] &&
            substr($tokens[$i - 1][1], -1) === "\n")
        {
          ++$line;
        }
        # add false opening as a text token (at the same line)
        $tokens[$i++] = ['',$s0,$line];
        # continue after the false opening
        $i0 = $b;
        continue;
      }
      # determine position of the next character
      # after the closing delimiter
      $i1 = $a + $n1;
      # add syntax token
      switch ($c[0]) {
      case '#':
      case '^':
      case '@':
        # block start
        $tokens[$i++] = [
          $c[0],ltrim(substr($c, 1), ' '),
          $line,$indent,$i1
        ];
        break;
      case '|':
        # block section
        $tokens[$i++] = [
          '|',ltrim(substr($c, 1), ' '),
          $line,$indent,$i0,$i1
        ];
        break;
      case '/':
        # block end
        $tokens[$i++] = [
          '/',ltrim(substr($c, 1), ' '),
          $line,$indent,$i0
        ];
        break;
      case '!':
        # comment
        $tokens[$i++] = [
          '!','',$line,$indent
        ];
        break;
      case '_':# assisted variable
      case '&':# tagged variable
        $c = $c[0].ltrim(substr($c, 1), ' ');
        # fallthrough..
      default:
        # variable
        $tokens[$i++] = [
          '_',$c,$line,$indent
        ];
        break;
      }
      # continue
      $i0 = $i1;
    }
    # tokens collected,
    # clear standalone blocks
    # {{{
    # prepare
    $line = $n0 = $n1 = 0;
    $length = $i;
    # iterate
    for ($i = 0; $i <= $length; ++$i)
    {
      # check on the same line
      if ($i < $length && $line === $tokens[$i][2]) {
        ++$n0;# total tokens in a line
      }
      else
      {
        # line changed,
        # check line has any blocks that could be standalone
        if ($n1 && ($c = $n0 - $n1) && $c <= 2)
        {
          # get first and last token indexes
          $a = $i - $n0;
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
        $line = $tokens[$i][2];
        $n0 = 1;
        $n1 = 0;
      }
      # count blocks
      if (($a = $tokens[$i][0]) && strpos('#^@|/!', $a) !== false) {
        ++$n1;
      }
    }
    # }}}
    # complete
    $tokens[] = null;
    return $tokens;
  }
  # }}}
  function _parse(# {{{
    string $tpl,
    array  $tokens,
    int    &$i = 0,
    ?array &$p = null
  ):array
  {
    ###
    # result/tree:
    #  node1,node2,..
    # node:
    #  type,name,line/argument,indent,3*count,sections
    # sections:
    #  section1,section2,..
    # section:
    #  type/condition,text,tree
    ###
    # prepare
    $tree = [];
    $size = 0;
    if ($p)
    {
      # first section's condition is the block condition
      $p[5][] = ($p[0] === '^') ? 0 : 1;
      $from = $p[4];
    }
    else {
      $from = -1;
    }
    # iterate
    while ($t = &$tokens[$i++])
    {
      switch ($t[0]) {
      case '#':
      case '^':
      case '@':
        # block opener
        $t[5] = [];
        $this->_parse($tpl, $tokens, $i, $t);
        if ($t[4]) {# non-empty
          $tree[$size++] = &$t;
        }
        break;
      case '|':
        # block section,
        # check no parent token specified
        if (!$p)
        {
          throw ErrorEx::fatal(
            'unexpected |'.$t[1].' line #'.$t[2],
            "\n".$tpl
          );
        }
        # compose section [text,tree,condition] and
        # add it to the parent block
        $p[5][] = substr($tpl, $from, $t[4] - $from);
        $p[5][] = $tree;
        $p[5][] = ($t[1] === '')
          ? abs($p[5][0] - 1)
          : "'".$t[1]."'";
        ###
        $from = $t[5];
        $tree = [];
        $size = 0;
        break;
      case '/':
        # block terminator
        if ($p === null || $t[1] !== $p[1])
        {
          throw ErrorEx::fatal(
            'unexpected /'.$t[1].' line #'.$t[2],
            "\n".$tpl
          );
        }
        if ($size)
        {
          $p[5][] = substr(
            $tpl, $from, $t[4] - $from
          );
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
      throw ErrorEx::fatal(
        'missing /'.$p[1].' line #'.$p[2],
        "\n".$tpl
      );
    }
    return $tree;
  }
  # }}}
  function _compose(# {{{
    array $tree, int $depth=0
  ):string
  {
    # prepare
    if ($depth >= 0)
    {
      $nextDepth = $depth + 1;
      $index     = $this->index;
    }
    else
    {
      $nextDepth = 1;
      $index     = $depth;
    }
    $x = '';
    $i = $t = 0;
    $j = $k = count($tree);
    # compose pieces of code
    do
    {
      $t0 = $tree[$i][0];
      $t1 = $tree[$i][1];
      switch ($t0) {
      case '':
        # plaintext
        $x .= "'".addcslashes($t1, "'\\")."'";
        break;
      case '_':
        # variable {{{
        if ($this->_path($t1, $t, true))
        {
          $t++;
          $x .= '$x->v('.$t1.')';
        }
        else {
          $x .= '$x->av('.$t1.')';
        }
        # }}}
        break;
      default:
        # block {{{
        # prepare
        $t4 = $tree[$i][4];
        $t5 = $tree[$i][5];
        $s0 = 0;# FALSY section
        $s1 = 0;# TRUTHY section
        # collect sections
        for ($a='',$n=0,$m=0; $m < $t4; $m+=3)
        {
          $b = $this->_index(
            $t5[$m+1], $t5[$m+2], $nextDepth
          );
          switch ($t5[$m]) {
          case 0:
            $s0 = $b;
            break;
          case 1:
            $s1 = $b;
            break;
          default:
            $a .= ','.$t5[$m].','.$b;
            $n += 2;
            break;
          }
        }
        # compose
        if ($this->_path($t1, $t))
        {
          $t++;
          if ($n)
          {
            # SWITCH
            # static arguments:
            #  0-4: name group
            #  5-6: FALSY/TRUTHY sections
            #  7: count of variadics (N)
            # variadic arguments:
            #  0-M: section condition (N-1)
            #  1-N: section index (M+1)
            ###
            $a  = $t1.','.$s0.','.$s1.','.$n.$a;
            $x .= '$x->b2('.$a.')';
          }
          elseif ($t0 === '^')
          {
            # FALSY OR
            $a  = $t1.','.$s0.','.$s1;
            $x .= '$x->b0('.$a.')';
          }
          elseif ($t0 === '@')
          {
            # ITERABLE
            $a  = $t1.','.$s1.','.$s0;
            $x .= '$x->b1a('.$a.')';
          }
          else
          {
            # TRUTHY OR
            $a  = $t1.','.$s1.','.$s0;
            $x .= '$x->b1('.$a.')';
          }
        }
        else
        {
          if ($n)
          {
            $a  = $t1.','.$s0.','.$s1.','.$n.$a;
            $x .= '$x->a2('.$a.')';
          }
          elseif ($t0 === '^')
          {
            $a  = $t1.','.$s0.','.$s1;
            $x .= '$x->a0('.$a.')';
          }
          else
          {
            $a  = $t1.','.$s1.','.$s0;
            $x .= '$x->a1('.$a.')';
          }
        }
        # }}}
        break;
      }
      # add joiner
      if (--$k) {
        $x .= ".";
      }
    }
    while (++$i < $j);
    # compose types
    if ($t)
    {
      $a = '0'.str_repeat(',0', $t - 1);
      $a = "\n".'static $T=['.$a.'];';
    }
    else {
      $a = '';
    }
    # compose depth dependency
    if ($depth > 0) {
      $a .= "\nreturn ".$x.";\n";
    }
    else
    {
      $a .= "\n".'$x->index='.$index.';';
      $a .= "\n".'$r='.$x.';';
      $a .= "\n".'$x->index=0;';
      $a .= "\n".'return $r;'."\n";
    }
    # compose function
    return
      'return'.
      ' (static function($x)'.
      ' {'.$a.'});';
  }
  # }}}
  function _index(# {{{
    string $tpl, array $tree, int $depth
  ):int
  {
    if ($tpl === '') {
      return 0;# empty function
    }
    # arrays should be populated naturally,
    # but the index may jump further in compose,
    # so populate them beforhand
    $i = ++$this->index;
    $this->hash[$i] = '';
    $this->text[$i] = $tpl;
    $this->code[$i] = '';
    $this->func[$i] = null;
    $e = $this->_compose($tree, $depth);
    $this->code[$i] = $e;
    $this->func[$i] = eval($e);
    return $i;
  }
  # }}}
  function _path(# {{{
    string &$s, int $typeIdx, bool $var=false
  ):int
  {
    # check assisted
    if ($s[0] === '_')
    {
      # count backpedal
      for ($p=1; $s[$p] === '_'; ++$p)
      {}
      # complete
      $s = "'".substr($s, $p)."',".$p;
      return 0;
    }
    # prepare typed access
    $t = ',$T['.$typeIdx.']';
    # check unescaped scalar
    if ($var)
    {
      if ($s[0] === '&')
      {
        $s = substr($s, 1);
        $e = ',0';
      }
      elseif ($this->escape) {
        $e = ',1';
      }
      else {
        $e = ',0';
      }
      $e = $t.$e;
    }
    else {
      $e = $t;
    }
    # check implicit iterator
    if ($s === '.')
    {
      $s = "'',0,null".$e;
      return 1;
    }
    # dot notation
    $a = explode('.', $s);
    $s = "'".$a[0]."'";
    # single name
    if (!($j = count($a) - 1))
    {
      $s = $s.',0,null'.$e;
      return 2;
    }
    # multiple names
    for ($b='',$i=1; $i < $j; ++$i) {
      $b .= "'".$a[$i]."',";
    }
    $b = $b."'".$a[$j]."'";
    $s = $s.','.$j.',['.$b.']'.$e;
    return 2;
  }
  # }}}
  # }}}
  function helper(array|object $h): void # {{{
  {
    $this->ctx->raise($h);
  }
  # }}}
  function trim(string $tpl, bool $all=false): string # {{{
  {
    return preg_replace('/\n[ \t]+/', '',
      str_replace("\r", '', trim($tpl))
    );
  }
  # }}}
  function alltrim(string $tpl): string # {{{
  {
    return preg_replace('/\n[ \n\t]+/', '',
      str_replace("\r", '', trim($tpl))
    );
  }
  # }}}
  function prepare(# {{{
    string $tpl, $dta=null, string $sep=''
  ):string
  {
    # check delimieters
    if ($sep === '')
    {
      $n0 = $this->n0; $s0 = $this->s0;
      $n1 = $this->n1; $s1 = $this->s1;
    }
    else
    {
      $a  = explode(' ', $sep, 2);
      $n0 = strlen($s0 = $a[0]);
      $n1 = strlen($s1 = $a[1]);
    }
    # check necessity
    if (strlen($tpl) <= $n0 + $n1 ||
        strpos($tpl, $s0) === false)
    {
      return $tpl;
    }
    # create renderer
    $i = $this->index;
    $f = eval($this->_compose(
      $this->_parse($tpl,
        $this->_tokenize(
          $tpl, $s0, $s1, $n0, $n1
        )
      ), -1
    ));
    # execute
    $x = $this->ctx;
    if ($dta === null)
    {
      if ($this->pushed && !$x->index)
      {
        $this->pushed = false;
        $x->pop();
      }
      $r = $f($x);
    }
    else
    {
      $r = $f($x->push($dta));
      $x->pop();
    }
    # although the main template is not cached,
    # its pieces are, so do the cleanup
    $this->clear($i);
    return $r;
  }
  # }}}
  function render(string $tpl, $dta=null): string # {{{
  {
    # check lambda recursion
    if (($x = $this->ctx)->index)
    {
      # render without caching as with preparation
      $i = $this->index;
      $f = eval($this->_compose(
        $this->_parse($tpl,
          $this->_tokenize(
            $tpl, $this->s0, $this->s1,
            $this->n0, $this->n1
          )
        ), -1
      ));
      if ($dta === null) {
        $r = $f($x);
      }
      else
      {
        $r = $f($x->push($dta));
        $x->pop();
      }
      $this->clear($i);
      return $r;
    }
    # compute template hash
    $k = hash('xxh3', $tpl, true);
    # checkout cache
    if (isset($this->cache[$k])) {
      $f = $this->func[$this->cache[$k]];
    }
    else
    {
      # create new renderer
      $i = ++$this->index;
      $this->cache[$k] = $i;
      $this->hash[$i]  = $k;
      $this->text[$i]  = $tpl;
      $this->code[$i]  = '';
      $this->func[$i]  = null;
      $e = $this->_compose($this->_parse(
        $tpl, $this->_tokenize(
          $tpl, $this->s0, $this->s1,
          $this->n0, $this->n1
        )
      ));
      $this->code[$i]  = $e;
      $this->func[$i]  = $f = eval($e);
    }
    # execute
    if ($dta === null)
    {
      if ($this->pushed)
      {
        $this->pushed = false;
        $x->pop();
      }
      return $f($x);
    }
    if ($this->pushed) {
      return $f($x->set($dta));
    }
    $this->pushed = true;
    return $f($x->push($dta));
  }
  # }}}
  function set(string $tpl): int # {{{
  {
    # compute template hash
    $k = hash('xxh3', $tpl, true);
    # checkout cache
    if (isset($this->cache[$k])) {
      return $this->cache[$k];
    }
    # create new renderer
    $i = ++$this->index;
    $this->cache[$k] = $i;
    $this->hash[$i]  = $k;
    $this->text[$i]  = $tpl;
    $this->code[$i]  = '';
    $this->func[$i]  = null;
    $e = $this->_compose($this->_parse(
      $tpl, $this->_tokenize(
        $tpl, $this->s0, $this->s1,
        $this->n0, $this->n1
      )
    ));
    $this->code[$i]  = $e;
    $this->func[$i]  = eval($e);
    return $i;
  }
  # }}}
  function clear(int $to=0): void # {{{
  {
    for ($i=$this->index; $i > $to; --$i)
    {
      if (($k = $this->hash[$i]) !== '') {
        unset($this->cache[$k]);
      }
      unset(
        $this->hash[$i], $this->text[$i],
        $this->code[$i], $this->func[$i]
      );
    }
    $this->index = $to;
  }
  # }}}
  function get(int $i, $dta=null): string # {{{
  {
    $f = $this->func[$i];
    if (($x = $this->ctx)->index)
    {
      if ($dta === null) {
        return $f($x);
      }
      $r = $f($x->push($dta));
      $x->pop();
      return $r;
    }
    if ($dta === null)
    {
      if ($this->pushed)
      {
        $this->pushed = false;
        $x->pop();
      }
      return $f($x);
    }
    if ($this->pushed) {
      return $f($x->set($dta));
    }
    $this->pushed = true;
    return $f($x->push($dta));
  }
  # }}}
}
# }}}
class MustacheCtx # {{{
{
  # constructor {{{
  public array  $stack=[null],$type=[0],$help=[];
  public int    $current=0,$helpSz=0,$index=0;
  public object $base;
  function __construct(object $mustache) {
    $this->base = $mustache;
  }
  # }}}
  # hlp {{{
  static function typeof($v): int # {{{
  {
    return is_array($v)
      ? 4
      : (is_object($v)
        ? (($v instanceof Closure)
          ? 7
          : (($v instanceof Mustachable)
            ? 6
            : 5))
        : (is_string($v)
          ? 1
          : (is_bool($v)
            ? 3
            : 2)));
  }
  # }}}
  static function type_456($v): int # {{{
  {
    return is_array($v)
      ? 4
      : (is_object($v)
        ? (($v instanceof Mustachable)
          ? 6
          : 5)
        : 0);
  }
  # }}}
  function raise(array|object $x): void # {{{
  {
    $i = ++$this->current;
    $this->stack[$i] = $x;
    $this->type[$i]  = self::type_456($x);
  }
  # }}}
  function pop(): void # {{{
  {
    $i = $this->current--;
    unset($this->stack[$i]);
  }
  # }}}
  function push($x): self # {{{
  {
    $i = ++$this->current;
    $this->stack[$i] = $x;
    $this->type[$i]  = self::typeof($x);
    return $this;
  }
  # }}}
  function set($x): self # {{{
  {
    $i = $this->current;
    $this->stack[$i] = $x;
    $this->type[$i]  = self::typeof($x);
    return $this;
  }
  # }}}
  function get(# {{{
    string $p, int $n, ?array $pn, int &$t
  ):mixed
  {
    static $NOT_FOUND=null;
    # resolve first name {{{
    $i = $this->current;
    if ($p === '')
    {
      # take current value
      $v = $this->stack[$i];
      if ($n) {
        $j = $this->type[$i] ?: self::type_456($v);
      }
      else
      {
        # single dot - implicit iterator
        if ($t) {
          return $v;
        }
        $t = $this->type[$i] ?: self::typeof($v);
        return $v;
      }
    }
    else
    {
      search:
        $v = $this->stack[$i];
        switch ($this->type[$i]) {
        case 4:
          if (isset($v[$p]))
          {
            if ($n)
            {
              $v = $v[$p];
              $j = self::type_456($v);
              goto found;
            }
            if ($t) {
              return $v[$p];
            }
            $v = $v[$p];
            $t = self::typeof($v);
            return $v;
          }
          break;
        case 5:
          if (isset($v->$p))
          {
            if ($n)
            {
              $v = $v->$p;
              $j = self::type_456($v);
              goto found;
            }
            if ($t) {
              return $v->$p;
            }
            $v = $v->$p;
            $t = self::typeof($v);
            return $v;
          }
          break;
        case 6:
          if (isset($v::STASH[$p]))
          {
            if ($n)
            {
              if (($j = $v::STASH[$p]) < 7) {
                $v = $v->$p;
              }
              else {
                $v = $v->$p();
              }
              goto found;
            }
            if ($t)
            {
              if ($v::STASH[$p] < 7) {
                return $v->$p;
              }
              return $v->$p(...);
            }
            if (($t = $v::STASH[$p]) < 7) {
              return $v->$p;
            }
            return $v->$p(...);
          }
          break;
        }
        # continue?
        if ($i < 2) {
          return $NOT_FOUND;
        }
        $i--;
        goto search;
      found:
    }
    # }}}
    # resolve in-between {{{
    for ($i=0,--$n; $i < $n; ++$i)
    {
      $p = $pn[$i];
      switch ($j) {
      case 4:
        if (isset($v[$p]))
        {
          $v = $v[$p];
          $j = self::type_456($v);
          break;
        }
        return $NOT_FOUND;
      case 5:
        if (isset($v->$p))
        {
          $v = $v->$p;
          $j = self::type_456($v);
          break;
        }
        return $NOT_FOUND;
      case 6:
        if (isset($v::STASH[$p]))
        {
          if (($j = $v::STASH[$p]) < 7) {
            $v = $v->$p;
          }
          else
          {
            $v = $v->$p();
            $j = $j - 7;
          }
          break;
        }
        # fallthrough..
      default:
        return $NOT_FOUND;
      }
    }
    # }}}
    # resolve last name {{{
    switch ($j) {
    case 4:
      $p = $pn[$n];
      if (isset($v[$p]))
      {
        if ($t) {
          return $v[$p];
        }
        $v = $v[$p];
        $t = self::typeof($v);
        return $v;
      }
      break;
    case 5:
      $p = $pn[$n];
      if ($t)
      {
        return ($t < 7)
          ? $v->$p
          : $v->$p(...);
      }
      if (isset($v->$p))
      {
        $v = $v->$p;
        $t = self::typeof($v);
        return $v;
      }
      if (method_exists($v, $p))
      {
        $t = 7;
        return $v->$p(...);
      }
      break;
    case 6:
      $p = $pn[$n];
      if ($t)
      {
        return ($t < 7)
          ? $v->$p
          : $v->$p(...);
      }
      if (isset($v::STASH[$p]))
      {
        return (($t = $v::STASH[$p]) < 7)
          ? $v->$p
          : $v->$p(...);
      }
      break;
    }
    return $NOT_FOUND;
    # }}}
  }
  # }}}
  # }}}
  function b0(# FALSY OR {{{
    string $p, int $n, ?array $pn, int &$t,
    int $i0, int $i1
  ):string
  {
    # prepare {{{
    $E = $this->base;
    $v = $this->get($p, $n, $pn, $t);
    # check not found
    if ($v === null) {
      return $E->func[$i0]($this);
    }
    # }}}
    # handle lambda {{{
    if ($t >= 7)
    {
      # invoke
      $v = $v($E);
      # clarify
      if ($t > 7) {
        $j = $t - 7;
      }
      else
      {
        $j = self::typeof($v);
        $t = 7 + $j;
      }
    }
    else {
      $j = $t;
    }
    # }}}
    # render {{{
    switch ($j) {
    case 1:
      return ($v === '')
        ? $E->func[$i0]($this)
        : ($i1 ? $E->func[$i1]($this) : '');
    case 2:
    case 3:
    case 4:
      return $v
        ? ($i1 ? $E->func[$i1]($this) : '')
        : $E->func[$i0]($this);
    }
    return $i1 ? $E->func[$i1]($this) : '';
    # }}}
  }
  # }}}
  function b1(# TRUTHY OR {{{
    string $p, int $n, ?array $pn, int &$t,
    int $i1, int $i0
  ):string
  {
    # prepare {{{
    $E = $this->base;
    $v = $this->get($p, $n, $pn, $t);
    # check not found
    if ($v === null) {
      return $i0 ? $E->func[$i0]($this) : '';
    }
    # }}}
    # handle lambda {{{
    if ($t >= 7)
    {
      # invoke with section content
      $v = $v($E, $E->text[$i1]);
      # clarify
      if ($t > 7) {
        $j = $t - 7;
      }
      else
      {
        $j = self::typeof($v);
        $t = 7 + $j;
      }
      # perform content substitution
      switch ($j) {
      case 1:
        return ($v === '')
          ? ($i0 ? $E->func[$i0]($this) : '')
          : $v;
      case 2:
        return strval($v);
      }
    }
    else {
      $j = $t;
    }
    # }}}
    # render falsy or helper {{{
    switch ($j) {
    case 1:
      # check empty
      if ($v === '') {
        return $i0 ? $E->func[$i0]($this) : '';
      }
      # string helper
      $i = ++$this->current;
      $this->stack[$i] = &$v;
      $this->type[$i]  = 1;
      $x = $E->func[$i1]($this);
      $this->current--;
      return $x;
    case 2:
      # numeric helper
      $i = ++$this->current;
      $this->stack[$i] = &$v;
      $this->type[$i]  = 2;
      $x = $E->func[$i1]($this);
      $this->current--;
      return $x;
    case 4:
      # check empty
      if (!($k = count($v))) {
        return $i0 ? $E->func[$i0]($this) : '';
      }
      # check iterable
      if (array_is_list($v)) {
        break;
      }
      # array helper
      $i = ++$this->current;
      $this->stack[$i] = &$v;
      $this->type[$i]  = 4;
      $x = $E->func[$i1]($this);
      $this->current--;
      return $x;
    case 5:
    case 6:
      # check iterable
      if ($v instanceof Countable)
      {
        # check empty
        if (!($k = count($v))) {
          return $i0 ? $E->func[$i0]($this) : '';
        }
        break;
      }
      # object helper
      $i = ++$this->current;
      $this->stack[$i] = &$v;
      $this->type[$i]  = $j;
      $x = $E->func[$i1]($this);
      $this->current--;
      return $x;
    default:
      # simplified selection
      return $v
        ? $E->func[$i1]($this)
        : ($i0 ? $E->func[$i0]($this) : '');
    }
    # }}}
    # render iterable {{{
    # array/object iteration
    $i = ++$this->current;
    $w = $v[0];
    $this->stack[$i] = &$w;
    $this->type[$i]  = self::type_456($w);
    $f = $E->func[$i1];
    $x = $f($this);
    $j = 0;
    while (++$j < $k)
    {
      $w  = $v[$j];
      $x .= $f($this);
    }
    unset($this->stack[$i]);
    $this->current--;
    return $x;
    # }}}
  }
  # }}}
  function b1a(# ITERABLE OR {{{
    string $p, int $n, ?array $pn, int &$t,
    int $i1, int $i0
  ):string
  {
    # prepare {{{
    $E = $this->base;
    $v = $this->get($p, $n, $pn, $t);
    # check not found
    if ($v === null) {
      return $i0 ? $E->func[$i0]($this) : '';
    }
    # }}}
    # handle lambda {{{
    if ($t >= 7)
    {
      # invoke
      $v = $v($E, $E->text[$i1]);
      # clarify
      if ($t > 7) {
        $j = $t - 7;
      }
      else
      {
        $j = self::typeof($v);
        $t = 7 + $j;
      }
    }
    else {
      $j = $t;
    }
    # }}}
    # render {{{
    # check empty
    if (!($k = count($v))) {
      return $i0 ? $E->func[$i0]($this) : '';
    }
    # array/object iteration with helper!
    # prepare
    $i = ++$this->current;
    $w = $v[0];
    $this->stack[$i] = &$w;
    $this->type[$i]  = 0;
    $f = $E->func[$i1];
    # add iteration helper
    $h = [
      'first' => true,
      'last'  => false,
      'index' => 0,
      'count' => $k,
    ];
    $this->help[$this->helpSz++] = &$h;
    # start
    if (--$k)
    {
      # do first
      $x = $f($this);
      # do next
      $h['first'] = false;
      for ($j=1; $j < $k; ++$j)
      {
        $h['index'] = $j;
        $w  = $v[$j];
        $x .= $f($this);
      }
      # do last
      $h['index'] = $j;
      $h['last'] = true;
      $w  = $v[$j];
      $x .= $f($this);
    }
    else
    {
      # first is last
      $h['last'] = true;
      $x = $f($this);
    }
    # cleanup and complete
    unset(
      $this->stack[$i],
      $this->help[--$this->helpSz]
    );
    $this->current--;
    return $x;
    # }}}
  }
  # }}}
  function b2(# SWITCH {{{
    string $p, int $n, ?array $pn, int &$t,
    int $i0, int $i1, int $in, ...$a
  ):string
  {
    # prepare {{{
    $E = $this->base;
    $v = $this->get($p, $n, $pn, $t);
    # check not found
    if ($v === null) {
      return $i0 ? $E->func[$i0]($this) : '';
    }
    # }}}
    # handle lambda {{{
    if ($t >= 7)
    {
      # invoke
      $v = $v($E);
      # clarify
      if ($t > 7) {
        $j = $t - 7;
      }
      else
      {
        $j = self::typeof($v);
        $t = 7 + $j;
      }
    }
    else {
      $j = $t;
    }
    # }}}
    # render falsy or simplified {{{
    switch ($j) {
    case 1:
      # check empty
      if ($v === '') {
        return $i0 ? $E->func[$i0]($this) : '';
      }
      break;
    case 2:
      # cast to string
      $v = strval($v);
      break;
    default:
      # simplified selection
      return $v
        ? ($i1 ? $E->func[$i1]($this) : '')
        : ($i0 ? $E->func[$i0]($this) : '');
    }
    # }}}
    # render switch section {{{
    # search for it
    for ($i=0; $i < $in; $i+=2)
    {
      if ($a[$i] === $v) {
        return $E->func[$a[$i+1]]($this);
      }
    }
    # not found,
    # render truthy or falsy
    return ($j === 1 || $v)
      ? ($i1 ? $E->func[$i1]($this) : '')
      : ($i0 ? $E->func[$i0]($this) : '');
    # }}}
  }
  # }}}
  function v(# VARIABLE {{{
    string $p, int $n, ?array $pn, int &$t,
    int $escape
  ):string
  {
    # prepare {{{
    $E = $this->base;
    $v = $this->get($p, $n, $pn, $t);
    # check not found
    if ($v === null) {
      return '';
    }
    # }}}
    # handle lambda {{{
    if ($t >= 7)
    {
      # invoke
      $v = $v($E);
      # clarify
      if ($t > 7) {
        $j = $t - 7;
      }
      else
      {
        $j = self::typeof($v);
        $t = 7 + $j;
      }
    }
    else {
      $j = $t;
    }
    # }}}
    # render {{{
    switch ($j) {
    case 1:
      return $escape ? ($E->escape)($v) : $v;
    case 2:
      return strval($v);
    }
    return '';
    # }}}
  }
  # }}}
  function a0(# ASSISTED FALSY OR {{{
    string $p, int $i, int $i0, int $i1
  ):string
  {
    # prepare
    if (($i = $this->helpSz - $i) < 0 ||
        !isset($this->help[$i][$p]))
    {
      return '';
    }
    $v = $this->help[$i][$p];
    $E = $this->base;
    # complete
    return $v
      ? ($i1 ? $E->func[$i1]($this) : '')
      : $E->func[$i0]($this);
  }
  # }}}
  function a1(# ASSISTED TRUTHY OR {{{
    string $p, int $i, int $i1, int $i0
  ):string
  {
    # prepare
    if (($i = $this->helpSz - $i) < 0 ||
        !isset($this->help[$i][$p]))
    {
      return '';
    }
    $v = $this->help[$i][$p];
    $E = $this->base;
    # complete
    return $v
      ? $E->func[$i1]($this)
      : ($i0 ? $E->func[$i0]($this) : '');
  }
  # }}}
  function a2(# ASSISTED SWITCH {{{
    string $p, int $i,
    int $i0, int $i1, int $in, ...$a
  ):string
  {
    # prepare
    if (($i = $this->helpSz - $i) < 0 ||
        !isset($this->help[$i][$p]))
    {
      return '';
    }
    $v = $this->help[$i][$p];
    $E = $this->base;
    # check scalar not boolean
    if (is_scalar($v) && !is_bool($v))
    {
      # convert into string and
      # render falsy when it's empty
      if (($w = strval($v)) === '')
      {
        return $i0
          ? $E->func[$i0]($this)
          : '';
      }
      # search for a switch section and
      # render one found
      for ($i=0; $i < $in; $i+=2)
      {
        if ($a[$i] === $w) {
          return $E->func[$a[$i+1]]($this);
        }
      }
      # fix special character
      if ($w === '0') {
        $v = true;
      }
    }
    # render truthy or falsy
    return $v
      ? ($i1 ? $E->func[$i1]($this) : '')
      : ($i0 ? $E->func[$i0]($this) : '');
  }
  # }}}
  function av(# ASSISTED VARIABLE {{{
    string $p, int $i
  ):string
  {
    if (($i = $this->helpSz - $i) < 0 ||
        !isset($this->help[$i][$p]))
    {
      return '';
    }
    if (is_string($v = $this->help[$i][$p])) {
      return $v;
    }
    if (is_bool($v)) {
      return '';
    }
    return strval($v);
  }
  # }}}
}
# }}}
return Mustache::init();
###
