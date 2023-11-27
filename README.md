# state machine utilities

<details>
<summary>mustache</summary>

## about
`SM\Mustache` is
a [template processor](https://en.wikipedia.org/wiki/Template_processor)
and **eval**uator (it uses `eval` function
to generate executable code) of
[mustache templates](https://mustache.github.io/)
written in [PHP](https://www.php.net/)
and compatible with
[mustache specification](https://github.com/mustache/spec)
in reasonable parts.
it is reduced from [initial prototype](https://github.com/bobthecow/mustache.php)
to meet personal preferences of its glourious author.

### history
- [the-parable-of-mustache-js](https://writing.jan.io/2013/11/01/the-parable-of-mustache-js.html)
- [mustache-2.0](https://writing.jan.io/mustache-2.0.html)

### performance
this implementation, running in
[the JIT mode](https://php.watch/versions/8.0/JIT),
is comparable to various JS implementations
![perf](https://raw.githack.com/determin1st/sm-utils/main/mm/mustache-perf.jpg)

## syntax
### delimiters
a pair of markers - `{{` and `}}` (the default, which look like
[moustache](https://en.wikipedia.org/wiki/Moustache)) are
used to point to a
[clause](https://en.wikipedia.org/wiki/Clause)
in the
[template](https://en.wikipedia.org/wiki/Template_(word_processing)).
delimiters **must** differ, but they dont have to
mirror each other or be of equal length.
Single letter delimiter is also valid.

delimiters are set once for the
[instance](https://en.wikipedia.org/wiki/Instance_(computer_science)):
```php
$m = SM\Mustache::new(['delims' => '<% %>']);
```
or, arbitrarily, with preparational methods:
```php
$txt = $m->prepare($template, $data, '[[ ]]');
$id  = $m->prep($template, '{: :}');;
```

### clauses
![clause](https://raw.githack.com/determin1st/sm-utils/main/mm/mustache-clause.jpg)
a clause is the basic construct of the
[mustache language](https://en.wikipedia.org/wiki/Transformation_language),
it will be removed or replaced in the final output.

its content may consists of a
[special sigil](https://en.wikipedia.org/wiki/Sigil_(computer_programming))
and/or [a path](https://en.wikipedia.org/wiki/Path_(computing)).

there are two major kinds of clauses in mustache -
a **variable** (independent) and a **block** (dependent).
both are to be associated with particular
[value](https://en.wikipedia.org/wiki/Value_(computer_science))
in **the context stack** using **the path**.

### the context stack
![stack](https://raw.githack.com/determin1st/sm-utils/main/mm/mustache-stack.jpg)
a place inside the mustache instance where all the data sits.
internally it represents a
[stack](https://en.wikipedia.org/wiki/Stack_(abstract_data_type)).
any [composite data](https://en.wikipedia.org/wiki/Composite_data_type)
(an [array](https://www.php.net/manual/en/language.types.array.php)
or an [object](https://www.php.net/manual/en/language.oop5.php)
) pushed to the stack prior to template processing
is called **a helper** or a helper data or
a data that helps in rendering.

helpers may be set at instantiation:
```php
$m = SM\Mustache::new([# push one
  'helper' => $helper1
]);
$m = SM\Mustache::new([# push many
  'helpers' => [$helper1, $helper2, $helper3]
]);
```
or afterwards:
```php
$m->push($helper1);
$m->push($helper2)->push($helper3);
```
they can be removed with:
```php
$m->pull();# removes $helper3
$m->pull(true);# removes all
```

### the path
![path](https://raw.githack.com/determin1st/sm-utils/main/mm/mustache-path.jpg)
represents
[an address of the value](https://en.wikipedia.org/wiki/Name_binding)
in **the context stack** and follows **the dot notation**.
it consists of one or multiple
[identifiers](https://en.wikipedia.org/wiki/Identifier)
```php
$m = SM\Mustache::new([
  'helpers' => [
    ['name' => 'Joe',   'age' => 81, 'another' => ['name' => 'Sleepy']]
    ['name' => 'Barak', 'age' => 62],
    ['name' => 'Donald','another' => ['term' => 2024]],
  ]
]);
$m->value('name');# Donald
$m->value('age');# 62
$m->value('another.name');# Sleepy
```
when `.` precedes a path, the value is fetched
rather than looked up, that is,
the `.` selector points to the top of the stack,
`..` to the second value from the top, etc.
```php
echo $m->value('.name');# Donald
echo $m->value('..name');# Barak
echo $m->value('...name');# Joe
```

### variables






<details>
<summary>variables</summary>

When a simple `{{name}}` specified, it means a variable substitute.
Surrounding space is ignored so, `{{ name }}` is also valid.
The name must be alpha-numeric, for example:
`{{1}}`, `{{name}}`, `{{name1}}` or `{{1name}}`.

Dot notation looks like `{{name.1.has.a.value}}`
it specifies access to a nested variable by using names and dots.
</details>
<details>
<summary>if</summary>

if block is rendered when block value is truthy
```
{{#block}} truthy {{/block}}
```
</details>
<details>
<summary>if-not</summary>

if-not block is rendered when block value is falsy
```
{{^block}} falsy {{/block}}
```
</details>
<details>
<summary>if-else</summary>

if-else block has two sections, one is always rendered
```
{{#block}} truthy {{|}} falsy {{/block}}
```
</details>
<details>
<summary>if-not-else</summary>

if-not-else block has two sections, one is always rendered
```
{{^block}} falsy {{|}} truthy {{/block}}
```
</details>
<details>
<summary>switch block</summary>

switch block is composed of multiple sections.
when one section matches the value, it is rendered,
otherwise, block renders empty.
```
  {{#block}}
    when other sections dont match,
    will match TRUE or TRUTHY values
  {{|}}
    when other sections dont match,
    will match FALSE or FALSY values
  {{|0}}
    will match 0,"0"
  {{|1}}
    will match 1,"1"
  {{|2}}
    will match 2,"2"
  {{|hello}}
    will match "hello"
  {{/block}}
```

switch-not block is similar to if-not block.
only one section may be rendered.
it is more natural than switch block because default section is not the first one.
```
  {{^block}}
    falsy section
  {{|0}}
    zero (string)
  {{|1}}
    one (string/number)
  {{|2}}
    two (string/number)
  {{|}}
    truthy section (default)
  {{/block}}
```
</details>

---
</details>
<details>
<summary>promise</summary>

### about
...

### effects
a very special handling of a promise constitute a new concept of the effect.
the effect is whether a result value of a promise is not needed or is consumed
internally, by the last action handler of the promise itself.

every effect must bear an important attribute - identifier.
it allows to enqueue effects with ease, at any time, otherwise,
the burden of managing effects is purely on the user side.

</details>

