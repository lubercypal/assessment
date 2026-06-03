#!/bin/bash

set -e

msg="${1:-Update}"
branch="${2:-$(git branch --show-current)}"

git add .

if git diff --cached --quiet; then
    echo "No changes to commit"
    exit 0
fi

git commit -m "$msg" || exit 1
git push origin "$branch" || exit 1

ssh assessment "cd ~/domains/assessment.netcascade.in/public_html && git fetch origin && git checkout $branch && git pull origin $branch" || exit 1

echo ""
echo "✅ Deployment Completed Successfully on branch: $branch"
