"use strict";
(async function() {
  // prepare {{{
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
  // load script {{{
  let fails = 0;
  let m,render,name,fun="render()",pad="\t\t\t";
  let time = -process.hrtime.bigint();
  // select variant
  switch (test) {
  case 1:
    m = require(name = 'handlebars');
    m.render = (function(template, data) {
      return m.compile(template)(data);
    });
    if (count > 9999) {pad = "\t\t";}
    break;
  case 2:
    m = require(name = 'handlebars');
    fun = "compile()+render()";
    if (count < 100) {pad = "\t\t";}
    else {pad = "\t";}
    break;
  case 3:
    m = require(name = 'hogan.js');
    m.render = (function(template, data) {
      return m.compile(template).render(data);
    });
    break;
  case 4:
    m = require(name = 'hogan.js');
    fun = "compile()+render()";
    pad = "\t\t";
    break;
  case 5:
    m = require(name = 'wontache');
    fun = "()*()";
    if (count < 10) {pad = "\t\t\t\t";}
    break;
  case 6:
    m = require(name = 'wontache');
    fun = "()+()";
    if (count < 10) {pad = "\t\t\t\t";}
    break;
  default:
    m = require(name = 'mustache');
    break;
  }
  time = (time + process.hrtime.bigint()) / 1000000n;// nano to milli
  logit(
    "\nNODE> "+
    str_fg_color(name, 'yellow', 1)+
    ": loaded in "+time+"ms, "+fun+"*"+count+pad
  );
  // }}}
  switch (test) {
  case 2:// handlebars {{{
    time = process.hrtime.bigint();
    for (let k in json)
    {
      let j = json[k];
      if (j.hasOwnProperty('speed') && !j.speed) {
        continue;
      }
      let i=0,t;
      for (t in j['tests'])
      {
        t = j['tests'][t];
        if (t['skip']) {
          continue;
        }
        t['tpl'] = m.compile(t['template']);
      }
    }
    while (count--)
    {
      for (let k in json)
      {
        let j = json[k];
        if (j.hasOwnProperty('speed') && !j.speed) {
          continue;
        }
        let i=0,t;
        for (t in j['tests'])
        {
          t = j['tests'][t];
          if (t['skip']) {
            continue;
          }
          if (t['tpl'](t['data']) !== t['expected'])
          {
            fails++;
          }
        }
      }
    }
    break;
    // }}}
  case 4:// hogan {{{
    time = process.hrtime.bigint();
    for (let k in json)
    {
      let j = json[k];
      if (j.hasOwnProperty('speed') && !j.speed) {
        continue;
      }
      let i=0,t;
      for (t in j['tests'])
      {
        t = j['tests'][t];
        if (t['skip']) {
          continue;
        }
        t['tpl'] = m.compile(t['template']);
      }
    }
    while (count--)
    {
      for (let k in json)
      {
        let j = json[k];
        if (j.hasOwnProperty('speed') && !j.speed) {
          continue;
        }
        let i=0,t;
        for (t in j['tests'])
        {
          t = j['tests'][t];
          if (t['skip']) {
            continue;
          }
          if (t['tpl'].render(t['data']) !== t['expected']) {
            fails++;
          }
        }
      }
    }
    break;
    // }}}
  case 5:// {{{
    time = process.hrtime.bigint();
    while (count--)
    {
      for (let k in json)
      {
        let j = json[k];
        if (j.hasOwnProperty('speed') && !j.speed) {
          continue;
        }
        let i=0,t;
        for (t in j['tests'])
        {
          t = j['tests'][t];
          if (t['skip']) {
            continue;
          }
          if (m(t['template'])(t['data']) !== t['expected'])
          {
            fails++;
          }
        }
      }
    }
    break;
    // }}}
  case 6:// {{{
    time = process.hrtime.bigint();
    for (let k in json)
    {
      let j = json[k];
      if (j.hasOwnProperty('speed') && !j.speed) {
        continue;
      }
      let i=0,t;
      for (t in j['tests'])
      {
        t = j['tests'][t];
        if (t['skip']) {
          continue;
        }
        t['tpl'] = m(t['template']);
      }
    }
    while (count--)
    {
      for (let k in json)
      {
        let j = json[k];
        if (j.hasOwnProperty('speed') && !j.speed) {
          continue;
        }
        let i=0,t;
        for (t in j['tests'])
        {
          t = j['tests'][t];
          if (t['skip']) {
            continue;
          }
          if (t['tpl'](t['data']) !== t['expected'])
          {
            fails++;
          }
        }
      }
    }
    break;
    // }}}
  default:// {{{
    time = process.hrtime.bigint();
    while (count--)
    {
      for (let k in json)
      {
        let j = json[k];
        if (j.hasOwnProperty('speed') && !j.speed) {
          continue;
        }
        let i=0,t;
        for (t in j['tests'])
        {
          t = j['tests'][t];
          if (t['skip']) {
            continue;
          }
          if (m.render(t['template'], t['data']) !== t['expected'])
          {
            fails++;
          }
        }
      }
    }
    break;
    // }}}
  }
  time = (process.hrtime.bigint() - time) / 1000000n;// nano to milli
  logit(" "+time+"ms"+(fails ? ' (fails='+fails+')' : ''));
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
