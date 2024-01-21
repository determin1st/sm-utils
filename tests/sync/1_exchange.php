<?php declare(strict_types=1);
namespace SM;
require_once
  __DIR__.DIRECTORY_SEPARATOR.
  '..'.DIRECTORY_SEPARATOR.
  '..'.DIRECTORY_SEPARATOR.
  'autoload.php';
###
ErrorLog::init(['ansi' => Conio::is_ansi()]);
for ($p0=$p1=$p2=null;;)
{
  switch (await_any($p0,$p1,$p2)) {
  default:# {{{
    # create exchange object
    $IPC = SyncExchange::new([
      'id' => 'sync-exchange-test'
    ]);
    if (ErrorEx::is($IPC))
    {
      echo ErrorLog::render($IPC);
      exit(1);
    }
    # simulate console input - show info
    $p0 = Promise::Value('i');
    break;
  # }}}
  case 0:# console command {{{
    # handle
    switch ($k = $p0->result->value) {
    case 'i':
      echo "> info\n\n";
      echo "SyncExchange•".Fx::$PROCESS_ID."\n";
      echo " [1] write(): notification - a single write\n";
      echo " [2] write()+read(): echo\n";
      echo " [3] ...\n";
      echo " [9] read()\n";
      echo " [0] cancel read()/write()\n";
      echo " --- \n";
      echo " [i] information\n";
      echo " [q] quit\n";
      echo "\n";
      break;
    case 'q':
      $p1 && $p1->cancel();
      $p2 && $p2->cancel();
      echo "> quit\n";
      exit(0);
    case '1':
    case '2':
      # skip when there's already a reading/writing
      if ($p1 || $p2) {
        break;
      }
      # create basic write
      $p2 = Promise::Func(function () use ($IPC) {
        echo "> write: ";
        return $IPC->write();
      })
      ->okay(function($A) use ($k) {
        # handle protocol
        $s = $k.':hello from PID='.Fx::$PROCESS_ID;
        switch ($k) {
        case '1':
          echo "notification: ";
          return $A->write($s);
        case '2':
          echo "request: ";
          return $A
          ->write($s)
          ->okay(function($A) {
            echo "response: ";
            return $A->read();
          })
          ->okay(function($A) {
            echo $A->result->value;
          });
        }
        return null;
      })
      ->done(function($r) {
        if ($r->ok) {
          echo "OK\n";
        }
        else
        {
          echo "FAILED\n";
          echo ErrorLog::render($r);
        }
      });
      break;
    case '9':
      # reading: create recharge dummy
      if (!$p1 && !$p2) {
        $p1 = Promise::Value();
      }
      break;
    case '0':
      # cancel reading/writing
      if (!$p1 && !$p2) {
        echo "> nothing to cancel\n";
      }
      $p1 && $p1->cancel();
      $p2 && $p2->cancel();
      break;
    }
    # recharge
    $p0 = Conio::readch();
    break;
  # }}}
  case 1:# exchange read {{{
    # recharge
    $p1 && $p1 = Promise::Func(function($A) use ($IPC) {
      # startup
      echo "> read: ";
      return $IPC->read();
    })
    ->okay(function ($A) {
      # select protocol
      $v = explode(':', $A->result->value, 2);
      $p = null;
      switch ($v[0]) {
      case '1':
        echo 'notification: '.$v[1];
        break;
      case '2':
        echo 'echo: '.$v[1];
        $p = $A->write('you say '.$v[1]);
        break;
      default:
        echo 'unknown: '.$v[0];
        break;
      }
      return $p;
    })
    ->done(function ($r) use (&$p1) {
      # check the result
      if ($r->ok) {
        echo "OK\n";
      }
      else
      {
        echo "ERROR\n";
        echo ErrorLog::render($r);
        $p1 = null;
      }
    });
    break;
  # }}}
  case 2:# exchange write {{{
    # cleanup
    $p2 = null;
    break;
  # }}}
  }
}
###
