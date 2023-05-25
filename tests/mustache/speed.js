"use strict";
(async function() {
  // prep {{{
  // check arguments
  let args = process.argv.slice(2);
  if (!args.length)
  {
    logit("specify arguments: <variant=0/1> [<iterations=1>]\n");
    return;
  }
  let test  = +args[0];
  let count = (args.length > 1) ? +args[1] : 1;
  // load testfiles
  let fs = require('fs');
  let files = fs.readdirSync('.').filter(function(e) {
    return (e.slice(-5) === '.json');
  });
  let json = {}, item;
  for (let file of files) {
    json[file.slice(0, -5)] = JSON.parse(fs.readFileSync(file));
  }
  // }}}
  // measure load
  let time = -process.hrtime.bigint();
  let m,render;
  // select variant
  if (test === 0)
  {
    m = require(test = 'mustache');
  }
  else if (test === 1)
  {
    m = require(test = 'handlebars');
    m.render = (function(template, data) {return m.compile(template)(data);});
  }
  else if (test === 2)
  {
    // sm-mustache.js?
    return;
  }
  else {
    return;
  }
  time = (time + process.hrtime.bigint()) / 1000000n;// nano to milli
  logit("\nNODE> "+str_fg_color(test, 'yellow', 1)+": loaded in "+time+"ms, loop("+count+") ");
  // measure load
  let S = (count / 10) | 0;
  let s = 0;
  let fails = 0;
  time = -process.hrtime.bigint();
  await sleep(1000);
  // iterate over all covered tests
  while (count--)
  {
    //if (++s >= S) {logit('.');s = 0;}
    ////
    for (let k in json)
    {
      let j = json[k];
      // skip certain test sets
      if (j.hasOwnProperty('speed') && !j.speed) {
        continue;
      }
      //logit("> testing: "+str_fg_color(k, 'cyan', 1)+"\n");
      let i = 0;
      for (test in j['tests'])
      {
        test = j['tests'][test];
        //logit(" #"+str_fg_color(i++, 'cyan', 1)+": "+test['name']+".. ");
        if (test['skip']) {
          //logit(str_fg_color('skip', 'blue', 0)+"\n");
          continue;
        }
        if (m.render(test['template'], test['data']) === test['expected']) {
          //logit(str_fg_color('ok', 'green', 1)+"\n");
        }
        else
        {
          /***
          logit(str_fg_color('fail', 'red', 1)+"\n");
          console.log(test);
          logit('['+str_bg_color(m.render(test['template'], test['data']), 'red', 0)+']');
          return;
          /***/
          fails++;
        }
      }
    }
  }
  time = (time + process.hrtime.bigint()) / 1000000n;// nano to milli
  logit(" "+(time - 1000n)+"ms"+(fails ? ' (fails='+fails+')' : ''));
  ////
  // util {{{
  function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
  }
  function logit(m, level=-1)
  {
    if (~level) {
      m = 'sm: '+str_bg_color(m, (level ? 'red' : 'cyan'))+"\n";
    }
    process.stderr.write(m);
  }
  function str_bg_color(m, name, strong=0)
  {
    let c = {
      'black'   : [40,100],
      'red'     : [41,101],
      'green'   : [42,102],
      'yellow'  : [43,103],
      'blue'    : [44,104],
      'magenta' : [45,105],
      'cyan'    : [46,106],
      'white'   : [47,107],
    };
    c = c[name][strong];
    return (name.indexOf("\n") === -1)
      ? '['+c+'m'+m+'[0m'
      : '['+c+'m'+m.replaceAll("\n", "[0m\n["+c+"m")+'[0m';
  }
  function str_fg_color(m, name, strong=0)
  {
    let c = {
      'black'   : [30,90],
      'red'     : [31,91],
      'green'   : [32,92],
      'yellow'  : [33,93],
      'blue'    : [34,94],
      'magenta' : [35,95],
      'cyan'    : [36,96],
      'white'   : [37,97],
    };
    c = c[name][strong];
    return '['+c+'m'+m+'[0m';
  }
  // }}}
})();
