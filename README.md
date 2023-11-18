# state machine utilities

<details>
<summary>mustache</summary>

## about
`SM\Mustache` is an **eval**uator of
[mustache templates](https://mustache.github.io/)
written in [PHP](https://www.php.net/)
and compatible with
[mustache specification](https://github.com/mustache/spec)
in reasonable parts.
it is reduced from [initial prototype](https://github.com/bobthecow/mustache.php)
to meet personal preferences of its glourious author.

### history origins
https://writing.jan.io/2013/11/01/the-parable-of-mustache-js.html
https://writing.jan.io/mustache-2.0.html

### performance
this implementation, running in JIT mode,
is comparable to various JS implementations
![perf](https://raw.githack.com/determin1st/sm-utils/main/mm/mustache-perf.jpg)

## syntax
### delimiters

A pair of markers, like `{{` and `}}` (the default),
used to point mustache syntax in the template.

Left and right delimiter **must** differ.
They dont have to mirror each other or be of equal size.

examples:
- `{:` and `:}`
- `[` and `]`
- `<!--` and `-->`
- `(((` and `)))`
- `<?` and `?>`

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

