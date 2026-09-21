#!/usr/bin/env python3
"""
Auto-generate logs/content-fetcher-configuration/all_path.py
from the current project structure.

- Includes only .php, .css, .js files
- Preserves the pinned "File Structure" block
- Groups entries by their containing directory with # ===== headers
- Paths are prefixed with "/" and relative to the project root
- Always emits POSIX-style (forward slash) paths, regardless of OS
"""

import sys
from pathlib import Path

# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------

# Extensions to include when walking the tree
INCLUDE_EXTENSIONS = {".php", ".css", ".js"}

# These are ALWAYS emitted first, verbatim, under the File Structure block
PINNED_ENTRIES = [
    "/logs/output/project_structure.md",
    "/sql/database.sql",
    "/sql/sample/seed-data.sql",
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
}

# Output file location (relative to project root)
OUTPUT_RELATIVE = "logs/content-fetcher-configuration/all_path.py"

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def find_project_root() -> Path:
    """Project root = parent of the directory containing this script."""
    return Path(__file__).resolve().parent.parent


def should_skip_dir(dir_path: Path, project_root: Path) -> bool:
    """Skip excluded dirs, hidden dirs, and anything in logs/."""
    if dir_path.name in EXCLUDED_DIRS:
        return True
    if dir_path.name.startswith("."):
        return True
    # Skip anything nested under logs/
    try:
        rel_parts = dir_path.relative_to(project_root).parts
    except ValueError:
        return True
    if rel_parts and rel_parts[0] == "logs":
        return True
    return False


def collect_files(project_root: Path) -> dict[str, list[str]]:
    """
    Walk the project and return {directory_rel_path: [file names]}
    for every directory containing at least one matching file.

    Only .php/.css/.js files are included.
    Directory keys are always POSIX-style ("admin/assets/css").
    """
    results: dict[str, list[str]] = {}

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

        # Force POSIX separators so output is identical on Windows/Linux/macOS
        rel = path.relative_to(project_root).as_posix()
        if "/" in rel:
            parent_dir, filename = rel.rsplit("/", 1)
        else:
            parent_dir, filename = ".", rel

        results.setdefault(parent_dir, []).append(filename)

    # Sort files within each directory
    for key in results:
        results[key].sort()

    return results


def format_header(rel_dir: str) -> str:
    """Produce the '# ===== ... =====' comment for a directory."""
    label = "Root" if rel_dir == "." else rel_dir
    return f"    # ===== {label} ====="


def build_files_to_check(project_root: Path) -> str:
    """Build the full text of the new all_path.py."""
    dirs = collect_files(project_root)

    lines: list[str] = []
    lines.append("filesToCheck = [")
    lines.append("    # ===== File Structure =====")
    for entry in PINNED_ENTRIES:
        lines.append(f'    "{entry}",')
    lines.append("")

    # Root-level files first (if any)
    if "." in dirs:
        lines.append(format_header("."))
        for name in dirs["."]:
            lines.append(f'    "/{name}",')
        lines.append("")

    # Then every other directory, in sorted order
    for rel_dir in sorted(d for d in dirs if d != "."):
        lines.append(format_header(rel_dir))
        for name in dirs[rel_dir]:
            lines.append(f'    "/{rel_dir}/{name}",')
        lines.append("")

    lines.append("]")
    lines.append("")
    return "\n".join(lines)


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main() -> int:
    project_root = find_project_root()
    output_path = project_root / OUTPUT_RELATIVE

    print(f"Project root : {project_root}")
    print(f"Output file  : {output_path}")

    content = build_files_to_check(project_root)

    # Sanity guard: this script must never emit Windows-style separators.
    if "\\" in content:
        print("ERROR: backslash leaked into generated paths.", file=sys.stderr)
        return 1

    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(content, encoding="utf-8")

    # Quick stats
    count = content.count('    "/')
    print(f"Wrote {count} file entries to {output_path.name}")
    return 0


if __name__ == "__main__":
    sys.exit(main())