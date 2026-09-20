#!/usr/bin/env python3
"""
Web Project Tree Mapper - Directory structure visualization for web projects
Python version
Revised to output clean tree structure with proper code block formatting
"""

import os
import sys
from pathlib import Path
from datetime import datetime
from typing import Dict, List, Optional, Tuple, Set

# Text colors for terminal output
class Colors:
    GREEN = '\033[1;32m'
    YELLOW = '\033[1;33m'
    RED = '\033[1;31m'
    BLUE = '\033[1;34m'
    CYAN = '\033[1;36m'
    PURPLE = '\033[1;35m'
    RESET = '\033[0m'

def color_text(text: str, color: str) -> str:
    """Return colored text for terminal output."""
    return f"{color}{text}{Colors.RESET}"

# File type definitions
FILE_TYPES = {
    '.html': 'HTML',
    '.htm': 'HTML',
    '.php': 'PHP',
    '.css': 'CSS',
    '.js': 'JavaScript',
    '.json': 'JSON',
    '.md': 'Markdown',
    '.txt': 'Text',
    '.sh': 'Shell',
    '.py': 'Python',
    '.sql': 'SQL',
    '.xml': 'XML',
}

# Image extensions
IMAGE_EXTENSIONS = {'.png', '.jpg', '.jpeg', '.gif', '.svg', '.ico', '.webp', '.bmp'}

# Web file extensions for web_only mode
WEB_EXTENSIONS = {'.html', '.htm', '.php', '.css', '.js', '.json', '.md', '.txt'}

# Directories to exclude
EXCLUDED_DIRS = {'.git', 'node_modules', '__pycache__', '.venv', 'venv', 'env'}

def is_hidden_file(file_path: Path) -> bool:
    """Check if file or directory is hidden."""
    return file_path.name.startswith('.')

def should_exclude_directory(dir_name: str) -> bool:
    """Check if directory should be excluded."""
    return dir_name in EXCLUDED_DIRS

def should_include_file(file_name: str, mode: str) -> bool:
    """Check if file should be included based on mode."""
    suffix = Path(file_name).suffix.lower()
    
    if mode == 'web_only':
        return suffix in WEB_EXTENSIONS
    elif mode == 'assets_only':
        return suffix in IMAGE_EXTENSIONS
    elif mode == 'all':
        return True
    else:
        return False

def count_files_by_type(project_root: Path) -> Dict[str, int]:
    """Count files by type."""
    counts = {
        'html': 0,
        'php': 0,
        'css': 0,
        'js': 0,
        'json': 0,
        'text': 0,
        'images': 0,
        'other': 0
    }
    
    for file_path in project_root.rglob('*'):
        if not file_path.is_file():
            continue
        
        # Skip excluded directories
        skip = False
        for part in file_path.parts:
            if should_exclude_directory(part):
                skip = True
                break
        if skip:
            continue
        
        suffix = file_path.suffix.lower()
        
        if suffix in ['.html', '.htm']:
            counts['html'] += 1
        elif suffix == '.php':
            counts['php'] += 1
        elif suffix == '.css':
            counts['css'] += 1
        elif suffix == '.js':
            counts['js'] += 1
        elif suffix == '.json':
            counts['json'] += 1
        elif suffix in ['.txt', '.md']:
            counts['text'] += 1
        elif suffix in IMAGE_EXTENSIONS:
            counts['images'] += 1
        else:
            counts['other'] += 1
    
    return counts

