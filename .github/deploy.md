name: CI and Deploy

on:
  push:
    branches: [ main ]
  pull_request:
  workflow_dispatch:

jobs:
  lint:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none

      - name: Lint and smoke test
        run: |
          set -euo pipefail
          files=$(git ls-files '*.php')
          for f in $files; do php -l "$f"; done
          php tests/smoke.php

  deploy:
    if: github.ref == 'refs/heads/main' && github.event_name == 'push'
    needs: lint
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Configure SSH
        env:
          DEPLOY_SSH_KEY: ${{ secrets.DEPLOY_SSH_KEY }}
          # Optional: DEPLOY_KNOWN_HOSTS: ${{ secrets.DEPLOY_KNOWN_HOSTS }}
        run: |
          set -euo pipefail
          mkdir -p ~/.ssh
          chmod 700 ~/.ssh
          printf '%s\n' "$DEPLOY_SSH_KEY" > ~/.ssh/id_ed25519
          chmod 600 ~/.ssh/id_ed25519
          # If using DEPLOY_KNOWN_HOSTS secret, write it; otherwise accept-new:
          ssh-keyscan -H 20.174.162.13 >> ~/.ssh/known_hosts 2>/dev/null || true
          chmod 644 ~/.ssh/known_hosts

      - name: Deploy
        env:
          TARGET_HOST: ${{ secrets.DEPLOY_HOST }}   # e.g. keane@20.174.162.13
          TARGET_PATH: ${{ secrets.DEPLOY_PATH }}   # e.g. /var/www/html/cat2
          SSH_OPTS: -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=15
        run: bash scripts/deploy.sh
