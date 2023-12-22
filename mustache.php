<?php declare(strict_types=1);
# defs {{{
namespace SM;
use
  Countable,Iterator,ArrayAccess,
  Exception,Closure;
use function
  is_callable,is_scalar,is_object,is_array,
  is_bool,is_string,method_exists,hash,
  htmlspecialchars,ctype_alnum,ctype_space,
  preg_replace,str_replace,trim,ltrim,
  strlen,strpos,substr,addcslashes,str_repeat,
  count,explode,implode,array_slice,array_pop,
  array_is_list,reset,key,next,gettype;
# }}}
interface Mustachable # friendly objects {{{
{
  const STASH=[];# name=>type
  ###
  # type:
  #  0=unknown,
  #  1=string,2=numeric,3=bool,
  #  4=array,5=object,6=Mustachable,
  #  7=callable, 7+N=callable+type
  ###
}
# }}}
class Mustache # {{{
{
  # TODO: more type predictions / re-composition
  # TODO: restore after exception? set dirty? state cleanup?
  # TODO: refactor tokenizer
  # constructor {{{
  static string  $EMPTY='';# hash of empty string
  public string  $s0='{{',$s1='}}';# default delimiters
  public int     $n0=2,$n1=2;# delimiter sizes
  public int     $pushed=0;# count of helpers
  public ?object $escape=null;# callable
  public bool    $unescape=false;# "&" unescapes/escapes
  public int     $dedent=0;# re-indent?
  public array   $wraps=["\033[41m","\033[49m"];# red bg
  public array   $booleans=['no','yes'];
  public int     $index=0;# current hash/text/func
  public array   $hash=[''],$text=[''],$func=[null];
  #public array   $code=[''];# DEBUG?
  public array   $cache=[];# hash=>index
  public object  $ctx;
  private function __construct()
  {
    $this->cache[self::$EMPTY] = 0;
    $this->hash[0] = self::$EMPTY;
    $this->func[0] = (
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
    if (isset($o[$k = 'unescape'])) {
      $I->unescape = !!$o[$k];
    }
    if (isset($o[$k = 'helper'])) {
      $I->push($o[$k]);
    }
    if (isset($o[$k = 'helpers']))
    {
      for ($i=0,$j=count($o[$k]); $i < $j; ++$i) {
        $I->push($o[$k][$i]);
      }
    }
    if (isset($o[$k = 'dedent'])) {
      $I->dedent = $o[$k];
    }
    if (isset($o[$k = 'wraps'])) {
      $I->wraps = $o[$k];
    }
    if (isset($o[$k = 'booleans'])) {
      $I->booleans = $o[$k];
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
  function _wrap(string $txt, int $i, int $j): string # {{{
  {
    return
      substr($txt, 0, $i).
      $this->wraps[0].substr($txt, $i, $j - $i).
      $this->wraps[1].substr($txt, $j);
  }
  # }}}
  function _tokenize(# {{{
    string $tpl, string $s0, string $s1, int $n0, int $n1
  ):array
  {
    # prepare
    $token = [];
    $len = strlen($tpl);
    $i = $i0 = $i1 = $line = 0;
    # iterate
    while ($i0 < $len)
    {
      # search for a newline and an opening tag
      $a = strpos($tpl, "\n", $i0);
      $b = strpos($tpl, $s0, $i0);
      # check no more tags left
      if ($b === false)
      {
        # to be able to catch standalone tags later,
        # add next text chunk spearately
        if ($a !== false)
        {
          $token[$i++] = [
            20,substr($tpl, $i0, $a - $i0 + 1),
            $line++
          ];
          $i0 = $a + 1;# move to the char after newline
        }
        # add the last text chunk
        $token[$i++] = [
          20,substr($tpl, $i0),$line
        ];
        break;
      }
      # accumulate plaintext
      while ($a !== false && $a < $b)
      {
        $i1 = $a + 1;# move to the char after newline
        $token[$i++] = [
          20,substr($tpl, $i0, $i1 - $i0),
          $line++
        ];
        $a = strpos($tpl, "\n", $i0 = $i1);
      }
      # check something left before the opening
      if ($i0 < $b)
      {
        # add final plaintext (at the same line)
        $c = substr($tpl, $i0, $b - $i0);
        $token[$i++] = [20,$c,$line];
        # determine size of indentation
        $indent = (trim($c, " \t") ? -1 : strlen($c));
        $i0 = $b;
      }
      else {# opening is at newline
        $indent = 0;
      }
      # the tag must not be empty, oversized or unknown, so,
      # find closing delimiter, check for false opening and
      # validate tag (first character)
      $b += $n0;# shift to the tag name
      if (!($a = strpos($tpl, $s1, $b)) ||
          !($c = trim(substr($tpl, $b, $a - $b))))
      {
        # GOT AN INCORRECT TAG..
        # check newline
        if ($i && !$token[$i - 1][0] &&
            substr($token[$i - 1][1], -1) === "\n")
        {
          ++$line;
        }
        # replace it with plaintext
        $token[$i++] = [20,$s0,$line];
        # continue after the tag
        $i0 = $b;
        continue;
      }
      # determine position of the next character
      # after the closing delimiter
      $i1 = $a + $n1;
      # add syntax token
      switch ($c[0]) {
      case '^':# FALSY BLOCK
        $token[$i++] = [
          0,ltrim(substr($c, 1)),
          $line,$indent,$i0,$i1
        ];
        break;
      case '#':# TRUTHY BLOCK
        $token[$i++] = [
          1,ltrim(substr($c, 1)),
          $line,$indent,$i0,$i1
        ];
        break;
      case '@':# ITERATOR BLOCK
        $token[$i++] = [
          2,ltrim(substr($c, 1)),
          $line,$indent,$i0,$i1
        ];
        break;
      case '|':# OR/CASE SECTION
        $token[$i++] = [
          10,ltrim($c),
          $line,$indent,$i0,$i1
        ];
        break;
      case '/':# TERMINUS
        $token[$i++] = [
          11,'',$line,$indent,$i0,$i1
        ];
        break;
      case '!':# COMMENT
        $token[$i++] = [
          12,'',$line,$indent,$i0,$i1
        ];
        break;
      default:# VARIABLE
        $token[$i++] = [
          21,$c,$line,$indent,$i0,$i1
        ];
        break;
      }
      # continue
      $i0 = $i1;
    }
    if ($this->dedent) {
      # clear standalone blocks {{{
      # prepare
      $line = $n0 = $n1 = 0;
      $len  = $i;
      # iterate
      for ($i = 0; $i <= $len; ++$i)
      {
        # check on the same line
        if ($i < $len && $line === $token[$i][2]) {
          ++$n0;# total tokens in a line
        }
        else
        {
          # line changed,
          # check line has standalone blocks
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
              if ($token[$b][0] === 20 &&
                  ctype_space($token[$b][1]))
              {
                $token[$b][1] = '';
              }
              elseif ($i === $len &&
                      $token[$a][0] === 20 &&
                      ctype_space($token[$a][1]))
              {
                $token[$a][1] = '';
                break;# final block(s)
              }
            }
            else
            {
              # two tokens are not blocks,
              # check both first and last are whitespaces
              if ($token[$a][0] === 20 &&
                  $token[$b][0] === 20 &&
                  ctype_space($token[$a][1]) &&
                  ctype_space($token[$b][1]))
              {
                $token[$a][1] = $token[$b][1] = '';
              }
            }
          }
          # check the end
          if ($i === $len) {
            break;
          }
          # change line and reset counters
          $line = $token[$i][2];
          $n0 = 1;
          $n1 = 0;
        }
        # count blocky
        if ($token[$i][0] < 20) {
          ++$n1;
        }
      }
      # }}}
    }
    # complete
    return $token;
  }
  # }}}
  function _parse(string $tpl, array $token): array # {{{
  {
    # create first area
    $area = [[[], 0, count($token)]];
    $idx  = 0;
    $cnt  = 1;
    while ($idx < $cnt)
    {
      # select area
      $x = &$area[$idx][0];
      $i = $area[$idx][1];
      $j = $area[$idx][2];
      $idx++;
      # assemble contents
      while ($i < $j)
      {
        $a = $token[$i++];
        switch ($a[0]) {
        case 0:
        case 1:
        case 2:
          # THE BLOCK {{{
          # create section storage
          # with the first section
          $sect = [$a[0]];
          $offs = $a[5];
          # collect content and alternative sections
          for ($k=$i,$n=0; $k < $j; ++$k)
          {
            $b = $token[$k];
            switch ($b[0]) {
            case 0:
            case 1:
            case 2:
              # increment inner blocks count
              $n++;
              break;
            case 10:
              # subsection encountered!
              # skip if it belongs to another block
              if ($n) {
                break;
              }
              # close previous section,
              # add text
              $sect[] = substr(
                $tpl, $offs, $b[4] - $offs
              );
              # add new area for parsing
              $area[$cnt] = [[], $i, $k];
              $sect[] = &$area[$cnt][0];
              $cnt++;
              $i = $k + 1;
              # open next section
              $sect[] = $b[1];
              $offs = $b[5];
              break;
            case 11:
              # decrement inner blocks count and
              # check current block's not closed
              if (--$n >= 0) {
                break;
              }
              # close previous section,
              # add text and new area for parsing
              $sect[] = substr(
                $tpl, $offs, $b[4] - $offs
              );
              $area[$cnt] = [[], $i, $k];
              $sect[] = &$area[$cnt][0];
              $cnt++;
              $i = $k + 1;
              goto block_complete;
            }
          }
          $c = ('^#@'[$a[0]]).$a[1];
          throw new Exception(
            'unterminated block '.$c.' at '.$a[4].
            "\n".$this->_wrap($tpl, $a[4], $a[5])
          );
          # }}}
        block_complete:
          # validate
          if (($a[0] === 2) &&
              (($n = count($sect)) > 6 ||
               ($n > 3 && $sect[3] !== '|')))
          {
            throw new Exception(
              'ITERATOR cannot have a CASE section '.
              "\n".$this->_wrap($tpl, $a[4], $a[5])
            );
          }
          # assemble block node
          $x[] = $this->_node($tpl, $a, $sect);
          break;
        case 10:
          throw new Exception(
            'unexpected '.
            ((strlen($a[1]) > 1)
              ? 'CASE'
              : 'OR'
            ).
            ' section at '.$a[4].
            "\n".$this->_wrap($tpl, $a[4], $a[5])
          );
        case 11:
          throw new Exception(
            'unexpected TERMINUS at '.$a[4].
            "\n".$this->_wrap($tpl, $a[4], $a[5])
          );
        case 20:# plaintext
          if ($a[1] !== '')
          {
            $a[1] = "'".addcslashes($a[1], "'\\")."'";
            $x[] = $a;
          }
          break;
        case 21:# variable
          $x[] = $this->_node($tpl, $a);
          break;
        }
      }
    }
    return $area[0][0];
  }
  # }}}
  function _node(# {{{
    string $tpl, array $t, ?array $sect=null
  ):array
  {
    # TODO: redefine variable
    # TODO: exception when variable modifier is applied to a block
    # prepare
    $isVar = $t[0] === 21;
    $path  = $t[1];
    $esc   = 0;
    # check escape modifier
    if ($path[0] === '&')
    {
      if (!$isVar)
      {
        throw new Exception(
          'variable modifier "&" '.
          'may not apply to block'.
          "\n".$this->_wrap($tpl, $t[4], $t[5])
        );
      }
      $path = ltrim(substr($path, 1));
      $esc = $this->escape
        ? ($this->unescape ? 0 : 1)
        : 0;
    }
    elseif ($isVar && $this->escape) {
      $esc = 1;
    }
    # check auxiliary
    if ($path[0] === '_')
    {
      # count backpedal
      for ($i=1; $path[$i] === '_'; ++$i)
      {}
      $a = substr($path, $i);
      # verify
      if (($j = strpos($a, '.')) !== false)
      {
        # dot notation,
        # check the name is auxiliary
        $a = substr($a, 0, $j);
        if (isset(MustacheCtx::AUX[$a]))
        {
          throw new Exception(
            'dot notation may not apply '.
            'to the auxiliary value of "'.$path.'"'.
            "\n".$this->_wrap($tpl, $t[4], $t[5])
          );
        }
      }
      elseif (($j = strpos($a, ' ')) !== false)
      {
        # lambda argument,
        # check the name is auxiliary
        $a = substr($a, 0, $j);
        if (isset(MustacheCtx::AUX[$a]))
        {
          throw new Exception(
            'lambda argument may not apply '.
            'to the auxiliary value of "'.$path.'"'.
            "\n".$this->_wrap($tpl, $t[4], $t[5])
          );
        }
      }
      elseif (isset(MustacheCtx::AUX[$a]))
      {
        # auxiliary value
        # verify use in blocks
        if ($sect)
        {
          if ($sect[0] > 1)
          {
            throw new Exception(
              'auxiliary value cannot be iterated'.
              "\n".$this->_wrap($tpl, $t[4], $t[5])
            );
          }
          $j = MustacheCtx::AUX[$a];
          if (($j > 2) &&
              (($k = count($sect)) > 6 ||
               ($k > 3 && $sect[3] !== '|')))
          {
            throw new Exception(
              'auxiliary value of type '.$j.
              (isset(MustacheCtx::TYPE[$j])
                ? '='.MustacheCtx::TYPE[$j]
                : ''
              ).
              ' cannot be used in the SWITCH block'.
              "\n".$this->_wrap($tpl, $t[4], $t[5])
            );
          }
        }
        $a = "'".$a."',".$i;
        return [
          100 + $t[0],$a,'',$esc,
          $t[4],$t[5],$sect
        ];
      }
    }
    # check arguments
    if ($i = strpos($path, ' '))
    {
      $arg  = substr($path, $i + 1);
      $arg  = "'".addcslashes($arg, "'\\")."'";
      $path = substr($path, 0, $i);
    }
    else {
      $arg  = '';
    }
    # determine dot selector backpedal
    $i = 0;
    $j = strlen($path);
    while ($path[$i] === '.')
    {
      # check dots only
      if (++$i >= $j)
      {
        return [
          $t[0],"'',".$i.',0,null',
          $arg,$esc,$t[4],$t[5],$sect
        ];
      }
    }
    # check seek variant
    if ($i)
    {
      # stack selector - at least one name
      $a = '';
      $b = explode('.', substr($path, $i));
      $c = "['".implode("','", $b)."']";
      $j = count($b);
    }
    elseif (($k = strpos($path, '.')) === false)
    {
      # stack seeker - single name
      $a = $path;
      $c = 'null';
      $j = 0;
    }
    else
    {
      # stack seeker - multiple names
      $a = substr($path, 0, $k);
      $b = explode('.', substr($path, $k + 1));
      $c = "['".implode("','", $b)."']";
      $j = count($b);
    }
    return [
      $t[0],"'".$a."',".$i.','.$j.','.$c,
      $arg,$esc,$t[4],$t[5],$sect
    ];
  }
  # }}}
  function _func(string $tpl, string $sep=''): int # {{{
  {
    # get delimieters
    if ($sep === '')
    {
      $n0 = $this->n0; $s0 = $this->s0;
      $n1 = $this->n1; $s1 = $this->s1;
    }
    else
    {
      # extract custom delimiters
      $a  = explode(' ', $sep, 2);
      $n0 = strlen($s0 = $a[0]);
      $n1 = strlen($s1 = $a[1]);
      # check necessity
      if (strlen($tpl) <= $n0 + $n1 ||
          strpos($tpl, $s0) === false)
      {
        return 0;
      }
    }
    # create syntax tree
    $tree = $this->_parse($tpl, $this->_tokenize(
      $tpl, $s0, $s1, $n0, $n1
    ));
    if (!($n = count($tree))) {
      return $tpl;
    }
    # create source code
    $s = $this->_compose($tree, $n, 0);
    $i = ++$this->index;
    $s =
    'return (static function($x) {'.
      '$x->index='.$i.';'.
      '$r='.$s.
      '$x->index=0;'.
      'return $r;'.
    '});';
    # compile and store
    #$this->code[$i] = $s;# DEBUG?
    $this->text[$i] = $tpl;
    $this->func[$i] = eval($s);
    # complete
    return $i;
  }
  # }}}
  function _compose(# {{{
    array $tree, int $size, int $depth
  ):string
  {
    # prepare
    $depth++;
    $ctx = $this->ctx;
    # compose pieces
    for ($x='',$i=0,$j=$size; $i < $j; ++$i)
    {
      $node = $tree[$i];
      switch ($node[0]) {
      case  20:# plaintext {{{
        $x .= $node[1];
        break;
        # }}}
      case  21:# variable {{{
        $a = $node[1].','.$ctx->typeSz.',';
        if ($node[2] === '')
        {
          $a .= $node[3];
          $x .= '$x->bv('.$a.')';
        }
        else
        {
          $a .= $node[2].','.$node[3];
          $x .= '$x->fv('.$a.')';
        }
        # create type entry
        $ctx->typeMap[$ctx->typeSz++] = 0;
        $ctx->typeMap[$ctx->typeSz++] = $node[4];
        $ctx->typeMap[$ctx->typeSz++] = $node[5];
        break;
        # }}}
      case 121:# auxiliary variable {{{
        $x .= '$x->av('.$node[1].')';
        break;
        # }}}
      default:# the block {{{
        # prepare
        $b = $node[6];
        $c = count($b);
        # construct the primary section
        $k = $this->_index($b[1], $b[2], $depth);
        if ($b[0])
        {
          $i0 = 0;
          $i1 = $k;
        }
        else
        {
          $i0 = $k;
          $i1 = 0;
        }
        # construct alternative sections
        for ($a='',$n=0,$m=3; $m < $c; $m+=3)
        {
          $k = $this->_index(
            $b[$m+1], $b[$m+2], $depth
          );
          if ($b[$m] !== '|')
          {
            $a .= ",'".addcslashes($b[$m], "'\\");
            $a .= "',".$k;
            $n += 2;
          }
          elseif ($b[0]) {
            $i0 = $k;
          }
          else {
            $i1 = $k;
          }
        }
        # compose auxiliary block
        if ($node[0] >= 100)
        {
          # create base parameters
          $d = $node[1].','.$i0.','.$i1;
          # create and append block entry
          if ($b[0])
          {
            # TRUTHY/SWITCH
            $x .= $n
              ? '$x->a2('.$d.','.$n.$a.')'
              : '$x->a1('.$d.')';
          }
          else
          {
            # FALSY/SWITCH
            $x .= $n
              ? '$x->a2('.$d.','.$n.$a.')'
              : '$x->a0('.$d.')';
          }
          break;
        }
        # compose basic block
        if ($node[2] === '')
        {
          # create base parameters
          $d =
            $node[1].','.     # the path
            $ctx->typeSz.','. # type store index
            $i0.','.$i1;      # sections
          # create type entry
          $ctx->typeMap[$ctx->typeSz++] = 0;
          $ctx->typeMap[$ctx->typeSz++] = $node[4];
          $ctx->typeMap[$ctx->typeSz++] = $node[5];
          # create and append block entry
          switch ($b[0]) {
          case 0:# FALSY/SWITCH
            $x .= $n
              ? '$x->b2('.$d.','.$n.$a.')'
              : '$x->b0('.$d.')';
            break;
          case 1:# TRUTHY/SWITCH
            $x .= $n
              ? '$x->b2('.$d.','.$n.$a.')'
              : '$x->b1('.$d.')';
            break;
          case 2:# ITERATOR
            $x .= '$x->bi('.$d.')';
            break;
          }
          break;
        }
        # compose lambda block
        # create base parameters
        $d =
          $node[1].','.$ctx->typeSz.','.
          $node[2].','.$i0.','.$i1;
        # create type entry
        $ctx->typeMap[$ctx->typeSz++] = 7;
        $ctx->typeMap[$ctx->typeSz++] = $node[4];
        $ctx->typeMap[$ctx->typeSz++] = $node[5];
        # create and append block entry
        switch ($b[0]) {
        case 0:# FALSY/SWITCH
          $x .= $n
            ? '$x->f2('.$d.','.$n.$a.')'
            : '$x->f0('.$d.')';
          break;
        case 1:# TRUTHY/SWITCH
          $x .= $n
            ? '$x->f2('.$d.','.$n.$a.')'
            : '$x->f1('.$d.')';
          break;
        case 2:# ITERATOR
          $x .= '$x->fi('.$d.')';
          break;
        }
        break;
        # }}}
      }
      # add joiner or terminator
      $x .= --$size ? '.' : ';';
    }
    return $x;
  }
  # }}}
  function _index(# {{{
    string $tpl, array $tree, int $depth
  ):int
  {
    # check empty
    if (!($n = count($tree))) {
      return 0;
    }
    # create source code
    $s =
    'return (static function($x) {'.
      'return '.$this->_compose($tree, $n, $depth).
    '});';
    # compile and store
    $i = ++$this->index;
    $this->hash[$i] = '';
    $this->text[$i] = $tpl;
    #$this->code[$i] = $s;# DEBUG?
    $this->func[$i] = eval($s);
    # complete
    return $i;
  }
  # }}}
  # }}}
  function &value(string $path='.', $val=null): mixed # {{{
  {
    # prepare
    static $NONE=null;
    $x = $this->ctx;
    $i = 0;
    if (!($j = strlen($path))) {
      return $NONE;
    }
    # determine dot selector backpedal
    while ($path[$i] === '.')
    {
      if (++$i >= $j)
      {
        # dots only,
        # determine index
        if (($i = $x->stackIdx - $i + 1) < 0) {
          return $NONE;
        }
        # getter
        if ($val === null) {
          return $x->stack[$i];
        }
        # setter
        if ($x::typeof($val) !== $x->stackType[$i]) {
          return $NONE;
        }
        $x->stack[$i] = $val;
        return $x->stack[$i];
      }
    }
    # check access variant
    if ($i)
    {
      # stack selector - at least one name
      $a = '';
      $b = explode('.', substr($path, $i));
      $j = count($b);
    }
    elseif (($k = strpos($path, '.')) === false)
    {
      # stack seeker - single name
      $a = $path;
      $b = null;
      $j = 0;
    }
    else
    {
      # stack seeker - multiple names
      $a = substr($path, 0, $k);
      $b = explode('.', substr($path, $k + 1));
      $j = count($b);
    }
    # getter
    if ($val === null) {
      return $x->seek($a, $i, $j, $b);
    }
    # setter
    $v = &$x->seek($a, $i, $j, $b);
    if ($v === null) {
      return $NONE;
    }
    $v = $val;
    return $v;
  }
  # }}}
  function push(array|object &$h): self # {{{
  {
    if (($x = $this->ctx)->index === 0)
    {
      if (is_array($h)) {$x->pushArray($h);}
      else {$x->pushObject($h);}
      $this->pushed++;
    }
    return $this;
  }
  # }}}
  function pull(bool $all=false): void # {{{
  {
    if (($x = $this->ctx)->index === 0 &&
        ($j = $this->pushed - 1) > 0)
    {
      if ($all) {
        $this->pushed = $j = 0;
      }
      else {
        $this->pushed = $j;
      }
      $x->stackIdx = $j;
      $i = count($x->stack) - 1;
      while ($i > $j) {
        unset($x->stack[$i--]);
      }
    }
  }
  # }}}
  function prep(string $tpl, string $sep=''): int # {{{
  {
    return $this->set($this->prepare(
      $this->outdent($tpl), null, $sep
    ));
  }
  # }}}
  function outdent(string $tpl): string # {{{
  {
    return preg_replace('/\n[ \n\t]*/', '',
      str_replace("\r", '', trim($tpl))
    );
  }
  # }}}
  function prepare(# {{{
    string $tpl, $dta=null, string $sep=''
  ):string
  {
    # get currents
    $x = $this->ctx;
    $i = $this->index;
    $j = $x->typeSz;
    # create renderer
    if (!($k = $this->_func($tpl, $sep))) {
      return $tpl;
    }
    # execute
    if ($dta === null) {
      $r = $this->func[$k]($x);
    }
    else
    {
      $r = $this->func[$k]($x->push($dta));
      $x->stackIdx--;
    }
    # restore currents
    $this->index = $i;
    $x->typeSz   = $j;
    # complete
    return $r;
  }
  # }}}
  function render(string $tpl, $dta=null): string # {{{
  {
    # compute template hash
    $k = hash('xxh3', $tpl, true);
    # checkout cache
    if (isset($this->cache[$k])) {
      $i = $this->cache[$k];
    }
    else
    {
      # create new renderer
      $i = $this->_func($tpl);
      $this->cache[$k] = $i;
      $this->hash[$i]  = $k;
      $this->text[$i]  = $tpl;
    }
    # execute
    if ($dta === null) {
      return $this->func[$i]($this->ctx);
    }
    $x = $this->ctx;
    $r = $this->func[$i]($x->push($dta));
    $x->stackIdx--;
    return $r;
  }
  # }}}
  function get(int $i, $dta=null): string # {{{
  {
    if ($dta === null) {
      return $this->func[$i]($this->ctx);
    }
    $x = $this->ctx;
    $r = $this->func[$i]($x->push($dta));
    $x->stackIdx--;
    return $r;
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
    $i = $this->_func($tpl);
    $this->cache[$k] = $i;
    $this->hash[$i]  = $k;
    $this->text[$i]  = $tpl;
    return $i;
  }
  # }}}
}
# }}}
class MustacheCtx # data stack {{{
{
  # constructor {{{
  const TYPE = [# type=>name
    0 => 'unknown',
    1 => 'string', 2 => 'number', 3 => 'boolean',
    4 => 'array',  5 => 'object', 6 => 'Mustachable'
  ];
  const AUX = [# name=>type
    'count' => 2,
    'first' => 3,
    'last'  => 3,
    'index' => 2,
    'key'   => 1
  ];
  public int   $helpSz=1;
  public array $help=[[# initial values
    'count' => 0,
    'first' => true,
    'last'  => false,
    'index' => 0,
    'key'   => ''
  ]];
  public array  $stack=[''],$stackType=[1];
  public int    $stackIdx=0;
  public array  $typeMap=[],$lambdaStack=[];
  public int    $typeSz=0,$index=0;
  public object $base,$lambda;
  function __construct(object $mustache)
  {
    $this->base   = $mustache;
    $this->lambda = new MustacheLambda($mustache);
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
  static function dotname(# {{{
    string $p, ?array $pn
  ):string
  {
    return $pn ? $p.'.'.implode('.', $pn) : $p;
  }
  # }}}
  function push($x): self # {{{
  {
    $i = ++$this->stackIdx;
    $this->stack[$i] = $x;
    $this->stackType[$i] = self::typeof($x);
    return $this;
  }
  # }}}
  function pushArray(array &$x): void # {{{
  {
    $i = ++$this->stackIdx;
    $this->stack[$i] = &$x;
    $this->stackType[$i] = 4;
  }
  # }}}
  function pushObject(object $x): void # {{{
  {
    $i = ++$this->stackIdx;
    $t = ($x instanceof Mustachable) ? 6 : 5;
    $this->stack[$i] = $x;
    $this->stackType[$i] = $t;
  }
  # }}}
  function clear(int $to=-1): void # {{{
  {
    $i = count($this->stack);
    $j = $this->stackIdx;
    if ($to < 0 || ($to < $j && $this->index)) {
      $to = $j;
    }
    while (--$i > $to)
    {
      unset(
        $this->stack[$i],
        $this->stackType[$i]
      );
    }
    $i = count($this->help);
    $j = $this->helpSz;
    while (--$i > $j) {
      unset($this->help[$i]);
    }
    $i = count($this->typeMap);
    $j = $this->typeSz;
    while (--$i > $j) {
      unset($this->typeMap[$i]);
    }
  }
  # }}}
  function get(# {{{
    string $p, int $pi, int $n, ?array $pn, int $ti
  ):mixed
  {
    # resolve first name {{{
    $t = $this->typeMap[$ti];
    if ($pi)
    {
      # explicit selection from the stack
      if (($i = $this->stackIdx - $pi + 1) < 0) {
        return null;
      }
      $v = $this->stack[$i];
      $j = $this->stackType[$i];
      if ($n)
      {
        if ($j) {
          goto seek_next;
        }
        $j = self::type_456($v);
        goto seek_next;
      }
      if ($t && $t === $j) {
        return $v;
      }
      $this->typeMap[$ti] = self::typeof($v);
      return $v;
    }
    else {
      $i = $this->stackIdx;
    }
    seek_first:
      $v = $this->stack[$i];
      switch ($this->stackType[$i]) {
      case 4:
        if (isset($v[$p]))
        {
          if ($n)
          {
            $v = $v[$p];
            $j = self::type_456($v);
            goto seek_next;
          }
          if ($t) {
            return $v[$p];
          }
          $v = $v[$p];
          $this->typeMap[$ti] = self::typeof($v);
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
            goto seek_next;
          }
          if ($t) {
            return $v->$p;
          }
          $v = $v->$p;
          $this->typeMap[$ti] = self::typeof($v);
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
            goto seek_next;
          }
          if ($t === 0) {
            $this->typeMap[$ti] = $t = $v::STASH[$p];
          }
          if ($t < 7) {
            return $v->$p;
          }
          return $v->$p(...);
        }
        break;
      }
      # continue?
      if ($i < 2) {
        return null;
      }
      $i--;
      goto seek_first;
    ###
    # }}}
    # resolve in-between {{{
    seek_next:
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
          return null;
        case 5:
          if (isset($v->$p))
          {
            $v = $v->$p;
            $j = self::type_456($v);
            break;
          }
          return null;
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
          return null;
        }
      }
    ###
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
        $this->typeMap[$ti] = self::typeof($v);
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
        $this->typeMap[$ti] = self::typeof($v);
        return $v;
      }
      if (method_exists($v, $p))
      {
        $this->typeMap[$ti] = 7;
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
        $this->typeMap[$ti] = $t = $v::STASH[$p];
        return ($t < 7)
          ? $v->$p
          : $v->$p(...);
      }
      break;
    }
    # }}}
    return null;
  }
  # }}}
  function &seek(# {{{
    string $p, int $pi, int $n, ?array $pn
  ):mixed
  {
    static $NONE=null;
    # resolve first name {{{
    if ($pi)
    {
      # explicit selection from the stack
      if (($i = $this->stackIdx - $pi + 1) < 0) {
        return $NONE;
      }
      $v = &$this->stack[$i];
      $j = $this->stackType[$i];
      if ($n)
      {
        if ($j) {
          goto seek_next;
        }
        $j = self::type_456($v);
        goto seek_next;
      }
      return $v;
    }
    else {
      $i = $this->stackIdx;
    }
    seek_first:
      $v = &$this->stack[$i];
      switch ($this->stackType[$i]) {
      case 4:
        if (isset($v[$p]))
        {
          if ($n)
          {
            $v = &$v[$p];
            $j = self::type_456($v);
            goto seek_next;
          }
          return $v[$p];
        }
        break;
      case 5:
        if (isset($v->$p))
        {
          if ($n)
          {
            $v = &$v->$p;
            $j = self::type_456($v);
            goto seek_next;
          }
          return $v->$p;
        }
        break;
      case 6:
        if (isset($v::STASH[$p]))
        {
          if ($n)
          {
            if (($j = $v::STASH[$p]) < 7) {
              $v = &$v->$p;
            }
            else {
              $v = &$v->$p();
            }
            goto seek_next;
          }
          if ($t < 7) {
            return $v->$p;
          }
          return $v->$p(...);
        }
        break;
      }
      # continue?
      if ($i < 2) {
        return $NONE;
      }
      $i--;
      goto seek_first;
    ###
    # }}}
    # resolve in-between {{{
    seek_next:
      for ($i=0,--$n; $i < $n; ++$i)
      {
        $p = $pn[$i];
        switch ($j) {
        case 4:
          if (isset($v[$p]))
          {
            $v = &$v[$p];
            $j = self::type_456($v);
            break;
          }
          return $NONE;
        case 5:
          if (isset($v->$p))
          {
            $v = &$v->$p;
            $j = self::type_456($v);
            break;
          }
          return $NONE;
        case 6:
          if (isset($v::STASH[$p]))
          {
            if (($j = $v::STASH[$p]) < 7) {
              $v = &$v->$p;
            }
            else
            {
              $v = &$v->$p();
              $j = $j - 7;
            }
            break;
          }
          # fallthrough..
        default:
          return $NONE;
        }
      }
    ###
    # }}}
    # resolve last name {{{
    switch ($j) {
    case 4:
      $p = $pn[$n];
      if (isset($v[$p])) {
        return $v[$p];
      }
      break;
    case 5:
    case 6:
      $p = $pn[$n];
      if (isset($v->$p)) {
        return $v->$p;
      }
      break;
    }
    # }}}
    return $NONE;
  }
  # }}}
  function iterate(# {{{
    array|object $a, int $cnt, int $idx
  ):string
  {
    # prepare
    $f = $this->base->func[$idx];
    $i = ++$this->stackIdx;
    $v = &$this->stack[$i];
    $h = &$this->help[$this->helpSz++];
    $h = $this->help[0];
    $h['count'] = $cnt;
    $v = $a[0];
    $this->stackType[$i] = self::typeof($v);
    # start
    if (--$cnt)
    {
      # do first
      $x = $f($this);
      # do next
      $h['first'] = false;
      for ($idx=1; $idx < $cnt; ++$idx)
      {
        $h['index'] = $idx;
        $v  = $a[$idx];
        $x .= $f($this);
      }
      # do last
      $h['index'] = $idx;
      $h['last']  = true;
      $v  = $a[$idx];
      $x .= $f($this);
    }
    else
    {
      # first is last
      $h['last'] = true;
      $x = $f($this);
    }
    # complete
    $this->helpSz--;
    $this->stackIdx--;
    return $x;
  }
  # }}}
  function traverseA(# {{{
    array $a, int $cnt, int $idx
  ):string
  {
    # prepare
    $f = $this->base->func[$idx];
    $i = ++$this->stackIdx;
    $v = &$this->stack[$i];
    $h = &$this->help[$this->helpSz++];
    $h = $this->help[0];
    $h['count'] = $cnt;
    $v = reset($a);
    $this->stackType[$i] = 0;# flexy
    $h['key'] = key($a);
    # start
    if (--$cnt)
    {
      # do first
      $x = $f($this);
      $v = next($a);
      # do next
      $h['first'] = false;
      for ($idx=1; $idx < $cnt; ++$idx)
      {
        $h['index'] = $idx;
        $h['key']   = key($a);
        $x .= $f($this);
        $v  = next($a);
      }
      # do last
      $h['last']  = true;
      $h['index'] = $idx;
      $h['key']   = key($a);
      $x .= $f($this);
    }
    else
    {
      # first is last
      $h['last'] = true;
      $x = $f($this);
    }
    # complete
    $this->helpSz--;
    $this->stackIdx--;
    return $x;
  }
  # }}}
  function traverseO(# {{{
    object $o, int $cnt, int $idx
  ):string
  {
    # prepare
    $f = $this->base->func[$idx];
    $i = ++$this->stackIdx;
    $v = &$this->stack[$i];
    $h = &$this->help[$this->helpSz++];
    $h = $this->help[0];
    $h['count'] = $cnt;
    $o->rewind();
    $v = $o->current();
    $this->stackType[$i] = 0;# flexy
    $h['key'] = $o->key();
    # start
    if (--$cnt)
    {
      # do first
      $x = $f($this);
      $o->next();
      # do next
      $h['first'] = false;
      for ($idx=1; $idx < $cnt; ++$idx)
      {
        $h['index'] = $idx;
        $h['key']   = $o->key();
        $v  = $o->current();
        $x .= $f($this);
        $o->next();
      }
      # do last
      $h['last']  = true;
      $h['index'] = $idx;
      $h['key']   = $o->key();
      $v  = $o->current();
      $x .= $f($this);
    }
    else
    {
      # first is last
      $h['last'] = true;
      $x = $f($this);
    }
    # complete
    $this->helpSz--;
    $this->stackIdx--;
    return $x;
  }
  # }}}
  function invoke(# {{{
    object $f, string $a, int $i
  ):mixed
  {
    # prepare
    $m = $this->lambda;
    $j = $m->index;
    $k = $m->pushed;
    # initialize and execute
    $m->index  = $i;
    $m->pushed = 0;
    $v = $f($m, $a);
    # restore stack
    if ($i < 0)
    {
      # variable, restore now
      if ($m->pushed) {
        $this->stackIdx -= $i;
      }
    }
    else
    {
      # block, restore later
      $this->lambdaStack[] = $m->pushed;
    }
    # restore facade
    $m->index  = $j;
    $m->pushed = $k;
    # complete
    return $v;
  }
  # }}}
  function revoke(): void # {{{
  {
    if ($i = array_pop($this->lambdaStack)) {
      $this->stackIdx -= $i;
    }
  }
  # }}}
  # }}}
  # basic entry {{{
  function b0(# FALSY {{{
    string $p, int $pi, int $n, ?array $pn, int $t,
    int $i0, int $i1
  ):string
  {
    # prepare
    $v = $this->get($p, $pi, $n, $pn, $t);
    if ($v === null) {
      return $this->base->func[$i0]($this);
    }
    # handle lambda
    if (($j = $this->typeMap[$t]) >= 7) {
      return $this->f0x($v, '', $t, $i0, $i1);
    }
    # render value
    return $this->v0($v, $j, $i0, $i1);
  }
  # }}}
  function b1(# TRUTHY {{{
    string $p, int $pi, int $n, ?array $pn, int $t,
    int $i0, int $i1
  ):string
  {
    # resolve the path
    $v = $this->get($p, $pi, $n, $pn, $t);
    if ($v === null) {
      return $i0 ? $this->base->func[$i0]($this) : '';
    }
    # render lambda
    if (($j = $this->typeMap[$t]) >= 7) {
      return $this->f1x($v, '', $t, $i0, $i1);
    }
    # render value
    return $this->v1($v, $j, $i0, $i1);
  }
  # }}}
  function bi(# ITERATOR {{{
    string $p, int $pi, int $n, ?array $pn, int $t,
    int $i0, int $i1
  ):string
  {
    # resolve the path
    $v = $this->get($p, $pi, $n, $pn, $t);
    if ($v === null) {
      return $i0 ? $this->base->func[$i0]($this) : '';
    }
    # render lambda
    if (($j = $this->typeMap[$t]) >= 7) {
      return $this->fix($v, '', $t, $i0, $i1);
    }
    # render value
    return $this->vi($v, $t, $j, $i0, $i1);
  }
  # }}}
  function b2(# SWITCH {{{
    string $p, int $pi, int $n, ?array $pn, int $t,
    int $i0, int $i1, int $in, ...$a
  ):string
  {
    # resolve the path
    $v = $this->get($p, $pi, $n, $pn, $t);
    if ($v === null) {
      return $i0 ? $this->base->func[$i0]($this) : '';
    }
    # render lambda
    if (($j = $this->typeMap[$t]) >= 7) {
      return $this->f2x($v, '', $t, $i0, $i1, $in, $a);
    }
    # render value
    return $this->v2($v, $t, $j, $i0, $i1, $in, $a);
  }
  # }}}
  function bv(# VARIABLE {{{
    string $p, int $pi, int $n, ?array $pn, int $t,
    int $escape
  ):string
  {
    # resolve the path
    $v = $this->get($p, $pi, $n, $pn, $t);
    if ($v === null) {
      return '';
    }
    # render lambda
    if (($j = $this->typeMap[$t]) >= 7) {
      return $this->fvx($v, '', $t, $escape);
    }
    # render value
    return $this->vv($v, $t, $j, $escape);
  }
  # }}}
  # }}}
  # lambda entry {{{
  function f0(# FALSY getter {{{
    string $p, int $pi, int $n, ?array $pn, int $t,
    string $arg, int $i0, int $i1
  ):string
  {
    $v = $this->get($p, $pi, $n, $pn, $t);
    if ($v === null) {
      return $this->base->func[$i0]($this);
    }
    return $this->f0x($v, $arg, $t, $i0, $i1);
  }
  # }}}
  function f0x(# FALSY executor {{{
    object $f, string $arg, int $t, int $i0, int $i1
  ):string
  {
    # execute
    $v = $this->invoke($f, $arg, 0);
    # clarify the type
    if (($j = $this->typeMap[$t]) > 7) {
      $j -= 7;
    }
    else
    {
      $j = self::typeof($v);
      $this->typeMap[$t] = 7 + $j;
    }
    # complete
    $r = $this->v0($v, $j, $i0, $i1);
    $this->revoke();
    return $r;
  }
  # }}}
  function f1(# TRUTHY getter {{{
    string $p, int $pi, int $n, ?array $pn, int $t,
    string $arg, int $i0, int $i1
  ):string
  {
    $v = $this->get($p, $pi, $n, $pn, $t);
    if ($v === null) {
      return $i0 ? $this->base->func[$i0]($this) : '';
    }
    return $this->f1x($v, $arg, $t, $i0, $i1);
  }
  # }}}
  function f1x(# TRUTHY executor {{{
    object $f, string $arg, int $t, int $i0, int $i1
  ):string
  {
    # execute
    $v = $this->invoke($f, $arg, $i1);
    # clarify the type
    if (($j = $this->typeMap[$t]) > 7) {
      $j -= 7;
    }
    else
    {
      $j = self::typeof($v);
      $this->typeMap[$t] = 7 + $j;
    }
    # handle content substitution
    if ($j === 1)
    {
      if ($i0 && $v === '') {
        $v = $this->base->func[$i0]($this);
      }
      $this->revoke();
      return $v;
    }
    # complete normally
    $r = $this->v1($v, $j, $i0, $i1);
    $this->revoke();
    return $r;
  }
  # }}}
  function fi(# ITERATOR getter {{{
    string $p, int $pi, int $n, ?array $pn, int $t,
    string $arg, int $i0, int $i1
  ):string
  {
    $v = $this->get($p, $pi, $n, $pn, $t);
    if ($v === null) {
      return $i0 ? $this->base->func[$i0]($this) : '';
    }
    return $this->fix($v, $arg, $t, $i0, $i1);
  }
  # }}}
  function fix(# ITERATOR executor {{{
    object $f, string $arg, int $t, int $i0, int $i1
  ):string
  {
    # execute
    $v = $this->invoke($f, $arg, $i1);
    # clarify the type
    if (($j = $this->typeMap[$t]) > 7) {
      $j -= 7;
    }
    else
    {
      $j = self::typeof($v);
      $this->typeMap[$t] = 7 + $j;
    }
    # complete
    $r = $this->vi($v, $t, $j, $i0, $i1);
    $this->revoke();
    return $r;
  }
  # }}}
  function f2(# SWITCH getter {{{
    string $p, int $pi, int $n, ?array $pn, int $t,
    string $arg, int $i0, int $i1, int $in, ...$a
  ):string
  {
    # get the lambda
    $f = $this->get($p, $pi, $n, $pn, $t);
    if ($f === null) {
      return $i0 ? $this->base->func[$i0]($this) : '';
    }
    # complete
    return $this->f2x(
      $f, $arg, $t, $i0, $i1, $in, $a
    );
  }
  # }}}
  function f2x(# SWITCH executor {{{
    object $f, string $arg, int $t,
    int $i0, int $i1, int $in, array $a
  ):string
  {
    # determine the primary section
    if ($i0)
    {
      if ($i1) {
        $i = ($i0 < $i1) ? $i0 : $i1;
      }
      else {
        $i = $i0;
      }
    }
    else {
      $i = $i1;
    }
    # execute
    $v = $this->invoke($f, $arg, $i);
    # clarify the type
    if (($j = $this->typeMap[$t]) > 7) {
      $j -= 7;
    }
    else
    {
      $j = self::typeof($v);
      $this->typeMap[$t] = 7 + $j;
    }
    # complete
    $r = $this->v2($v, $t, $j, $i0, $i1, $in, $a);
    $this->revoke();
    return $r;
  }
  # }}}
  function fv(# VARIABLE getter {{{
    string $p, int $pi, int $n, ?array $pn, int $t,
    string $arg, int $escape
  ):string
  {
    # get the lambda
    $f = $this->get($p, $pi, $n, $pn, $t);
    if ($f === null) {
      return '';
    }
    # complete
    return $this->fvx($f, $arg, $t, $escape);
  }
  # }}}
  function fvx(# VARIABLE executor {{{
    object $f, string $arg, int $t, int $escape
  ):string
  {
    # execute
    $v = $this->invoke($f, $arg, -1);
    # clarify the type
    if (($j = $this->typeMap[$t]) > 7) {
      $j -= 7;
    }
    else
    {
      $j = self::typeof($v);
      $this->typeMap[$t] = 7 + $j;
    }
    # complete
    return $this->vv($v, $t, $j, $escape);
  }
  # }}}
  # }}}
  # auxiliary entry {{{
  function a0(# FALSY {{{
    string $p, int $i, int $i0, int $i1
  ):string
  {
    if (($i = $this->helpSz - $i) < 0) {
      return $this->base->func[$i0]($this);
    }
    return $this->v0(
      $this->help[$i][$p], self::AUX[$p], $i0, $i1
    );
  }
  # }}}
  function a1(# TRUTHY {{{
    string $p, int $i, int $i0, int $i1
  ):string
  {
    if (($i = $this->helpSz - $i) < 0) {
      return $i0 ? $this->base->func[$i0]($this) : '';
    }
    return $this->v1(
      $this->help[$i][$p], self::AUX[$p], $i0, $i1
    );
  }
  # }}}
  function a2(# SWITCH {{{
    string $p, int $i,
    int $i0, int $i1, int $in, ...$a
  ):string
  {
    if (($i = $this->helpSz - $i) < 0) {
      return $i0 ? $this->base->func[$i0]($this) : '';
    }
    return $this->v2(
      $this->help[$i][$p], -1, self::AUX[$p],
      $i0, $i1, $in, $a
    );
  }
  # }}}
  function av(# VARIABLE {{{
    string $p, int $i
  ):string
  {
    if (($i = $this->helpSz - $i) < 0) {
      return '';
    }
    return $this->vv(
      $this->help[$i][$p], -1, self::AUX[$p], 0
    );
  }
  # }}}
  # }}}
  # value renderers {{{
  function v0(# FALSY {{{
    $v, int $j, int $i0, int $i1
  ):string
  {
    switch ($j) {
    case 1:
      return ($v === '')
        ? $this->base->func[$i0]($this)
        : ($i1 ? $this->base->func[$i1]($this) : '');
      ###
    case 5:
    case 6:
      if (($v instanceof Countable) && !count($v)) {
        return $this->base->func[$i0]($this);
      }
      return $i1
        ? $this->base->func[$i1]($this)
        : '';
      ###
    default:# 2,3,4
      return $v
        ? ($i1 ? $this->base->func[$i1]($this) : '')
        : $this->base->func[$i0]($this);
    }
  }
  # }}}
  function v1(# TRUTHY {{{
    $v, int $j, int $i0, int $i1
  ):string
  {
    # check type
    switch ($j) {
    case 1:
      if ($v === '')
      {
        return $i0
          ? $this->base->func[$i0]($this)
          : '';
      }
      break;
    case 2:
      if ($i0 && !$v) {
        return $this->base->func[$i0]($this);
      }
      break;
    case 3:
      return $v
        ? $this->base->func[$i1]($this)
        : ($i0
          ? $this->base->func[$i0]($this)
          : '');
    case 4:
      if ($k = count($v))
      {
        if (array_is_list($v)) {
          goto render_iterable;
        }
        break;
      }
      return $i0
        ? $this->base->func[$i0]($this)
        : '';
    case 5:
    case 6:
      if ($v instanceof Countable)
      {
        if ($k = count($v)) {
          goto render_iterable;
        }
        return $i0
          ? $this->base->func[$i0]($this)
          : '';
      }
      break;
    }
    render_helper:# single value
      $i = ++$this->stackIdx;
      $this->stack[$i] = $v;
      $this->stackType[$i] = $j;
      $r = $this->base->func[$i1]($this);
      $this->stackIdx--;
      return $r;
    ###
    render_iterable:# multiple values
      $i = ++$this->stackIdx;
      $w = &$this->stack[$i];
      $w = $v[0];
      $this->stackType[$i] = self::type_456($w);
      $f = $this->base->func[$i1];
      $r = $f($this);
      $j = 0;
      while (++$j < $k)
      {
        $w  = $v[$j];
        $r .= $f($this);
      }
      $this->stackIdx--;
      return $r;
    ###
  }
  # }}}
  function v2(# SWITCH {{{
    $v, int $t, int $j,
    int $i0, int $i1, int $in, array $a
  ):string
  {
    # check type
    switch ($j) {
    case 1:
      # the CASE section cannot contain empty string,
      # so check and render it first
      if ($v === '')
      {
        return $i0
          ? $this->base->func[$i0]($this)
          : '';
      }
      $w = true;# TRUTHY default
      break;
    case 2:
      $w = $v !== 0;# determine default
      $v = (string)$v;
      break;
    default:
      throw new Exception(
        'SWITCH: incorrect value type: '.$j.
        (isset(self::TYPE[$j])
          ? '='.self::TYPE[$j]
          : ''
        ).
        "\n".$this->base->_wrap(
          $this->base->text[$this->index],
          $this->typeMap[$t + 1],
          $this->typeMap[$t + 2]
        )
      );
    }
    # render CASE
    for ($v='|'.$v,$i=0; $i < $in; $i+=2)
    {
      if (strpos($a[$i], $v) !== false) {
        return $this->base->func[$a[$i+1]]($this);
      }
    }
    # render DEFAULT
    return $w
      ? ($i1 ? $this->base->func[$i1]($this) : '')
      : ($i0 ? $this->base->func[$i0]($this) : '');
  }
  # }}}
  function vi(# ITERATOR {{{
    $v, int $t, int $j, int $i0, int $i1
  ):string
  {
    switch ($j) {
    case 4:
      if ($k = count($v))
      {
        return array_is_list($v)
          ? $this->iterate($v, $k, $i1)
          : $this->traverseA($v, $k, $i1);
      }
      return $i0
        ? $this->base->func[$i0]($this)
        : '';
      ###
    case 5:
    case 6:
      if ($k = count($v))
      {
        return ($v instanceof Iterator)
          ? $this->traverseO($v, $k, $i1)
          : $this->iterate($v, $k, $i1);
      }
      return $i0
        ? $this->base->func[$i0]($this)
        : '';
    }
    throw new Exception(
      'ITERATOR: incorrect value type: '.$j.
      (isset(self::TYPE[$j])
        ? '='.self::TYPE[$j]
        : ''
      ).
      "\n".$this->base->_wrap(
        $this->base->text[$this->index],
        $this->typeMap[$t + 1],
        $this->typeMap[$t + 2]
      )
    );
  }
  # }}}
  function vv(# VARIABLE {{{
    $v, int $t, int $j, int $escape
  ):string
  {
    switch ($j) {
    case 1:
      return $escape
        ? ($this->base->escape)($v)
        : $v;
    case 2:
      return (string)$v;
    case 3:
      return $this->base->booleans[$v ? 1 : 0];
    }
    throw new Exception(
      'VARIABLE: incorrect value type: '.$j.
      (isset(self::TYPE[$j])
        ? '='.self::TYPE[$j]
        : ''
      ).
      "\n".$this->base->_wrap(
        $this->base->text[$this->index],
        $this->typeMap[$t + 1],
        $this->typeMap[$t + 2]
      )
    );
  }
  # }}}
  # }}}
}
# }}}
class MustacheLambda # {{{
  implements ArrayAccess
{
  # constructor {{{
  public object $base;
  public int    $index=0,$pushed=0;
  function __construct(object $mustache) {
    $this->base = $mustache;
  }
  # }}}
  # [] access {{{
  function offsetExists(mixed $k): bool {
    return $this->value($k) !== null;
  }
  function offsetGet(mixed $k): mixed {
    return $this->value($k);
  }
  function offsetSet(mixed $k, mixed $v): void {
    $this->value($k, $v);
  }
  function offsetUnset(mixed $k): void
  {}
  # }}}
  function text(): string # {{{
  {
    return (($i = $this->index) > 0)
      ? $this->base->text[$i]
      : '';
  }
  # }}}
  function &value(string $path='.', $val=null): mixed # {{{
  {
    # handle auxiliary (readonly)
    if ($path[0] === '_')
    {
      # count backpedal
      for ($i=1; $path[$i] === '_'; ++$i)
      {}
      $a = substr($path, $i);
      # check name exists
      if (isset(MustacheCtx::AUX[$a]))
      {
        $x = $this->base->ctx;
        if (($i = $x->helpSz - $i) < 0)
        {
          $v = null;
          return $v;
        }
        $v = $x->help[$i][$a];
        return $v;
      }
    }
    return $this->base->value($path, $val);
  }
  # }}}
  function push(array|object $h): self # {{{
  {
    if (is_array($h)) {
      $this->base->ctx->pushArray($h);
    }
    else {
      $this->base->ctx->pushObject($h);
    }
    $this->pushed++;
    return $this;
  }
  # }}}
  function pull(bool $all=false): void # {{{
  {
    if ($i = $this->pushed)
    {
      if ($all)
      {
        $this->pushed = 0;
        $this->base->ctx->stackIdx -= $i;
      }
      else
      {
        $this->pushed = $i - 1;
        $this->base->ctx->stackIdx -= 1;
      }
    }
  }
  # }}}
  function outdent(string $tpl): string # {{{
  {
    return $this->base->outdent($tpl);
  }
  # }}}
  function prepare(# {{{
    string $tpl, $dta=null, string $sep=''
  ):string
  {
    return $this->base->prepare($tpl, $dta, $sep);
  }
  # }}}
  function render($dta=null): string # {{{
  {
    if (($i = $this->index) <= 0) {
      return '';
    }
    $m = $this->base;
    $x = $m->ctx;
    if ($dta === null) {
      return $m->func[$i]($x);
    }
    $r = $m->func[$i]($x->push($dta));
    $x->stackIdx--;
    return $r;
  }
  # }}}
  function get(int $i, $dta=null): string # {{{
  {
    $m = $this->base;
    if ($dta === null) {
      return $m->func[$i]($m->ctx);
    }
    $x = $m->ctx;
    $r = $m->func[$i]($x->push($dta));
    $x->stackIdx--;
    return $r;
  }
  # }}}
}
# }}}
return Mustache::init();
###
