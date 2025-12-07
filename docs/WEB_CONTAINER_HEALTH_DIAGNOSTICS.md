# Web Container Health Diagnostics

## Quick Diagnostics

Run these commands on the production server to diagnose why the web container is unhealthy:

```bash
cd ~/aviationwx

# 1. Check container logs (most important - will show why Apache isn't starting)
docker compose -f docker/docker-compose.prod.yml logs web | tail -100

# 2. Check if Apache process is running inside container
docker compose -f docker/docker-compose.prod.yml exec web ps aux | grep -E "apache|httpd"

# 3. Test if Apache is responding inside container
docker compose -f docker/docker-compose.prod.yml exec web curl -f http://localhost/ 2>&1

# 4. Check if Apache is listening on port 80
docker compose -f docker/docker-compose.prod.yml exec web netstat -tuln | grep :80

# 5. Check healthcheck details and history
docker inspect aviationwx-web | jq '.[0].State.Health'

# 6. Check entrypoint script execution
docker compose -f docker/docker-compose.prod.yml exec web cat /proc/1/cmdline | tr '\0' ' ' && echo

# 7. Check if vsftpd/sshd are blocking Apache startup
docker compose -f docker/docker-compose.prod.yml exec web pgrep -a vsftpd
docker compose -f docker/docker-compose.prod.yml exec web pgrep -a sshd

# 8. Check for any errors in the entrypoint script
docker compose -f docker/docker-compose.prod.yml logs web 2>&1 | grep -i -E "error|fail|exit"
```

## Common Issues

### Issue 1: Apache not starting
**Symptoms:** Container logs show entrypoint script running but no Apache startup messages
**Check:** Look for "Apache/2.4" in logs
**Fix:** Check if vsftpd/sshd startup is failing and blocking Apache

### Issue 2: Apache starting but not responding
**Symptoms:** Apache process exists but curl fails
**Check:** `netstat -tuln | grep :80` should show LISTEN
**Fix:** Check Apache error logs: `docker exec aviationwx-web tail -50 /var/log/apache2/error.log`

### Issue 3: Healthcheck failing due to slow startup
**Symptoms:** Container is healthy after 2-3 minutes
**Check:** `start-period` in healthcheck might be too short
**Fix:** Increase `start-period` in Dockerfile HEALTHCHECK

### Issue 4: Entrypoint script exiting early
**Symptoms:** Container exits immediately or logs stop abruptly
**Check:** Look for exit codes in logs
**Fix:** Check if `set -e` is causing early exit due to non-fatal errors

