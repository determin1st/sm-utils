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
    # initialize
    # create exchange object
    $IPC = SyncExchange::new([
      'id' => 'sync-exchange-test',
      'share-read' => true,
      'share-write' => true,
      'boost' => true,
      'size' => 2,
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
      echo " [1] write - notification, w\n";
      echo " [2] write - echo, w/r\n";
      echo " [3] write - w/r/w\n";
      echo " [4] write - w/...\n";
      echo " [5] ...\n";
      echo " [9] read\n";
      echo " [0] cancel\n";
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
    case '3':
    case '4':
      # skip when there's already a reading/writing
      if ($p1 || $p2) {
        break;
      }
      # create basic write
      $p2 = Promise::Func(function () use ($IPC,$k) {
        echo "> write(".$k."): ";
        return $IPC->write();
      })
      ->okay(function($A) use ($k) {
        # handle protocol
        $s = $k.':hello from PID='.Fx::$PROCESS_ID;
        switch ($k) {
        case '1':
          return $A->write($s);
        case '2':
          return $A
          ->write($s)
          ->okay(function($A) {
            return $A->read();
          })
          ->okay(function($A) {
            echo $A->result->value.' ';
          });
        case '3':
          return $A
          ->write($s)
          ->okay(function($A) {
            return $A->read();
          })
          ->okay(function($A) {
            echo $A->result->value."\n";
            echo "> write(*): ";
            return $A->write('THE END');
          });
        case '4':
          $n = rand(1, 1000);
          #$n = 100000;
          $s = '4:'.$n;
          return $A
          ->write($s)
          ->okay(function($A) {
            return $A->read();
          })
          ->okay(function($A) {
            $s = $A->result->value;
            $n = (int)($s);
            echo $n."\n";
            echo "> write(*): ";
            $n--;
            $s = (string)$n;
            if (!$n) {
              return $A->write($s);
            }
            return $A
            ->write($s)
            ->okay(function($A) {
              return $A->read();
            })
            ->okay($A);# repeats current routine
          });
        }
        return null;
      })
      ->done(function($r) {
        if ($r->ok) {
          echo "ok\n";
        }
        else
        {
          echo ~$r->status?'fail':'cancel';
          echo "\n";
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
      echo "protocol=".$v[0].": ".$v[1]." ";
      switch ($v[0]) {
      case '1':
        break;
      case '2':
        $p = $A->write($v[1].' + '.$v[1]);
        break;
      case '3':
        $p = $A
        ->write($v[1].' + '.$v[1])
        ->okay(function($A) {
          echo "\n> read: ";
          return $A->read();
        })
        ->okay(function($A) {
          echo $A->result->value." ";
        });
        break;
      case '4':
        $p = $A
        ->write($v[1])
        ->okay(function($A) {
          ###
          static $X=1;# switch read/write
          if ($X)
          {
            $X = 0;
            return $A
            ->read()
            ->okay($A);
          }
          echo "\n> read: ";
          $X = 1;
          $s = $A->result->value;
          $n = (int)($s);
          echo $s." ";
          if (!$n) {
            return null;
          }
          return $A
          ->write($s)
          ->okay($A);
        });
        break;
      }
      return $p;
    })
    ->done(function ($r) use (&$p1) {
      # check the result
      if ($r->ok) {
        echo "ok\n";
      }
      else
      {
        echo ~$r->status?'fail':'cancel';
        echo "\n";
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
