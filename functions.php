<?php declare(strict_types=1);
namespace SM;
require_once __DIR__.DIRECTORY_SEPARATOR.'error.php';
use # {{{
  JsonSerializable,ArrayAccess,Iterator,Stringable,
  SyncEvent,SyncReaderWriter,SyncSharedMemory,
  Generator,Closure,CURLFile,
  Throwable,Error,Exception;
use function
  set_time_limit,ini_set,register_shutdown_function,
  set_error_handler,class_exists,function_exists,
  method_exists,func_num_args,
  ### variable handling
  gettype,intval,strval,is_object,is_array,is_bool,is_null,
  is_string,is_scalar,
  ### arrays
  explode,implode,count,reset,next,key,array_keys,
  array_push,array_pop,array_shift,array_unshift,
  array_splice,array_slice,in_array,array_search,
  array_reverse,
  ### strings
  strpos,strrpos,strlen,trim,rtrim,uniqid,ucfirst,
  str_repeat,str_replace,strtolower,
  lcfirst,strncmp,substr_count,preg_match,preg_match_all,
  hash,http_build_query,
  json_encode,json_decode,json_last_error,
  json_last_error_msg,
  ### filesystem
  file_put_contents,file_get_contents,clearstatcache,
  file_exists,unlink,filesize,filemtime,tempnam,
  sys_get_temp_dir,mkdir,scandir,fwrite,fread,fclose,glob,
  ### CURL
  curl_init,curl_setopt_array,curl_exec,
  curl_errno,curl_error,curl_strerror,curl_close,
  curl_multi_init,curl_multi_add_handle,curl_multi_exec,
  curl_multi_select,curl_multi_strerror,
  curl_multi_info_read,curl_multi_remove_handle,
  curl_multi_close,
  ### misc
  proc_open,is_resource,proc_get_status,proc_terminate,
  getmypid,ob_start,ob_get_length,ob_flush,ob_end_clean,
  pack,unpack,time,hrtime,sleep,usleep,
  min,max,pow;
# }}}
###
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
function array_import(array &$to, array &$from): void # {{{
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
}
# }}}
function array_import_all(array &$to, array &$from): void # {{{
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
}
# }}}
function array_import_new(array &$to, array &$from): void # {{{
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
}
# }}}
function file_persist(string $file): bool # {{{
{
  clearstatcache(true, $file);
  return file_exists($file);
}
# }}}
function file_unlink(string $file): bool # {{{
{
  return (!file_exists($file) || unlink($file));
}
# }}}
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
function json_error(): string # {{{
{
  return (json_last_error() !== JSON_ERROR_NONE)
    ? json_last_error_msg() : '';
}
# }}}
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
    ? preg_replace($e, '', $s);
    : $s;
}
# }}}
###