def generate_tree_structure(
    dir_path: Path,
    output_lines: List[str],
    indent: str,
    mode: str,
    exclude_logs: bool = False
) -> None:
    """
    Recursively generate clean tree structure.
    
    Args:
        dir_path: Directory to process
        output_lines: List to append output lines to
        indent: Current indentation string
        mode: Filter mode ('web_only', 'all', 'assets_only')
        exclude_logs: Whether to exclude logs directory
    """
    try:
        # Skip excluded directories
        if should_exclude_directory(dir_path.name):
            return
        
        # Skip hidden directories
        if is_hidden_file(dir_path):
            return
        
        # Get all entries, sorted
        entries = []
        
        # Directories first
        for item in sorted(dir_path.iterdir()):
            if item.is_dir():
                if exclude_logs and item.name == 'logs':
                    continue
                entries.append((item, 'dir'))
        
        # Files next
        for item in sorted(dir_path.iterdir()):
            if item.is_file():
                entries.append((item, 'file'))
        
        total = len(entries)
        counter = 1
        
        for entry, entry_type in entries:
            base_name = entry.name
            
            # Skip hidden files
            if is_hidden_file(entry):
                counter += 1
                continue
            
            is_last = (counter == total)
            
            if entry_type == 'dir':
                # Handle directories
                if is_last:
                    output_lines.append(f"{indent}└── {base_name}/")
                    generate_tree_structure(entry, output_lines, indent + "    ", mode, exclude_logs)
                else:
                    output_lines.append(f"{indent}├── {base_name}/")
                    generate_tree_structure(entry, output_lines, indent + "│   ", mode, exclude_logs)
            else:
                # Handle files - filter based on mode
                if not should_include_file(base_name, mode):
                    counter += 1
                    continue
                
                # Files
                if is_last:
                    output_lines.append(f"{indent}└── {base_name}")
                else:
                    output_lines.append(f"{indent}├── {base_name}")
            
            counter += 1
    
    except PermissionError:
        # Skip directories we don't have permission to access
        pass
    except Exception as e:
        # Log other errors but continue
        print(color_text(f"Warning: Error processing {dir_path}: {e}", Colors.YELLOW))

