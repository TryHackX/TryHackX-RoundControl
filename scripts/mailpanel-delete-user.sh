#!/bin/bash
# Skrypt do bezpiecznego usuwania katalogu pocztowego użytkownika

BASE_DIR="/var/mail/vhosts"
EMAIL="$1"

# 1. Walidacja formatu email (prosty regex)
if [[ ! "$EMAIL" =~ ^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$ ]]; then
    echo "Error: Invalid email format"
    exit 1
fi

# 2. Wyodrębnienie domeny i użytkownika
USER_PART=$(echo "$EMAIL" | cut -d'@' -f1)
DOMAIN_PART=$(echo "$EMAIL" | cut -d'@' -f2)

# 3. Zabezpieczenie przed Path Traversal (kropki i slashe)
if [[ "$USER_PART" == *".."* ]] || [[ "$USER_PART" == *"/"* ]] || \
   [[ "$DOMAIN_PART" == *".."* ]] || [[ "$DOMAIN_PART" == *"/"* ]]; then
    echo "Error: Invalid characters detected"
    exit 2
fi

TARGET_DIR="$BASE_DIR/$DOMAIN_PART/$USER_PART"

# 4. Sprawdzenie czy katalog istnieje i jest katalogiem
if [ -d "$TARGET_DIR" ]; then
    # Ostateczne bezpieczeństwo: upewnij się, że usuwamy coś wewnątrz /var/mail/vhosts
    if [[ "$TARGET_DIR" == /var/mail/vhosts/* ]]; then
        rm -rf "$TARGET_DIR"
        echo "Success: Directory $TARGET_DIR removed"
        exit 0
    else
        echo "Error: Path sanity check failed"
        exit 3
    fi
else
    echo "Warning: Directory not found, nothing to delete"
    exit 0
fi
