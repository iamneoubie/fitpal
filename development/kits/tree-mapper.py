#!/usr/bin/env python3
"""
Web Project Tree Mapper - Directory structure visualization for web projects.

Generates a clean, code-block-wrapped tree structure with a summary table.
Project root is auto-discovered by walking up from the script until a known
marker file is found (.git, composer.json, package.json, LICENSE, readme.md).
"""

from __future__ import annotations

import argparse
import os
import sys
from dataclasses import dataclass, field
from datetime import datetime
from enum import Enum
from pathlib import Path
from typing import Dict, Iterable, List, Optional, Set, Tuple


# ---------------------------------------------------------------------------
# Terminal helpers
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
    
    # UI Colors
    MAGENTA = "\033[1;35m"
    WHITE   = "\033[1;37m"


def paint(text: str, *styles: str) -> str:
    """Wrap text in one or more ANSI styles."""
    if not styles:
        return text
    return "".join(styles) + text + C.RESET


def clear_screen() -> None:
    if os.name == "nt":
        os.system("cls")
    else:
        sys.stdout.write("\033[2J\033[H")
        sys.stdout.flush()


# ---------------------------------------------------------------------------
# Enums & constants
# ---------------------------------------------------------------------------

class Mode(str, Enum):
    ALL = "all"
    WEB_ONLY = "web_only"
    ASSETS_ONLY = "assets_only"


MENU_CHOICES: List[Tuple[str, Optional[Mode], str]] = [
    ("1", Mode.ALL,         "All files and folders"),
    ("2", Mode.WEB_ONLY,    "Web files only"),
    ("3", Mode.ASSETS_ONLY, "Assets only"),
    ("0", None,             "Exit"),
]
MENU_MAP: Dict[str, Optional[Mode]] = {k: m for k, m, _ in MENU_CHOICES}

ROOT_MARKERS: Tuple[str, ...] = (
    ".git", "composer.json", "package.json",
    "LICENSE", "readme.md", "README.md",
)

IMAGE_EXTENSIONS: Set[str] = {
    ".png", ".jpg", ".jpeg", ".gif", ".svg",
    ".ico", ".webp", ".bmp",
}

WEB_EXTENSIONS: Set[str] = {
    ".html", ".htm", ".php", ".css", ".js",
    ".json", ".md", ".txt",
}

DEFAULT_EXCLUDED_DIRS: Set[str] = {
    ".git", "node_modules", "__pycache__",
    ".venv", "venv", "env",
}

SUMMARY_BUCKETS: Dict[str, Set[str]] = {
    "html":   {".html", ".htm"},
    "php":    {".php"},
    "css":    {".css"},
    "js":     {".js"},
    "json":   {".json"},
    "text":   {".txt", ".md"},
    "images": IMAGE_EXTENSIONS,
}

SUMMARY_DISPLAY_NAMES: Dict[str, str] = {
    "html":   "HTML files",
    "php":    "PHP files",
    "css":    "CSS files",
    "js":     "JavaScript files",
    "json":   "JSON files",
    "text":   "Text / Markdown",
    "images": "Images",
    "other":  "Other",
}


# ---------------------------------------------------------------------------
# Root discovery
# ---------------------------------------------------------------------------

def find_project_root(
    start: Path,
    markers: Tuple[str, ...] = ROOT_MARKERS,
) -> Optional[Path]:
    current = start.resolve()
    for candidate in [current, *current.parents]:
        if any((candidate / marker).exists() for marker in markers):
            return candidate
    return None


# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

@dataclass
class Config:
    script_path: Path = field(default_factory=lambda: Path(__file__).resolve())
    project_root: Optional[Path] = None
    output_subdir: Path = Path("development") / "documentation" / "architecture"
    output_filename: str = "project_structure.md"
    mode: Mode = Mode.ALL
    excluded_dirs: Set[str] = field(default_factory=lambda: set(DEFAULT_EXCLUDED_DIRS))
    extra_excluded_dirs: Set[str] = field(default_factory=set)
    skip_hidden: bool = True
    exclude_self: bool = True

    @property
    def root(self) -> Path:
        if self.project_root is not None:
            return self.project_root
        discovered = find_project_root(self.script_path.parent)
        if discovered is None:
            raise FileNotFoundError(
                f"Could not locate project root from {self.script_path}. "
                f"Pass --project-root explicitly to override."
            )
        return discovered

    @property
    def output_dir(self) -> Path:
        return self.root / self.output_subdir

    @property
    def output_file(self) -> Path:
        return self.output_dir / self.output_filename

    @property
    def all_excluded_dirs(self) -> Set[str]:
        return self.excluded_dirs | self.extra_excluded_dirs


# ---------------------------------------------------------------------------
# Filtering
# ---------------------------------------------------------------------------

