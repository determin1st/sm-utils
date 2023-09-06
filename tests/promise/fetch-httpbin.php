<?php declare(strict_types=1);
namespace SM;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
require_once DIR_SM_UTILS.'fetch.php';
# AMPHP?
$amp = __DIR__.DIRECTORY_SEPARATOR.
  '__http-client'.DIRECTORY_SEPARATOR.
  'vendor'.DIRECTORY_SEPARATOR.
  'autoload.php';
###
if ($amp_exists = file_exists($amp))
{
  require_once $amp;
  $client = HttpClientBuilder::buildDefault();
}
###
$fetch = Fetch::new([
  'baseUrl' => 'http://httpbin.org/',
  #'options' => [CURLOPT_VERBOSE => true],
]);
if (ErrorEx::is($fetch))
{
  var_dump($fetch);
  exit(1);
}
$I = 'Fetch•'.proc_id();
cli_set_process_title($I);
echo $I." started\n";
echo "[1] measure time waste\n";
echo "[q] quit\n\n";
while (1)
{
  switch (Conio::getch()) {
  case 'q':# {{{
    echo "> quit\n";
    break 2;
  # }}}
  case '1':# {{{
    # promise constructor
    $f = (function($n) use ($fetch) {
      return
        $fetch(['url' => 'delay/'.$n])
        ->then(function() use ($n) {
          echo '{'.$n.'}';
        });
    });
    # assemble
    $n = 9;
    $a = [];
    for ($i=0; $i < $n; ++$i)
    {
      $a[$i] = [];
      for ($j=0; $j < $i+1; ++$j) {
        $a[$i][] = $f($j + 1);
      }
      $a[$i] = array_reverse($a[$i]);
    }
    # execute
    foreach ($a as $row)
    {
      $i = count($row);
      $j = $i * 1000;
      echo "> measuring time waste of delay/[".$i."..1]: ";
      ###
      $t = hrtime(true);
      $r = await($row);
      $t = hrtime_delta_ms($t);
      ###
      if (!$r->ok)
      {
        echo " FAILED\n";
        var_dump($r);
        break 3;
      }
      echo " OK\n";
      echo "> total time: ".$t."ms, wasted: ".($t - $j)."ms\n";
    }
    ######
    # VS #
    ######
    if (!$amp_exists) {
      break;
    }
    # future constructor
    $f = (function($n) use ($client)
    {
      $q = new Request('http://httpbin.org/delay/'.$n, "POST");
      return (function() use ($client, $q, $n)
      {
        $r = $client->request($q);
        echo '{'.$n.'}';
        return $r;
      });
    });
    # assemble
    $n = 9;
    $a = [];
    for ($i=0; $i < $n; ++$i)
    {
      $a[$i] = [];
      for ($j=0; $j < $i+1; ++$j) {
        $a[$i][] = $f($j + 1);
      }
      $a[$i] = array_reverse($a[$i]);
    }
    # execute
    echo "\n> AMPHP http-client\n";
    foreach ($a as $row)
    {
      $i = count($row);
      $j = $i * 1000;
      echo "> measuring time waste of delay/[".$i."..1]: ";
      foreach ($row as &$f) {
        $f = \Amp\async($f);
      }
      ###
      $t = hrtime(true);
      $r = \Amp\Future\await($row);
      $t = hrtime_delta_ms($t);
      ###
      echo " OK\n";
      echo "> total time: ".$t."ms, wasted: ".($t - $j)."ms\n";
    }
    break;
  # }}}
  default:# cooldown {{{
    usleep(100000);# 100ms
    break;
  # }}}
  }
}
###
