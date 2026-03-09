#!/usr/bin/env python3
"""
Wrapper that injects mock GPIO before running pidoors.py.
Executed inside the Docker controller container.
"""
import sys
import os

# Mock RPi.GPIO before pidoors.py tries to import it
sys.path.insert(0, os.path.dirname(__file__))
import mock_gpio  # noqa: F401  – registers RPi.GPIO in sys.modules

# Load pidoors.py source, enable debug so logs appear in `docker compose logs`
with open('/opt/pidoors/pidoors.py') as f:
    code = f.read()
code = code.replace('DEBUG_MODE = False', 'DEBUG_MODE = True', 1)

# Run with __name__ = '__main__' so the main block executes
exec(compile(code, '/opt/pidoors/pidoors.py', 'exec'),
     {'__name__': '__main__', '__file__': '/opt/pidoors/pidoors.py'})
