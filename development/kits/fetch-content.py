#!/usr/bin/env python3
"""
Web Content Fetcher - Modular file collection for web development

Reads preset configurations from `development/kits/configuration/`.
Writes the generated markdown bundle to `development/kits/temp/`.
"""

import os
import sys
from pathlib import Path
from datetime import datetime
from typing import List, Dict, Optional, Tuple

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


def print_error(message: str) -> None:
    print()
    print(paint("  [ERROR] ", C.DANGER, C.BOLD) + paint(message, C.DANGER))
    print()


# ---------------------------------------------------------------------------
# Root Discovery & Paths
# ---------------------------------------------------------------------------

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
    # Fallback if no markers exist
    return start.resolve().parent.parent.parent


def get_paths() -> Tuple[Path, Path, Path]:
    """
    Setup dynamic paths based on the script's location.
    Returns: (project_root, config_dir, output_dir)
    """
    script_path = Path(__file__).resolve()
    project_root = find_project_root(script_path.parent)
    
    # Read from development/kits/configuration
    config_dir = project_root / "development" / "kits" / "configuration"
    
    # Write to development/kits/temp
    output_dir = project_root / "development" / "kits" / "temp"
    
    return project_root, config_dir, output_dir


# ---------------------------------------------------------------------------
# Preset Management
# ---------------------------------------------------------------------------

def load_presets(config_dir: Path) -> List[Path]:
    """Load all .py preset configuration files."""
    if not config_dir.exists() or not config_dir.is_dir():
        print_error(f"Config directory not found: {config_dir}")
        return []
    
    preset_files = list(config_dir.glob("*.py"))
    if not preset_files:
        print_error(f"No .py preset files found in {config_dir}")
    
    return sorted(preset_files)

def show_preset_menu(preset_files: List[Path]) -> None:
    """Display a menu of available presets."""
    if not preset_files:
        return
    
    print(paint("  Available Presets", C.WARNING, C.BOLD))
    print()
    
    for i, preset_path in enumerate(preset_files, 1):
        preset_name = preset_path.stem
        display_name = preset_name.replace('-', ' ')
        num = paint(f"[{i}]", C.INFO)
        txt = paint(display_name, C.SUCCESS)
        print(f"  {num} {txt}")
    
    num = paint("[0]", C.DANGER)
    txt = paint("Exit", C.DIM)
    print(f"  {num} {txt}")
    print()

def get_user_selection(preset_count: int) -> Optional[int]:
    """Get user selection from the menu."""
    while True:
        prompt = paint("  Choice ", C.MAGENTA, C.BOLD) + paint("› ", C.DIM)
        choice = input(prompt).strip()
        
        if not choice.isdigit():
            print(paint("  Invalid input. Please enter a number.", C.DANGER))
            print()
            continue
        
        val = int(choice)
        if val == 0:
            return None
        elif 1 <= val <= preset_count:
            return val - 1
        
        print(paint(f"  Invalid selection. Enter 0-{preset_count}.", C.DANGER))
        print()

def load_files_list(preset_file: Path) -> Optional[List[str]]:
    """Load the filesToCheck list from a Python preset file."""
    if not preset_file.exists():
        print_error(f"Preset file not found: {preset_file}")
        return None
    
    try:
        preset_globals = {}
        with open(preset_file, 'r', encoding='utf-8') as f:
            exec(f.read(), preset_globals)
        
        if 'filesToCheck' not in preset_globals:
            print_error(f"filesToCheck list not defined in preset: {preset_file.name}")
            return None
        
        files_to_check = preset_globals['filesToCheck']
        if not files_to_check:
            print_error(f"No files defined in preset: {preset_file.name}")
            return None
        
        return files_to_check
        
    except Exception as e:
        print_error(f"Error loading preset file {preset_file.name}: {e}")
        return None


# ---------------------------------------------------------------------------
# File Processing
# ---------------------------------------------------------------------------

def get_file_header(file_path: Path) -> str:
    """Get appropriate header comment for different file types."""
    headers = {
        '.html': '```html', '.htm': '```html',
        '.php': '```php',   '.css': '```css',
        '.js': '```javascript', '.jsx': '```jsx',
        '.ts': '```typescript', '.tsx': '```tsx',
        '.py': '```python', '.json': '```json',
        '.xml': '```xml',   '.md': '```markdown',
        '.txt': '```text',  '.sql': '```sql',
    }
    return headers.get(file_path.suffix.lower(), '```')

