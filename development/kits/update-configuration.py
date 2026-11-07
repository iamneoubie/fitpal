#!/usr/bin/env python3
"""
Auto-generate all_path.py from the current project structure.

- Includes only .php, .css, .js files
- Preserves the pinned "File Structure" block
- Groups entries by their containing directory with # ===== headers
- Paths are prefixed with "/" and relative to the project root
- Always emits POSIX-style (forward slash) paths, regardless of OS
"""

import argparse
import os
import sys
from pathlib import Path
from typing import Dict, List, Optional, Set, Tuple

# ---------------------------------------------------------------------------
# Terminal Helpers
# ---------------------------------------------------------------------------

class C:
    """ANSI color palette categorized by semantic meaning."""
    RESET   = "\033[0m"
    BOLD    = "\033[1m"
    DIM     = "\033[2m"
    
    # Semantic Colors
    INFO    = "\033[1;36m"  # Cyan
    SUCCESS = "\033[1;32m"  # Green
    WARNING = "\033[1;33m"  # Yellow
    DANGER  = "\033[1;31m"  # Red
    WHITE   = "\033[1;37m"


def paint(text: str, *styles: str) -> str:
    if not styles:
        return text
    return "".join(styles) + text + C.RESET


def clear_screen() -> None:
    if os.name == "nt":
        os.system("cls")
    else:
        sys.stdout.write("\033[2J\033[H")
        sys.stdout.flush()


def print_error(message: str) -> None:
    print()
    print(paint("  [ERROR] ", C.DANGER, C.BOLD) + paint(message, C.DANGER))
    print()


# ---------------------------------------------------------------------------
# Config & Root Discovery
# ---------------------------------------------------------------------------

# Extensions to include when walking the tree
INCLUDE_EXTENSIONS = {".php", ".css", ".js"}

# These are ALWAYS emitted first, verbatim, under the File Structure block
PINNED_ENTRIES = [
    "/logs/instructions/general.md",
    "/logs/tree-mapper/project_structure.md",
    "/logs/logic/business-logic.md",
    "/sql/database.sql",
    "/sql/seed/all-in-one.sql",
]

# Directories to skip when walking (relative to project root)
EXCLUDED_DIRS = {
    "logs",
    ".git",
    "node_modules",
    "__pycache__",
    ".venv",
    "venv",
    "env",
    "development", # Skip the development folder containing scripts/docs
}

# Output file location (relative to project root)
OUTPUT_RELATIVE = "development/kits/configuration/all_directory.py"

ROOT_MARKERS: Tuple[str, ...] = (
    ".git", "composer.json", "package.json",
    "LICENSE", "readme.md", "README.md",
)

def find_project_root(start: Path) -> Path:
    """Walk upward from start until a marker file is found."""
    current = start.resolve()
    for candidate in [current, *current.parents]:
        if any((candidate / marker).exists() for marker in ROOT_MARKERS):
            return candidate
    # Fallback if no markers exist (assumes 3 levels up from kits/configuration)
    return start.resolve().parent.parent.parent


# ---------------------------------------------------------------------------
# Generation Logic
# ---------------------------------------------------------------------------

def should_skip_dir(dir_path: Path, project_root: Path) -> bool:
    """Skip excluded dirs, hidden dirs, and anything in logs/."""
    if dir_path.name in EXCLUDED_DIRS:
        return True
    if dir_path.name.startswith("."):
        return True
    
    try:
        rel_parts = dir_path.relative_to(project_root).parts
    except ValueError:
        return True
    
    if rel_parts and rel_parts[0] in EXCLUDED_DIRS:
        return True
    return False


def collect_files(project_root: Path) -> Dict[str, List[str]]:
    """Walk the project and return {directory_rel_path: [file names]}."""
    results: Dict[str, List[str]] = {}

    for path in sorted(project_root.rglob("*")):
        if not path.is_file():
            continue
        if path.suffix.lower() not in INCLUDE_EXTENSIONS:
            continue

        # Walk up to make sure no excluded dir is in the path chain
        skip = False
        for parent in path.parents:
            if parent == project_root:
                break
            if should_skip_dir(parent, project_root):
                skip = True
                break
        if skip:
            continue

        # Force POSIX separators
        rel = path.relative_to(project_root).as_posix()
        if "/" in rel:
            parent_dir, filename = rel.rsplit("/", 1)
        else:
            parent_dir, filename = ".", rel

        results.setdefault(parent_dir, []).append(filename)

    for key in results:
        results[key].sort()

    return results


def format_header(rel_dir: str) -> str:
    label = "Root" if rel_dir == "." else rel_dir
    return f"    # ===== {label} ====="


def build_files_to_check(project_root: Path) -> str:
    dirs = collect_files(project_root)

    lines: List[str] = []
    lines.append("filesToCheck = [")
    lines.append("    # ===== File Structure =====")
    for entry in PINNED_ENTRIES:
        lines.append(f'    "{entry}",')
    lines.append("")

    # Root-level files
    if "." in dirs:
        lines.append(format_header("."))
        for name in dirs["."]:
            lines.append(f'    "/{name}",')
        lines.append("")

    # Other directories
    for rel_dir in sorted(d for d in dirs if d != "."):
        lines.append(format_header(rel_dir))
        for name in dirs[rel_dir]:
            lines.append(f'    "/{rel_dir}/{name}",')
        lines.append("")

    lines.append("]")
    lines.append("")
    return "\n".join(lines)


# ---------------------------------------------------------------------------
# Main Execution
# ---------------------------------------------------------------------------

def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Update all_directory.py configuration.")
    parser.add_argument("--no-clear", action="store_true", help="Do not clear the screen.")
    return parser


def main(argv: Optional[List[str]] = None) -> int:
    args = build_parser().parse_args(argv)

    if not args.no_clear:
        clear_screen()

    print()
    print(paint("  All Path Config Generator", C.INFO, C.BOLD))
    print()

    script_path = Path(__file__).resolve()
    project_root = find_project_root(script_path.parent)
    output_path = project_root / OUTPUT_RELATIVE

    print(paint("  [READ]  ", C.INFO) + paint(str(project_root), C.WHITE))
    print(paint("  [WRITE] ", C.INFO) + paint(str(output_path), C.WHITE))
    print()

    print(paint("  [SUCCESS] ", C.SUCCESS) + paint("Scanning files...", C.WHITE), end="", flush=True)

    try:
        content = build_files_to_check(project_root)
    except Exception as exc:
        print()
        print_error(str(exc))
        return 1

    print(" " + paint("done", C.SUCCESS))
    print()

    # Sanity guard: this script must never emit Windows-style separators.
    if "\\" in content:
        print_error("Backslash leaked into generated paths.")
        return 1

    try:
        output_path.parent.mkdir(parents=True, exist_ok=True)
        output_path.write_text(content, encoding="utf-8")
    except IOError as exc:
        print_error(f"Failed to write file: {exc}")
        return 1

    count = content.count('    "/')
    print(paint("  [SUMMARY] ", C.WARNING) + paint(f"{count} file entries found", C.WHITE))
    print()
    
    print(paint("  Written to ", C.DIM) + paint(str(output_path), C.SUCCESS))
    print()

    return 0


if __name__ == "__main__":
    sys.exit(main())