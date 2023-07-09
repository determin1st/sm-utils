#define FFI_LIB "ucrtbase.dll"

int _kbhit(void);
/*
Return value
_kbhit returns a nonzero value if a key has been pressed.
Otherwise, it returns 0.
Remarks
The _kbhit function checks the console for a recent keystroke.
If the function returns a nonzero value, a keystroke is waiting
in the buffer. The program can then call _getch or _getche
to get the keystroke.
*/

//int _getch(void);// unicode's better
int _getwch(void);
//int _getch_nolock(void);// still locks!?
//int _getwch_nolock(void);
/*
Return value
Returns the character read. There's no error return.
Remarks
The _getch and _getwch functions read a single character
from the console without echoing the character.
To read a function key or arrow key, each function
must be called twice. The first call returns 0 or 0xE0.
The second call returns the key scan code.
These functions lock the calling thread and so are thread-safe.
For non-locking versions, see _getch_nolock, _getwch_nolock.
_getch_nolock and _getwch_nolock are identical to
_getch and _getchw except that they not protected from
interference by other threads. They might be faster because
they don't incur the overhead of locking out other threads.
Use these functions only in thread-safe contexts such as
single-threaded applications or where the calling scope
already handles thread isolation.
*/

//int _putch(int);
int _putwch(int);
//int _putch_nolock(int);
//int _putwch_nolock(int);
/*
Return value
Returns c if successful. If _putch fails,
it returns EOF; if _putwch fails, it returns WEOF.
Remarks
These functions write the character c directly,
without buffering, to the console. In Windows NT,
_putwch writes Unicode characters using the current
console locale setting.
_putch_nolock and _putwch_nolock are identical to
_putch and _putwch, respectively, except that they
aren't protected from interference by other threads.
They might be faster because they don't incur the
overhead of locking out other threads. Use these
functions only in thread-safe contexts such as
single-threaded applications or where the calling
scope already handles thread isolation.
*/

//char *_cgets(char *buffer);
//wchar_t *_cgetws(wchar_t *buffer);
/*
Return value
_cgets and _cgetws return a pointer to the start
of the string, at buffer[2]. If buffer is NULL,
these functions invoke the invalid parameter handler,
as described in Parameter validation. If execution
is allowed to continue, they return NULL and
set errno to EINVAL.

Remarks
These functions read a string of characters from
the console and store the string and its length in the
location pointed to by buffer. The buffer parameter
must be a pointer to a character array.
The first element of the array, buffer[0], must contain
the maximum length (in characters) of the string to be read.
The array must contain enough elements to hold the string,
a terminating null character ('\0'), and 2 extra bytes.
The function reads characters until a carriage return-line
feed (CR-LF) combination or the specified number of characters
is read. The string is stored starting at buffer[2].
If the function reads a CR-LF, it stores the null character ('\0').
The function then stores the actual length of the string
in the second array element, buffer[1].
Because all editing keys are active when _cgets or _cgetws
is called while in a console window, pressing the F3 key
repeats the last entered entry.
By default, this function's global state is scoped to the application.
To change this behavior, see Global state in the CRT.
*/

//define _IOFBF 0 /* Fully buffered  */
//define _IOLBF 1 /* Line buffered   */
//define _IONBF 2 /* No buffering    */
//int setvbuf(FILE *stream, char *buffer, int mode, int size);
//int setvbuf(char*,char*,int,int);
/*
*/






