#!/usr/bin/env bash
# -------------------------------------------------------------------
# build.sh - Create Moodle-ready ZIP packages for installation
#
# Usage:
#   ./build.sh            # Package both plugins
#   ./build.sh aichat     # Package only local_aichat
#   ./build.sh theme      # Package only theme_myuni
# -------------------------------------------------------------------
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
DIST_DIR="$SCRIPT_DIR/dist"

mkdir -p "$DIST_DIR"

get_plugin_version() {
    local version_file="$1"
    local release
    release=$(grep -oP "\\\$plugin->release\s*=\s*'\K[^']+" "$version_file" 2>/dev/null || true)
    if [ -z "$release" ]; then
        release=$(grep -oP "\\\$plugin->version\s*=\s*\K\d+" "$version_file" 2>/dev/null || echo "unknown")
    fi
    echo "$release"
}

build_zip() {
    local source_dir="$1"
    local top_folder="$2"
    local version_file="$3"
    local output_prefix="$4"

    local version
    version=$(get_plugin_version "$version_file")
    local zip_name="${output_prefix}-${version}.zip"
    local zip_path="$DIST_DIR/$zip_name"

    # Remove old zip if it exists
    rm -f "$zip_path"

    # Create a temporary staging directory
    local staging_dir
    staging_dir=$(mktemp -d)

    # Copy plugin files, excluding dev artifacts
    rsync -a \
        --exclude='.git' \
        --exclude='node_modules' \
        --exclude='.github' \
        --exclude='__pycache__' \
        --exclude='.gitignore' \
        --exclude='.gitattributes' \
        --exclude='.editorconfig' \
        --exclude='*.log' \
        "$source_dir/" "$staging_dir/$top_folder/"

    # Create the ZIP (cd into staging so the zip has the correct top-level folder)
    (cd "$staging_dir" && zip -rq "$zip_path" "$top_folder")

    # Clean up
    rm -rf "$staging_dir"

    local size
    size=$(du -h "$zip_path" | cut -f1)
    echo "  Created: $zip_name ($size)"
}

TARGET="${1:-all}"

if [ "$TARGET" = "aichat" ] || [ "$TARGET" = "all" ]; then
    echo ""
    echo "Packaging local_aichat plugin..."
    build_zip \
        "$SCRIPT_DIR/local/aichat" \
        "aichat" \
        "$SCRIPT_DIR/local/aichat/version.php" \
        "local_aichat"
fi

if [ "$TARGET" = "theme" ] || [ "$TARGET" = "all" ]; then
    echo ""
    echo "Packaging custom theme..."
    build_zip \
        "$SCRIPT_DIR/theme/myuni" \
        "myuni" \
        "$SCRIPT_DIR/theme/myuni/version.php" \
        "theme_myuni"
fi

echo ""
echo "Done! ZIP files are in: $DIST_DIR"
