<?php declare(strict_types=1);
# defs {{{
namespace SM;
use Throwable,JsonException;
use function
  ### arrays
  is_array,explode,implode,count,reset,next,key,array_slice,
  ### strings
  is_string,strval,substr,strpos,strrpos,strlen,rtrim,str_replace,
  json_encode,json_decode,json_last_error,json_last_error_msg,
  preg_replace,
  ### filesystem
  is_file,is_dir,file_put_contents,file_get_contents,clearstatcache,
  file_exists,unlink,filectime,filemtime,mkdir,touch,
  ### other
  get_parent_class,hrtime,getmypid;
use const
  JSON_UNESCAPED_UNICODE,JSON_INVALID_UTF8_IGNORE,JSON_ERROR_NONE,
  PHP_OS_FAMILY,DIRECTORY_SEPARATOR;
###
require_once __DIR__.DIRECTORY_SEPARATOR.'error.php';
# }}}
class Fx
{
  const AUTOLOAD=true;
  static function file_persist(string $file): bool # {{{
  {
    clearstatcache(true, $file);
    return file_exists($file);
  }
  # }}}
  static function file_touch(string $file): bool # {{{
  {
    if (!touch($file)) {
      throw ErrorEx::fail('touch', $file);
    }
    return true;
  }
  # }}}
  static function file_unlink(string $file): bool # {{{
  {
    if (self::file_persist($file) &&
        !unlink($file))
    {
      throw ErrorEx::fail('unlink', $file);
    }
    return true;
  }
  # }}}
}
class Fq extends Fx # quiet
{
  static function file_unlink(string $file): bool # {{{
  {
    try {
      return parent::file_unlink($file);
    }
    catch (Throwable) {
      return false;
    }
  }
  # }}}
}
function await(object|array $p): object # {{{
{
  return Loop::await(is_array($p)
    ? Promise::Row($p)
    : Promise::from($p)
  );
}
# }}}
###
# array {{{
function array_key(array &$a, int $index): int|string|null # {{{
{
  reset($a);
  while ($index--) {
    next($a);
  }
  return key($a);
}
# }}}
function array_string_keys(array &$a): array # {{{
{
  for ($keys = [], reset($a); $k = key($a); next($a)) {
    $keys[] = is_string($k) ? $k : strval($k);
  }
  return $keys;
}
# }}}
function &array_import(array &$to, array $from): array # {{{
{
  foreach ($to as $k => &$v)
  {
    if (isset($from[$k]))
    {
      if (is_array($v)) {
        array_import($v, $from[$k]);
      }
      else {
        $v = $from[$k];
      }
    }
  }
  return $to;
}
# }}}
function &array_import_all(array &$to, array $from): array # {{{
{
  foreach ($from as $k => &$v)
  {
    if (isset($to[$k]) && is_array($to[$k]) && is_array($v)) {
      array_import_all($to[$k], $v);
    }
    else {
      $to[$k] = $v;
    }
  }
  return $to;
}
# }}}
function &array_import_new(array &$to, array $from): array # {{{
{
  foreach ($from as $k => &$v)
  {
    if (!isset($to[$k])) {
      $to[$k] = $v;
    }
    elseif (is_array($v) && is_array($to[$k])) {
      array_import_new($to[$k], $v);
    }
  }
  return $to;
}
# }}}
# }}}
# file {{{
function &file_get_array(string $file): ?array # {{{
{
  try
  {
    if (!file_exists($file) || !is_array($data = require $file)) {
      $data = null;
    }
  }
  catch (Throwable) {
    $data = null;
  }
  return $data;
}
# }}}
function &file_get_json(string $file): ?array # {{{
{
  if (file_exists($file) === false ||
      ($a = file_get_contents($file)) === '')
  {
    $a = [];
    return $a;
  }
  if ($a === false ||
      ($a[0] !== '[' &&
       $a[0] !== '{'))
  {
    $a = null;
    return $a;
  }
  $a = json_decode(
    $a, true, 128, JSON_INVALID_UTF8_IGNORE
  );
  if (!is_array($a)) {
    $a = null;
  }
  return $a;
}
# }}}
function file_set_json(string $file, array|object &$data): int # {{{
{
  if (($a = json_encode($data, JSON_UNESCAPED_UNICODE)) === false ||
      ($b = file_put_contents($file, $a)) === false)
  {
    return 0;# json file cant be empty
  }
  return $b;
}
# }}}
function file_time(string $file, bool $creat = false): int # {{{
{
  try
  {
    $a = $creat ? filectime($file) : filemtime($file);
    $a = $a ?: 0;
  }
  catch (Throwable) {
    $a = 0;
  }
  return $a;
}
# }}}
# }}}
# dir {{{
function dir_check_make(string $dir, int $perms = 0750): bool # {{{
{
  if (file_exists($dir)) {
    return true;
  }
  try {
    $res = mkdir($dir, $perms);
  }
  catch (Throwable) {
    $res = false;
  }
  return $res;
}
# }}}
function dir_path(string $path, int $level = 1): string # {{{
{
  $a = explode(DIRECTORY_SEPARATOR, $path);
  if ($level >= ($b = count($a))) {
    return '';
  }
  return implode(
    DIRECTORY_SEPARATOR,
    array_slice($a, 0, $b - $level)
  );
}
# }}}
function dir_exists(string $path): bool # {{{
{
  if (is_dir($path)) {
    return true;
  }
  if (($dir = dir_path($path)) === '') {
    return false;
  }
  return file_exists($dir);
}
# }}}
# }}}
# class {{{
function class_basename(string $name): string # {{{
{
  return ($i = strrpos($name, '\\'))
    ? substr($name, $i + 1) : $name;
}
# }}}
function class_name(object $o): string # {{{
{
  return class_basename($o::class);
}
# }}}
function class_parent_name(object $o): string # {{{
{
  return ($name = get_parent_class($o))
    ? class_basename($name) : '';
}
# }}}
# }}}
# str {{{
function str_fg_color(# {{{
  string $s, string $color, int $strong = 0
):string
{
  static $z = '[0m';
  static $COLOR = [
    'black'   => [30,90],
    'red'     => [31,91],
    'green'   => [32,92],
    'yellow'  => [33,93],
    'blue'    => [34,94],
    'magenta' => [35,95],
    'cyan'    => [36,96],
    'white'   => [37,97],
  ];
  if (!isset($COLOR[$color])) {
    return $s;
  }
  $x = '['.$COLOR[$color][($strong ? 1 : 0)].'m';
  return strpos($s, $z)
    ? $x.str_replace($z, $z.$x, $s).$z
    : $x.$s.$z;
}
# }}}
function str_bg_color(# {{{
  string $s, string $color, int $strong = 0
):string
{
  static $z = '[0m';
  static $COLOR = [
    'black'   => [40,100],
    'red'     => [41,101],
    'green'   => [42,102],
    'yellow'  => [43,103],
    'blue'    => [44,104],
    'magenta' => [45,105],
    'cyan'    => [46,106],
    'white'   => [47,107],
  ];
  if (!isset($COLOR[$color])) {
    return $s;
  }
  $x = '['.$COLOR[$color][($strong ? 1 : 0)].'m';
  if (strpos($s, $z)) {
    $s = str_replace($z, $z.$x, $s);
  }
  return (strpos($s, "\n") === false)
    ? $x.$s.$z
    : $x.str_replace("\n", $z."\n".$x, $s).$z;
}
# }}}
function str_no_color(string $s): string # {{{
{
  static $z = '[';
  static $e = '/\\[\\d+m/';
  return (strpos($s, $z) !== false)
    ? preg_replace($e, '', $s)
    : $s;
}
# }}}
function json_error(): string # {{{
{
  return (json_last_error() !== JSON_ERROR_NONE)
    ? json_last_error_msg() : '';
}
# }}}
function try_json_encode(# {{{
  mixed &$value, ?object &$error=null
):string
{
  static $flags=0
    |JSON_INVALID_UTF8_IGNORE
    |JSON_UNESCAPED_UNICODE
    |JSON_THROW_ON_ERROR;
  try {
    return json_encode($value, $flags);
  }
  catch (JsonException $e) {
    $error = ErrorEx::fail('json_encode', $e->getMessage());
  }
  catch (Throwable $e) {
    $error = ErrorEx::from($e);
  }
  return '';
}
# }}}
function try_json_decode(# {{{
  string &$s, ?object &$error=null
):mixed
{
  static $flags=0
    |JSON_INVALID_UTF8_IGNORE
    |JSON_THROW_ON_ERROR;
  try {
    return json_decode($s, true, 128, $flags);
  }
  catch (JsonException $e) {
    $error = ErrorEx::fail('json_decode', $e->getMessage());
  }
  catch (Throwable $e) {
    $error = ErrorEx::from($e);
  }
  return null;
}
# }}}
# }}}
# other {{{
function hrtime_delta_ms(int $t0, int $t1=0): int # {{{
{
  if ($t1 < 1) {
    $t1 = hrtime(true);
  }
  $t0 = ($t1 > $t0)
    ? $t1 - $t0
    : $t0 - $t1;
  return (int)($t0 / 1000000);
}
# }}}
function hrtime_expired(int $ms, int $t0, int $t1=0): bool # {{{
{
  return hrtime_delta_ms($t0, $t1) > $ms;
}
# }}}
function hrtime_add_ms(int $t, int $ms): int # {{{
{
  return $t + $ms * 1000000; # milli => nano
}
# }}}
function proc_id(): string # {{{
{
  static $id=null;
  if ($id === null)
  {
    $id = ($i = getmypid())
      ? strval($i)
      : '';
  }
  return $id;
}
# }}}
# }}}
###
