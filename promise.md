# promise

## about
...

## passive promises
common promise implementation assumes
a promise becomes active from the moment of
its creation till completion:
```javascript
p = new Promise(function(resolve, reject) {
  setTimeout(() => {
    console.log('fetching user..');
    const user = {
      id:    101,
      name: 'Joe'
    };
    resolve(user);
  }, 1000);
})
.then(function(user) {
  console.log("got the user..");
  return user.name;
})
.then(function(name) {
  console.log("user name is "+name);
});

```

## effects
a special handling of a promise 
constitutes a new concept of the effect.

the effect is whether a result value of a promise is not needed or is consumed
internally, by the last action handler of the promise itself.

every effect must bear an important attribute - identifier.
it allows to enqueue effects with ease, at any time, otherwise,
the burden of managing effects is purely on the user side.


