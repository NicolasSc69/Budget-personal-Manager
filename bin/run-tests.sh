#!/bin/sh
set -e

cd "$(dirname "$0")/.."

mkdir -p var/test-report

php bin/phpunit \
    --log-junit var/test-report/junit.xml \
    --testdox-text var/test-report/testdox.txt \
    "$@"
