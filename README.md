# state machine utilities

- [mustache](mustache.md)


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

