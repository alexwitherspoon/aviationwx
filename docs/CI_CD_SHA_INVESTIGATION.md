# CI/CD SHA Investigation Report

## Summary

Investigation into CI/CD pipeline issues where commits/merges to main don't always run CI & CD against the right SHA, and the latest SHA doesn't always make it to production.

## Issues Fixed

### 1. Concurrency Group Scope
**Problem**: Concurrency group was `deploy-production` for all deployments, causing deployments to queue together and potentially deploy out of order.

**Fix**: Changed to SHA-specific concurrency groups:
```yaml
concurrency:
  group: deploy-production-${{ github.event.workflow_run.head_sha || github.sha }}
```

Each commit now gets its own deployment slot, preventing conflicts and ensuring correct SHA deployment.

### 2. Missing SHA Verification
**Problem**: Workflow trusted `github.event.workflow_run.head_sha` without validation.

**Fix**: Added comprehensive verification:
- Validates `workflow_run.head_sha` is present and not empty
- Verifies branch is `main`
- Checks commit exists in repository via GitHub API
- Verifies SHA consistency between workflow_run event and determined SHA
- Fails fast if SHA mismatch detected

### 3. Duplicate Deployment Detection
**Problem**: Blocked ALL active deployments, not just same commit.

**Fix**: Changed to only check for duplicate deployments of the SAME commit, allowing parallel deployments of different commits.

### 4. Enhanced Logging
**Problem**: Limited logging made it difficult to track which SHA was being deployed.

**Fix**: Added comprehensive SHA tracking throughout the pipeline with detailed logging at each step.

## Root Cause

When multiple commits are pushed to main rapidly:
1. QA workflow runs for each commit
2. QA workflows may complete in different order
3. Without proper SHA tracking and verification, deployments could use wrong SHA
4. Concurrency group was too broad, causing deployments to queue together

## Testing

Monitor recent workflow runs to verify:
- Deploy SHA matches QA workflow SHA
- Latest commits are being deployed
- No SHA mismatches in logs
- Each commit gets its own deployment slot
