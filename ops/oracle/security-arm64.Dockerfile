FROM lefenda-laravel-arm64-qa
RUN apt-get update && apt-get install -y --no-install-recommends python3 acl && rm -rf /var/lib/apt/lists/*
COPY ops ./ops
CMD ["bash", "ops/oracle/rehearse-security.sh"]
