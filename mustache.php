<?php declare(strict_types=1);
# defs {{{
namespace SM;
use Countable,Iterator,Exception,Closure;
use function
  is_callable,is_scalar,is_object,is_array,
  is_bool,is_string,method_exists,hash,
  htmlspecialchars,ctype_alnum,ctype_space,
  preg_replace,str_replace,trim,ltrim,strval,
  strlen,strpos,substr,addcslashes,str_repeat,
  count,explode,implode,array_slice,array_is_list,
  reset,key,next,gettype;
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
  # TODO: refactor tokenizer
  # TODO: exceptions with source while rendering
  # TODO: more type prediction
  # constructor {{{
  static string  $EMPTY='';# hash of an empty string
  public string  $s0='{{',$s1='}}';# default delimiters
  public int     $n0=2,$n1=2;# delimiter sizes
  public int     $pushed=0;# count of helpers
  public ?object $escape=null;# callable
  public bool    $unescape=false;# "&" unescapes/escapes
  public int     $dedent=0;# re-indent?
  public array   $wraps=["\033[41m","\033[49m"];# red bg
  public int     $index=0;# current hash/text/code/func
  public array   $hash=[''],$text=[''];
  public array   $code=[''],$func=[null];
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
      case '@':# ITERABLE BLOCK
        $token[$i++] = [
          2,ltrim(substr($c, 1)),
          $line,$indent,$i0,$i1
        ];
        break;
      case '|':# BLOCK SECTION
        $token[$i++] = [
          10,ltrim($c),
          $line,$indent,$i0,$i1
        ];
        break;
      case '/':# BLOCK TERMINATOR
        $token[$i++] = [
          11,'',$line,$indent,$i0,$i1
        ];
        break;
      case '!':# COMMENTARY
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
      $section = &$area[$idx][0];
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
          # construct block node
          $node = $this->_node($tpl, $a);
          $offs = $a[5];
          # create section storage and
          # open first section
          $node[6] = [$a[0]];
          # search for terminator
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
              $node[6][] = substr(
                $tpl, $offs, $b[4] - $offs
              );
              # add new area for parsing
              $area[$cnt] = [[], $i, $k];
              $node[6][] = &$area[$cnt][0];
              $cnt++;
              $i = $k + 1;
              # open next section
              $offs = $b[5];
              $node[6][] = $b[1];
              break;
            case 11:
              # decrement inner blocks count and
              # check current block's not closed
              if (--$n >= 0) {
                break;
              }
              # close previous section,
              # add text and new area for parsing
              $node[6][] = substr(
                $tpl, $offs, $b[4] - $offs
              );
              $area[$cnt] = [[], $i, $k];
              $node[6][] = &$area[$cnt][0];
              $cnt++;
              $i = $k + 1;
              goto block_complete;
            }
          }
          $c = ('^#@'[$a[0]]).$a[1];
          throw new Exception(
            'unclosed '.$c.' at '.$a[4].
            "\n".$this->_wrap($tpl, $a[4], $a[5])
          );
          # }}}
        block_complete:
          if (($a[0] === 2) &&
              (($n = count($node[6])) > 6 ||
               ($n > 3 && $node[6][3] !== '|')))
          {
            throw new Exception(
              'iterable block '.
              'cannot have switch sections '.
              "\n".$this->_wrap($tpl, $a[4], $a[5])
            );
          }
          $section[] = $node;
          break;
        case 10:
          throw new Exception(
            'unexpected |'.$a[1].' at '.$a[4].
            "\n".$this->_wrap($tpl, $a[4], $a[5])
          );
        case 11:
          throw new Exception(
            'unexpected /'.$a[1].' at '.$a[4].
            "\n".$this->_wrap($tpl, $a[4], $a[5])
          );
        case 20:# plaintext
          if ($a[1] !== '')
          {
            $a[1] = "'".addcslashes($a[1], "'\\")."'";
            $section[] = $a;
          }
          break;
        case 21:# variable
          $section[] = $this->_node($tpl, $a);
          break;
        }
      }
    }
    return $area[0][0];
  }
  # }}}
  function _node(string $tpl, array $t): array # {{{
  {
    static $ASSISTED=[# name=>type
      'first' => 3,
      'last'  => 3,
      'index' => 2,
      'count' => 2,
      'key'   => 1,
      'value' => 0,
    ];
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
          'does not apply to blocks'.
          "\n".$this->_wrap($tpl, $t[5], $t[6])
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
    # check assisted
    if ($path[0] === '_')
    {
      # count backpedal
      for ($i=1; $path[$i] === '_'; ++$i)
      {}
      $a = substr($path, $i);
      # validate
      if (($j = strpos($a, '.')) !== false)
      {
        $a = substr($a, 0, $j);
        if (isset($ASSISTED[$a]))
        {
          throw new Exception(
            'dot notation does not apply '.
            'for assisted value "'.$path.'"'.
            "\n".$this->_wrap($tpl, $t[5], $t[6])
          );
        }
      }
      elseif (($j = strpos($a, ' ')) !== false)
      {
        $a = substr($a, 0, $j);
        if (isset($ASSISTED[$a]))
        {
          throw new Exception(
            'lambda does not apply '.
            'for assisted value "'.$path.'"'.
            "\n".$this->_wrap($tpl, $t[5], $t[6])
          );
        }
      }
      elseif (isset($ASSISTED[$a]))
      {
        $a = "'".$a."',".$i.','.$ASSISTED[$a];
        return [100 + $t[0],$a,$t[4],$t[5],'',$esc];
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
          $t[4],$t[5],$arg,$esc
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
      $t[4],$t[5],$arg,$esc
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
    $this->code[$i] = $s;
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
      case 20:# plaintext {{{
        $x .= $node[1];
        break;
        # }}}
      case 21:# variable {{{
        $a = $node[1].','.$ctx->typeSz.',';
        if ($node[4] === '')
        {
          $a .= $node[5];
          $x .= '$x->v('.$a.')';
        }
        else
        {
          $a .= $node[4].','.$node[5];
          $x .= '$x->fv('.$a.')';
        }
        $ctx->typeMap[$ctx->typeSz++] = 0;
        break;
        # }}}
      case 121:# assisted variable {{{
        $a  = $node[1].','.$node[5];
        $x .= '$x->av('.$a.')';
        break;
        # }}}
      default:# block {{{
        # prepare
        $b = $node[6];
        $c = count($b);
        # construct primary section
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
        # construct secondary sections
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
        # compose assisted block
        if ($node[0] >= 100)
        {
          if ($n)
          {
            $a  = $node[1].','.$i0.','.$i1.','.$n.$a;
            $x .= '$x->a2('.$a.')';
          }
          else
          {
            $a  = $node[1].','.$i0.','.$i1;
            $x .= '$x->a01('.$a.')';
          }
          break;
        }
        # compose generic block
        if ($node[4] === '')
        {
          $d =
            $node[1].','.
            $ctx->typeSz.','.
            $i0.','.$i1;
          ###
          $ctx->typeMap[$ctx->typeSz++] = 0;
          switch ($b[0]) {
          case 0:# FALSY OR/SWITCH
            $x .= $n
              ? '$x->b2('.$d.','.$n.$a.')'
              : '$x->b0('.$d.')';
            break;
          case 1:# TRUTHY OR/SWITCH
            $x .= $n
              ? '$x->b2('.$d.','.$n.$a.')'
              : '$x->b1('.$d.')';
            break;
          case 2:# ITERABLE
            $x .= '$x->bi('.$d.')';
            break;
          }
          break;
        }
        # compose lambda block
        $d =
          $node[1].','.$ctx->typeSz.','.
          $node[4].','.$i0.','.$i1;
        ###
        $ctx->typeMap[$ctx->typeSz++] = 7;
        switch ($b[0]) {
        case 0:# FALSY LAMBDA OR/SWITCH
          $x .= $n
            ? '$x->f2('.$d.','.$n.$a.')'
            : '$x->f0('.$d.')';
          break;
        case 1:# TRUTHY LAMBDA OR/SWITCH
          $x .= $n
            ? '$x->f2('.$d.','.$n.$a.')'
            : '$x->f1('.$d.')';
          break;
        case 2:# ITERABLE LAMBDA
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
    $this->code[$i] = $s;
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
    if (!($j = strlen($path)))
    {
      throw new Exception(
        __METHOD__.': '.
        'path argument is empty'
      );
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
  public int   $helpSz=1;
  public array $help=[[# initial values
    'first' => true,
    'last'  => false,
    'index' => 0,
    'count' => 0,
    'key'   => '',
    'value' => '',
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
    # assisted iteration
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
    # assisted array traversal
    # prepare
    $f = $this->base->func[$idx];
    $i = ++$this->stackIdx;
    $v = &$this->stack[$i];
    $h = &$this->help[$this->helpSz++];
    $h = $this->help[0];
    $h['count'] = $cnt;
    $h['value'] = &$v;
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
    # assisted object traversal
    # prepare
    $f = $this->base->func[$idx];
    $i = ++$this->stackIdx;
    $v = &$this->stack[$i];
    $h = &$this->help[$this->helpSz++];
    $h = $this->help[0];
    $h['count'] = $cnt;
    $h['value'] = &$v;
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
  # standard {{{
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
      return $this->bf0($v, '', $t, $j, $i0, $i1);
    }
    # render
    switch ($j) {
    case 1:
      if ($v === '') {
        return $this->base->func[$i0]($this);
      }
      if ($i1) {
        return $this->base->func[$i1]($this);
      }
      return '';
    case 2:
    case 3:
    case 4:
      if ($v)
      {
        return $i1
          ? $this->base->func[$i1]($this)
          : '';
      }
      return $this->base->func[$i0]($this);
    }
    return $i1
      ? $this->base->func[$i1]($this)
      : '';
  }
  # }}}
  function bf0(# FALSY LAMBDA {{{
    object $f, string $arg, int $t, int $j,
    int $i0, int $i1
  ):string
  {
    # execute
    $v = $this->invoke($f, $arg, 0);
    # clarify
    if ($j > 7) {
      $j -= 7;
    }
    else
    {
      $j = self::typeof($v);
      $this->typeMap[$t] = 7 + $j;
    }
    # render
    switch ($j) {
    case 1:
      if ($v === '') {
        $r = $this->base->func[$i0]($this);
      }
      elseif ($i1) {
        $r = $this->base->func[$i1]($this);
      }
      else {
        $r = '';
      }
      break;
    case 2:
    case 3:
    case 4:
      if ($v)
      {
        $r = $i1
          ? $this->base->func[$i1]($this)
          : '';
      }
      else {
        $r = $this->base->func[$i0]($this);
      }
      break;
    default:
      $r = $i1
        ? $this->base->func[$i1]($this)
        : '';
      break;
    }
    # complete
    $this->revoke();
    return $r;
  }
  # }}}
  function b1(# TRUTHY {{{
    string $p, int $pi, int $n, ?array $pn, int $t,
    int $i0, int $i1
  ):string
  {
    # prepare
    $v = $this->get($p, $pi, $n, $pn, $t);
    if ($v === null)
    {
      return $i0
        ? $this->base->func[$i0]($this)
        : '';
    }
    # handle lambda
    if (($j = $this->typeMap[$t]) >= 7) {
      return $this->bf1($v, '', $t, $j, $i0, $i1);
    }
    # render
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
    default:
      return $v
        ? $this->base->func[$i1]($this)
        : ($i0
          ? $this->base->func[$i0]($this)
          : '');
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
  function bf1(# TRUTHY LAMBDA {{{
    object $f, string $arg, int $t, int $j,
    int $i0, int $i1
  ):string
  {
    # execute
    $v = $this->invoke($f, $arg, $i1);
    # clarify
    if ($j > 7) {
      $j -= 7;
    }
    else
    {
      $j = self::typeof($v);
      $this->typeMap[$t] = 7 + $j;
    }
    # render
    switch ($j) {
    case 1:
      # content substitution
      if ($v === '' && $i0) {
        $v = $this->base->func[$i0]($this);
      }
      $this->revoke();
      return $v;
    case 2:
      if ($i0 && !$v)
      {
        # apply falsy section
        $r = $this->base->func[$i0]($this);
        $this->revoke();
        return $r;
      }
      break;
    case 4:
      if ($k = count($v))
      {
        if (array_is_list($v)) {
          goto render_iterable;
        }
        break;
      }
      # apply falsy section
      $r = $i0
        ? $this->base->func[$i0]($this)
        : '';
      $this->revoke();
      return $r;
    case 5:
    case 6:
      if ($v instanceof Countable)
      {
        if ($k = count($v)) {
          goto render_iterable;
        }
        # apply falsy section
        $r = $i0
          ? $this->base->func[$i0]($this)
          : '';
        $this->revoke();
        return $r;
      }
      break;
    default:
      # apply truthy/falsy section
      $r = $v
        ? $this->base->func[$i1]($this)
        : ($i0
          ? $this->base->func[$i0]($this)
          : '');
      $this->revoke();
      return $r;
    }
    render_helper:# single value
      $i = ++$this->stackIdx;
      $this->stack[$i] = $v;
      $this->stackType[$i] = $j;
      $r = $this->base->func[$i1]($this);
      $this->stackIdx--;
      $this->revoke();
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
      $this->revoke();
      return $r;
    ###
  }
  # }}}
  function bi(# ITERABLE {{{
    string $p, int $pi, int $n, ?array $pn, int $t,
    int $i0, int $i1
  ):string
  {
    # prepare
    $v = $this->get($p, $pi, $n, $pn, $t);
    if ($v === null)
    {
      return $i0
        ? $this->base->func[$i0]($this)
        : '';
    }
    # handle lambda
    if (($j = $this->typeMap[$t]) >= 7) {
      return $this->bfi($v, '', $t, $j, $i0, $i1);
    }
    # render
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
      'block @'.self::dotname($p, $pn).
      ' has recieved a non-iterable value'.
      ' ('.gettype($v).')'
    );
  }
  # }}}
  function bfi(# ITERABLE LAMBDA {{{
    object $f, string $arg, int $t, int $j,
    int $i0, int $i1
  ):string
  {
    # execute
    $v = $this->invoke($f, $arg, $i1);
    # clarify
    if ($j > 7) {
      $j -= 7;
    }
    else
    {
      $j = self::typeof($v);
      $this->typeMap[$t] = 7 + $j;
    }
    # render
    switch ($j) {
    case 4:
      if ($k = count($v))
      {
        $r = array_is_list($v)
          ? $this->iterate($v, $k, $i1)
          : $this->traverseA($v, $k, $i1);
      }
      else
      {
        $r = $i0
          ? $this->base->func[$i0]($this)
          : '';
      }
      $this->revoke();
      return $r;
    case 5:
    case 6:
      if ($k = count($v))
      {
        $r = ($v instanceof Iterator)
          ? $this->traverseO($v, $k, $i1)
          : $this->iterate($v, $k, $i1);
      }
      else
      {
        $r = $i0
          ? $this->base->func[$i0]($this)
          : '';
      }
      $this->revoke();
      return $r;
    }
    throw new Exception(
      'lambda block'.
      ' has recieved a non-iterable value'.
      ' ('.gettype($v).')'
    );
  }
  # }}}
  function b2(# SWITCH {{{
    string $p, int $pi, int $n, ?array $pn, int $t,
    int $i0, int $i1, int $in, ...$a
  ):string
  {
    # prepare
    $v = $this->get($p, $pi, $n, $pn, $t);
    if ($v === null)
    {
      return $i0
        ? $this->base->func[$i0]($this)
        : '';
    }
    # handle lambda
    if (($j = $this->typeMap[$t]) >= 7)
    {
      return $this->bf2(
        $v, '', $t, $j, $i0, $i1, $in, $a
      );
    }
    # render falsy/simplified
    switch ($j) {
    case 1:
      if ($v === '')
      {
        return $i0
          ? $this->base->func[$i0]($this)
          : '';
      }
      $w = true;
      break;
    case 2:
      $w = $v !== 0;
      $v = strval($v);# cast to string
      break;
    default:
      return $v
        ? ($i1 ? $this->base->func[$i1]($this) : '')
        : ($i0 ? $this->base->func[$i0]($this) : '');
    }
    # render switch section
    for ($v='|'.$v,$i=0; $i < $in; $i+=2)
    {
      if (strpos($a[$i], $v) !== false) {
        return $this->base->func[$a[$i+1]]($this);
      }
    }
    # render default section (truthy/falsy)
    return $w
      ? ($i1 ? $this->base->func[$i1]($this) : '')
      : ($i0 ? $this->base->func[$i0]($this) : '');
  }
  # }}}
  function bf2(# LAMBDA SWITCH {{{
    object $f, string $arg, int $t, int $j,
    int $i0, int $i1, int $in, array $a
  ):string
  {
    # determine primary section and execute
    $i = ($i0 && $i1)
      ? (($i0 < $i1) ? $i0 : $i1)
      : ($i0 ? $i0 : $i1);
    $v = $this->invoke($f, $arg, $i);
    # clarify
    if ($j > 7) {
      $j -= 7;
    }
    else
    {
      $j = self::typeof($v);
      $this->typeMap[$t] = 7 + $j;
    }
    # render falsy/simplified
    switch ($j) {
    case 1:
      if ($v === '')
      {
        $r = $i0
          ? $this->base->func[$i0]($this)
          : '';
        $this->revoke();
        return $r;
      }
      $w = true;
      break;
    case 2:
      $w = $v !== 0;
      $v = strval($v);# cast to string
      break;
    default:
      $r = $v
        ? ($i1 ? $this->base->func[$i1]($this) : '')
        : ($i0 ? $this->base->func[$i0]($this) : '');
      $this->revoke();
      return $r;
    }
    # render switch section
    for ($v='|'.$v,$i=0; $i < $in; $i+=2)
    {
      if (strpos($a[$i], $v) !== false)
      {
        $r = $this->base->func[$a[$i+1]]($this);
        $this->revoke();
        return $r;
      }
    }
    # render default section (truthy/falsy)
    $r = $w
      ? ($i1 ? $this->base->func[$i1]($this) : '')
      : ($i0 ? $this->base->func[$i0]($this) : '');
    $this->revoke();
    return $r;
  }
  # }}}
  function v(# VARIABLE {{{
    string $p, int $pi, int $n, ?array $pn, int $t,
    int $escape
  ):string
  {
    # prepare
    $v = $this->get($p, $pi, $n, $pn, $t);
    if ($v === null) {
      return '';
    }
    # handle lambda
    if (($j = $this->typeMap[$t]) >= 7)
    {
      $v = $this->invoke($v, '', -1);
      if ($j > 7) {
        $j -= 7;
      }
      else
      {
        $j = self::typeof($v);
        $this->typeMap[$t] = 7 + $j;
      }
    }
    # render
    switch ($j) {
    case 1:
      return $escape
        ? ($this->base->escape)($v)
        : $v;
    case 2:
      return strval($v);
    }
    return '';
  }
  # }}}
  # }}}
  # lambda {{{
  function f0(# FALSY {{{
    string $p, int $pi, int $n, ?array $pn, int $t,
    string $arg, int $i0, int $i1
  ):string
  {
    $v = $this->get($p, $pi, $n, $pn, $t);
    if ($v === null) {
      return $this->base->func[$i0]($this);
    }
    return $this->bf0(
      $v, $arg, $t, $this->typeMap[$t], $i0, $i1
    );
  }
  # }}}
  function f1(# TRUTHY {{{
    string $p, int $pi, int $n, ?array $pn, int $t,
    string $arg, int $i0, int $i1
  ):string
  {
    $v = $this->get($p, $pi, $n, $pn, $t);
    if ($v === null)
    {
      return $i0
        ? $this->base->func[$i0]($this)
        : '';
    }
    return $this->bf1(
      $v, $arg, $t, $this->typeMap[$t], $i0, $i1
    );
  }
  # }}}
  function fi(# ITERABLE {{{
    string $p, int $pi, int $n, ?array $pn, int $t,
    string $arg, int $i0, int $i1
  ):string
  {
    $v = $this->get($p, $pi, $n, $pn, $t);
    if ($v === null)
    {
      return $i0
        ? $this->base->func[$i0]($this)
        : '';
    }
    return $this->bfi(
      $v, $arg, $t, $this->typeMap[$t], $i0, $i1
    );
  }
  # }}}
  function f2(# SWITCH {{{
    string $p, int $pi, int $n, ?array $pn, int $t,
    string $arg, int $i0, int $i1, int $in, ...$a
  ):string
  {
    $v = $this->get($p, $pi, $n, $pn, $t);
    if ($v === null)
    {
      return $i0
        ? $this->base->func[$i0]($this)
        : '';
    }
    return $this->bf2(
      $v, $arg, $t, $this->typeMap[$t],
      $i0, $i1, $in, $a
    );
  }
  # }}}
  function fv(# VARIABLE {{{
    string $p, int $pi, int $n, ?array $pn, int $t,
    string $arg, int $escape
  ):string
  {
    # prepare
    $v = $this->get($p, $pi, $n, $pn, $t);
    if ($v === null) {
      return '';
    }
    # handle lambda
    $v = $this->invoke($v, $arg, -1);
    if (($j = $this->typeMap[$t]) > 7) {
      $j -= 7;
    }
    else
    {
      $j = self::typeof($v);
      $this->typeMap[$t] = 7 + $j;
    }
    # render
    switch ($j) {
    case 1:
      return $escape
        ? ($this->base->escape)($v)
        : $v;
    case 2:
      return strval($v);
    }
    return '';
  }
  # }}}
  # }}}
  # assisted value {{{
  function a01(# FALSY/TRUTHY {{{
    string $p, int $i, int $t,
    int $i0, int $i1
  ):string
  {
    if (($i = $this->helpSz - $i) < 0) {
      return '';
    }
    $E = $this->base;
    $v = $this->help[$i][$p];
    switch ($t ?: self::typeof($v)) {
    case 1:
      return ($v === '')
        ? ($i0 ? $E->func[$i0]($this) : '')
        : ($i1 ? $E->func[$i1]($this) : '');
    default:
      return $v
        ? ($i1 ? $E->func[$i1]($this) : '')
        : ($i0 ? $E->func[$i0]($this) : '');
    }
  }
  # }}}
  function a2(# SWITCH {{{
    string $p, int $i, int $t,
    int $i0, int $i1, int $in, ...$a
  ):string
  {
    # prepare
    if (($i = $this->helpSz - $i) < 0) {
      return '';
    }
    $v = $this->help[$i][$p];
    # render falsy/simplified
    switch ($t ?: self::typeof($v)) {
    case 1:
      if ($v === '')
      {
        return $i0
          ? $this->base->func[$i0]($this)
          : '';
      }
      $w = true;
      break;
    case 2:
      $w = $v !== 0;
      $v = strval($v);
      break;
    default:
      return $v
        ? ($i1 ? $this->base->func[$i1]($this) : '')
        : ($i0 ? $this->base->func[$i0]($this) : '');
    }
    # render switch section
    for ($v='|'.$v,$i=0; $i < $in; $i+=2)
    {
      if (strpos($a[$i], $v) !== false) {
        return $this->base->func[$a[$i+1]]($this);
      }
    }
    # render default (truthy/falsy) section
    return $w
      ? ($i1 ? $this->base->func[$i1]($this) : '')
      : ($i0 ? $this->base->func[$i0]($this) : '');
  }
  # }}}
  function av(# VARIABLE {{{
    string $p, int $i, int $t, int $escape
  ):string
  {
    if (($i = $this->helpSz - $i) < 0) {
      return '';
    }
    $v = $this->help[$i][$p];
    switch ($t ?: self::typeof($v)) {
    case 1:
      if ($escape && !$t) {
        return ($this->base->escape)($v);
      }
      return $v;
    case 2:
      return strval($v);
    }
    return '';
  }
  # }}}
  # }}}
}
# }}}
class MustacheLambda # {{{
{
  # constructor {{{
  public object $base;
  public int    $index=0,$pushed=0;
  function __construct(object $mustache) {
    $this->base = $mustache;
  }
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
