<?php declare(strict_types=1);
namespace SM;
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'help.php';
###
ErrorLog::init(['ansi' => Conio::is_ansi()]);
test_info('PromiseResult','
[1] dump Promise::Column
[2] dump Promise::Row
[3] ErrorLog::from(<PromiseResult>)
');
while (1)
{
  switch (Conio::getch()) {
  case '1':# {{{
    echo "> DUMP: ";
    $r = await(
      Promise::Func(function($f) {
        $r = $f->result;
        $r->info('message #1');
        $r->set('value #1');
        $r->set('value #2');
        $r->set(12345);
        $a = [1,2,3];
        $r->extend()->setRef($a);
        $r->set(true);
        $r->warn('message #2');
        $r->fail('message #3');
        $r->confirm('OPERATION','COOPERATION');
      })
      ->thenColumn([
        Promise::Func(function($f) {
          $a = 'element #1';
          $f->result->extend()->setRef($a);
        }),
        Promise::Func(function($f) {
          $a = 'element #2';
          $f->result->set($a);
          $f->result->fail('oops');
        }),
        Promise::Func(function($f) {
          $f->result->set('element #3');
        }),
      ])
      ->then(function($f) {
        $f->result->confirm('subtitle');
        $f->result->confirm('main title');
      })
    );
    var_dump($r);
    echo "\n";
    break;
  # }}}
  case '2':# {{{
    echo "> DUMP: ";
    $r = await(
      Promise::Func(function($f) {
        $f->result->info('row results follow');
      })
      ->thenRow([
        Promise::Timeout(10, function($f) {
          $a = 'element #1';
          $f->result->extend()->setRef($a);
        }),
        Promise::Func(function($f) {
          $a = 'element #2';
          $f->result->set($a);
          $f->result->fail('oops');
        }),
        Promise::Func(function($f) {
          $f->result->set('element #3');
        }),
      ])
    );
    var_dump($r);
    echo "\n";
    break;
  # }}}
  case '3':# {{{
    echo "> ErrorLog::render(<PromiseResult>): ";
    $r = await(
      Promise::Func(function($f) {
        $r = $f->result;
        $r->info('information','is','very','good');
        $r->warn(
          'wow',"warning\nplus multiline message"
        );
        $r->info('standard positive message');
        $r->error(ErrorEx::fatal());
        $f->pupa();
        $r->confirm('title','number','one');
      })
      ->okay(function($f) {
        $f->result->info('this message never appears');
      })
      ->failFuse(function($f) {
        $r = $f->result;
        $r->warn('something bad happened but im going to fix it all!');
        $r->info('yes','yes','well done!!!');
        $r->info(# 1
          'this','title','is','oneliner'
        );
        $r->confirm('the test of promise result','complete');
      })
      ->okay(function($f) {
        $f->result->info('this message never appears');
      })
    );
    /***/
    echo "\n";
    #echo "\n================\n";
    $r = $r->log();
    #var_dump($r);
    #ErrorLog::init(['trace-dir' => true]);
    echo ErrorLog::render($r);
    #echo "================\n";
    break;
  # }}}
  default:# {{{
    test_cooldown();
    break;
  # }}}
  }
}
###
