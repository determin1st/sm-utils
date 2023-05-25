# sm-utils


<details>
<summary>array</summary>

...
</details>
<details>
<summary>mustache</summary>

### about
mustache is [mustache templates](https://mustache.github.io/) **eval**uator
(reduced from [prototype](https://github.com/bobthecow/mustache.php))
### performance
This implementation (running in JIT mode) is comparable to JS implementation of
[mustache.js](https://github.com/janl/mustache.js)
(later has one [issue](https://github.com/janl/mustache.js/issues/65))
### spec
<https://github.com/mustache/spec>
deviations are:
- no `<` template parent (inheritance)
- no `>` template partials (inheritance)
- no `=` delimiter alternation, rendering with custom delimiters is possible
  but rendered template will not be cached, assuming preparation steps.
- no escaping by default, escape function or flag must be specified explicitly.
- no `{{{tripple stashes}}}`, this mode is set explicitly with `&` variable tag.
- template recursions are disabled by default.
### template syntax
<details>
<summary>delimiters</summary>

A pair of markers around text, for example `{{` and `}}`
are common (and default) in mustache templates, they also look like mustaches.

There is the left and the right delimiter, they should differ from each other
and be at least 2 characters long.

The choice of base delimiter depends on context, for example, in HTML
it may be reasonable to use `<!--` and `-->` which constitute a comment.
</details>
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
<summary>switch</summary>

switch block is similar to if/if-else block.
only one section may be rendered.
```
  {{#block}}
    truthy section (default)
  {{|0}}
    zero (string)
  {{|1}}
    one (string/number)
  {{|2}}
    two (string/number)
  {{|}}
    falsy section
  {{/block}}
```
</details>
<details>
<summary>switch-not</summary>

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


