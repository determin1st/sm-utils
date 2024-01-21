@echo off
:: opcache.opt_debug_level
:: 0x10000: Output OPCodes prior to optimizations.
:: 0x20000: Output OPCodes After optimizations.
:: 0x40000: Output OPCodes with Context-Free Grammar
:: 0x200000: Output OPCodes with Static Single Assignments forms.

:: check & remove:
:: FETCH_CONSTANT ~ use constants
:: INIT_NS_FCALL_BY_NAME ~ use functions

::php -d opcache.opt_debug_level=0x20000 -f "%CD%\..\autoload.php" 2> autoload.opcode
::php -d opcache.opt_debug_level=0x20000 -f "%CD%\..\mustache.php" 2> mustache.opcode
::php -d opcache.opt_debug_level=0x20000 -f "%CD%\..\error.php" 2> error.opcode
::php -d opcache.opt_debug_level=0x20000 -f "%CD%\..\functions.php" 2> functions.opcode
::php -d opcache.jit_debug=0xFFFFFFFF -f "%CD%\..\error.php" 2> error.asm
php -d opcache.opt_debug_level=0x20000 -f "%CD%\..\sync.php" 2> sync.opcode
::php -d opcache.opt_debug_level=0x20000 -f "%CD%\..\conio.php" 2> conio.opcode
::php -d opcache.opt_debug_level=0x20000 -f "%CD%\..\promise.php" 2> promise.opcode
::php -d opcache.opt_debug_level=0x20000 -f "%CD%\..\fetch.php" 2> fetch.opcode

exit
