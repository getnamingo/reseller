#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
  echo "Run this package installer as root." >&2
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive

apt-get update
apt-get install -y ca-certificates curl gnupg software-properties-common unzip git

add-apt-repository -y ppa:ondrej/php
curl -LsS https://r.mariadb.com/downloads/mariadb_repo_setup \
  | bash -s -- --mariadb-server-version=mariadb-11.8

apt-get update
apt-get install -y \
  composer mariadb-client mariadb-server \
  php8.5-cli php8.5-common php8.5-curl php8.5-mbstring php8.5-mysql \
  php8.5-opcache php8.5-swoole php8.5-xml php8.5-zip

php8.5 -r 'foreach (["curl", "openssl", "pdo_mysql", "SimpleXML", "sodium", "swoole", "xmlwriter"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "Missing extension: $e\n"); exit(1); } }'
mariadb --version

echo "PHP 8.5, Sury Swoole, Composer, and MariaDB 11.8 packages are installed."
