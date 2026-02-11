Quick notes for Docker production deployment

- Ensure secrets in `.env-docker` are set (APP_KEY, DB_PASSWORD, etc.).
- Make the deploy script executable: `chmod +x docker-deploy.sh`.
- To deploy (on `master` branch):

```sh
./docker-deploy.sh
```

- The compose file is at `docker/docker-compose.prod.yml` and exposes Nginx on port 8080.
