# Upgrade the database MCP server

## Upgrade to v0.0.16

### Docker installations

Version v0.0.16 bundles the GLiNER PII model and tokenizer in the Docker image.
Follow these steps for an existing Compose setup:

1. In your Compose file, remove the following volume mount from `database-mcp`
   unless you intentionally use custom models:

   ```yaml
   - ./models:/app/models:ro
   ```

   This mount hides the bundled files, even if the host directory is empty.
   You can keep the local files as a backup.

2. Remove the `download-models` service from your Compose file. Docker installations
   no longer need a separate model download.

3. If you pinned the image version, update it to `ineersa/database-mcp:0.0.16`
   or a later version. Keep `latest` if you use that tag.

4. From the directory containing your Compose file, pull the updated image:

   ```bash
   docker compose pull
   ```

5. Restart the database MCP connection in your client. If the client has no restart
   control, close and reopen the client so it starts a new container.

6. Run a query against a connection with `pii_enabled: true` to verify that PII
   detection works with the bundled files.

The image is larger because it includes about 1.8 GB of model files before
compression. The machine must be able to pull the Docker image, but PII detection
no longer requires runtime access to Hugging Face.

### Custom models

Keep your model mount if you intentionally use a custom compatible GLiNER ONNX
model. Check that `pii.tokenizer_path` and `pii.model_path` point to its paths
inside the container. See [PII setup in the README](README.md#1-set-up-models).

### Native PHP installations

No model setup changes are required. The `php bin/console download-models`
command remains available for native installations.
