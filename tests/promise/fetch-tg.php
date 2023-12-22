<?php declare(strict_types=1);
namespace SM;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
###
$token = ($_SERVER['argc'] > 1)
  ? $_SERVER['argv'][1]
  : '';
###
if (!$token)
{
  echo "\nspecify bot token\n\n";
  exit(0);
}
$fetch = Fetch::new([
  'baseUrl' => 'https://api.telegram.org/'.$token.'/',
  'options' => [
    CURLOPT_VERBOSE => true
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE,
    CURLOPT_PIPEWAIT     => true,# be lazy/multiplexy
  ],
]);
if (ErrorEx::is($fetch))
{
  var_dump($fetch);
  exit(1);
}
$I = 'Fetch•'.proc_id();
cli_set_process_title($I);
echo $I." started\n";
echo "[1] getMe()\n";
echo "[q] to quit\n\n";
while (1)
{
  switch (Conio::getch()) {
  case 'q':# {{{
    echo "> quit\n";
    break 2;
  # }}}
  case '1':# {{{
    echo "> getMe(): ";
    $r = await(
      $fetch(['url' => 'getMe'])
    );
    if (!$r->ok)
    {
      echo "FAIL\n";
      break 2;
    }
    var_dump($r->value['content']);
    echo "\n";
    break;
  # }}}
  default:# cooldown {{{
    usleep(100000);# 100ms
    break;
  # }}}
  }
}
###