class Filter:
    def __init__(self, config: Config) -> None:
        self.config = config

    def include_file(self, name: str) -> bool:
        suffix = Path(name).suffix.lower()
        mode = self.config.mode
        if mode is Mode.ALL:
            return True
        if mode is Mode.WEB_ONLY:
            return suffix in WEB_EXTENSIONS
        if mode is Mode.ASSETS_ONLY:
            return suffix in IMAGE_EXTENSIONS
        return False

    def include_dir(self, name: str) -> bool:
        if self.config.skip_hidden and name.startswith("."):
            return False
        return name not in self.config.all_excluded_dirs

    def include_entry(self, path: Path) -> bool:
        if path.is_dir():
            return self.include_dir(path.name)
        if not path.is_file():
            return False
        if self.config.skip_hidden and path.name.startswith("."):
            return False
        if self.config.exclude_self and path.resolve() == self.config.script_path:
            return False
        return self.include_file(path.name)


# ---------------------------------------------------------------------------
# Tree building
# ---------------------------------------------------------------------------

class TreeBuilder:
    def __init__(self, config: Config, filt: Filter) -> None:
        self.config = config
        self.filter = filt
        self.lines: List[str] = []

    def build(self, root: Path) -> List[str]:
        self.lines = [f"{root.name}/", "│"]
        self._walk(root, indent="")
        return self.lines

    def _walk(self, directory: Path, indent: str) -> None:
        entries = self._collect_entries(directory)
        total = len(entries)
        for index, (entry, is_dir) in enumerate(entries, start=1):
            is_last = index == total
            connector = "└── " if is_last else "├── "
            name = f"{entry.name}/" if is_dir else entry.name
            self.lines.append(f"{indent}{connector}{name}")
            if is_dir:
                child_indent = indent + ("    " if is_last else "│   ")
                self._walk(entry, child_indent)

    def _collect_entries(self, directory: Path) -> List[Tuple[Path, bool]]:
        try:
            children = list(directory.iterdir())
        except PermissionError:
            return []

        dirs, files = [], []
        for child in children:
            if child.is_dir():
                if self.filter.include_dir(child.name):
                    dirs.append(child)
            elif child.is_file():
                if self.filter.include_entry(child):
                    files.append(child)

        dirs.sort(key=lambda p: p.name.lower())
        files.sort(key=lambda p: p.name.lower())
        return [(d, True) for d in dirs] + [(f, False) for f in files]


# ---------------------------------------------------------------------------
# Statistics
# ---------------------------------------------------------------------------

@dataclass
class Stats:
    by_bucket: Dict[str, int] = field(default_factory=dict)
    total_dirs: int = 0
    total_files: int = 0

    @classmethod
    def empty(cls) -> "Stats":
        return cls(by_bucket={name: 0 for name in SUMMARY_DISPLAY_NAMES})


class StatsCollector:
    def __init__(self, config: Config, filt: Filter) -> None:
        self.config = config
        self.filter = filt

    def collect(self, root: Path) -> Stats:
        stats = Stats.empty()
        for path in self._iter_visible(root):
            if path.is_dir():
                stats.total_dirs += 1
                continue
            if not path.is_file():
                continue
            if not self.filter.include_entry(path):
                continue
            stats.total_files += 1
            stats.by_bucket[self._classify(path.suffix.lower())] += 1
        return stats

    def _iter_visible(self, root: Path) -> Iterable[Path]:
        stack = [root]
        while stack:
            current = stack.pop()
            try:
                children = list(current.iterdir())
            except PermissionError:
                continue
            for child in children:
                if child.is_dir():
                    if self.filter.include_dir(child.name):
                        stack.append(child)
                        yield child
                else:
                    yield child

    @staticmethod
    def _classify(suffix: str) -> str:
        for bucket, extensions in SUMMARY_BUCKETS.items():
            if suffix in extensions:
                return bucket
        return "other"


# ---------------------------------------------------------------------------
# Markdown rendering
# ---------------------------------------------------------------------------

class MarkdownRenderer:
    def __init__(self, config: Config) -> None:
        self.config = config

    def render(self, tree_lines: List[str], stats: Stats) -> str:
        parts: List[str] = []
        parts.extend(self._header())
        parts.append("```")
        parts.extend(tree_lines)
        parts.append("```")
        parts.append("")
        parts.extend(self._summary(stats))
        parts.extend(self._footer())
        return "\n".join(parts)

    def _header(self) -> List[str]:
        return [
            "# Web Project Structure",
            "",
            f"**Project:** {self.config.root.name}",
            f"**Generated:** {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}",
            f"**Mode:** {self.config.mode.value}",
            "",
        ]

    def _summary(self, stats: Stats) -> List[str]:
        rows = ["## Summary", "", "| File Type | Count |", "|-----------|-------|"]
        for bucket in SUMMARY_DISPLAY_NAMES:
            label = SUMMARY_DISPLAY_NAMES[bucket]
            rows.append(f"| {label} | {stats.by_bucket.get(bucket, 0)} |")
        rows.append("")
        rows.append(f"**Total directories:** {stats.total_dirs}")
        rows.append(f"**Total files:** {stats.total_files}")
        rows.append("")
        return rows

    def _footer(self) -> List[str]:
        return [
            "---",
            "",
            "*Generated by Web Project Tree Mapper*",
            f"*Script: {self.config.script_path.name}*",
            "",
        ]


