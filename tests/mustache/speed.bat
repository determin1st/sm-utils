@echo off
set FILE="speed.php"
::goto ALL
goto ONLY

:TOP
php -f %FILE% 4 %1
php -f %FILE% 1 %1
node speed.js 0 %1
node speed.js 3 %1
goto END

:ONLY
::php -f %FILE% 1 %1
php -f %FILE% 2 %1
php -f %FILE% 3 %1
goto END

:ALL
:: mustache
php -f %FILE% 0 %1
:: sm-mustache-old
php -f %FILE% 4 %1
:: sm-mustache (+preset)
php -f %FILE% 1 %1
php -f %FILE% 2 %1
php -f %FILE% 3 %1
:: mustache.js
node speed.js 0 %1
:: handlebars.js (+preset)
node speed.js 1 %1
node speed.js 2 %1
:: hoogan.js (+preset)
node speed.js 3 %1
node speed.js 4 %1
:: wontache (+preset)
node speed.js 5 %1
node speed.js 6 %1

:END
echo.
exit
