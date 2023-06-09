<?php declare(strict_types=1);
namespace SM;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
require_once DIR_SM_UTILS.'conio.php';
require_once DIR_SM_UTILS.'sync.php';
###
$o = SyncExchange::new(['id' => 'test-exchange']);
if (ErrorEx::is($o))
{
  var_dump($o);
  exit(1);
}
$I = 'SyncExchange•'.proc_id();
cli_set_process_title($I);
echo $I." started\n";
echo "[1] send request: write()+read()\n";
echo "[2] send notification: notify()+flush()\n";
echo "[3] send signal: signal() or notify()+close()\n";
echo "[4] disable/enable reader\n";
echo "[q] to quit\n\n";
$req = 'hello world!';
$res = $I.' got "'.$req.'"';
$srv = true;
$e = null;
while (1)
{
  $e = null;
  switch (Conio::getch()) {
  case 'q':
    echo "> quit\n";
    break 2;
  case 'i':
    echo '> info: state='.$o->state->get();
    echo ' reader='.$o->reader->get();
    echo ' writer='.$o->writer->get();
    echo ' role='.$o->role;
    echo ' reading='.$o->reading;
    echo "\n";
    break;
  case '1':
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
    break;
  case '2':
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
  case '3':
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
  case '4':
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
  case '':
    # skip reading when disabled
    if (!$srv)
    {
      usleep(100000);# 100ms
      break;
    }
    # SERVER protocol: read=>write=>flush
    if (($a = $o->read($e)) === null && !$e)
    {
      usleep(100000);# 100ms
      break;
    }
    echo "SERVER> read/";
    if ($e)
    {
      echo "FAIL\n";
      break 2;
    }
    if ($o->pending)
    {
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
      break 2;
    }
    echo "OK\n";
    echo "SERVER> flush/confirmation: ";
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
  }
}
$o->close($e);
if ($e)
{
  echo "=ERROR=\n";
  var_dump($e);
  echo "\npress any key to quit..";
  Conio::getch_wait();
}

