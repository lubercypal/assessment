#!/bin/bash

set -e

msg="${1:-Update}"
branch="${2:-develop}"

git add .

if git diff --cached --quiet; then
    echo "No changes to commit, continuing deployment..."
else
    git commit -m "$msg"
    git push origin "$branch"
fi

ssh assessment "
cd ~/domains/assessment.netcascade.in/public_html &&
git fetch origin &&
git reset --hard &&
git checkout $branch &&
git reset --hard origin/$branch
"

echo ""
echo "✅ Deployment Completed Successfully on branch: $branch"