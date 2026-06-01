#!/bin/bash

msg="${1:-Update}"

git add .

if git diff --cached --quiet; then
    echo "No changes to commit"
    exit 0
fi

git commit -m "$msg" || exit 1
git push || exit 1

ssh assessment 'cd ~/domains/assessment.netcascade.in/public_html && git pull' || exit 1

echo ""
echo "✅ Deployment Completed Successfully"
