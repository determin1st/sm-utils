<?php declare(strict_types=1);
# defs {{{
namespace SM;
use Stringable,Traversable,Closure;
use function
  is_callable,is_scalar,is_object,is_array,
  is_bool,is_string,method_exists,abs,
  hash,htmlspecialchars,ctype_alnum,ctype_space,
  str_replace,trim,ltrim,strval,strlen,strpos,substr,
  count,explode,array_is_list;
use const
  DIRECTORY_SEPARATOR;
###
require_once __DIR__.DIRECTORY_SEPARATOR.'error.php';
# }}}
interface Mustachable # {{{
{
  #  STASH: [name => [access,type]]
  # access: 0=method,1=property
  #   type: -1=mixed,0=scalar,1=array,2=object,3=Mustachable
  ###
  const STASH=[];
}
# }}}
class Mustache
{
  # constructor {{{
  const TERMINUS = "\nTEMPLATE";
  const TPL_BEG =
    'return (function(object $x):string {'.
    "\nreturn <<<TEMPLATE\n";
  const TPL_END =
    "\nTEMPLATE;\n});";
  ###
  public string  $s0='{{',$s1='}}';# delimiters
  public int     $n0=2,$n1=2;# delimiter sizes
  public ?object $escape=null;# callable
  public int     $total=1;# cache size >0
  public array   $cache=[''=>0];# hash=>index
  public array   $templ=[''],$func=[null];# index=>*
  public object  $ctx;
  private function __construct() {
    $this->ctx = new MustacheCtx($this);
  }
  static function construct(array $o=[]): object
  {
    # create new instance
    $I = new self();
    # initialize
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
      $I->ctx->extend($o[$k]);
    }
    return $I;
  }
  # }}}
  # hlp {{{
  function __debugInfo(): array # {{{
  {
    return ['total' => $this->total];
  }
  # }}}
  function _func(string &$tpl): object # {{{
  {
    # compute template hash
    $k = hash('xxh3', $tpl, true);
    # checkout cache
    if (isset($this->cache[$k])) {
      return $this->func[$this->cache[$k]];
    }
    # create template renderer function
    #$s = (
    $func = eval(
      self::TPL_BEG.
      $this->_compose(
        $this->_parse($tpl,
          $this->_tokenize(
            $tpl, $this->s0, $this->s1,
            $this->n0, $this->n1
          )
        )
      ).
      self::TPL_END
    );
    #var_dump($s);
    #$func = eval($s);
    # store and complete
    $i = $this->total;
    $this->total     = $i + 1;
    $this->templ[$i] = $tpl;
    $this->cache[$k] = $i;
    return $this->func[$i] = $func;
  }
  # }}}
  function _index(string &$tpl, array &$tree): int # {{{
  {
    # compute template hash
    $k = hash('xxh3', $tpl, true);
    # checkout cache
    if (isset($this->cache[$k])) {
      return $this->cache[$k];
    }
    # create template renderer function
    $func = eval(
      self::TPL_BEG.
      $this->_compose($tree).
      self::TPL_END
    );
    # store and complete
    $i = $this->total;
    $this->total     = $i + 1;
    $this->templ[$i] = $tpl;
    $this->func[$i]  = $func;
    return $this->cache[$k] = $i;
  }
  # }}}
  function &_tokenize(# {{{
    string &$tpl, string $s0, string $s1,
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
      if (($a = strpos($tpl, $s1, $b)) === false ||
          !($c = trim(substr($tpl, $b, $a - $b), ' ')) ||
          (!ctype_alnum($c[0]) && strpos('#^?|/.&!', $c[0]) === false) ||
          (strlen($c) > 32 && $c[0] !== '!'))
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
      case '?':
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
      if (($a = $tokens[$i][0]) && strpos('#^|/!', $a) !== false) {
        ++$n1;
      }
    }
    # }}}
    # complete
    $tokens[] = null;
    return $tokens;
  }
  # }}}
  function &_parse(# {{{
    string &$tpl,
    array  &$tokens,
    int    &$i = 0,
    ?array &$p = null
  ):array
  {
    ###
    #  tree: node1,node2,..
    #  node: type,name,line,indent,size,block
    # block: section1,section2,..
    # section: text,tree,condition
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
      case '?':
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
          $p[5][] = substr($tpl, $from, $t[4] - $from);
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
  function _compose(array &$tree): string # {{{
  {
    static $TYPE=['^'=>0,'#'=>1,'?'=>2];
    # compose code for evaluation
    $x = '';
    for ($i=0,$j=count($tree); $i < $j; ++$i)
    {
      $t0 = $tree[$i][0];
      $t1 = $tree[$i][1];
      switch ($t0) {
      case '':
        # plaintext
        # apply heredoc guards
        if (strpos($t1, '\\') !== false) {
          $t1 = str_replace('\\', '\\\\', $t1);
        }
        if (strpos($t1, '$') !== false) {
          $t1 = str_replace('$', '\\$', $t1);
        }
        $x .= $t1;
        break;
      case '_':
        # variable
        # check marked as clean
        if ($t1[0] === '&')
        {
          $t1 = substr($t1, 1);
          $s0 = 0;
        }
        elseif ($this->escape) {
          $s0 = 1;
        }
        else {
          $s0 = 0;
        }
        $x .= '{$x->v(\''.$t1.'\','.$s0.')}';
        break;
      case '#':
      case '^':
      case '?':
        # block
        ###
        # static arguments:
        #  0: block name
        #  1: block type
        #  3: FALSY section index or 0
        #  2: TRUTHY section index or 0
        #  4: count of variadics (N)
        # variadic arguments:
        #  0-M: section condition (N-1)
        #  1-N: section index (M+1)
        ###
        $t4 = $tree[$i][4];
        $t5 = $tree[$i][5];
        $s0 = 0;
        $s1 = 0;
        for ($a='',$n=0,$k=0; $k < $t4; $k+=3)
        {
          $b = $this->_index(
            $t5[$k+1], $t5[$k+2]
          );
          switch ($t5[$k]) {
          case 0:
            $s0 = $b;
            break;
          case 1:
            $s1 = $b;
            break;
          default:
            $a .= ','.$t5[$k].','.$b;
            $n += 2;
            break;
          }
        }
        # compose
        if ($n)
        {
          # SWITCH
          $a = '"'.$t1.'",'.$s0.','.$s1.','.$n.$a;
          $x .= '{$x->b2('.$a.')}';
        }
        elseif ($t0 === '^')
        {
          # FALSY OR
          $a = '"'.$t1.'",'.$s0.','.$s1;
          $x .= '{$x->b0('.$a.')}';
        }
        else
        {
          # TRUTHY OR
          $a = '"'.$t1.'",'.$s1.','.$s0;
          $x .= '{$x->b1('.$a.')}';
        }
        break;
      }
    }
    # apply heredoc terminator guard
    if (strpos($x, self::TERMINUS)) {
      $x = str_replace(self::TERMINUS, '{$x}', $x);
    }
    # complete
    return $x;
  }
  # }}}
  # }}}
  function prepare(# {{{
    string $tpl, string $delims, array $data
  ):string
  {
    # template preparation (no caching)
    # get delimiters
    [$s0,$s1] = explode(' ', $delims, 2);
    # check delimiter presence
    if (strpos($tpl, $s0) === false) {
      return $tpl;
    }
    # determine delimiter sizes
    $n0 = strlen($s0);
    $n1 = strlen($s1);
    # create renderer function
    $f = eval(
      self::TPL_BEG.
      $this->_compose($this->_parse(
        $tpl, $this->_tokenize(
          $tpl, $s0, $s1, $n0, $n1
        ))
      ).
      self::TPL_END
    );
    # execute it within the context
    return $f($this->ctx->set($data));
  }
  # }}}
  function render(# {{{
    string $tpl, string|array $data=''
  ):string
  {
    # check necessity
    if (($len = strlen($tpl)) < 5 ||
        strpos($tpl, $this->s0) === false)
    {
      return $tpl;
    }
    # create renderer and
    # execute within the context
    return $this->_func($tpl)(
      $this->ctx->set($data)
    );
  }
  # }}}
}
class MustacheCtx
  implements Stringable
{
  # constructor {{{
  public object $base;
  public array  $stack=[null];
  public array  $type=[0];
  public int    $last=0;
  function __construct(object $mustache) {
    $this->base = $mustache;
  }
  # }}}
  # hlp {{{
  function __toString(): string # {{{
  {
    return Mustache::TERMINUS;
  }
  # }}}
  static function typeof(&$x): int # {{{
  {
    if (is_scalar($x)) {
      return 4;
    }
    if (is_array($x)) {
      return 1;
    }
    if (is_object($x)) {
      return 3;
    }
    return 0;
  }
  # }}}
  function extend(array|object &$x): void # {{{
  {
    $i = &$this->last;
    $this->stack[$i] = &$x;
    $this->type[$i]  = is_array($x)
      ? 1
      : (($x instanceof Mustachable)
        ? 3
        : 2);
    #####
    $i++;
    $this->stack[$i] = null;
    $this->type[$i]  = 0;
  }
  # }}}
  function set(array|string &$x): self # {{{
  {
    if (is_array($x))
    {
      if (!$x) {
        return $this;
      }
      $type = 1;
    }
    elseif ($x === '') {
      return $this;
    }
    else {
      $type = 0;
    }
    $i = $this->last;
    $this->stack[$i] = &$x;
    $this->type[$i]  = $type;
    return $this;
  }
  # }}}
  function &get_OLD(string $name): mixed # {{{
  {
    static $EMPTY='';
    # resolve first name,
    # check for implicit iterator
    if ($name[0] === '.')
    {
      # take currect stack value
      $v = &$this->stack[$this->last];
      # when there's more to resolve,
      # compose the names chain
      if (isset($name[1])) {
        $a = substr($name, 1);
      }
      else {# use current
        return $v;
      }
    }
    else
    {
      # in case of dot notation,
      # extract first name from the chain
      if ($i = strpos($name, '.'))
      {
        $a = substr($name, $i + 1);
        $name = substr($name, 0, $i);
      }
      # find first value in the stack
      # {{{
      $v = &$this->stack;
      $t = &$this->type;
      for ($j=$this->last; $j >= 0; --$j)
      {
        switch ($t[$j]) {
        case 1:# array
          if (isset($v[$j][$name]))
          {
            if ($i)
            {
              $v = &$v[$j][$name];
              break 2;
            }
            return $v[$j][$name];
          }
          break;
        case 2:# object
          if (isset($v[$j]->$name))
          {
            if ($i)
            {
              $v = &$v[$j]->$name;
              break 2;
            }
            return $v[$j]->$name;
          }
          break;
        case 3:# friendly object
          if (isset($v[$j]::MAP[$name]))
          {
            # property
            if ($v[$j]::MAP[$name])
            {
              if ($i)
              {
                $v = &$v[$j]->$name;
                break 2;
              }
              return $v[$j]->$name;
            }
            # method
            if ($i)
            {
              $w = $v[$j]->$name();
              $v = &$w;
              break 2;
            }
            $w = $v[$j]->$name(...);
            return $w;
          }
          break;
        }
      }
      # check not found
      if ($j < 0) {
        return $EMPTY;
      }
      # }}}
    }
    # resolve names further,
    # traverse the value til the last name
    while (($i = strpos($a, '.')) !== false)
    {
      # select next name and cut the chain
      $b = substr($a, 0, $i);
      $a = substr($a, $i + 1);
      # select next value
      if (is_array($v))
      {
        if (!isset($v[$b])) {
          return $EMPTY;
        }
        $v = &$v[$b];
      }
      elseif (is_object($v))
      {
        if (!isset($v->$b)) {
          return $EMPTY;
        }
        $v = &$v->$b;
      }
      else {# non-traversible
        return $EMPTY;
      }
    }
    # check rare case of the dot at the end
    if ($a === '') {
      return $v;
    }
    # resolve the last name
    if (is_array($v))
    {
      if (isset($v[$a])) {
        return $v[$a];
      }
    }
    elseif (is_object($v))
    {
      if (isset($v->$a)) {
        return $v->$a;
      }
      if (method_exists($a, $v)) {
        return $v->$a(...);
      }
    }
    return $EMPTY;
  }
  # }}}
  function &get(# {{{
    string $name, int &$type=-1
  ):mixed
  {
    static $EMPTY='';
    # get stack index
    $i = $this->last;
    # resolve first name,
    # check for implicit iterator
    if ($name[0] === '.')
    {
      # take currect stack value
      $v = &$this->stack[$i];
      # when there's more to resolve,
      # compose the names chain
      if (isset($name[1])) {
        $a = substr($name, 1);
      }
      else
      {
        # use current
        $type = $this->type[$i];
        return $v;
      }
    }
    else
    {
      # in case of dot notation,
      # extract first name from the chain
      if ($j = strpos($name, '.'))
      {
        $a = substr($name, $j + 1);
        $name = substr($name, 0, $j);
      }
      # find first value in the stack
      # {{{
      $v = &$this->stack;
      $t = &$this->type;
      while ($i >= 0)
      {
        switch ($t[$i]) {
        case 1:# array
          if (isset($v[$i][$name]))
          {
            if ($j)
            {
              $v = &$v[$i][$name];
              break 2;
            }
            return $v[$i][$name];
          }
          break;
        case 2:# object
          if (isset($v[$i]->$name))
          {
            if ($j)
            {
              $v = &$v[$i]->$name;
              break 2;
            }
            return $v[$i]->$name;
          }
          break;
        case 3:# friendly object
          if (isset($v[$i]::STASH[$name]))
          {
            # property?
            $v = &$v[$i];
            if ($v::STASH[$name][0])
            {
              if ($j)
              {
                $v = &$v->$name;
                break 2;
              }
              $type = $v::STASH[$name][1];
              return $v->$name;
            }
            # method!
            if ($j)
            {
              $w = $v->$name();
              $v = &$w;
              break 2;
            }
            $type = 5 + $v::STASH[$name][1];
            $func = $v->$name(...);
            return $func;
          }
          break;
        }
        $i--;
      }
      # check not found
      if ($i < 0)
      {
        $type = 0;
        return $EMPTY;
      }
      # }}}
    }
    # resolve names further,
    # traverse the value til the last name
    while (($i = strpos($a, '.')) !== false)
    {
      # select next name and cut the chain
      $b = substr($a, 0, $i);
      $a = substr($a, $i + 1);
      # select next value
      if (is_array($v))
      {
        if (!isset($v[$b]))
        {
          $type = 0;
          return $EMPTY;
        }
        $v = &$v[$b];
      }
      elseif (is_object($v))
      {
        if (!isset($v->$b))
        {
          $type = 0;
          return $EMPTY;
        }
        $v = &$v->$b;
      }
      else
      {
        $type = 0;
        return $EMPTY;
      }
    }
    # check rare case of the dot at the end
    if ($a === '') {
      return $v;
    }
    # resolve the last name
    if (is_array($v))
    {
      if (isset($v[$a])) {
        return $v[$a];
      }
    }
    elseif (is_object($v))
    {
      if (isset($v->$a)) {
        return $v->$a;
      }
      if (method_exists($a, $v)) {
        return $v->$a(...);
      }
    }
    $type = 0;
    return $EMPTY;
  }
  # }}}
  # }}}
  function b0(# FALSY OR {{{
    string $name, int $i0, int $i1
  ):string
  {
    # prepare
    $E = $this->base;
    $t = -1;
    $v = &$this->get($name, $t);
    # check truthy
    if ($v)
    {
      # checkout lambda and
      # render falsy when result is falsy
      if ((($t > 3) ||
           ($v instanceof Closure)) &&
          !$v($E))
      {
        return $E->func[$i0]($this);
      }
      # render truthy
      return $i1
        ? $E->func[$i1]($this)
        : '';
    }
    # render falsy
    return $E->func[$i0]($this);
  }
  # }}}
  function b1(# TRUTHY OR {{{
    string $name, int $i1, int $i0
  ):string
  {
    # prepare
    $E = $this->base;
    $t = -1;
    $v = &$this->get($name, $t);
    # clarify type of value
    if ($t < 0)
    {
      $t = is_scalar($v)
        ? 0
        : (is_array($v)
          ? 1
          : (($v instanceof Closure)
            ? 4
            : 2));
    }
    # handle lambda
    if ($t > 3) # 4..8 => -1..3
    {
      # invoke
      $r = $v($E, $E->templ[$i1]);
      # when result is scalar,
      # try content substitution
      if ($t === 5 || is_scalar($r))
      {
        # render falsy
        if (!$r)
        {
          return ($r === '0')
            ? $r
            : ($i0
              ? $E->func[$i0]($this)
              : '');
        }
        # render simple truth or a substitute
        return ($r === true)
          ? $E->func[$i1]($this)
          : $r;
      }
      # reassign
      $v = &$r;
      # clarify type of result
      if ($t > 4) {
        $t -= 5;# from Mustachable
      }
      else
      {
        $t = is_scalar($v)
          ? 0
          : (is_array($v)
            ? 1
            : 2);
      }
    }
    # handle value
    switch ($t) {
    case 0:
      # render boolean
      if (is_bool($v))
      {
        return $v
          ? $E->func[$i1]($this)
          : ($i0
            ? $E->func[$i0]($this)
            : '');
      }
      # convert to string
      if (!is_string($v))
      {
        $w = strval($v);
        $v = &$w;
      }
      # render empty string as falsy
      if ($v === '')
      {
        return $i0
          ? $E->func[$i0]($this)
          : '';
      }
      # string helper
      $i = ++$this->last;
      $this->stack[$i] = &$v;
      $this->type[$i]  = 0;
      $x = $E->func[$i1]($this);
      $this->last--;
      return $x;
      ###
    case 1:
      # render falsy
      if (!($k = count($v)))
      {
        return $i0
          ? $E->func[$i0]($this)
          : '';
      }
      # prepare
      $i = ++$this->last;
      # check
      if (array_is_list($v))
      {
        # array iteration!
        # prepare
        $S = &$this->stack;
        $f = $E->func[$i1];
        $x = '';
        $j = 0;
        # set type (assume uniformity)
        $this->type[$i] = is_scalar($v[0])
          ? 0 # array of scalars
          : (is_array($v[0])
            ? 1 # array of arrays
            : (($v[0] instanceof Mustachable)
              ? 3 # array of friendly objects
              : 2)); # array of objects
        # iterate
        do
        {
          $S[$i] = &$v[$j];
          $x .= $f($this);
        }
        while (++$j < $k);
      }
      else
      {
        # array helper
        $this->stack[$i] = &$v;
        $this->type[$i]  = 1;
        $x = $E->func[$i1]($this);
      }
      # complete
      $this->last--;
      return $x;
      ###
    case 2:
      # TODO
      # prepare
      $i = ++$this->last;
      # check
      if ($v instanceof Traversable)
      {
        # object iteration!
        # ...
        $f = $E->func[$i1];
        $x = 'TODO: object iteration';
      }
      elseif ($v instanceof Mustachable)
      {
        # friendly object helper
        $this->stack[$i] = &$v;
        $this->type[$i]  = 3;
        $x = $E->func[$i1]($this);
      }
      else
      {
        # object helper
        $this->stack[$i] = &$v;
        $this->type[$i]  = 2;
        $x = $E->func[$i1]($this);
      }
      # complete
      $this->last--;
      return $x;
    }
    return '';
  }
  # }}}
  function b2(# SWITCH {{{
    string $name, int $i0, int $i1, int $n, ...$a
  ):string
  {
    # prepare
    $E = $this->base;
    $t = -1;
    $v = &$this->get($name, $t);
    # handle lambda
    if (($t > 3) ||
        (($t < 0) &&
         ($v instanceof Closure)))
    {
      # invoke and reassign
      $r = $v($E);
      $v = &$r;
    }
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
      for ($i=0; $i < $n; $i+=2)
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
  function v(# VARIABLE {{{
    string $name, int $escape
  ):string
  {
    # prepare
    $E = $this->base;
    $t = -1;
    $v = &$this->get($name, $t);
    # clearify type
    if ($t < 0)
    {
      $t = is_scalar($v)
        ? 0
        : (($v instanceof Closure)
          ? 4
          : 1);# assume 1/2/3
    }
    # handle lambda
    if ($t > 3)
    {
      # invoke and reassign
      $r = $v($E, '');
      $v = &$r;
      # clarify type
      if ($t > 4) {
        $t -= 5;# from Mustachable
      }
      elseif (is_scalar($v)) {
        $t = 0;
      }
      else {
        $t = 1;# assume 1/2/3
      }
    }
    # handle non-scalar or non-string
    if ($t || !is_string($v)) {
      return strval($v);
    }
    # render string
    return $escape
      ? ($E->escape)($v)
      : $v;
  }
  # }}}
}
###
