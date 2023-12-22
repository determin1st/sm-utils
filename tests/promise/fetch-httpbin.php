<?php declare(strict_types=1);
namespace SM;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
# AMP http-client {{{
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
# }}}
# SM Fetch {{{
$fetch = Fetch::new([
  'baseUrl' => 'http://httpbin.org/',
  #'options' => [CURLOPT_VERBOSE => true],
]);
if (ErrorEx::is($fetch))
{
  var_dump($fetch);
  exit(1);
}
# }}}
test_info('Fetch','
[1] measure time waste
[2] HTTP methods
[3] Auth methods
[4] Request inspection
[5] ...
');
while (1)
{
  switch (Conio::getch()) {
  case '1':# time waste {{{
    # promise constructor
    $f = (function($n) use ($fetch) {
      return
        $fetch(['url' => 'delay/'.$n])
        ->then(function() use ($n) {
          echo '{'.$n.'}';
        });
    });
    # assemble
    $n = 5;# max=[1..10]
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
    echo "> SM Fetch\n";
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
        break 3;
      }
      echo " OK\n";
      echo "> total time: ".$t."ms, wasted: ".($t - $j)."ms\n";
      #var_dump($r);
      #break 2;
    }
    echo "\n";
    ######
    # VS #
    ######
    if (0 || !$amp_exists) {
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
    echo "> AMP http-client\n";
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
    echo "\n";
    break;
  # }}}
  case '2':# methods {{{
    echo "> testing HTTP methods: ";
    $a = ['HELLO THERE!', true];
    $t = hrtime(true);
    $r = await([
      ###
      $fetch->delete('delete', $a),
      $fetch->get('get'),
      $fetch->patch('patch', $a),
      $fetch->post('post'),
      $fetch->put('put', $a),
      ###
    ]);
    $t = hrtime_delta_ms($t);
    if (!$r->ok)
    {
      echo "FAIL\n";
      var_dump($r);
      break;
    }
    echo "OK, ".$t."ms\n";
    break;
  # }}}
  case '3':# auth {{{
    echo "> ";
    $a = ['username', 'password'];
    $t = hrtime(true);
    $r = await(
      $fetch([
        'method'=>'GET',
        'url'=>'basic-auth/'.$a[0].'/'.$a[1],
      ])
      ->okay(function($f) use ($fetch,$a) {
        # expect HTTP code: 401 UNAUTHORIZED
        $r = $f->result;
        $x = $r->value['http_code'];
        if ($x !== 401)
        {
          $r->fail('unexpected HTTP code: '.$x);
          return null;
        }
        # report challenge
        $h = $r->value['headers'];
        if (isset($h['www-authenticate'])) {
          echo $h['www-authenticate'].': ';
        }
        # send credentials
        $auth = base64_encode($a[0].':'.$a[1]);
        return $fetch([
          'method'=>'GET',
          'url'=>'basic-auth/'.$a[0].'/'.$a[1],
          'headers'=>[
            'authorization'=>'Basic '.$auth
          ],
        ]);
      })
      ->okay(function($f) {
        # match HTTP code
        $r = $f->result;
        $x = $r->value['http_code'];
        if ($x !== 200)
        {
          $r->fail('unexpected HTTP code: '.$x);
          return null;
        }
        $r->info('authorized!');
        return null;
      })
    );
    $t = hrtime_delta_ms($t);
    if (!$r->ok)
    {
      echo "FAIL\n";
      var_dump($r);
      break;
    }
    echo "OK, ".$t."ms\n";
    break;
  # }}}
  case '4':# request inspection {{{
    $t = hrtime(true);
    $r = await([
      $fetch([
        'method'=>'GET',
        'url'=>'headers',
      ])
      ->okay(function($f) {
        ###
        $r = $f->result;
        $x = $r->value['http_code'];
        if ($x !== 200)
        {
          $r->fail('unexpected HTTP code: '.$x);
          return null;
        }
        echo "> headers: ";
        var_dump($r->value['content']);
        return null;
      }),
      $fetch([
        'method'=>'GET',
        'url'=>'ip',
      ])
      ->okay(function($f) {
        ###
        $r = $f->result;
        $x = $r->value['http_code'];
        if ($x !== 200)
        {
          $r->fail('unexpected HTTP code: '.$x);
          return null;
        }
        echo "> ip: ";
        var_dump($r->value['content']);
        return null;
      }),
      $fetch([
        'method'=>'GET',
        'url'=>'user-agent',
      ])
      ->okay(function($f) {
        ###
        $r = $f->result;
        $x = $r->value['http_code'];
        if ($x !== 200)
        {
          $r->fail('unexpected HTTP code: '.$x);
          return null;
        }
        echo "> user-agent: ";
        var_dump($r->value['content']);
        return null;
      }),
    ]);
    $t = hrtime_delta_ms($t);
    if (!$r->ok)
    {
      echo "> FAIL\n";
      var_dump($r);
      echo "\n";
      break;
    }
    echo "> OK, ".$t."ms\n\n";
    break;
  # }}}
  default:# {{{
    test_cooldown();
    break;
  # }}}
  }
}
###
