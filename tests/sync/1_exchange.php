<?php declare(strict_types=1);
namespace SM;
require_once
  __DIR__.DIRECTORY_SEPARATOR.'..'.
  DIRECTORY_SEPARATOR.'help.php';
###
ErrorLog::init(['ansi' => Conio::is_ansi()]);
for ($p0=$p1=$p2=$p3=null;;)
{
  switch (await_any($p0,$p1,$p2,$p3)) {
  default:# initialize {{{
    # create exchange
    $SE = SyncExchange::new([
      'id' => 'sync-exchange-test'
    ]);
    if (ErrorEx::is($SE))
    {
      echo ErrorLog::render($SE);
      exit(1);
    }
    # initialize
    $p0 = Conio::getch();
    $rq = 'hello world!';
    $rs = 'instance #'.Fx::$PROCESS_ID.' got "'.$rq.'"';
    # show info
    test_info('SyncExchange',
      "[1] enable reading\n".
      "[2] notification: a single write()\n".
      "[3] ...\n".
      "[4] ..."
    );
    break;
  # }}}
  case 0:# console input {{{
    # get the result
    if (!($r = $p0->result)->ok)
    {
      ErrorLog::render($r);
      exit(1);
    }
    # handle
    switch ($a = $r->value) {
    case '1':# {{{
      if ($p1)
      {
        $p1->cancel();
        $p1 = null;
        echo "> reading off\n";
      }
      else
      {
        $p1 = Promise::Value();
        echo "> reading on\n";
      }
      break;
    # }}}
    case '2':# {{{
      ###
      $p1 && $p1->cancel();
      #$p2 && $p2->cancel();
      if ($p2)
      {
        $p2->cancel();
        echo "[cancel]";
        #var_dump($p2->result);
      }
      $p3 = Promise::Func(function() use ($SE,&$p2) {
        echo "> write: ";
        $p2 = $SE->write('hello');
      });
      break;
    # }}}
    case '222':# {{{
      $i = rand(2, 9);
      echo "CLIENT> N=".$i."\n";
      while ($i--)
      {
        echo "CLIENT> write/request: ";
        while (!$o->write($req, $e) && !$e) {
          usleep(50000);# 50ms
        }
        if ($e)
        {
          if ($e->level)
          {
            echo "FAIL\n";
            break 3;
          }
          echo "SKIP\n";
          break;
        }
        echo $req."\n";
        echo "CLIENT> read/response: ";
        while (($a = $o->read($e)) === null && !$e) {
          usleep(50000);# 50ms
        }
        if ($e)
        {
          echo "FAIL\n";
          break 3;
        }
        echo $a."\n";
      }
      if ($o->pending)
      {
        echo "CLIENT> close: ";
        if ($o->close($e)) {
          echo "OK\n";
        }
        else
        {
          echo "FAIL\n";
          break 2;
        }
      }
      break;
    # }}}
    default:
      test_key();
      break;
    }
    # recharge
    $p0 = Conio::getch();
    break;
  # }}}
  case 1:# exchange reader {{{
    # show error
    if (!($r = $p1->result)->ok)
    {
      echo "> read() error: ";
      echo ErrorLog::render($r);
      echo "\n";
    }
    # recharge
    $p1 = $SE->read()
    ->then(function ($A) {
      ###
      if (!($r = $A->result)->ok) {
        return null;
      }
      echo "> read(): ".$r->context->value."\n";
    });
    break;
  # }}}
  case 2:# exchange writer {{{
    # show error
    if (!($r = $p2->result)->ok)
    {
      echo "FAILED\n";
      echo ErrorLog::render($r);
      echo "\n";
    }
    # cleanup
    $p2 = null;
    break;
  # }}}
  case 3:# operation {{{
    # cleanup
    $p3 = null;
    break;
  # }}}
  }
}
###