def generate_map(project_root: Path, output_file: Path, mode: str) -> None:
    """
    Generate the project structure map with proper code block formatting.
    
    Args:
        project_root: Root directory of the project
        output_file: Path to output file
        mode: Filter mode ('web_only', 'all', 'assets_only')
    """
    print(color_text("\nGenerating project structure map...", Colors.GREEN))
    print(color_text(f"Project root: {project_root}", Colors.YELLOW))
    print(color_text(f"Output file: {output_file}", Colors.YELLOW))
    print()
    
    output_lines = []
    
    # Header
    output_lines.append("# Web Project Structure")
    output_lines.append("")
    output_lines.append(f"**Project:** {project_root.name}")
    output_lines.append(f"**Generated:** {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    output_lines.append(f"**Mode:** {mode}")
    output_lines.append("")
    
    # Start code block for tree structure
    output_lines.append("```")
    output_lines.append(f"{project_root.name}/")
    output_lines.append("│")
    
    # Get root level entries
    root_entries = []
    script_name = Path(__file__).name
    
    # Directories first
    for item in sorted(project_root.iterdir()):
        if item.is_dir():
            if not should_exclude_directory(item.name) and not is_hidden_file(item):
                root_entries.append((item, 'dir'))
    
    # Files at root level
    for item in sorted(project_root.iterdir()):
        if item.is_file():
            if item.name != script_name:
                root_entries.append((item, 'file'))
    
    total_root = len(root_entries)
    root_counter = 1
    
    # Process root entries
    for entry, entry_type in root_entries:
        base_name = entry.name
        is_last = (root_counter == total_root)
        
        if entry_type == 'dir':
            if is_last:
                output_lines.append(f"└── {base_name}/")
                generate_tree_structure(entry, output_lines, "    ", mode, exclude_logs=False)
            else:
                output_lines.append(f"├── {base_name}/")
                generate_tree_structure(entry, output_lines, "│   ", mode, exclude_logs=False)
        else:
            # Filter files at root level based on mode
            if not should_include_file(base_name, mode):
                root_counter += 1
                continue
            
            if is_last:
                output_lines.append(f"└── {base_name}")
            else:
                output_lines.append(f"├── {base_name}")
        
        root_counter += 1
    
    # Close code block
    output_lines.append("```")
    output_lines.append("")
    
    # Add summary section
    output_lines.append("## Summary")
    output_lines.append("")
    
    # Count files by type
    counts = count_files_by_type(project_root)
    
    # Count directories
    dir_count = 0
    for item in project_root.rglob('*'):
        if item.is_dir():
            # Skip excluded directories
            skip = False
            for part in item.parts:
                if should_exclude_directory(part):
                    skip = True
                    break
            if not skip and not is_hidden_file(item):
                dir_count += 1
    
    # Count all files
    file_count = 0
    for item in project_root.rglob('*'):
        if item.is_file():
            # Skip files in excluded directories
            skip = False
            for part in item.parts:
                if should_exclude_directory(part):
                    skip = True
                    break
            if not skip and not is_hidden_file(item) and should_include_file(item.name, mode):
                file_count += 1
    
    # Create summary table
    output_lines.append("| File Type | Count |")
    output_lines.append("|-----------|-------|")
    output_lines.append(f"| HTML Files | {counts['html']} |")
    output_lines.append(f"| PHP Files | {counts['php']} |")
    output_lines.append(f"| CSS Files | {counts['css']} |")
    output_lines.append(f"| JavaScript Files | {counts['js']} |")
    output_lines.append(f"| JSON Files | {counts['json']} |")
    output_lines.append(f"| Text/Markdown | {counts['text']} |")
    output_lines.append(f"| Image Files | {counts['images']} |")
    output_lines.append(f"| Other Files | {counts['other']} |")
    output_lines.append("")
    output_lines.append(f"**Total Directories:** {dir_count}")
    output_lines.append(f"**Total Files:** {file_count}")
    output_lines.append("")
    output_lines.append("---")
    output_lines.append("")
    output_lines.append(f"*Generated by Web Project Tree Mapper*")
    output_lines.append(f"*Script: {Path(__file__).name}*")
    
    # Write to file
    try:
        with open(output_file, 'w', encoding='utf-8') as f:
            f.write('\n'.join(output_lines))
        
        print(color_text("\n✓ Project structure mapping complete!", Colors.GREEN))
        print(color_text(f"✓ File created: {output_file}", Colors.YELLOW))
        print()
        
    except IOError as e:
        print(color_text(f"\n✗ Error writing to output file: {e}", Colors.RED))
        sys.exit(1)

def show_menu() -> None:
    """Display the menu options."""
    print(color_text("Choose mapping option:", Colors.BLUE))
    print("[1] Web files only (.html, .php, .css, .js, .json, .md, .txt)")
    print("[2] All files and folders (excluding node_modules/.git)")
    print("[3] Assets only (images, icons, media files)")
    print("[0] Exit")

def get_user_choice() -> str:
    """Get user choice from menu."""
    while True:
        choice = input(color_text("\nEnter your choice: ", Colors.YELLOW)).strip()
        if choice in ['0', '1', '2', '3']:
            return choice
        print(color_text("Invalid choice. Please enter 0, 1, 2, or 3.", Colors.RED))

def main() -> None:
    """Main execution function."""
    # Setup paths
    script_dir = Path(__file__).parent.absolute()
    project_root = script_dir.parent.absolute()
    
    # Output path: /logs/output/
    logs_path = project_root / 'logs'
    output_path = logs_path / 'output'
    
    # Create directories if they don't exist
    output_path.mkdir(parents=True, exist_ok=True)
    
    # Output file with .md extension
    output_file = output_path / 'project_structure.md'
    
    # Display banner
    print(color_text("\n=== Web Project Tree Mapper ===", Colors.CYAN))
    print()
    print(color_text(f"Project Root: {project_root}", Colors.GREEN))
    print(color_text(f"Project Name: {project_root.name}", Colors.GREEN))
    print(color_text(f"Output Folder: {output_path}", Colors.GREEN))
    print()
    
    # Check if output file exists (inform user but always overwrite)
    if output_file.exists():
        print(color_text(f"Overwriting existing file: {output_file}", Colors.YELLOW))
    
    # Show menu and get choice
    show_menu()
    choice = get_user_choice()
    
    if choice == '0':
        print("Exiting...")
        sys.exit(0)
    
    # Map choice to mode
    mode_map = {
        '1': 'web_only',
        '2': 'all',
        '3': 'assets_only'
    }
    
    mode = mode_map[choice]
    
    # Verify project root exists and has content
    if not project_root.exists():
        print(color_text(f"Error: Project root does not exist: {project_root}", Colors.RED))
        sys.exit(1)
    
    # Generate the map
    generate_map(project_root, output_file, mode)

if __name__ == "__main__":
    main()