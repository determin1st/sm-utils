<?php declare(strict_types=1);
require_once
  __DIR__.DIRECTORY_SEPARATOR.
  '..'.DIRECTORY_SEPARATOR.
  'conio.php';
###
$logs = [
# top multilines {{{
[
  'level' => 0,
  'msg'   => [# type=2 (no title)
    "A major change was made by the first Emperor,\n".
    "Augustus (27 BC — 14 AD), who reformed revenue\n".
    "collection, bringing large parts of Rome’s empire\n".
    "under consistent direct taxation instead of asking\n".
    "for intermittent tributes. Taxation was determined\n".
    "by population census and private tax farming\n".
    "was abolished in favor for civil service tax collectors.\n".
    "This made provincial administration far more tolerable,\n".
    "decreased corruption and oppression and\n".
    "increased revenues."
  ],
  'span'  => 123456,
  'time'  => 1700073135,
  'logs'  => [],
],
[
  'level' => 2,
  'msg'   => [# type=2 (title)
    'Slavery burden',
    "\n".
    "While it is true that the Roman Empire wasn’t as heavily\n".
    "bureaucratized as the Han Dynasty of Imperial China,\n".
    "the Roman Empire did have a bureaucracy even in this early\n".
    "imperial period that tends to be underestimated; the officials,\n".
    "as stated above, had a large number of slaves who worked\n".
    "informally in administrative jobs."
  ],
  'span'  => 123456,
  'time'  => 1700073135,
  'logs'  => [],
],
[
  'level' => 0,
  'msg'   => [# type=3 (no title)
    'history','city-states',"confederation\n".
    "Rome has been called by some historians a confederation\n".
    "of city-states and that is true as the Roman state\n".
    "preferred on a local level to delegate tasks to city-states\n".
    "and in provinces that did not have such traditions,\n".
    "Rome brought them into being as far as it could.\n".
    "City authorities had to maintain order and extract revenue\n".
    "not only for the city itself but also from the countryside\n".
    "that was allocated to that city and where\n".
    "the majority of the population lived."
  ],
  'span'  => 123456,
  'time'  => 1700073135,
  'logs'  => [],
],
[
  'level' => 2,
  'msg'   => [# type=3 (title)
    'Empire','problems','Tax burden',
    "\n".
    "Under Diocletian and Constantine in the late third and\n".
    "early fourth centuries, the Roman Empire underwent an even\n".
    "greater bureaucratization in order to deal with the threat\n".
    "of external enemies and internal instability.\n".
    "The Empire was to be ruled by two Emperors, one in the West\n".
    "and one in the East, who could respond faster to internal\n".
    "and external threats. In order to maintain a large army\n".
    "to defend the Empire from the ‘barbarian’ threat,\n".
    "more taxes were needed."
  ],
  'span'  => 123456,
  'time'  => 1700073135,
  'logs'  => [],
],
# }}}
# oneliners {{{
[
  'level' => 0,
  'msg'   => ['one'],
  'span'  => 123456,
  'time'  => 1700073135,
  'logs'  => [],
],
[
  'level' => 0,
  'msg'   => ['one','two'],
  'span'  => 123456,
  'time'  => 1700073135,
  'logs'  => [],
],
[
  'level' => 0,
  'msg'   => ['one','two','three'],
  'span'  => 123456,
  'time'  => 1700073135,
  'logs'  => [],
],
# }}}
# nesting {{{
[
  'level' => 2,
  'msg'   => ['go','deeper'],
  'span'  => 123456,
  'time'  => 1700073135,
  'logs'  =>
  [
    [
      'level' => 2,
      'msg'   => ['one'],
    ],
    [
      'level' => 0,
      'msg'   => ['one','two'],
    ],
    [
      'level' => 0,
      'msg'   => ['one','two','three'],
    ],
    [
      'level' => 0,
      'msg'   => ['one','two','three','four'],
    ],
    [
      'level' => 0,
      'msg'   => ['one','two','three','four','five'],
    ],
    ###
    [
      'level' => 0,
      'msg'   => ['one two three'],
      'logs'  =>
      [
        [
          'level' => 1,
          'msg'   => [
            'one','two','three','four',
            "five\n".
            "the bunny went for a walk\n".
            "suddenly, the hunter runs out and.."
          ],
          'logs'  =>
          [
            [
              'level' => 1,
              'msg'   => [
                "..shots stright away!\n".
                "oh my, oh my"
              ],
              'logs'  =>
              [
                [
                  'level' => 1,
                  'msg'   => ["message 1\nmessage 2\nmessage 3"],
                ],
                [
                  'level' => 1,
                  'msg'   => ['bunny is dead'],
                ],
                [
                  'level' => 1,
                  'msg'   => ["bunny\nis dead"],
                ],
                [
                  'level' => 1,
                  'msg'   => ["bunny","is\ndead"],
                ],
              ],
            ],
            [
              'level' => 0,
              'msg'   => ['everything is fine'],
            ],
          ],
        ],
        ###
        [
          'level' => 0,
          'msg'   => ['one','two','three','four','five'],
        ],
        [
          'level' => 0,
          'msg'   => ['one','two','three','four'],
        ],
        [
          'level' => 0,
          'msg'   => ['one','two','three'],
        ],
        [
          'level' => 0,
          'msg'   => ['one','two'],
        ],
      ],
    ],
  ],
],
# }}}
];
if (0)
{
  file_put_contents(
    substr(__FILE__, 0, -4).'.json',
    json_encode($logs,  JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)
  );
}
if (class_exists('SM\Conio', false))
{
  \SM\ErrorLog::init([
    'ansi' => \SM\Conio::is_ansi()
  ]);
}
$out = \SM\ErrorLog::render($logs);
echo $out;
if (0)
{
  file_put_contents(
    substr(__FILE__, 0, -4).'.out',
    #mb_convert_encoding($out, 'UTF-16BE', 'UTF-8')
    #iconv('UTF-8', 'CP866', $out)
    mb_convert_encoding($out, 'CP866', 'UTF-8')
    #$out
  );
}
###
