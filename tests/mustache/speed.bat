@echo off
set FILE="speed.php"
goto ALL

:TOP
php -f %FILE% 3 %1
php -f %FILE% 1 %1
node speed.js 0 %1
node speed.js 3 %1
goto END

:ALL
:: mustache
php -f %FILE% 0 %1
:: sm-mustache-old
php -f %FILE% 3 %1
:: sm-mustache (+preloaded)
php -f %FILE% 1 %1
php -f %FILE% 2 %1
:: mustache.js
node speed.js 0 %1
:: handlebars.js (+precompiled)
::node speed.js 1 %1
node speed.js 2 %1
:: hoogan.js (+precompiled)
node speed.js 3 %1
node speed.js 4 %1
:END
echo.
exit
