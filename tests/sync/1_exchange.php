<?php declare(strict_types=1);
namespace SM;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
###
$o = SyncExchange::new(['id' => 'test-exchange']);
if (ErrorEx::is($o))
{
  var_dump($o);
  exit(1);
}
test_info(
  'SyncExchange',
  "[1] send request: write()+read()+close()\n".
  "[2] send chain of requests: (write()+read())*N + close()\n".
  "[3] send notification: notify()+flush()\n".
  "[4] send signal: signal() or notify()+close()\n".
  "[5] disable/enable reader: read()+write()+flush()"
);
$req = 'hello world!';
$res = 'instance #'.Fx::$PROCESS_ID.' got "'.$req.'"';
$srv = true;
while (1)
{
  $e = null;
  switch (Conio::getch()) {
  case '1':# {{{
    echo "CLIENT> write/request: ";
    while (!$o->write($req, $e) && !$e) {
      usleep(50000);# 50ms
    }
    if ($e)
    {
      if ($e->level)
      {
        echo "FAIL\n";
        break 2;
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
      break 2;
    }
    echo $a."\n";
    echo "CLIENT> close: ";
    if ($o->close($e)) {
      echo "OK\n";
    }
    else
    {
      echo "FAIL\n";
      break 2;
    }
    break;
  # }}}
  case '2':# {{{
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
  case '3':# {{{
    echo "CLIENT> notify: ";
    while (!$o->notify($req, $e) && !$e) {
      usleep(50000);# 50ms
    }
    if ($e)
    {
      if ($e->level)
      {
        echo "FAIL\n";
        break 2;
      }
      echo "SKIP\n";
      break;
    }
    echo $req."\n";
    echo "CLIENT> flush/confirmation: ";
    while (!$o->flush($e) && !$e) {
      usleep(50000);# 50ms
    }
    if ($e)
    {
      echo "FAIL\n";
      break 2;
    }
    echo "OK\n";
    break;
  # }}}
  case '4':# {{{
    echo "CLIENT> signal: ";
    while (!$o->signal($req, $e) && !$e) {
      usleep(50000);# 50ms
    }
    if ($e)
    {
      if ($e->level)
      {
        echo "FAIL\n";
        break 2;
      }
      echo "SKIP\n";
      break;
    }
    echo $req."\n";
    break;
  # }}}
  case '5':# {{{
    if ($srv)
    {
      $srv = false;
      if (!$o->close($e)) {
        break 2;
      }
      echo "SERVER> disabled\n";
    }
    else
    {
      $srv = true;
      echo "SERVER> enabled\n";
    }
    break;
  # }}}
  case '?':# {{{
    echo '> info: state='.$o->state->get();
    echo ' reader='.$o->reader->get();
    echo ' writer='.$o->writer->get();
    echo ' role='.$o->role;
    echo ' reading='.$o->reading;
    echo "\n";
    break;
  # }}}
  default:# {{{
    # skip reading when disabled
    if (!$srv)
    {
      test_cooldown();
      break;
    }
    # SERVER protocol: read=>write=>flush
    while (1)
    {
      if (($a = $o->read($e)) === null && !$e)
      {
        test_cooldown();
        break;
      }
      echo "SERVER> read/";
      if ($e)
      {
        echo "FAIL\n";
        break 3;
      }
      if ($o->pending) {
        echo "request: ".$a."\n";
      }
      else
      {
        echo "notification: ".$a."\n";
        break;
      }
      echo "SERVER> write/response: ";
      if (!$o->write($res, $e))
      {
        echo "FAIL\n";
        break 3;
      }
      echo "OK\n";
      echo "SERVER> flush/confirmation: ";
      while (!$o->flush($e) && !$e) {
        usleep(50000);# 50ms
      }
      if ($e)
      {
        echo "FAIL\n";
        break 3;
      }
      echo "OK\n";
      # check for continuation,
      # did client write another request?
      if (!$o->pending) {
        break;# nope
      }
      # continue..
      echo "+\n";
    }
    break;
  # }}}
  }
}
$o->close($e);
error_dump($e);
###
