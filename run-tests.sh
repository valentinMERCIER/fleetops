#!/bin/bash

# FleetOps Test Runner
# Handles the custom vendor directory structure

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PEST_RUNNER="$SCRIPT_DIR/pest"
PEST_SIMPLE="$SCRIPT_DIR/pest-simple"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    local color=$1
    local message=$2
    echo -e "${color}${message}${NC}"
}

# Check if custom runner exists
if [ ! -f "$PEST_RUNNER" ]; then
    print_status $RED "Error: Custom PEST runner not found at $PEST_RUNNER"
    echo "Please create it first following the instructions."
    exit 1
fi

# Make sure it's executable
chmod +x "$PEST_RUNNER"
chmod +x "$PEST_SIMPLE" 2>/dev/null

# Check for special commands
case "${1:-}" in
    --help|-h)
        print_status $GREEN "FleetOps Test Runner"
        echo
        echo "Usage: $0 [options] [PEST arguments]"
        echo
        echo "Special options:"
        echo "  --simple         Use the simple PEST runner instead"
        echo "  --debug          Enable debug output"
        echo "  --check          Check runner setup without running tests"
        echo
        echo "Common PEST arguments:"
        echo "  --configuration server/phpunit.xml    Use specific config"
        echo "  --testsuite Unit                      Run only Unit tests"
        echo "  --testsuite Feature                   Run only Feature tests"
        echo "  --filter TestName                     Run specific test"
        echo "  --coverage                            Show coverage report"
        echo "  --version                             Show PEST version"
        echo
        echo "Examples:"
        echo "  $0 --configuration server/phpunit.xml --testsuite Unit"
        echo "  $0 --simple --version"
        echo "  $0 --filter OrderImportServiceTest"
        exit 0
        ;;
    --simple)
        shift
        RUNNER="$PEST_SIMPLE"
        print_status $YELLOW "Using simple PEST runner..."
        ;;
    --check)
        print_status $GREEN "Checking PEST runner setup..."
        
        # Check autoloader
        if [ -f "$SCRIPT_DIR/server_vendor/autoload.php" ]; then
            print_status $GREEN "✓ Autoloader found: $SCRIPT_DIR/server_vendor/autoload.php"
        else
            print_status $RED "✗ Autoloader not found"
            exit 1
        fi
        
        # Check PEST binary
        if [ -f "$SCRIPT_DIR/server_vendor/bin/pest" ]; then
            print_status $GREEN "✓ PEST binary found: $SCRIPT_DIR/server_vendor/bin/pest"
        else
            print_status $RED "✗ PEST binary not found"
            exit 1
        fi
        
        # Check if PEST can be loaded
        if php -r "require '$SCRIPT_DIR/server_vendor/autoload.php'; echo class_exists('Pest\TestSuite') ? 'PEST classes available' : 'PEST classes not found';" | grep -q "PEST classes available"; then
            print_status $GREEN "✓ PEST classes can be loaded"
        else
            print_status $RED "✗ PEST classes cannot be loaded"
            exit 1
        fi
        
        print_status $GREEN "✓ All checks passed!"
        exit 0
        ;;
    *)
        RUNNER="$PEST_RUNNER"
        ;;
esac

# Run PEST with all arguments passed through
print_status $GREEN "Running PEST tests..."
print_status $YELLOW "Command: php $RUNNER $*"
echo

# Execute the runner
php "$RUNNER" "$@"
exit_code=$?

# Print result
if [ $exit_code -eq 0 ]; then
    echo
    print_status $GREEN "✓ Tests completed successfully!"
else
    echo
    print_status $RED "✗ Tests failed with exit code: $exit_code"
    
    if [ $exit_code -eq 1 ] && [[ "$*" != *"--debug"* ]]; then
        echo
        print_status $YELLOW "Tip: Try running with --debug for more information:"
        print_status $YELLOW "$0 --debug $*"
    fi
fi

exit $exit_code