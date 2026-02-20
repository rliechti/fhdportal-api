#!/bin/bash
set -e

# Navigate to the project directory
cd /api

# Download the latest schemas
if [ -f "bin/fega.phar" ]; then
    echo "Running 'bin/fega.phar update' to download the latest schemas..."
    php bin/fega.phar update || echo "Warning: 'fega.phar update' failed"
else
    echo "Warning: 'bin/fega.phar' not found, skipping schema update"
fi

# Start Apache in the foreground
exec apache2-foreground
