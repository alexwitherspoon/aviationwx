# GitHub Actions Setup

Repository secrets required by the CI and deploy workflows.

## Secrets

Add these under **Settings** > **Secrets and variables** > **Actions** > **New repository secret**.

| Secret | Required | Purpose |
| --- | --- | --- |
| `SSH_PRIVATE_KEY` | yes | Private key for the deploy runner. The matching public key must be authorized on the target host. |
| `HOST` | yes | SSH target hostname or IP. |
| `USER` | yes | SSH login user on the target host. |
| `LETSENCRYPT_EMAIL` | yes | Email for TLS certificate registration and renewal notices. |
| `CLOUDFLARE_API_TOKEN` | yes | Cloudflare DNS API token for the DNS-01 challenge and renewals. |
| `OPENWEATHERMAP_API_KEY` | no | Used by the weekly upstream API probes. The OpenWeatherMap check skips when unset. |

`GITHUB_TOKEN` is provided automatically and does not need to be added.

## What runs when

- Pull requests and pushes to `main`: tests, lint, and CodeQL.
- `main` after tests pass: `Deploy to Production` runs automatically, or manually from the **Actions** tab.
- Weekly: link check, upstream API probes, and WMM coefficient verify.

The deploy connects over SSH to `USER@HOST`, builds the image, and recreates the containers with `docker compose -f docker/docker-compose.prod.yml`, bootstraps TLS, then runs post-deploy health checks with rollback.

## Troubleshooting

### Tests are failing

- Check the **Actions** tab for the failing job.
- Run the suite locally with `make test-ci` (or `make test-unit` for a faster subset).

### Deployment failed

- Confirm `SSH_PRIVATE_KEY`, `HOST`, and `USER` are set and the host accepts the key.
- Confirm `LETSENCRYPT_EMAIL` and `CLOUDFLARE_API_TOKEN` are set; TLS bootstrap fails without them.
- See `.github/workflows/deploy-docker.yml` for the exact steps.