def process_files(
    project_root: Path,
    main_folder_name: str,
    preset_name: str,
    files_to_check: List[str],
    output_file: Path
) -> None:
    """Process all files and generate the output directly to the target file."""
    try:
        with open(output_file, 'w', encoding='utf-8') as f:
            f.write(f"# Web Project: {main_folder_name}\n\n")
            f.write(f"**Preset:** {preset_name}\n\n")
            f.write(f"**Generated:** {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n\n")
            f.write(f"---\n\n")
            
            for relative_path in files_to_check:
                if os.path.isabs(relative_path):
                    full_path = Path(relative_path)
                    display_path = relative_path
                else:
                    clean_path = relative_path.lstrip('/')
                    full_path = project_root / clean_path
                    display_path = f"{main_folder_name}/{clean_path}"
                
                f.write(f"## File: `{display_path}`\n\n")
                
                if full_path.exists() and full_path.is_file():
                    f.write("**Status:** `FOUND`\n\n")
                    
                    header = get_file_header(full_path)
                    if header:
                        f.write(f"{header}\n")
                    
                    try:
                        with open(full_path, 'r', encoding='utf-8') as content_file:
                            content = content_file.read()
                            f.write(content)
                        if content and not content.endswith('\n'):
                            f.write('\n')
                    except UnicodeDecodeError:
                        try:
                            with open(full_path, 'r', encoding='latin-1') as content_file:
                                f.write(content_file.read())
                        except Exception:
                            f.write("*[ERROR: Could not read file content]*\n")
                    except Exception as e:
                        f.write(f"*[ERROR reading file: {e}]*\n")
                    
                    f.write("```\n")
                else:
                    f.write("**Status:** `MISSING`\n\n")
                    f.write("*[File not found]*\n")
                
                f.write(f"\n---\n\n")
                
    except IOError as e:
        raise IOError(f"Error writing to output file: {e}")


# ---------------------------------------------------------------------------
# Main Execution
# ---------------------------------------------------------------------------

def main() -> int:
    clear_screen()
    
    print()
    print(paint("  File Content Fetcher", C.INFO, C.BOLD))
    print()

    project_root, config_dir, output_dir = get_paths()

    # Context Output
    print(paint("  [READ]  ", C.INFO) + paint(str(config_dir), C.WHITE))
    print(paint("  [WRITE] ", C.INFO) + paint(str(output_dir), C.WHITE))
    print()

    # Load presets
    preset_files = load_presets(config_dir)
    if not preset_files:
        return 1
    
    # Menu selection
    show_preset_menu(preset_files)
    selected_index = get_user_selection(len(preset_files))
    
    if selected_index is None:
        print(paint("  Cancelled.", C.DIM))
        print()
        return 0
    
    selected_preset = preset_files[selected_index]
    preset_name = selected_preset.stem
    
    # Load file list
    files_to_check = load_files_list(selected_preset)
    if files_to_check is None:
        return 1

    # Determine output path
    output_dir.mkdir(parents=True, exist_ok=True)
    output_file = output_dir / f"{preset_name}_codebase.md"
    
    if output_file.exists():
        print(paint("  Overwriting existing file: ", C.WARNING) + paint(str(output_file.name), C.DIM))
        print()

    print(paint("  [SUCCESS] ", C.SUCCESS) + paint("Fetching content...", C.WHITE), end="", flush=True)

    try:
        process_files(
            project_root,
            project_root.name,
            preset_name,
            files_to_check,
            output_file
        )
    except Exception as e:
        print()
        print_error(str(e))
        return 1

    print(" " + paint("done", C.SUCCESS))
    print()
    
    print(paint("  [SUMMARY] ", C.WARNING) + paint(f"{len(files_to_check)} files processed", C.WHITE))
    print()
    
    print(paint("  Written to ", C.DIM) + paint(str(output_file), C.SUCCESS))
    print()

    return 0


if __name__ == "__main__":
    sys.exit(main())