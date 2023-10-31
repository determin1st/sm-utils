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
  count,explode,implode,array_is_list,
  reset,key,next,gettype;
# }}}
interface Mustachable # friendly objects {{{
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
  # TODO: refactor iterator/assisted
  # TODO: preset types more
  # constructor {{{
  static string  $EMPTY='';# hash of an empty string
  public string  $s0='{{',$s1='}}';# default delimiters
  public int     $n0=2,$n1=2;# delimiter sizes
  public int     $pushed=0;# indicates stack has a value
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
      $I->helper($o[$k]);
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
            '',$line++
          ];
          $i0 = $a + 1;# move to the char after newline
        }
        # add the last text chunk
        $token[$i++] = [
          20,substr($tpl, $i0),'',$line
        ];
        break;
      }
      # accumulate plaintext
      while ($a !== false && $a < $b)
      {
        $i1 = $a + 1;# move to the char after newline
        $token[$i++] = [
          20,substr($tpl, $i0, $i1 - $i0),
          '',$line++
        ];
        $a = strpos($tpl, "\n", $i0 = $i1);
      }
      # check something left before the opening
      if ($i0 < $b)
      {
        # add final plaintext (at the same line)
        $c = substr($tpl, $i0, $b - $i0);
        $token[$i++] = [
          20,$c,'',$line
        ];
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
        $token[$i++] = [20,$s0,'',$line];
        # continue after the tag
        $i0 = $b;
        continue;
      }
      # determine position of the next character
      # after the closing delimiter
      $i1 = $a + $n1;
      # add syntax token
      switch ($c[0]) {
      case '^':# 0: FALSY BLOCK {{{
        $c = ltrim(substr($c, 1));
        if ($j = strpos($c, ' '))
        {
          $d = substr($c, $j + 1);
          $c = substr($c, 0, $j);
        }
        else {
          $d = '';
        }
        $token[$i++] = [
          0,$c,$d,$line,$indent,$i0,$i1
        ];
        break;
        # }}}
      case '#':# 1: TRUTHY BLOCK {{{
        $c = ltrim(substr($c, 1));
        if ($j = strpos($c, ' '))
        {
          $d = substr($c, $j + 1);
          $c = substr($c, 0, $j);
        }
        else {
          $d = '';
        }
        $token[$i++] = [
          1,$c,$d,$line,$indent,$i0,$i1
        ];
        break;
        # }}}
      case '@':# 2: ITERABLE BLOCK {{{
        $c = ltrim(substr($c, 1));
        if ($j = strpos($c, ' '))
        {
          $d = substr($c, $j + 1);
          $c = substr($c, 0, $j);
        }
        else {
          $d = '';
        }
        $token[$i++] = [
          2,$c,$d,$line,$indent,$i0,$i1
        ];
        break;
        # }}}
      case '|':# 10: BLOCK SECTION {{{
        $token[$i++] = [
          10,ltrim(substr($c, 1)),'',
          $line,$indent,$i0,$i1
        ];
        break;
        # }}}
      case '/':# 11: BLOCK TERMINATOR {{{
        $token[$i++] = [
          11,'','',$line,$indent,$i0,$i1
        ];
        break;
        # }}}
      case '!':# 12: COMMENTARY {{{
        $token[$i++] = [
          12,'','',$line,$indent,$i0,$i1
        ];
        break;
        # }}}
      case '_':# HELPER VARIABLE
      case '&':# ESCAPABLE VARIABLE
        $c = $c[0].ltrim(substr($c, 1));
      default:# 21: VARIABLE {{{
        #$c = ltrim(substr($c, 1));
        if ($j = strpos($c, ' '))
        {
          $d = substr($c, $j + 1);
          $c = substr($c, 0, $j);
        }
        else {
          $d = '';
        }
        $token[$i++] = [
          21,$c,$d,$line,$indent,$i0,$i1
        ];
        break;
        # }}}
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
        if ($i < $len && $line === $token[$i][3]) {
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
          $line = $token[$i][3];
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
  function _parse(# {{{
    string $tpl, array $token
  ):array
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
      # assemble section
      while ($i < $j)
      {
        $a = $token[$i++];
        switch ($a[0]) {
        case 0:
        case 1:
        case 2:
          # add block {{{
          # create section storage and
          # open first section
          $offs = $a[6];
          $a[7] = [$a[0]];
          $a[8] = 3;
          # search for closing tags
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
              $a[7][] = substr(
                $tpl, $offs, $b[5] - $offs
              );
              # add new area for parsing
              $area[$cnt] = [[], $i, $k];
              $a[7][] = &$area[$cnt][0];
              $cnt++;
              $i = $k + 1;
              # open next subsection
              $offs   = $b[6];
              $a[7][] = ($b[1] === '')
                ? ($a[0] ? 0 : 1)
                : "'".$b[1]."'";
              $a[8]  += 3;
              break;
            case 11:
              # decrement inner blocks count and
              # check current block's not closed
              if (--$n >= 0) {
                break;
              }
              # close previous section,
              # add text and new area for parsing
              $a[7][] = substr(
                $tpl, $offs, $b[5] - $offs
              );
              $area[$cnt] = [[], $i, $k];
              $a[7][] = &$area[$cnt][0];
              $cnt++;
              $i = $k + 1;
              goto block_complete;
            }
          }
          $c = ('^#@'[$a[0]]).$a[1];
          throw new Exception(
            'unclosed '.$c.' at '.$a[5].
            "\n".$this->_wrap($tpl, $a[5], $a[6])
          );
          ###
          block_complete:
          $section[] = $a;
          # }}}
          break;
        case 10:
          throw new Exception(
            'unexpected |'.$a[1].' at '.$a[5].
            "\n".$this->_wrap($tpl, $a[5], $a[6])
          );
        case 11:
          throw new Exception(
            'unexpected /'.$a[1].' at '.$a[5].
            "\n".$this->_wrap($tpl, $a[5], $a[6])
          );
        default:
          # add non-empty plaintext or variable
          if ($a[1] !== '') {
            $section[] = $a;
          }
          break;
        }
      }
    }
    return $area[0][0];
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
      switch ($c = $tree[$i][0]) {
      case 20:# plaintext {{{
        $a  = addcslashes($tree[$i][1], "'\\");
        $x .= "'".$a."'";
        break;
        # }}}
      case 21:# variable {{{
        $a = $tree[$i][1];
        $k = $this->_path(
          $a, $tree[$i][2], $ctx->typeSz, true
        );
        switch ($k) {
        case 0:
          $x .= '$x->av('.$a.')';
          break;
        case 1:
          $ctx->typeMap[$ctx->typeSz++] = 0;
          $x .= '$x->v('.$a.')';
          break;
        case 2:
          $ctx->typeMap[$ctx->typeSz++] = 7;
          $x .= '$x->fv('.$a.')';
          break;
        }
        break;
        # }}}
      default:# block {{{
        # prepare
        $lst = $tree[$i][7];
        $cnt = $tree[$i][8];
        $s0 = 0;# FALSY section
        $s1 = 0;# TRUTHY section
        # collect sections
        for ($a='',$n=0,$m=0; $m < $cnt; $m+=3)
        {
          $k = $this->_index(
            $lst[$m+1], $lst[$m+2], $depth
          );
          if (is_string($lst[$m]))
          {
            $a .= ','.$lst[$m].','.$k;
            $n += 2;
          }
          elseif ($lst[$m]) {
            $s1 = $k;
          }
          else {
            $s0 = $k;
          }
        }
        # compose
        $b = $tree[$i][1];
        $k = $this->_path(
          $b, $tree[$i][2], $ctx->typeSz, false
        );
        switch ($k) {
        case 0:
          # block that is based on assisted value
          if ($n)
          {
            $a  = $b.','.$s0.','.$s1.','.$n.$a;
            $x .= '$x->a23('.$a.')';
          }
          else
          {
            $a  = $b.','.$s0.','.$s1;
            $x .= '$x->a01('.$a.')';
          }
          break;
        case 1:
          # standard block
          $ctx->typeMap[$ctx->typeSz++] = 0;
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
            $a  = $b.','.$s0.','.$s1.','.$n.$a;
            $x .= $c
              ? '$x->b3('.$a.')'
              : '$x->b2('.$a.')';
          }
          elseif ($c === 0)
          {
            # FALSY OR
            $a  = $b.','.$s0.','.$s1;
            $x .= '$x->b0('.$a.')';
          }
          elseif ($c === 1)
          {
            # TRUTHY OR
            $a  = $b.','.$s0.','.$s1;
            $x .= '$x->b1('.$a.')';
          }
          else
          {
            # ITERABLE
            $a  = $b.','.$s0.','.$s1;
            $x .= '$x->ba('.$a.')';
          }
          break;
        case 2:
          # lambda block
          $ctx->typeMap[$ctx->typeSz++] = 7;
          if ($n)
          {
            $a  = $b.','.$s0.','.$s1.','.$n.$a;
            $x .= $c
              ? '$x->f3('.$a.')'
              : '$x->f2('.$a.')';
          }
          elseif ($c === 0)
          {
            $a  = $b.','.$s0.','.$s1;
            $x .= '$x->f0('.$a.')';
          }
          elseif ($c === 1)
          {
            $a  = $b.','.$s0.','.$s1;
            $x .= '$x->f1('.$a.')';
          }
          else
          {
            $a  = $b.','.$s0.','.$s1;
            $x .= '$x->fa('.$a.')';
          }
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
  function _path(# {{{
    string &$name, string $args,
    int $typeIdx, bool $isVar
  ):int
  {
    # compose assisted name
    while ($name[0] === '_')
    {
      # count backpedal
      for ($i=1; $name[$i] === '_'; ++$i)
      {}
      # check not assisted
      $a = substr($name, $i);
      if (!isset(MustacheCtx::HELPVAR[$a])) {
        break;
      }
      # complete
      $name = "'".$a."',".$i;
      return 0;
    }
    # compose standard name,
    # check attached arguments
    if ($args === '')
    {
      # value access: type
      $a = ','.$typeIdx;
      $k = 1;
    }
    else
    {
      # lambda invokation: type + arg
      $a = addcslashes($args, "'\\");
      $a = ','.$typeIdx.",'".$a."'";
      $k = 2;
    }
    # check for variable
    if ($isVar)
    {
      # check for special modifier
      if ($name[0] === '&')
      {
        $name = substr($name, 1);
        $b = $this->escape
          ? ($this->unescape ? '0' : '1')
          : '0';
      }
      else {
        $b = $this->escape ? '1' : '0';
      }
      # add escape flag
      $a .= ','.$b;
    }
    # check implicit iterator
    if ($name === '.')
    {
      $name = "'',0,null".$a;
      return $k;
    }
    # dot notation,
    # extract the first name
    $c = explode('.', $name);
    $b = "'".$c[0]."'";
    # check no more names
    if (!($j = count($c) - 1))
    {
      $name = $b.',0,null'.$a;
      return $k;
    }
    # collect the rest names
    for ($d='',$i=1; $i < $j; ++$i) {
      $d .= "'".$c[$i]."',";
    }
    # complete
    $d = '['.$d."'".$c[$j]."']";
    $name = $b.','.$j.','.$d.$a;
    return $k;
  }
  # }}}
  # }}}
  function helper(array|object &$h): void # {{{
  {
    if (($x = $this->ctx)->index === 0)
    {
      if (is_array($h)) {
        $x->pushArray($h);
      }
      else {
        $x->pushObject($h);
      }
    }
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
    if ($dta === null)
    {
      if ($this->pushed && !$x->index)
      {
        $this->pushed = 0;
        $x->pop();
      }
      $r = $this->func[$k]($x);
    }
    else
    {
      $r = $this->func[$k]($x->push($dta));
      $x->pop();
    }
    # restore currents
    $this->index = $i;
    $x->typeSz = $j;
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
    if ($dta === null)
    {
      if ($this->pushed)
      {
        $this->pushed = 0;
        $this->ctx->pop();
      }
      return $this->func[$i]($this->ctx);
    }
    if ($this->pushed)
    {
      return $this->func[$i](
        $this->ctx->set($dta)
      );
    }
    $this->pushed = 1;
    return $this->func[$i](
      $this->ctx->push($dta)
    );
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
  function get(int $i, $dta=null): string # {{{
  {
    $f = $this->func[$i];
    $x = $this->ctx;
    if ($dta === null)
    {
      if ($this->pushed)
      {
        $this->pushed = 0;
        $x->pop();
      }
      return $f($x);
    }
    if ($this->pushed) {
      return $f($x->set($dta));
    }
    $this->pushed = 1;
    return $f($x->push($dta));
  }
  # }}}
}
# }}}
class MustacheCtx # data stack {{{
{
  # constructor {{{
  const HELPVAR=[# name=>type
    'first' => 3,
    'last'  => 3,
    'index' => 2,
    'count' => 2,
    'key'   => 1,
    'value' => 0,
  ];
  public int   $helpSz=1;
  public array $help=[[# initial values
    'first' => true,
    'last'  => false,
    'index' => 0,
    'count' => 0,
    'key'   => '',
    'value' => '',
  ]];
  public array  $stack=[null],$stackType=[0];
  public int    $stackIdx=0,$index=0;
  public array  $typeMap=[],$typeGrp=[0];
  public int    $typeSz=0;
  public object $base,$magic;
  function __construct(object $mustache)
  {
    $this->base  = $mustache;
    $this->magic = new MustacheMagic($mustache);
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
  function pop(): void # {{{
  {
    $i = $this->stackIdx--;
    unset($this->stack[$i]);
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
    $t = ($v instanceof Mustachable) ? 6 : 5;
    $this->stack[$i] = $x;
    $this->stackType[$i] = $t;
  }
  # }}}
  function set($x): self # {{{
  {
    $i = $this->stackIdx;
    $this->stack[$i] = $x;
    $this->stackType[$i] = self::typeof($x);
    return $this;
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
    string $p, int $n, ?array $pn, int $ti
  ):mixed
  {
    # resolve first name {{{
    $t = $this->typeMap[$ti];
    $i = $this->stackIdx;
    if ($p === '')
    {
      # take current value
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
      # single dot - implicit iterator
      if ($t && $t === $j) {
        return $v;
      }
      $this->typeMap[$ti] = self::typeof($v);
      return $v;
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
          return ($t < 7)
            ? $v->$p
            : $v->$p(...);
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
    return null;
    # }}}
  }
  # }}}
  function iterate(# {{{
    array|object $a, int $cnt, object $f
  ):string
  {
    # assisted iteration
    # prepare
    $i = ++$this->stackIdx;
    $v = &$this->stack[$i];
    $h = &$this->help[$this->helpSz++];
    $h = $this->help[0];
    $h['count'] = $cnt;
    $v = $a[0];
    $this->stackType[$i] = 0;
    # start
    if (--$cnt)
    {
      # do first
      $x = $f($this);
      # do next
      $h['first'] = false;
      for ($j=1; $j < $cnt; ++$j)
      {
        $h['index'] = $j;
        $v  = $a[$j];
        $x .= $f($this);
      }
      # do last
      $h['index'] = $j;
      $h['last'] = true;
      $v  = $a[$j];
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
    array $a, int $cnt, object $f
  ):string
  {
    # assisted traversal
    # prepare
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
      for ($j=1; $j < $cnt; ++$j)
      {
        $h['index'] = $j;
        $h['key']   = key($a);
        $x .= $f($this);
        $v  = next($a);
      }
      # do last
      $h['last']  = true;
      $h['index'] = $j;
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
    object $o, int $cnt, object $f
  ):string
  {
    # assisted traversal
    # prepare
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
      for ($j=1; $j < $cnt; ++$j)
      {
        $h['index'] = $j;
        $h['key']   = $o->key();
        $v  = $o->current();
        $x .= $f($this);
        $o->next();
      }
      # do last
      $h['last']  = true;
      $h['index'] = $j;
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
  function invoke(object $f, string $a, int $i): mixed # {{{
  {
    $m = $this->magic;
    $m->index = $i;
    $i = $this->stackIdx;
    $v = $f($m, $a);
    $this->stackIdx = $i;
    return $v;
  }
  # }}}
  # }}}
  # standard {{{
  function b0(# FALSY OR {{{
    string $p, int $n, ?array $pn, int $t,
    int $i0, int $i1
  ):string
  {
    # prepare
    $E = $this->base;
    if (($v = $this->get($p, $n, $pn, $t)) === null) {
      return $E->func[$i0]($this);
    }
    # handle lambda
    if (($j = $this->typeMap[$t]) >= 7)
    {
      $v = $this->invoke($v, '', 0);
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
  }
  # }}}
  function b1(# TRUTHY OR {{{
    string $p, int $n, ?array $pn, int $t,
    int $i0, int $i1
  ):string
  {
    # prepare
    $E = $this->base;
    if (($v = $this->get($p, $n, $pn, $t)) === null) {
      return $i0 ? $E->func[$i0]($this) : '';
    }
    # handle lambda
    if (($j = $this->typeMap[$t]) >= 7)
    {
      $v = $this->invoke($v, '', $i1);
      if ($j > 7) {
        $j -= 7;
      }
      else
      {
        $j = self::typeof($v);
        $this->typeMap[$t] = 7 + $j;
      }
      # content substitution?
      if ($j === 1)
      {
        return ($v === '')
          ? ($i0 ? $E->func[$i0]($this) : '')
          : $v;
      }
    }
    # render falsy/simplified
    switch ($j) {
    case 1:
      if ($v === '') {
        return $i0 ? $E->func[$i0]($this) : '';
      }
    case 2:
      break;
    case 4:
      if ($k = count($v))
      {
        if (array_is_list($v)) {
          goto render_iterable;
        }
        break;
      }
      return $i0 ? $E->func[$i0]($this) : '';
    case 5:
    case 6:
      if ($v instanceof Countable)
      {
        if ($k = count($v)) {
          goto render_iterable;
        }
        return $i0 ? $E->func[$i0]($this) : '';
      }
      break;
    default:
      return $v
        ? $E->func[$i1]($this)
        : ($i0 ? $E->func[$i0]($this) : '');
    }
    render_helper:# single value
      $i = ++$this->stackIdx;
      $this->stack[$i] = $v;
      $this->stackType[$i] = $j;
      $x = $E->func[$i1]($this);
      $this->stackIdx--;
      return $x;
    ###
    render_iterable:# multiple values
      $i = ++$this->stackIdx;
      $w = &$this->stack[$i];
      $w = $v[0];
      $this->stackType[$i] = self::type_456($w);
      $f = $E->func[$i1];
      $x = $f($this);
      $j = 0;
      while (++$j < $k)
      {
        $w  = $v[$j];
        $x .= $f($this);
      }
      $this->stackIdx--;
      return $x;
    ###
  }
  # }}}
  function ba(# ITERABLE OR {{{
    string $p, int $n, ?array $pn, int $t,
    int $i0, int $i1
  ):string
  {
    # prepare
    $E = $this->base;
    if (($v = $this->get($p, $n, $pn, $t)) === null) {
      return $i0 ? $E->func[$i0]($this) : '';
    }
    # handle lambda
    if (($j = $this->typeMap[$t]) >= 7)
    {
      $v = $this->invoke($v, '', $i1);
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
    case 4:
      if (!($k = count($v))) {
        return $i0 ? $E->func[$i0]($this) : '';
      }
      return array_is_list($v)
        ? $this->iterate($v, $k, $E->func[$i1])
        : $this->traverseA($v, $k, $E->func[$i1]);
    case 5:
    case 6:
      if (!($k = count($v))) {
        return $i0 ? $E->func[$i0]($this) : '';
      }
      return ($v instanceof Iterator)
        ? $this->traverseO($v, $k, $E->func[$i1])
        : $this->iterate($v, $k, $E->func[$i1]);
    }
    throw new Exception(
      'block @'.self::dotname($p, $pn).
      ' has recieved a non-iterable type'.
      ' ('.gettype($v).')'
    );
  }
  # }}}
  function b2(# SWITCH {{{
    string $p, int $n, ?array $pn, int $t,
    int $i0, int $i1, int $in, ...$a
  ):string
  {
    # prepare
    $E = $this->base;
    if (($v = $this->get($p, $n, $pn, $t)) === null) {
      return $E->func[$i0]($this);
    }
    # handle lambda
    if (($j = $this->typeMap[$t]) >= 7)
    {
      $v = $this->invoke($v, '', $i0);
      if ($j > 7) {
        $j -= 7;
      }
      else
      {
        $j = self::typeof($v);
        $this->typeMap[$t] = 7 + $j;
      }
    }
    # render falsy or simplified
    switch ($j) {
    case 1:
      if ($v === '') {
        return $E->func[$i0]($this);
      }
      $w = true;
      break;
    case 2:
      $w = $v !== 0;
      $v = strval($v);# cast to string
      break;
    default:
      return $v
        ? ($i1 ? $E->func[$i1]($this) : '')
        : $E->func[$i0]($this);
    }
    # render switch section
    for ($i=0; $i < $in; $i+=2)
    {
      if ($a[$i] === $v) {
        return $E->func[$a[$i+1]]($this);
      }
    }
    # render default section (truthy/falsy)
    return $w
      ? ($i1 ? $E->func[$i1]($this) : '')
      : $E->func[$i0]($this);
  }
  # }}}
  function b3(# TRUTHY SWITCH {{{
    string $p, int $n, ?array $pn, int $t,
    int $i0, int $i1, int $in, ...$a
  ):string
  {
    # prepare
    $E = $this->base;
    if (($v = $this->get($p, $n, $pn, $t)) === null) {
      return $i0 ? $E->func[$i0]($this) : '';
    }
    # handle lambda
    if (($j = $this->typeMap[$t]) >= 7)
    {
      $v = $this->invoke($v, '', $i1);
      if ($j > 7) {
        $j -= 7;
      }
      else
      {
        $j = self::typeof($v);
        $this->typeMap[$t] = 7 + $j;
      }
    }
    # render falsy or simplified
    switch ($j) {
    case 1:
      if ($v === '') {
        return $i0 ? $E->func[$i0]($this) : '';
      }
      $w = true;
      break;
    case 2:
      $w = $v !== 0;
      $v = strval($v);# cast to string
      break;
    default:
      return $v
        ? $E->func[$i1]($this)
        : ($i0 ? $E->func[$i0]($this) : '');
    }
    # render switch section
    for ($i=0; $i < $in; $i+=2)
    {
      if ($a[$i] === $v) {
        return $E->func[$a[$i+1]]($this);
      }
    }
    # render default section (truthy/falsy)
    return $w
      ? $E->func[$i1]($this)
      : ($i0 ? $E->func[$i0]($this) : '');
  }
  # }}}
  function v(# VARIABLE {{{
    string $p, int $n, ?array $pn, int $t,
    int $escape
  ):string
  {
    # prepare
    $E = $this->base;
    if (($v = $this->get($p, $n, $pn, $t)) === null) {
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
      return $escape ? ($E->escape)($v) : $v;
    case 2:
      return strval($v);
    }
    return '';
  }
  # }}}
  # }}}
  # lambda {{{
  function f0(# FALSY LAMBDA OR {{{
    string $p, int $n, ?array $pn, int $t,
    string $arg, int $i0, int $i1
  ):string
  {
    # prepare
    $E = $this->base;
    if (($v = $this->get($p, $n, $pn, $t)) === null) {
      return $E->func[$i0]($this);
    }
    # handle lambda
    $v = $this->invoke($v, $arg, 0);
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
  }
  # }}}
  function f1(# TRUTHY LAMBDA OR {{{
    string $p, int $n, ?array $pn, int $t,
    string $arg, int $i0, int $i1
  ):string
  {
    # prepare
    $E = $this->base;
    if (($v = $this->get($p, $n, $pn, $t)) === null) {
      return $i0 ? $E->func[$i0]($this) : '';
    }
    # handle lambda
    $v = $this->invoke($v, $arg, $i1);
    if (($j = $this->typeMap[$t]) > 7) {
      $j -= 7;
    }
    else
    {
      $j = self::typeof($v);
      $this->typeMap[$t] = 7 + $j;
    }
    # render falsy/simplified
    switch ($j) {
    case 1:# content substitution
      return ($v === '')
        ? ($i0 ? $E->func[$i0]($this) : '')
        : $v;
    case 2:
      break;
    case 4:
      if ($k = count($v))
      {
        if (array_is_list($v)) {
          goto render_iterable;
        }
        break;
      }
      return $i0 ? $E->func[$i0]($this) : '';
    case 5:
    case 6:
      if ($v instanceof Countable)
      {
        if ($k = count($v)) {
          goto render_iterable;
        }
        return $i0 ? $E->func[$i0]($this) : '';
      }
      break;
    default:# selection
      return $v
        ? $E->func[$i1]($this)
        : ($i0 ? $E->func[$i0]($this) : '');
    }
    render_helper:# single value
      $i = ++$this->stackIdx;
      $this->stack[$i] = $v;
      $this->stackType[$i] = $j;
      $x = $E->func[$i1]($this);
      $this->stackIdx--;
      return $x;
    ###
    render_iterable:# multiple values
      $i = ++$this->stackIdx;
      $w = &$this->stack[$i];
      $w = $v[0];
      $this->stackType[$i] = self::type_456($w);
      $f = $E->func[$i1];
      $x = $f($this);
      $j = 0;
      while (++$j < $k)
      {
        $w  = $v[$j];
        $x .= $f($this);
      }
      $this->stackIdx--;
      return $x;
    ###
  }
  # }}}
  function fa(# ITERABLE LAMBDA OR {{{
    string $p, int $n, ?array $pn, int $t,
    string $arg, int $i0, int $i1
  ):string
  {
    # prepare
    $E = $this->base;
    if (($v = $this->get($p, $n, $pn, $t)) === null) {
      return $i0 ? $E->func[$i0]($this) : '';
    }
    # handle lambda
    $v = $this->invoke($v, $arg, $i1);
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
    case 4:
      if (!($k = count($v))) {
        return $i0 ? $E->func[$i0]($this) : '';
      }
      return array_is_list($v)
        ? $this->iterate($v, $k, $E->func[$i1])
        : $this->traverseA($v, $k, $E->func[$i1]);
    case 5:
    case 6:
      if (!($k = count($v))) {
        return $i0 ? $E->func[$i0]($this) : '';
      }
      return ($v instanceof Iterator)
        ? $this->traverseO($v, $k, $E->func[$i1])
        : $this->iterate($v, $k, $E->func[$i1]);
    }
    throw new Exception(
      'lambda block @'.self::dotname($p, $pn).
      ' has recieved a non-iterable type'.
      ' ('.gettype($v).')'
    );
  }
  # }}}
  function f2(# FALSY LAMBDA SWITCH {{{
    string $p, int $n, ?array $pn, int $t,
    string $arg, int $i0, int $i1,
    int $in, ...$a
  ):string
  {
    # prepare
    $E = $this->base;
    if (($v = $this->get($p, $n, $pn, $t)) === null) {
      return $E->func[$i0]($this);
    }
    # handle lambda
    $v = $this->invoke($v, $arg, $i0);
    if (($j = $this->typeMap[$t]) > 7) {
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
      if ($v === '') {
        return $E->func[$i0]($this);
      }
      $w = true;
      break;
    case 2:
      $w = $v !== 0;
      $v = strval($v);
      break;
    default:
      return $v
        ? ($i1 ? $E->func[$i1]($this) : '')
        : $E->func[$i0]($this);
    }
    # render switch section
    for ($i=0; $i < $in; $i+=2)
    {
      if ($a[$i] === $v) {
        return $E->func[$a[$i+1]]($this);
      }
    }
    # render default (truthy/falsy) section
    return $w
      ? ($i1 ? $E->func[$i1]($this) : '')
      : $E->func[$i0]($this);
  }
  # }}}
  function f3(# TRUTHY LAMBDA SWITCH {{{
    string $p, int $n, ?array $pn, int $t,
    string $arg, int $i0, int $i1,
    int $in, ...$a
  ):string
  {
    # prepare
    $E = $this->base;
    if (($v = $this->get($p, $n, $pn, $t)) === null) {
      return $i0 ? $E->func[$i0]($this) : '';
    }
    # handle lambda
    $v = $this->invoke($v, $arg, $i1);
    if (($j = $this->typeMap[$t]) > 7) {
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
      if ($v === '') {
        return $i0 ? $E->func[$i0]($this) : '';
      }
      $w = true;
      break;
    case 2:
      $w = $v !== 0;
      $v = strval($v);
      break;
    default:
      return $v
        ? $E->func[$i1]($this)
        : ($i0 ? $E->func[$i0]($this) : '');
    }
    # render switch section
    for ($i=0; $i < $in; $i+=2)
    {
      if ($a[$i] === $v) {
        return $E->func[$a[$i+1]]($this);
      }
    }
    # render default (truthy/falsy) section
    return $w
      ? $E->func[$i1]($this)
      : ($i0 ? $E->func[$i0]($this) : '');
  }
  # }}}
  function fv(# LAMBDA VARIABLE {{{
    string $p, int $n, ?array $pn, int $t,
    string $arg, int $escape
  ):string
  {
    # prepare
    $E = $this->base;
    if (($v = $this->get($p, $n, $pn, $t)) === null) {
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
      return $escape ? ($E->escape)($v) : $v;
    case 2:
      return strval($v);
    }
    return '';
  }
  # }}}
  # }}}
  # assisted {{{
  function a01(# FALSY/TRUTHY OR {{{
    string $p, int $i, int $i0, int $i1
  ):string
  {
    if (($i = $this->helpSz - $i) < 0 ||
        !isset($this->help[$i][$p]))
    {
      return '';
    }
    $E = $this->base;
    $v = $this->help[$i][$p];
    $j = self::HELPVAR[$p] ?: self::typeof($v);
    switch ($j) {
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
  function a23(# FALSY/TRUTHY SWITCH {{{
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
    $E = $this->base;
    $v = $this->help[$i][$p];
    $j = self::HELPVAR[$p] ?: self::typeof($v);
    # render falsy or simplified
    switch ($j) {
    case 1:
      if ($v === '') {
        return $i0 ? $E->func[$i0]($this) : '';
      }
      $w = true;
      break;
    case 2:
      $w = $v !== 0;
      $v = strval($v);
      break;
    default:
      return $v
        ? ($i1 ? $E->func[$i1]($this) : '')
        : ($i0 ? $E->func[$i0]($this) : '');
    }
    # render switch section
    for ($i=0; $i < $in; $i+=2)
    {
      if ($a[$i] === $v) {
        return $E->func[$a[$i+1]]($this);
      }
    }
    # render default (truthy/falsy) section
    return $w
      ? ($i1 ? $E->func[$i1]($this) : '')
      : ($i0 ? $E->func[$i0]($this) : '');
  }
  # }}}
  function av(# VARIABLE {{{
    string $p, int $i
  ):string
  {
    if (($i = $this->helpSz - $i) < 0 ||
        !isset($this->help[$i][$p]))
    {
      return '';
    }
    $v = $this->help[$i][$p];
    $j = self::HELPVAR[$p] ?: self::typeof($v);
    switch ($j) {
    case 1:
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
class MustacheMagic # facade for lambdas {{{
{
  # constructor {{{
  public object $base;
  public int    $index=0;
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
  function helper(array|object &$h): void # {{{
  {
    $x = $this->base->ctx;
    if (is_array($h)) {
      $x->pushArray($h);
    }
    else {
      $x->pushObject($h);
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
    $x->pop();
    return $r;
  }
  # }}}
}
# }}}
return Mustache::init();
###
