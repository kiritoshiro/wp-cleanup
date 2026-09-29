#!/usr/bin/env bash
# Prepare a throwaway local WordPress site for the integration tests.
#
# Usage: bin/test-setup.sh /path/to/wordpress [wp-cli command]
#   e.g. bin/test-setup.sh ~/sites/wpcu-test "php ~/bin/wp-cli.phar"
#
# The site must already be installed on a local URL (localhost, 127.0.0.1, *.test)
# with this plugin present in wp-content/plugins/wp-cleanup (copy, symlink or junction).
# Then run:
#   cd /path/to/wordpress && WPCU_TESTS=1 wp eval-file wp-content/plugins/wp-cleanup/tests/integration/run.php
set -euo pipefail

SITE="${1:?path to the WordPress root}"
WP="${2:-wp}"
HERE="$(cd "$(dirname "$0")/.." && pwd)"

for fixture in wpcu-fixture-active wpcu-fixture-inactive; do
	rm -rf "$SITE/wp-content/plugins/$fixture"
	cp -R "$HERE/tests/fixtures/$fixture" "$SITE/wp-content/plugins/$fixture"
done

cd "$SITE"
$WP plugin activate wp-cleanup wpcu-fixture-active
$WP plugin deactivate wpcu-fixture-inactive --quiet 2>/dev/null || true
echo "Fixtures installed. Run: WPCU_TESTS=1 $WP eval-file wp-content/plugins/wp-cleanup/tests/integration/run.php"
