"""Run the dedicated AJAX lifecycle portion of the live HTTP browser suite."""
from browser_test import main

if __name__ == '__main__':
    main(ajax_only=True)
