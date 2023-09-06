<?php declare(strict_types=1);
namespace SM;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
require_once DIR_SM_UTILS.'fetch.php';
###
$fetch = Fetch::new([
  'baseUrl' => 'https://api.telegram.org/bot1704403321:AAFdV2pctonLeX3C0umW44iO85_r1jfmZUo/',
  'options' => [
    CURLOPT_VERBOSE => true,
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
    $r = await($fetch([
      'url' => 'getMe'
    ]));
    if (!$r->ok) {
      break 2;
    }
    echo "ok\n";
    var_dump($r->value['content']);
    echo "\n";
    break;
  # }}}
  default:# cooldown {{{
    usleep(100000);
    break;
  # }}}
  }
}
#$o->close($e);
#error_dump($e);
###
