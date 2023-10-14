@echo off
php -f "%CD%\speed.php" -- 0 %1
php -f "%CD%\speed.php" -- 2 %1
php -f "%CD%\speed.php" -- 1 %1
node speed.js 0 %1
::node speed.js 1 %1
::node speed.js 2 %1
echo.
exit
