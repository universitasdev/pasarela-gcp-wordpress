#!/usr/bin/env python3
"""Alias de sync-nowdocs.py (CSS + HTML + JS)."""
import runpy
from pathlib import Path

runpy.run_path(str(Path(__file__).resolve().parent / "sync-nowdocs.py"))
