#!/usr/bin/env python3
"""Regenerate every lesson (resources/content/lessons/*.html) from these sources: python3 tools/lessons/build_all.py"""
import os, runpy, sys
here = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, here)
for f in ('c1_user.py', 'c2_c3.py', 'c4_sales.py', 'c5_marketing.py'):
    runpy.run_path(os.path.join(here, f), run_name='__main__')