# ---------------------------------------------------------------------------
# Orchestrator
# ---------------------------------------------------------------------------

class TreeMapper:
    def __init__(self, config: Config) -> None:
        self.config = config
        self.filter = Filter(config)
        self.builder = TreeBuilder(config, self.filter)
        self.collector = StatsCollector(config, self.filter)
        self.renderer = MarkdownRenderer(config)

    def run(self) -> Tuple[Path, Stats]:
        root = self.config.root
        if not root.exists():
            raise FileNotFoundError(f"Project root does not exist: {root}")
        tree_lines = self.builder.build(root)
        stats = self.collector.collect(root)
        content = self.renderer.render(tree_lines, stats)
        self.config.output_dir.mkdir(parents=True, exist_ok=True)
        self.config.output_file.write_text(content, encoding="utf-8")
        return self.config.output_file, stats


# ---------------------------------------------------------------------------
# UI / Feedback
# ---------------------------------------------------------------------------

def show_menu() -> None:
    print(paint("  Choose mapping option", C.WARNING, C.BOLD))
    print()
    for key, _mode, label in MENU_CHOICES:
        # Use the exact colors from the screenshot you liked
        num = paint(f"[{key}]", C.INFO)
        txt = paint(label, C.SUCCESS if key != "0" else C.DIM)
        print(f"  {num} {txt}")
    print()


def get_user_mode() -> Optional[Mode]:
    while True:
        # No space after the arrow, input starts immediately
        prompt = paint("  Choice ", C.MAGENTA, C.BOLD) + paint("› ", C.DIM)
        choice = input(prompt).strip()
        if choice in MENU_MAP:
            return MENU_MAP[choice]
        valid = paint(", ".join(MENU_MAP.keys()), C.WARNING)
        print(paint(f"  Invalid choice. Enter one of: ", C.DANGER) + valid)
        print()


def print_error(message: str) -> None:
    print()
    print(paint("  [ERROR] ", C.DANGER, C.BOLD) + paint(message, C.DANGER))
    print()


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Generate a tree structure map for a web project.",
    )
    parser.add_argument("-m", "--mode", choices=[m.value for m in Mode], default=None,
                        help="Filtering mode. If omitted, an interactive menu is shown.")
    parser.add_argument("-o", "--output-dir", type=Path, default=None,
                        help="Output directory relative to project root.")
    parser.add_argument("-n", "--output-name", default="project_structure.md",
                        help="Output filename.")
    parser.add_argument("--project-root", type=Path, default=None,
                        help="Override project root.")
    parser.add_argument("--exclude", nargs="*", default=[], metavar="DIR",
                        help="Additional directory names to exclude.")
    parser.add_argument("--include-hidden", action="store_true",
                        help="Include hidden files/dirs.")
    parser.add_argument("--no-clear", action="store_true",
                        help="Do not clear the screen before running.")
    return parser


def main(argv: Optional[List[str]] = None) -> int:
    args = build_parser().parse_args(argv)

    if not args.no_clear:
        clear_screen()

    print()
    print(paint("  Web Project Tree Mapper", C.INFO, C.BOLD))
    print()

    if args.mode is not None:
        mode: Optional[Mode] = Mode(args.mode)
    else:
        show_menu()
        mode = get_user_mode()
        if mode is None:
            print(paint("  Cancelled.", C.DIM))
            print()
            return 0
        print()

    config = Config(
        mode=mode,
        output_filename=args.output_name,
        extra_excluded_dirs=set(args.exclude),
        skip_hidden=not args.include_hidden,
    )
    if args.output_dir is not None:
        config.output_subdir = args.output_dir
    if args.project_root is not None:
        config.project_root = args.project_root.resolve()

    try:
        root = config.root
    except FileNotFoundError as exc:
        print_error(str(exc))
        return 1

    # Context Output
    print(paint("  [READ]  ", C.INFO) + paint(str(root), C.WHITE))
    print(paint("  [WRITE] ", C.INFO) + paint(str(config.output_file), C.WHITE))
    print(paint("  [MODE]  ", C.INFO) + paint(config.mode.value, C.WHITE))
    print()

    print(paint("  [SUCCESS] ", C.SUCCESS) + paint("Mapping...", C.WHITE), end="", flush=True)

    try:
        result, stats = TreeMapper(config).run()
    except Exception as exc:
        print()
        print_error(str(exc))
        return 1

    print(" " + paint("done", C.SUCCESS))
    print()
    
    # Summary Output
    print(paint("  [SUMMARY] ", C.WARNING) + paint(f"{stats.total_dirs} directories", C.WHITE))
    print(paint("  [SUMMARY] ", C.WARNING) + paint(f"{stats.total_files} files", C.WHITE))
    print()

    # Final Success Output
    print(paint("  Written to ", C.DIM) + paint(str(result), C.SUCCESS))
    print()
    
    return 0


if __name__ == "__main__":
    raise SystemExit(main())